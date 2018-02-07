<?php
declare(strict_types=1);
namespace Ivory\Showcase;

use Ivory\Connection\IConnection;
use Ivory\IvoryTestCase;
use Ivory\Query\SqlRelationDefinition;
use Ivory\Type\BaseType;
use Ivory\Type\IType;
use Ivory\Type\IValueSerializer;
use Ivory\Type\Postgresql\ArrayType;
use Ivory\Type\Std\StringType;
use Ivory\Value\Date;
use Ivory\Value\Json;
use Ivory\Value\Polygon;
use Ivory\Value\Range;
use Ivory\Value\XmlContent;
use Ivory\Value\XmlDocument;

/**
 * This test presents the type system support.
 */
class TypeSystemTest extends IvoryTestCase
{
    /** @var IConnection */
    private $conn;

    protected function setUp()
    {
        parent::setUp();
        $this->conn = $this->getIvoryConnection();
    }

    public function testArrayType()
    {
        $arr = $this->conn->querySingleValue('SELECT %', ['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], $arr);

        $arr = $this->conn->querySingleValue('SELECT %', [4 => 'a', 6 => 'c', 5 => 'b']);
        $this->assertSame([4 => 'a', 5 => 'b', 6 => 'c'], $arr);

        $pattern = SqlRelationDefinition::fromPattern('INSERT INTO t (a) VALUES (%)', ['a', 'b', 'c']);
        $sql = $pattern->toSql($this->conn->getTypeDictionary());
        $this->assertSame("INSERT INTO t (a) VALUES ('[0:2]={a,b,c}'::pg_catalog.text[])", $sql);


        $stringArraySerializer = $this->conn->getTypeDictionary()->requireTypeByValue(['a']);
        assert($stringArraySerializer instanceof ArrayType);
        $stringArraySerializer->switchToPlainMode();
        try {
            $plainArr = $this->conn->querySingleValue("SELECT ARRAY['a', 'b', 'c']");
            $this->assertSame(['a', 'b', 'c'], $plainArr);

            $plainPattern = SqlRelationDefinition::fromPattern('INSERT INTO t (a) VALUES (%)', ['a', 'b', 'c']);
            $plainSql = $plainPattern->toSql($this->conn->getTypeDictionary());
            $this->assertSame("INSERT INTO t (a) VALUES (ARRAY['a','b','c']::pg_catalog.text[])", $plainSql);
        } finally {
            $stringArraySerializer->switchToStrictMode();
        }
    }

    /**
     * Ivory has no problems handling complex types.
     */
    public function testComplexTypes()
    {
        $tuple = $this->conn->querySingleTuple(
            <<<SQL
            SELECT ARRAY[
                    daterange('2015-05-19', '2015-12-01'),
                    daterange('2015-12-01', '2017-02-19'),
                    daterange('2017-02-19', NULL)
                  ],
                  '{"k1": 42, "k2": false}'::json,
                  XMLPARSE(DOCUMENT '<?xml version="1.0"?><book><title>Manual</title></book>') AS xml,
                  polygon '((1,1), (1, 5), (4, 3))',
                  ROW(1, 2, 'foo')
SQL
        );

        /** @var Range[] $rangeArray */
        $rangeArray = $tuple->array;
        $this->assertInstanceOf(Range::class, $rangeArray[2]);
        $this->assertSame('19.2.2017', $rangeArray[2]->getUpper()->format('j.n.Y'));
        $this->assertFalse($rangeArray[3]->isFinite());

        /** @var Json $json */
        $json = $tuple->json;
        $this->assertInstanceOf(Json::class, $json);
        $this->assertEquals((object)['k1' => 42, 'k2' => false], $json->getValue());

        /** @var XmlDocument $xml */
        $xml = $tuple->xml;
        $this->assertInstanceOf(XmlContent::class, $xml);
        $this->assertEquals(['Manual'], $xml->toSimpleXMLElement()->xpath('//title/text()'));

        /** @var Polygon $polygon */
        $polygon = $tuple->polygon;
        $this->assertInstanceOf(Polygon::class, $polygon);
        $this->assertEquals(6, $polygon->getArea(), '', 1e-12);

        $this->assertSame(['1', '2', 'foo'], $tuple->row);
    }

    /**
     * Whenever Ivory does not recognize a type in the result, it introspects the database and fetches and processes the
     * type automatically.
     */
    public function testDynamic()
    {
        $tx = $this->conn->startTransaction();
        try {
            // Create a brand new type.
            $this->conn->command(
                'CREATE TYPE __ivory_test_composite_type AS (
                   num INT,
                   chr TEXT
                 )'
            );

            // Use it in a query.
            $composite = $this->conn->querySingleValue("SELECT (1, 'a')::__ivory_test_composite_type");
            $this->assertSame('a', $composite->chr);
        } finally {
            $tx->rollback();
        }
    }

    /**
     * Serializers of values, like `%like`, may be customized very easily. In this example, we define a `%strlist`
     * serializer, which produces a `(...)` list, suitable for, e.g., `IN (...)`.
     */
    public function testCustomSerializer()
    {
        // define the serializer...
        $strListSerializer = new class implements IValueSerializer
        {
            private $stringType;

            public function __construct()
            {
                $this->stringType = new StringType('%strlist', 'string'); // it requires some name for identification
            }

            public function serializeValue($val): string
            {
                if (!is_array($val)) {
                    throw new \InvalidArgumentException('%strlist expects an array');
                }

                $result = '(';
                $isFirst = true;
                foreach ($val as $str) {
                    if (!$isFirst) {
                        $result .= ', ';
                    }
                    $isFirst = false;

                    $result .= $this->stringType->serializeValue($str);
                }
                if ($isFirst) {
                    throw new \InvalidArgumentException('%strlist list cannot be empty');
                }
                $result .= ')';

                return $result;
            }
        };

        // ...and register it. Either at the global level, or just for a specific connection.
        $this->conn->getTypeRegister()->registerValueSerializer('strlist', $strListSerializer);

        $result = $this->conn->querySingleValue('SELECT %s IN %strlist', 'foo', ['a', 'b', 'c']);
        $this->assertSame(false, $result);
    }

    /**
     * Type objects for custom user-defined types may easily be plugged into Ivory. Also, any type object shipped with
     * Ivory may be overridden with a custom one.
     */
    public function testCustomType()
    {
        // Just implement the IType (or ITotallyOrderedType, for the type to be usable in ranges)...
        $customTimeType = new class implements IType {
            public function getSchemaName(): string
            {
                return 'pg_catalog';
            }

            public function getName(): string
            {
                return 'time';
            }

            public function parseValue(string $extRepr)
            {
                $dt = \DateTime::createFromFormat('H:i:s.u', $extRepr);
                if ($dt === false) {
                    $dt = \DateTime::createFromFormat('H:i:s', $extRepr);
                }

                if ($dt !== false) {
                    return $dt;
                } else {
                    throw new \InvalidArgumentException('Invalid format to parse a time');
                }
            }

            public function serializeValue($val): string
            {
                if ($val === null) {
                    return 'NULL';
                } elseif ($val instanceof \DateTime) {
                    return $val->format("'H:i:s.u'");
                } else {
                    throw new \InvalidArgumentException('Invalid value to serialize as TIME');
                }
            }
        };

        // ...and register it. Either at the global level, or just for a specific connection.
        $this->conn->getTypeRegister()->registerType($customTimeType);

        $val = $this->conn->querySingleValue("SELECT TIME '16:42'");
        $this->assertEquals(new \DateTime('16:42:00'), $val);

        $eq = $this->conn->querySingleValue("SELECT TIME '13:30' = %time", new \DateTime('13:30:00'));
        $this->assertTrue($eq);
    }

    /**
     * If behaviour of a type converter does not suit the needs, it may easily be redefined.
     */
    public function testRedefineType()
    {
        $dateVal = $this->conn->querySingleValue('SELECT %date::DATE', Date::fromParts(2017, 9, 23));
        $this->assertInstanceOf(Date::class, $dateVal);

        // Prefer standard \DateTime over the custom Date class:
        $myDateType = new class('pg_catalog', 'date') extends BaseType
        {
            public function parseValue(string $extRepr)
            {
                return \DateTime::createFromFormat('!Y-m-d', $extRepr);
            }

            public function serializeValue($val): string
            {
                if ($val === null) {
                    return 'NULL';
                }
                if (!$val instanceof \DateTime) {
                    throw new \InvalidArgumentException();
                }

                return $val->format("'Y-m-d'");
            }
        };
        $this->conn->getTypeRegister()->registerType($myDateType);
        $this->conn->flushTypeDictionary();

        $dateTimeVal = $this->conn->querySingleValue('SELECT %date::DATE', new \DateTime('2017-09-30'));
        $this->assertInstanceOf(\DateTime::class, $dateTimeVal);
        $this->assertEquals(new \DateTime('2017-09-30'), $dateTimeVal);

        // Note, however, that the original DateType class represents a discrete type, enabling the type to be used in
        // ranges, that it reacts to the PostgreSQL date style setting, that it recognizes `-infinity` and `infinity`
        // values, and supports years exceeding four digits - nothing of which the simple implementation above handles.
    }
}

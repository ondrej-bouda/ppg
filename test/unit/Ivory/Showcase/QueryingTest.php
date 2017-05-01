<?php
namespace Ivory\Showcase;

use Ivory\Connection\IConnection;
use Ivory\Query\SqlRelationRecipe;
use Ivory\Type\ITypeDictionary;
use Ivory\Value\Date;
use Ivory\Value\Decimal;
use Ivory\Value\Json;

/**
 * This test presents the measures for querying the database.
 */
class QueryingTest extends \Ivory\IvoryTestCase
{
    /** @var IConnection */
    private $conn;
    /** @var ITypeDictionary */
    private $typeDict;

    protected function setUp()
    {
        parent::setUp();

        $this->conn = $this->getIvoryConnection();
        $this->conn->startTransaction();
        $this->typeDict = $this->conn->getTypeDictionary();
    }


    public function testSqlPatterns()
    {
        // Ivory introduces SQL patterns. They are similar to sprintf() but, when serializing to SQL, the actual types
        // defined on the database connection are used. See \Ivory\Lang\SqlPattern\SqlPattern class docs for details.
        $recipe = SqlRelationRecipe::fromPattern('SELECT %s, %num, %bool', "Ivory's escaping", '3.14', false);
        $sql = $recipe->toSql($this->typeDict);
        $this->assertSame("SELECT 'Ivory''s escaping', 3.14, FALSE", $sql);

        // Moreover, the types need not be specified explicitly. There are rules for recognizing the type by the data
        // type of the actual value.
        $recipe = SqlRelationRecipe::fromPattern('SELECT %, %, %', "Automatic type recognition", 3.14, false);
        $sql = $recipe->toSql($this->typeDict);
        $this->assertSame("SELECT 'Automatic type recognition', 3.14, FALSE", $sql);

        // As usual, both the types of placeholders and the rules for recognizing types from values are configurable.
        $this->conn->getTypeRegister()->registerTypeAbbreviation('js', 'pg_catalog', 'json');
        $this->conn->getTypeRegister()->addTypeRecognitionRule(\stdClass::class, 'pg_catalog', 'json');
        $this->conn->flushTypeDictionary(); // the type dictionary was changed while in use - explicit flush necessary

        $recipe = SqlRelationRecipe::fromPattern('SELECT %js, %', Json::null(), (object)['a' => 42]);
        $sql = $recipe->toSql($this->conn->getTypeDictionary());
        $this->assertSame("SELECT pg_catalog.json 'null', pg_catalog.json '{\"a\":42}'", $sql);

        // Also, brand new types may be introduced. See \Ivory\Showcase\TypeSystemTest::testCustomType().
    }

    public function testRelationRecipe()
    {
        // Ivory introduces the term "relation recipe" - the definition of a relation. Usually, they are defined using
        // SQL patterns.
        $recipe = SqlRelationRecipe::fromPattern('SELECT %bool, %, %num', true, 'str', 3.14);

        // The recipe may directly be used for querying the database...
        $tuple = $this->conn->querySingleTuple($recipe);
        $this->assertEquals([true, 'str', Decimal::fromNumber('3.14')], $tuple->toList());

        // ...or as a base for another recipe. Note the construction from "fragments" - parts of the SQL pattern put together.
        $valsRecipe = SqlRelationRecipe::fromFragments(
            'VALUES (%, %),', 4, Date::fromParts(2017, 2, 25),
            '       (%, %)', 7, null
        );
        $recipe = SqlRelationRecipe::fromPattern(
            'SELECT * FROM (%rel) AS t (id, creat)',
            $valsRecipe
        );
        $this->assertCount(2, $this->conn->query($recipe));

        // Since the recipe is not tied to a connection, it may be cached and retrieved later.
        $serialized = serialize($recipe);
        $this->assertCount(2, $this->conn->query(unserialize($serialized)));
    }

    public function testDataSource()
    {
        $this->markTestIncomplete();
    }
}

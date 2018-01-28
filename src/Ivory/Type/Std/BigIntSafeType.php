<?php
declare(strict_types=1);
namespace Ivory\Type\Std;

use Ivory\Type\BaseType;
use Ivory\Type\IDiscreteType;
use Ivory\Value\Alg\ComparisonUtils;

/**
 * Signed eight-byte integer.
 *
 * The PHP `int` representation is preferred. If, however, the value overflows `int` size, a string is returned
 * containing the decimal number.
 *
 * @see http://www.postgresql.org/docs/9.4/static/datatype-numeric.html#DATATYPE-INT
 */
class BigIntSafeType extends BaseType implements IDiscreteType
{
    public static function createForRange($min, $max, string $schemaName, string $typeName): IDiscreteType
    {
        if (bccomp($min, (string)PHP_INT_MIN) >= 0 && bccomp($max, (string)PHP_INT_MAX) <= 0) {
            return new IntegerType($schemaName, $typeName);
        } else {
            return new BigIntSafeType($schemaName, $typeName);
        }
    }

    public function parseValue(string $extRepr)
    {
        if ($extRepr >= PHP_INT_MIN && $extRepr <= PHP_INT_MAX) { // correctness: int does not overflow, but rather gets converted to a float
            return (int)$extRepr;
        } else {
            return $extRepr;
        }
    }

    public function serializeValue($val): string
    {
        if ($val === null) {
            return 'NULL';
        } elseif ($val >= PHP_INT_MIN && $val <= PHP_INT_MAX) {
            return (string)(int)$val;
        } else {
            if (preg_match('~^\s*-?[0-9]+\s*$~', $val)) {
                return (string)$val;
            } else {
                throw $this->invalidValueException($val);
            }
        }
    }

    public function compareValues($a, $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        return ComparisonUtils::compareBigIntegers($a, $b);
    }

    public function step(int $delta, $value)
    {
        if ($value === null) {
            return null;
        }
        if ($value > PHP_INT_MAX || $value < PHP_INT_MIN) {
            return bcadd($value, $delta, 0);
        } else {
            return (int)$value + $delta;
        }
    }
}

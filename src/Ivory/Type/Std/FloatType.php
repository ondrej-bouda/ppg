<?php
declare(strict_types=1);
namespace Ivory\Type\Std;

use Ivory\Type\BaseType;
use Ivory\Type\ITotallyOrderedType;
use Ivory\Utils\ValueUtils;

/**
 * Inexact, variable-precision numeric type.
 *
 * Represented as the PHP `float` type.
 *
 * @see http://www.postgresql.org/docs/9.4/static/datatype-numeric.html
 */
class FloatType extends BaseType implements ITotallyOrderedType
{
    public function parseValue(string $extRepr)
    {
        if (is_numeric($extRepr)) {
            return (float)$extRepr;
        } elseif (strcasecmp($extRepr, 'NaN') == 0) {
            return NAN;
        } elseif (strcasecmp($extRepr, 'Infinity') == 0) {
            return INF;
        } elseif (strcasecmp($extRepr, '-Infinity') == 0) {
            return -INF;
        } else {
            throw $this->invalidValueException($extRepr);
        }
    }

    public function serializeValue($val): string
    {
        if ($val === null) {
            return 'NULL';
        } elseif (is_finite($val)) {
            return (string)$val;
        } elseif (is_nan($val)) {
            return "'NaN'";
        } elseif ($val < 0) {
            return "'-Infinity'";
        } else {
            return "'Infinity'";
        }
    }

    public function compareValues($a, $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        return ValueUtils::compareFloats($a, $b);
    }
}

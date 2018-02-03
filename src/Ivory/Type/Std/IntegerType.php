<?php
declare(strict_types=1);
namespace Ivory\Type\Std;

use Ivory\Type\BaseType;
use Ivory\Type\ITotallyOrderedType;

/**
 * Signed integer.
 *
 * Represented as the PHP `int` type.
 *
 * @see http://www.postgresql.org/docs/9.4/static/datatype-numeric.html#DATATYPE-INT
 */
class IntegerType extends BaseType implements ITotallyOrderedType
{
    public function parseValue(string $extRepr)
    {
        return (int)$extRepr;
    }

    public function serializeValue($val): string
    {
        if ($val === null) {
            return 'NULL';
        } else {
            return (string)(int)$val;
        }
    }
}

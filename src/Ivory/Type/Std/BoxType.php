<?php
declare(strict_types=1);
namespace Ivory\Type\Std;

use Ivory\Value\Box;

/**
 * Rectangular box on a plane.
 *
 * Represented as a {@link \Ivory\Value\Box} object.
 *
 * @see http://www.postgresql.org/docs/9.4/static/datatype-geometric.html
 */
class BoxType extends CompoundGeometricType
{
    public function parseValue(string $extRepr)
    {
        $re = '~^ \s*
                (\()? \s*                           # optional opening parenthesis
                ((?(1)\()[^,]+,[^,]+(?(1)\))) \s*   # corner; parenthesized if the whole segment is parenthesized
                , \s*
                ((?(1)\().+(?(1)\))) \s*            # opposite corner; parenthesized if the whole segment is parenthesized
                (?(1)\)) \s*                        # pairing closing parenthesis
                $~x';

        if (preg_match($re, $extRepr, $m)) {
            try {
                $corner = $this->pointType->parseValue($m[2]);
                $oppositeCorner = $this->pointType->parseValue($m[3]);
                return Box::fromOppositeCorners($corner, $oppositeCorner);
            } catch (\InvalidArgumentException $e) {
                throw $this->invalidValueException($extRepr, $e);
            }
        } else {
            throw $this->invalidValueException($extRepr);
        }
    }

    public function serializeValue($val): string
    {
        if ($val === null) {
            return 'NULL';
        } elseif ($val instanceof Box) {
            return sprintf('box(%s,%s)',
                $this->pointType->serializeValue($val->getUpperRight()),
                $this->pointType->serializeValue($val->getLowerLeft())
            );
        } else {
            throw $this->invalidValueException($val);
        }
    }
}

<?php
declare(strict_types=1);
namespace Ivory\Value;

use Ivory\Exception\ImmutableException;
use Ivory\Exception\UnsupportedException;
use Ivory\Type\IDiscreteType;
use Ivory\Type\ITotallyOrderedType;
use Ivory\Utils\IEqualable;

/**
 * A range of values.
 *
 * The class resembles the range values manipulated by PostgreSQL. Just a brief summary:
 * - The range is defined above a type of values, called "subtype" (see {@link Range::getSubtype()}).
 * - The subtype must have a total order.
 * - A range may be empty, meaning it contains nothing. See {@link Range::isEmpty()} for distinguishing it.
 * - A non-empty range has the lower and the upper bounds, either of which may either be inclusive or exclusive. See
 *   {@link Range::getLower()} and {@link Range::getUpper()} for getting the boundaries. Also see
 *   {@link Range::isLowerInc()} and {@link Range::isUpperInc()} for finding out whichever boundary is inclusive.
 * - A range may be unbounded in either direction, covering all subtype values from the range towards minus infinity or
 *   towards infinity, respectively.
 * - A range might even cover just a single point. {@link Range::isSinglePoint()} may be used for checking out.
 *
 * In PostgreSQL, ranges on discrete subtypes may have a canonical function defined on them, the purpose of which is to
 * convert semantically equivalent ranges to syntactically equivalent ones (e.g., one such function might convert the
 * range `[1,3]` to `[1,4)`). Such a feature is *not* implemented on the PHP side. The ranges constructed in PHP are not
 * normalized in any way, they may only be converted manually using the {@link Range::toBounds()} method.
 *
 * A range is `IEqualable` to another range. Two ranges are equal only if they are above the same subtype and if the
 * effective range equals.
 *
 * Alternatively to the {@link Range::getLower()} and {@link Range::getUpper()} methods, `ArrayAccess` is implemented.
 * Indexes `0` and `1` may be used to get the lower and upper bound, respectively. Either of them returns `null` if the
 * range is empty or unbounded in the given direction.
 *
 * Note the range value is immutable, i.e., once constructed, its values cannot be changed. Thus, `ArrayAccess` write
 * operations ({@link \ArrayAccess::offsetSet()} and {@link \ArrayAccess::offsetUnset()}) throw an
 * {@link \Ivory\Exception\ImmutableException}.
 *
 * @see http://www.postgresql.org/docs/9.4/static/rangetypes.html
 */
class Range implements IEqualable, \ArrayAccess
{
    /** @var ITotallyOrderedType */
    private $subtype;
    /** @var bool */
    private $empty;
    /** @var mixed */
    private $lower;
    /** @var mixed */
    private $upper;
    /** @var bool */
    private $lowerInc;
    /** @var bool */
    private $upperInc;


    //region construction

    /**
     * Creates a new range with given lower and upper bounds.
     *
     * There are two ways of calling this factory method: whether the bounds are inclusive or exclusive may be
     * specified:
     * - either by passing a specification string as the `$boundsOrLowerInc` argument (e.g., `'[)'`),
     * - or by passing two boolean values as the `$boundsOrLowerInc` and `$upperInc` telling whichever bound is
     *   inclusive.
     *
     * When the constructed range is effectively empty, an empty range gets created, forgetting the given lower and
     * upper bound (just as PostgreSQL does). That happens in one of the following cases:
     * * the lower bound is greater than the upper bound, or
     * * both bounds are equal but any of them is exclusive, or
     * * the subtype is {@link IDiscreteType discrete}, the upper bound is just one step after the lower bound and both
     *   the bounds are exclusive.
     *
     * For creating an empty range explicitly, see {@link createEmpty()}.
     *
     * @param ITotallyOrderedType $subtype the range subtype
     * @param mixed $lower the range lower bound, or <tt>null</tt> if unbounded
     * @param mixed $upper the range upper bound, or <tt>null</tt> if unbounded
     * @param bool|string $boundsOrLowerInc
     *                          either the string of complete bounds specification, or a boolean telling whether the
     *                            lower bound is inclusive;
     *                          the complete specification is similar to PostgreSQL - it is a two-character string of
     *                            <tt>'['</tt> or <tt>'('</tt>, and <tt>']'</tt> or <tt>')'</tt> (brackets denoting an
     *                            inclusive bound, parentheses denoting an exclusive bound;
     *                          when the boolean is used, also the <tt>$upperInc</tt> argument is used;
     *                          either bound specification is irrelevant if the corresponding range edge is
     *                            <tt>null</tt> - the range is open by definition on that side, and thus will be created
     *                            as such
     * @param bool|null $upperInc whether the upper bound is inclusive;
     *                            only relevant if <tt>$boundsOrLowerInc</tt> is also boolean
     * @return Range
     */
    public static function createFromBounds(
        ITotallyOrderedType $subtype,
        $lower,
        $upper,
        $boundsOrLowerInc = '[)',
        ?bool $upperInc = null
    ): Range {
        list($loInc, $upInc) = self::processBoundSpec($boundsOrLowerInc, $upperInc);

        if ($lower !== null && $upper !== null) {
            $comp = $subtype->compareValues($lower, $upper);
            if ($comp > 0) {
                return self::createEmpty($subtype);
            } elseif ($comp == 0) {
                if (!$loInc || !$upInc) {
                    return self::createEmpty($subtype);
                }
            } elseif (!$loInc && !$upInc && $subtype instanceof IDiscreteType) {
                $up = $subtype->step(-1, $upper);
                $isEmpty = ($lower instanceof IEqualable ? $lower->equals($up) : ($lower == $up));
                if ($isEmpty) {
                    return self::createEmpty($subtype);
                }
            }
        } else {
            if ($lower === null) {
                $loInc = false;
            }
            if ($upper === null) {
                $upInc = false;
            }
        }

        return new Range($subtype, false, $lower, $upper, $loInc, $upInc);
    }

    /**
     * Creates a new empty range.
     *
     * @param ITotallyOrderedType $subtype the range subtype
     * @return Range
     */
    public static function createEmpty(ITotallyOrderedType $subtype): Range
    {
        return new Range($subtype, true, null, null, null, null);
    }

    private static function processBoundSpec($boundsOrLowerInc = '[)', ?bool $upperInc = null)
    {
        if (is_string($boundsOrLowerInc)) {
            if ($upperInc !== null) {
                trigger_error('$upperInc is irrelevant - string specification for $boundsOrLowerInc given', E_USER_NOTICE);
            }
            // OPT: measure whether preg_match('~^[[(][\])]$~', $boundsOrLowerInc) would not be faster
            if (strlen($boundsOrLowerInc) != 2 || // OPT: isset($boundsOrLowerInc[2] might be faster
                strpos('([', $boundsOrLowerInc[0]) === false ||
                strpos(')]', $boundsOrLowerInc[1]) === false
            ) {
                $msg = "Invalid bounds inclusive/exclusive specification string: $boundsOrLowerInc";
                throw new \InvalidArgumentException($msg);
            }

            $loInc = ($boundsOrLowerInc[0] == '[');
            $upInc = ($boundsOrLowerInc[1] == ']');
        } else {
            $loInc = (bool)$boundsOrLowerInc;
            $upInc = (bool)$upperInc;
        }

        return [$loInc, $upInc];
    }


    private function __construct(
        ITotallyOrderedType $subtype,
        bool $empty,
        $lower,
        $upper,
        ?bool $lowerInc,
        ?bool $upperInc
    ) {
        $this->subtype = $subtype;
        $this->empty = $empty;
        $this->lower = $lower;
        $this->upper = $upper;
        $this->lowerInc = $lowerInc;
        $this->upperInc = $upperInc;
    }

    //endregion

    //region getters

    final public function getSubtype(): ITotallyOrderedType
    {
        return $this->subtype;
    }

    final public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * @return mixed lower bound, or <tt>null</tt> if the range is lower-unbounded or empty
     */
    final public function getLower()
    {
        return $this->lower;
    }

    /**
     * @return mixed upper bound, or <tt>null</tt> if the range is upper-unbounded or empty
     */
    final public function getUpper()
    {
        return $this->upper;
    }

    /**
     * @return bool|null whether the range includes its lower bound, or <tt>null</tt> if the range is empty;
     *                   for lower-unbounded ranges, <tt>false</tt> is returned by definition
     */
    final public function isLowerInc(): ?bool
    {
        return $this->lowerInc;
    }

    /**
     * @return bool|null whether the range includes its upper bound, or <tt>null</tt> if the range is empty;
     *                   for upper-unbounded ranges, <tt>false</tt> is returned by definition
     */
    final public function isUpperInc(): ?bool
    {
        return $this->upperInc;
    }

    /**
     * @return string|null the bounds inclusive/exclusive specification, as accepted by {@link createFromBounds()}, or
     *                     <tt>null</tt> if the range is empty
     */
    final public function getBoundsSpec(): ?string
    {
        if ($this->empty) {
            return null;
        } else {
            return ($this->lowerInc ? '[' : '(') . ($this->upperInc ? ']' : ')');
        }
    }

    /**
     * @return bool whether the range is just a single point
     */
    final public function isSinglePoint(): bool
    {
        if ($this->empty || $this->lower === null || $this->upper === null) {
            return false;
        }

        $cmp = $this->subtype->compareValues($this->lower, $this->upper);
        if ($cmp == 0) {
            return ($this->lowerInc && $this->upperInc);
        }
        if ($this->lowerInc && $this->upperInc) { // optimization
            return false;
        }
        if ($this->subtype instanceof IDiscreteType) {
            $lo = ($this->lowerInc ? $this->lower : $this->subtype->step(1, $this->lower));
            $up = ($this->upperInc ? $this->upper : $this->subtype->step(-1, $this->upper));
            return ($this->subtype->compareValues($lo, $up) == 0);
        } else {
            return false;
        }
    }

    /**
     * Returns the range bounds according to the requested bound specification.
     *
     * **Only defined on ranges of {@link IDiscreteType discrete} subtypes.**
     *
     * E.g., on an integer range `(null,3)`, if bounds `[]` are requested, a pair of `null` and `2` is returned.
     *
     * @param bool|string $boundsOrLowerInc either the string of complete bounds specification, or a boolean telling
     *                                        whether the lower bound is inclusive;
     *                                      the complete specification is similar to PostgreSQL - it is a two-character
     *                                        string of <tt>'['</tt> or <tt>'('</tt>, and <tt>']'</tt> or <tt>')'</tt>
     *                                        (brackets denoting an inclusive bound, parentheses denoting an exclusive
     *                                        bound;
     *                                      when the boolean is used, also the <tt>$upperInc</tt> argument is used
     * @param bool $upperInc whether the upper bound is inclusive;
     *                       only relevant if <tt>$boundsOrLowerInc</tt> is also boolean
     * @return array|null pair of the lower and upper bound, or <tt>null</tt> if the range is empty
     * @throws UnsupportedException if the range subtype is not an {@link IDiscreteType}
     */
    public function toBounds($boundsOrLowerInc, ?bool $upperInc = null): ?array
    {
        if (!$this->subtype instanceof IDiscreteType) {
            throw new UnsupportedException('Range subtype is not ' . IDiscreteType::class . ', cannot convert bounds');
        }

        if ($this->empty) {
            return null;
        }

        list($loInc, $upInc) = self::processBoundSpec($boundsOrLowerInc, $upperInc);

        if ($this->lower === null) {
            $lo = null;
        } else {
            $lo = $this->lower;
            $step = (int)$loInc - (int)$this->lowerInc;
            if ($step) {
                $lo = $this->subtype->step($step, $lo);
            }
        }

        if ($this->upper === null) {
            $up = null;
        } else {
            $up = $this->upper;
            $step = (int)$this->upperInc - (int)$upInc;
            if ($step) {
                $up = $this->subtype->step($step, $up);
            }
        }

        return [$lo, $up];
    }

    final public function __toString()
    {
        if ($this->empty) {
            return 'empty';
        }

        $bounds = $this->getBoundsSpec();
        return sprintf('%s%s,%s%s',
            $bounds[0],
            ($this->lower === null ? '-infinity' : $this->lower),
            ($this->upper === null ? 'infinity' : $this->upper),
            $bounds[1]
        );
    }

    //endregion

    //region range operations

    /**
     * @param mixed $element a value of the range subtype
     * @return bool|null whether this range contains the given element;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function containsElement($element): ?bool
    {
        if ($element === null) {
            return null;
        }
        if ($this->empty) {
            return false;
        }

        if ($this->lower !== null) {
            $cmp = $this->subtype->compareValues($element, $this->lower);
            if ($cmp < 0 || ($cmp == 0 && !$this->lowerInc)) {
                return false;
            }
        }

        if ($this->upper !== null) {
            $cmp = $this->subtype->compareValues($element, $this->upper);
            if ($cmp > 0 || ($cmp == 0 && !$this->upperInc)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $element value of the range subtype
     * @return bool|null <tt>true</tt> iff this range is left of the given element - value of the range subtype;
     *                   <tt>false</tt> otherwise, especially if this range is empty;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function leftOfElement($element): ?bool
    {
        if ($element === null) {
            return null;
        }
        if ($this->empty) {
            return false;
        }

        if ($this->upper === null) {
            return false;
        }
        $cmp = $this->subtype->compareValues($element, $this->upper);
        return ($cmp > 0 || ($cmp == 0 && !$this->upperInc));
    }

    /**
     * @param mixed $element value of the range subtype
     * @return bool|null <tt>true</tt> iff this range is right of the given element - value of the range subtype;
     *                   <tt>false</tt> otherwise, especially if this range is empty;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function rightOfElement($element): ?bool
    {
        if ($element === null) {
            return null;
        }
        if ($this->empty) {
            return false;
        }

        if ($this->lower === null) {
            return false;
        }
        $cmp = $this->subtype->compareValues($element, $this->lower);
        return ($cmp < 0 || ($cmp == 0 && !$this->lowerInc));
    }

    /**
     * @param Range $other a range of the same subtype as this range
     * @return bool|null whether this range entirely contains the other range;
     *                   an empty range is considered to be contained in any range, even an empty one;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function containsRange(?Range $other): ?bool
    {
        if ($other === null) {
            return null;
        }
        if ($other->empty) {
            return true;
        }
        if ($this->empty) {
            return false;
        }

        if ($this->lower !== null) {
            if ($other->lower === null) {
                return false;
            } else {
                $cmp = $this->subtype->compareValues($this->lower, $other->lower);
                if ($cmp > 0 || ($cmp == 0 && !$this->lowerInc && $other->lowerInc)) {
                    return false;
                }
            }
        }

        if ($this->upper !== null) {
            if ($other->upper === null) {
                return false;
            } else {
                $cmp = $this->subtype->compareValues($this->upper, $other->upper);
                if ($cmp < 0 || ($cmp == 0 && !$this->upperInc && $other->upperInc)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param Range $other a range of the same subtype as this range
     * @return bool|null whether this range is entirely contained in the other range;
     *                   an empty range is considered to be contained in any range, even an empty one;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function containedInRange(?Range $other): ?bool
    {
        if ($other === null) {
            return null;
        }
        return $other->containsRange($this);
    }

    /**
     * @param Range $other a range of the same subtype as this range
     * @return bool|null whether this and the other range overlap, i.e., have a non-empty intersection;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function overlaps(?Range $other): ?bool
    {
        if ($other === null) {
            return null;
        }
        if ($this->empty || $other->empty) {
            return false;
        }

        if ($this->lower !== null && $other->upper !== null) {
            $cmp = $this->subtype->compareValues($this->lower, $other->upper);
            if ($cmp > 0 || ($cmp == 0 && (!$this->lowerInc || !$other->upperInc))) {
                return false;
            }
        }
        if ($other->lower !== null && $this->upper !== null) {
            $cmp = $this->subtype->compareValues($other->lower, $this->upper);
            if ($cmp > 0 || ($cmp == 0 && (!$other->lowerInc || !$this->upperInc))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Computes the intersection of this range with another range.
     *
     * @param Range $other a range of the same subtype as this range
     * @return Range|null intersection of this and the other range
     *                    <tt>null</tt> on <tt>null</tt> input
     */
    public function intersect(?Range $other): ?Range
    {
        if ($other === null) {
            return null;
        }
        if ($this->empty) {
            return $this;
        }
        if ($other->empty) {
            return $other;
        }

        if ($this->lower === null) {
            $lo = $other->lower;
            $loInc = $other->lowerInc;
        } elseif ($other->lower === null) {
            $lo = $this->lower;
            $loInc = $this->lowerInc;
        } else {
            $cmp = $this->subtype->compareValues($this->lower, $other->lower);
            if ($cmp < 0) {
                $lo = $other->lower;
                $loInc = $other->lowerInc;
            } elseif ($cmp > 0) {
                $lo = $this->lower;
                $loInc = $this->lowerInc;
            } else {
                $lo = $this->lower;
                $loInc = ($this->lowerInc && $other->lowerInc);
            }
        }

        if ($this->upper === null) {
            $up = $other->upper;
            $upInc = $other->upperInc;
        } elseif ($other->upper === null) {
            $up = $this->upper;
            $upInc = $this->upperInc;
        } else {
            $cmp = $this->subtype->compareValues($this->upper, $other->upper);
            if ($cmp < 0) {
                $up = $this->upper;
                $upInc = $this->upperInc;
            } elseif ($cmp > 0) {
                $up = $other->upper;
                $upInc = $other->upperInc;
            } else {
                $up = $this->upper;
                $upInc = ($this->upperInc && $other->upperInc);
            }
        }

        return self::createFromBounds($this->subtype, $lo, $up, $loInc, $upInc);
    }

    /**
     * @return bool whether the range is finite, i.e., neither starts nor ends in the infinity;
     *              note that an empty range is considered as finite
     */
    final public function isFinite(): bool
    {
        return ($this->empty || ($this->lower !== null && $this->upper !== null));
    }

    /**
     * @param Range|null $other a range of the same subtype as this range
     * @return bool|null <tt>true</tt> iff this range is strictly left of the other range, i.e., it ends before the
     *                     other starts;
     *                   <tt>false</tt> otherwise, especially if either range is empty;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function strictlyLeftOf(?Range $other): ?bool
    {
        if ($other === null) {
            return null;
        }
        if ($this->empty || $other->empty) {
            return false;
        }

        if ($this->upper === null || $other->lower === null) {
            return false;
        }
        $cmp = $this->subtype->compareValues($this->upper, $other->lower);
        return ($cmp < 0 || ($cmp == 0 && (!$this->upperInc || !$other->lowerInc)));
    }

    /**
     * @param Range|null $other a range of the same subtype as this range
     * @return bool|null <tt>true</tt> iff this range is strictly left of the other range, i.e., it ends before the
     *                     other starts;
     *                   <tt>false</tt> otherwise, especially if either range is empty;;
     *                   <tt>null</tt> on <tt>null</tt> input
     */
    public function strictlyRightOf(?Range $other): ?bool
    {
        if ($other === null) {
            return null;
        }
        if ($this->empty || $other->empty) {
            return false;
        }

        if ($other->upper === null || $this->lower === null) {
            return false;
        }
        $cmp = $this->subtype->compareValues($other->upper, $this->lower);
        return ($cmp < 0 || ($cmp == 0 && (!$other->upperInc || !$this->lowerInc)));
    }

    //endregion

    //region IEqualable

    public function equals($other): bool
    {
        if (!$other instanceof Range) {
            return false;
        }
        if ($this->subtype !== $other->subtype) {
            return false;
        }

        if ($this->empty) {
            return $other->empty;
        }
        if ($other->empty) {
            return false;
        }

        $objLower = $other->lower;
        $objUpper = $other->upper;

        if ($this->lowerInc != $other->lowerInc) {
            if ($other->subtype instanceof IDiscreteType) {
                $objLower = $other->subtype->step(($other->lowerInc ? -1 : 1), $objLower);
            } else {
                return false;
            }
        }
        if ($this->upperInc != $other->upperInc) {
            if ($other->subtype instanceof IDiscreteType) {
                $objUpper = $other->subtype->step(($other->upperInc ? 1 : -1), $objUpper);
            } else {
                return false;
            }
        }

        if ($this->lower === null) {
            if ($objLower !== null) {
                return false;
            }
        } elseif ($this->lower instanceof IEqualable) {
            if (!$this->lower->equals($objLower)) {
                return false;
            }
        } else {
            if ($this->lower != $objLower) {
                return false;
            }
        }

        if ($this->upper === null) {
            if ($objUpper !== null) {
                return false;
            }
        } elseif ($this->upper instanceof IEqualable) {
            if (!$this->upper->equals($objUpper)) {
                return false;
            }
        } else {
            if ($this->upper != $objUpper) {
                return false;
            }
        }

        return true;
    }

    //endregion

    //region ArrayAccess

    public function offsetExists($offset)
    {
        return (!$this->empty && ($offset == 0 || $offset == 1));
    }

    public function offsetGet($offset)
    {
        if ($offset == 0) {
            return ($this->empty ? null : $this->lower);
        } elseif ($offset == 1) {
            return ($this->empty ? null : $this->upper);
        } else {
            trigger_error("Undefined range offset: $offset", E_USER_WARNING);
            return null;
        }
    }

    public function offsetSet($offset, $value)
    {
        throw new ImmutableException();
    }

    public function offsetUnset($offset)
    {
        throw new ImmutableException();
    }

    //endregion
}

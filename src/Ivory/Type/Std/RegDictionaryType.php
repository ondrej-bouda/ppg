<?php
declare(strict_types=1);
namespace Ivory\Type\Std;

use Ivory\Value\PgTextSearchDictionaryRef;

/**
 * PostgreSQL object identifier type identifying a text search dictionary by its name.
 *
 * Represented as a {@link \Ivory\Value\PgTextSearchDictionaryRef} object.
 *
 * @see https://www.postgresql.org/docs/11/datatype-oid.html
 */
class RegDictionaryType extends PgObjectRefTypeBase
{
    public function parseValue(string $extRepr)
    {
        return PgTextSearchDictionaryRef::fromQualifiedName(...$this->parseObjectRef($extRepr));
    }

    public function serializeValue($val, bool $strictType = true): string
    {
        if ($val === null) {
            return $this->typeCastExpr($strictType, 'NULL');
        } elseif ($val instanceof PgTextSearchDictionaryRef) {
            return $this->serializeObjectRef($val, $strictType);
        } else {
            throw $this->invalidValueException($val);
        }
    }
}

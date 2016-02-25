<?php
namespace Ivory\Type\Std;

use Ivory\Value\XmlContent;
use Ivory\Value\XmlDocument;

/**
 * XML documents and content.
 *
 * Represented as an {@link \Ivory\Value\XmlContent} or {@link \Ivory\Value\XmlDocument} object.
 *
 * @see http://www.postgresql.org/docs/9.4/static/datatype-xml.html
 * @see http://www.postgresql.org/docs/9.4/static/functions-xml.html
 * @todo implement ITotallyOrderedType for this type to be applicable as a range subtype
 */
class XmlType extends \Ivory\Type\BaseType
{
	public function parseValue($str)
	{
		if ($str === null) {
			return null;
		}
		else {
			return XmlContent::fromValue($str);
		}
	}

	public function serializeValue($val)
	{
		if ($val === null) {
			return 'NULL';
		}

		try {
			$xml = XmlContent::fromValue($val);
			return sprintf("XMLPARSE(%s '%s')",
				($xml instanceof XmlDocument ? 'DOCUMENT' : 'CONTENT'),
				strtr($xml->toString(), ["'" => "''"])
			);
		}
		catch (\InvalidArgumentException $e) {
			$this->throwInvalidValue($val);
		}
	}
}

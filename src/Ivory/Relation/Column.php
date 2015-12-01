<?php
namespace Ivory\Relation;

use Ivory\Exception\NotImplementedException;
use Ivory\Result\QueryResult;
use Ivory\Type\IType;

class Column implements \Iterator, IColumn
{
    private $queryResult;
    private $colOffset;
    private $name;
    private $type;
    private $pos = 0;


    /**
     * @param QueryResult $queryResult query result the column comes from
     * @param int $colOffset offset of the column within the query result
     * @param string|null $name name of the column, or <tt>null</tt> if not named
     * @param IType $type type of the column values
     */
    public function __construct(QueryResult $queryResult, $colOffset, $name, IType $type)
    {
        $this->queryResult = $queryResult;
        $this->colOffset = $colOffset;
        $this->name = $name;
        $this->type = $type;
    }


    public function getName()
    {
        return $this->name;
    }

    public function getType()
    {
        return $this->type;
    }

    public function filter($decider)
    {
        throw new NotImplementedException();
    }

    public function uniq($hasher = null, $comparator = null)
    {
        throw new NotImplementedException();
    }

    public function toArray()
    {
        $result = [];
        foreach ($this as $value) {
            $result[] = $value;
        }
        return $result;
    }

    public function value($valueOffset = 0)
    {
        $this->queryResult->value($this->colOffset, $valueOffset);
    }

    //region ICachingDataProcessor

    public function populate()
    {
        throw new NotImplementedException();
    }

    public function flush()
    {
        throw new NotImplementedException();
    }

    //endregion

    //region Countable

    public function count()
    {
        return $this->queryResult->count();
    }

    //endregion

    //region Iterator

    public function current()
    {
        return $this->value($this->pos);
    }

    public function next()
    {
        $this->pos++;
    }

    public function key()
    {
        return $this->pos;
    }

    public function valid()
    {
        return $this->pos < $this->count();
    }

    public function rewind()
    {
        $this->pos = 0;
    }

    //endregion
}

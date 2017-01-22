<?php
namespace Ivory\Connection;

use Ivory\Ivory;
use Ivory\Type\ITotallyOrderedType;
use Ivory\Type\ITypeDictionaryUndefinedHandler;
use Ivory\Type\ITypeProvider;
use Ivory\Type\TypeDictionary;
use Ivory\Type\IntrospectingTypeDictionaryCompiler;
use Ivory\Type\TypeRegister;

class TypeControl implements ITypeControl, ITypeProvider
{
    private $connection;
    private $connCtl;
    private $typeRegister;
    /** @var TypeDictionary|null */
    private $typeDictionary = null;

    public function __construct(IConnection $connection, ConnectionControl $connCtl)
    {
        $this->connection = $connection;
        $this->connCtl = $connCtl;
        $this->typeRegister = new TypeRegister();
    }


    //region ITypeControl

    public function getTypeRegister()
    {
        return $this->typeRegister;
    }

    public function getTypeDictionary()
    {
        if ($this->typeDictionary === null) {
            $this->typeDictionary = new TypeDictionary(); // TODO: instead of empty dictionary, use a cached dictionary; for getting a cached dictionary, use TypeDictionary::export()
            $this->initTypeDictionary();
        }
        return $this->typeDictionary;
    }

    private function initTypeDictionary()
    {
        $localReg = $this->getTypeRegister();
        $globalReg = Ivory::getTypeRegister();
        foreach ([$globalReg, $localReg] as $reg) {
            /** @var TypeRegister $reg */
            foreach ($reg->getSqlPatternTypes() as $name => $type) {
                $this->typeDictionary->defineCustomType($name, $type);
            }
            foreach ($reg->getSqlPatternTypeAbbreviations() as $abbr => $name) {
                $this->typeDictionary->defineTypeAlias($abbr, $name);
            }
        }

        $handler = function ($oid, $name, $value) {
            $compiler = new IntrospectingTypeDictionaryCompiler($this->connection, $this->connCtl->requireConnection());
            $dict = $compiler->compileTypeDictionary($this);
            if ($oid !== null) {
                $type = $dict->requireTypeByOid($oid);
            }
            elseif ($name !== null) {
                $type = $dict->requireTypeByName($name);
            }
            elseif ($value !== null) {
                $type = $dict->requireTypeByValue($value);
            }
            else {
                return null;
            }

            // now the requested type was really found - replace the current dictionary with the new one, which recognized the type
            $this->typeDictionary = $dict;
            $this->initTypeDictionary();

            return $type;
        };

        $this->typeDictionary->setUndefinedTypeHandler(new class($handler) implements ITypeDictionaryUndefinedHandler {
            private $handler;

            public function __construct(\Closure $handler)
            {
                $this->handler = $handler;
            }

            public function handleUndefinedType($oid, $name, $value)
            {
                return call_user_func($this->handler, $oid, $name, $value);
            }
        });
    }

    public function flushTypeDictionary()
    {
        $this->typeDictionary = null;
    }

    //endregion

    //region ITypeProvider

    public function provideType($schemaName, $typeName)
    {
        $localReg = $this->getTypeRegister();
        $globalReg = Ivory::getTypeRegister();
        foreach ([$localReg, $globalReg] as $reg) {
            /** @var TypeRegister $reg */
            $type = $reg->getType($schemaName, $typeName);
            if ($type !== null) {
                return $type;
            }
            $type = $reg->loadType($schemaName, $typeName, $this->connection);
            if ($type !== null) {
                return $type;
            }
        }
        return null;
    }

    public function provideRangeCanonicalFunc($schemaName, $funcName, ITotallyOrderedType $subtype)
    {
        $localReg = $this->getTypeRegister();
        $globalReg = Ivory::getTypeRegister();
        foreach ([$localReg, $globalReg] as $reg) {
            /** @var TypeRegister $reg */
            $func = $reg->getRangeCanonicalFunc($schemaName, $funcName, $subtype);
            if ($func !== null) {
                return $func;
            }
            $func = $reg->provideRangeCanonicalFunc($schemaName, $funcName, $subtype);
            if ($func !== null) {
                return $func;
            }
        }
        return null;
    }

    //endregion
}

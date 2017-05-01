<?php
namespace Ivory;

use Ivory\Cache\ICacheControl;
use Ivory\Connection\ConnectionParameters;
use Ivory\Connection\IConnection;
use Ivory\Exception\StatementExceptionFactory;
use Ivory\Lang\SqlPattern\ISqlPatternParser;
use Ivory\Type\TypeRegister;

/**
 * Specification for factory of objects used in the core of Ivory.
 */
interface ICoreFactory
{
    function createGlobalTypeRegister(): TypeRegister;

    function createConnection(string $connName, ConnectionParameters $params): IConnection;

    /**
     * Create a cache control, either for a given connection or for global usage.
     *
     * @param IConnection|null $connection
     * @return ICacheControl
     */
    function createCacheControl(IConnection $connection = null): ICacheControl;

    function createSqlPatternParser(ICacheControl $cacheControl = null): ISqlPatternParser;

    function createStatementExceptionFactory(): StatementExceptionFactory;
}

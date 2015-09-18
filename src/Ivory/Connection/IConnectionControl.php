<?php
namespace Ivory\Connection;

use Ivory\Exception\ConnectionException;

interface IConnectionControl
{
    /**
     * @return ConnectionParameters parameters for establishing the connection
     */
    function getParameters();

    /**
     * Finds out whether the connection is established in the moment.
     *
     * @return bool|null <tt>true</tt> if the connection is established,
     *                   <tt>null</tt> if no connection was requested to be established (yet),
     *                   <tt>false</tt> if the connection was tried to be established, but it is broken or still in
     *                     process of (asynchronous) connecting (use {@link isConnectedWait()} instead to distinguish
     *                     the latter)
     */
    function isConnected();

    /**
     * Finds out whether the connection is established. Waits for asynchronous connecting process to finish before
     * figuring out.
     *
     * @return bool|null <tt>true</tt> if the connection is established,
     *                   <tt>null</tt> if no connection was requested to be established (yet),
     *                   <tt>false</tt> if the connection is broken
     */
    function isConnectedWait();

    /**
     * Starts establishing a connection with the database according to the current connection parameters, if it has not
     * been established yet for this connection object.
     *
     * An asynchronous connection is used - the method merely starts connecting to the database and returns immediately.
     * Further operations on this connection, which really need the database, block until the connection is actually
     * established.
     *
     * If the connection has already been established, or started to being established, nothing is done and
     * <tt>false</tt> is returned.
     *
     * For a synchronous variant, see {@link connectWait()}.
     *
     * The connection is not shared with any other <tt>IConnection</tt> objects. A new connection is always established.
     *
     * @return bool <tt>true</tt> if the connection has actually started to being established,
     *              <tt>false</tt> if the connection has already been open or started opening and thus this was a no-op
     * @throws ConnectionException on error connecting to the database
     */
    function connect();

    /**
     * Establishes a connection with the database and waits for the connection to be established.
     *
     * The operation is almost the same as {@link connect()}, except this method does not return until the connection is
     * actually established. The current connection parameters are used for the connection.
     *
     * If the connection has already been established, nothing is done and <tt>false</tt> is returned.
     *
     * If the connection has been started asynchronously using {@link connect()} before, this method merely waits until
     * the connection is ready, and returns <tt>false</tt> then.
     *
     * The connection is not shared with any other <tt>IConnection</tt> objects. A new connection is always established.
     *
     * @return bool <tt>true</tt> if a new connection has just been opened,
     *              <tt>false</tt> if the connection has already been open and thus this was a no-op
     * @throws ConnectionException on error connecting to the database
     */
    function connectWait();

    /**
     * Closes the connection (if any). The current transaction (if any) is rolled back.
     *
     * After disconnecting, it is possible to connect again using {@link connect()} or {@link connectWait()}.
     *
     * @return bool <tt>true</tt> if the connection has actually been closed,
     *              <tt>false</tt> if no connection was established and thus this was a no-op
     * @throws ConnectionException on error closing the connection
     */
    function disconnect();
}

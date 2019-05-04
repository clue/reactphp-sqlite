<?php

namespace Clue\React\SQLite;

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;

/**
 * The `DatabaseInterface` represents a connection that is responsible for
 * communicating with your SQLite database wrapper, managing the connection state
 * and sending your database queries.
 *
 * Besides defining a few methods, this interface also implements the
 * `EventEmitterInterface` which allows you to react to certain events:
 *
 * error event:
 *     The `error` event will be emitted once a fatal error occurs, such as
 *     when the connection is lost or is invalid.
 *     The event receives a single `Exception` argument for the error instance.
 *
 *     ```php
 *     $connection->on('error', function (Exception $e) {
 *         echo 'Error: ' . $e->getMessage() . PHP_EOL;
 *     });
 *     ```
 *
 *     This event will only be triggered for fatal errors and will be followed
 *     by closing the connection. It is not to be confused with "soft" errors
 *     caused by invalid SQL queries.
 *
 * close event:
 *     The `close` event will be emitted once the connection closes (terminates).
 *
 *     ```php
 *     $connecion->on('close', function () {
 *         echo 'Connection closed' . PHP_EOL;
 *     });
 *     ```
 *
 *     See also the [`close()`](#close) method.
 */
interface DatabaseInterface extends EventEmitterInterface
{
    /**
     * Executes an async query.
     *
     * This method returns a promise that will resolve with a `Result` on
     * success or will reject with an `Exception` on error. The SQLite wrapper
     * is inherently sequential, so that all queries will be performed in order
     * and outstanding queries will be put into a queue to be executed once the
     * previous queries are completed.
     *
     * ```php
     * $db->exec('CREATE TABLE test ...');
     * $db->exec('INSERT INTO test (id) VALUES (1)');
     * ```
     *
     * This method is specifically designed for queries that do not return a
     * result set (such as a `UPDATE` or `INSERT` statement). Queries that do
     * return a result set (such as from a `SELECT` or `EXPLAIN` statement) will
     * not allow access to this data, so you're recommended to use the `query()`
     * method instead.
     *
     * ```php
     * $db->exec($query)->then(function (Result $result) {
     *     // this is an OK message in response to an UPDATE etc.
     *     if ($result->insertId !== 0) {
     *         var_dump('last insert ID', $result->insertId);
     *     }
     *     echo 'Query OK, ' . $result->changed . ' row(s) changed' . PHP_EOL;
     * }, function (Exception $error) {
     *     // the query was not executed successfully
     *     echo 'Error: ' . $error->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * Unlike the `query()` method, this method does not support passing an
     * array of placeholder parameters that will be bound to the query. If you
     * want to pass user-supplied data, you're recommended to use the `query()`
     * method instead.
     *
     * @param string $sql SQL statement
     * @return PromiseInterface<Result> Resolves with Result instance or rejects with Exception
     */
    public function exec($sql);

    /**
     * Performs an async query.
     *
     * This method returns a promise that will resolve with a `Result` on
     * success or will reject with an `Exception` on error. The SQLite wrapper
     * is inherently sequential, so that all queries will be performed in order
     * and outstanding queries will be put into a queue to be executed once the
     * previous queries are completed.
     *
     * ```php
     * $db->query('CREATE TABLE test ...');
     * $db->query('INSERT INTO test (id) VALUES (1)');
     * ```
     *
     * If this SQL statement returns a result set (such as from a `SELECT`
     * statement), this method will buffer everything in memory until the result
     * set is completed and will then resolve the resulting promise.
     *
     * ```php
     * $db->query($query)->then(function (Result $result) {
     *     if (isset($result->rows)) {
     *         // this is a response to a SELECT etc. with some rows (0+)
     *         print_r($result->columns);
     *         print_r($result->rows);
     *         echo count($result->rows) . ' row(s) in set' . PHP_EOL;
     *     } else {
     *         // this is an OK message in response to an UPDATE etc.
     *         if ($result->insertId !== 0) {
     *             var_dump('last insert ID', $result->insertId);
     *         }
     *         echo 'Query OK, ' . $result->changed . ' row(s) changed' . PHP_EOL;
     *     }
     * }, function (Exception $error) {
     *     // the query was not executed successfully
     *     echo 'Error: ' . $error->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * You can optionally pass an array of `$params` that will be bound to the
     * query like this:
     *
     * ```php
     * $db->query('SELECT * FROM user WHERE id > ?', [$id]);
     * ```
     *
     * Likewise, you can also use named placeholders that will be bound to the
     * query like this:
     *
     * ```php
     * $db->query('SELECT * FROM user WHERE id > :id', ['id' => $id]);
     * ```
     *
     * All placeholder values will automatically be mapped to the native SQLite
     * datatypes and all result values will automatically be mapped to the
     * native PHP datatypes. This conversion supports `int`, `float`, `string`
     * and `null`. Any `string` that is valid UTF-8 without any control
     * characters will be mapped to `TEXT`, binary strings will be mapped to
     * `BLOB`. Both `TEXT` and `BLOB` will always be mapped to `string` . SQLite
     * does not have a native boolean type, so `true` and `false` will be mapped
     * to integer values `1` and `0` respectively.
     *
     * @param string $sql    SQL statement
     * @param array  $params Parameters which should be bound to query
     * @return PromiseInterface<Result> Resolves with Result instance or rejects with Exception
     */
    public function query($sql, array $params = array());

    /**
     * Quits (soft-close) the connection.
     *
     * This method returns a promise that will resolve (with a void value) on
     * success or will reject with an `Exception` on error. The SQLite wrapper
     * is inherently sequential, so that all commands will be performed in order
     * and outstanding commands will be put into a queue to be executed once the
     * previous commands are completed.
     *
     * ```php
     * $db->query('CREATE TABLE test ...');
     * $db->quit();
     * ```
     *
     * @return PromiseInterface<void> Resolves (with void) or rejects with Exception
     */
    public function quit();

    /**
     * Force-close the connection.
     *
     * Unlike the `quit()` method, this method will immediately force-close the
     * connection and reject all oustanding commands.
     *
     * ```php
     * $db->close();
     * ```
     *
     * Forcefully closing the connection should generally only be used as a last
     * resort. See also [`quit()`](#quit) as a safe alternative.
     *
     * @return void
     */
    public function close();
}

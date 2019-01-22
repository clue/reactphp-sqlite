<?php

namespace Clue\React\SQLite;

use Clue\React\NDJson\Decoder;
use Evenement\EventEmitter;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * The `Database` class represents a connection that is responsible for
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
class Database extends EventEmitter
{
    /**
     * Opens a new database connection for the given SQLite database file.
     *
     * This method returns a promise that will resolve with a `Database` on
     * success or will reject with an `Exception` on error. The SQLite extension
     * is inherently blocking, so this method will spawn an SQLite worker process
     * to run all SQLite commands and queries in a separate process without
     * blocking the main process.
     *
     * ```php
     * Database::open($loop, 'users.db')->then(function (Database $db) {
     *     // database ready
     *     // $db->query('INSERT INTO users (name) VALUES ("test")');
     *     // $db->quit();
     * }, function (Exception $e) {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * The optional `$flags` parameter is used to determine how to open the
     * SQLite database. By default, open uses `SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE`.
     *
     * ```php
     * Database::open($loop, 'users.db', SQLITE3_OPEN_READONLY)->then(function (Database $db) {
     *     // database ready (read-only)
     *     // $db->quit();
     * }, function (Exception $e) {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * @param LoopInterface $loop
     * @param string $filename
     * @param ?int   $flags
     * @return PromiseInterface<Database> Resolves with Database instance or rejects with Exception
     */
    public static function open(LoopInterface $loop, $filename, $flags = null)
    {
        $process = new Process('exec ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(__DIR__ . '/../res/sqlite-worker.php'));
        $process->start($loop);

        $db = new Database($process);
        $args = array($filename);
        if ($flags !== null) {
            $args[] = $flags;
        }

        return $db->send('open', $args)->then(function () use ($db) {
            return $db;
        }, function ($e) use ($db) {
            $db->close();
            throw $e;
        });
    }

    private $process;
    private $pending = array();
    private $id = 0;
    private $closed = false;

    private function __construct(Process $process)
    {
        $this->process = $process;

        $in = new Decoder($process->stdout, true, 512, 0, 16 * 1024 * 1024);
        $in->on('data', function ($data) use ($in) {
            if (!isset($data['id']) || !isset($this->pending[$data['id']])) {
                $this->emit('error', array(new \RuntimeException('Invalid message received')));
                $in->close();
                return;
            }

            /* @var Deferred $deferred */
            $deferred = $this->pending[$data['id']];
            unset($this->pending[$data['id']]);

            if (isset($data['error'])) {
                $deferred->reject(new \RuntimeException(
                    isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error',
                    isset($data['error']['code']) ? $data['error']['code'] : 0
                ));
            } else {
                $deferred->resolve($data['result']);
            }
        });
        $in->on('error', function (\Exception $e) {
            $this->emit('error', array($e));
            $this->close();
        });
        $in->on('close', function () {
            $this->close();
        });
    }

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
    public function exec($sql)
    {
        return $this->send('exec', array($sql))->then(function ($data) {
            $result = new Result();
            $result->changed = $data['changed'];
            $result->insertId = $data['insertId'];

            return $result;
        });
    }

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
     * @param string $sql    SQL statement
     * @param array  $params Parameters which should be bound to query
     * @return PromiseInterface<Result> Resolves with Result instance or rejects with Exception
     */
    public function query($sql, array $params = array())
    {
        return $this->send('query', array($sql, $params))->then(function ($data) {
            $result = new Result();
            $result->changed = $data['changed'];
            $result->insertId = $data['insertId'];
            $result->columns = $data['columns'];
            $result->rows = $data['rows'];

            return $result;
        });
    }

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
    public function quit()
    {
        $promise = $this->send('close', array());

        $this->process->stdin->end();

        return $promise;
    }

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
    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        foreach ($this->process->pipes as $pipe) {
            $pipe->close();
        }
        $this->process->terminate();

        foreach ($this->pending as $one) {
            $one->reject(new \RuntimeException('Database closed'));
        }
        $this->pending = array();

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function send($method, array $params)
    {
        if (!$this->process->stdin->isWritable()) {
            return \React\Promise\reject(new \RuntimeException('Database closed'));
        }

        $id = ++$this->id;
        $this->process->stdin->write(\json_encode(array(
            'id' => $id,
            'method' => $method,
            'params' => $params
        ), \JSON_UNESCAPED_SLASHES) . "\n");

        $deferred = new Deferred();
        $this->pending[$id] = $deferred;

        return $deferred->promise();
    }
}

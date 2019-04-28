<?php

namespace Clue\React\SQLite;

use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use Clue\React\SQLite\Io\ProcessIoDatabase;

class Factory
{
    private $loop;

    /**
     * The `Factory` is responsible for opening your [`DatabaseInterface`](#databaseinterface) instance.
     * It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).
     *
     * ```php
     * $loop = \React\EventLoop\Factory::create();
     * $factory = new Factory($loop);
     * ```
     *
     * @param LoopInterface $loop
     */
    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /**
     * Opens a new database connection for the given SQLite database file.
     *
     * This method returns a promise that will resolve with a `DatabaseInterface` on
     * success or will reject with an `Exception` on error. The SQLite extension
     * is inherently blocking, so this method will spawn an SQLite worker process
     * to run all SQLite commands and queries in a separate process without
     * blocking the main process.
     *
     * ```php
     * $factory->open('users.db')->then(function (DatabaseInterface $db) {
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
     * $factory->open('users.db', SQLITE3_OPEN_READONLY)->then(function (DatabaseInterface $db) {
     *     // database ready (read-only)
     *     // $db->quit();
     * }, function (Exception $e) {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * @param string $filename
     * @param ?int   $flags
     * @return PromiseInterface<DatabaseInterface> Resolves with DatabaseInterface instance or rejects with Exception
     */
    public function open($filename, $flags = null)
    {
        $command = 'exec ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(__DIR__ . '/../res/sqlite-worker.php');

        // Try to get list of all open FDs (Linux/Mac and others)
        $fds = @\scandir('/dev/fd');

        // Otherwise try temporarily duplicating file descriptors in the range 0-1024 (FD_SETSIZE).
        // This is known to work on more exotic platforms and also inside chroot
        // environments without /dev/fd. Causes many syscalls, but still rather fast.
        // @codeCoverageIgnoreStart
        if ($fds === false) {
            $fds = array();
            for ($i = 0; $i <= 1024; ++$i) {
                $copy = @\fopen('php://fd/' . $i, 'r');
                if ($copy !== false) {
                    $fds[] = $i;
                    \fclose($copy);
                }
            }
        }
        // @codeCoverageIgnoreEnd

        // launch process with default STDIO pipes
        $pipes = array(
            array('pipe', 'r'),
            array('pipe', 'w'),
            array('pipe', 'w')
        );

        // do not inherit open FDs by explicitly overwriting existing FDs with dummy files
        // additionally, close all dummy files in the child process again
        foreach ($fds as $fd) {
            if ($fd > 2) {
                $pipes[$fd] = array('file', '/dev/null', 'r');
                $command .= ' ' . $fd . '>&-';
            }
        }

        // default `sh` only accepts single-digit FDs, so run in bash if needed
        if ($fds && \max($fds) > 9) {
            $command = 'exec bash -c ' . \escapeshellarg($command);
        }

        $process = new Process($command, null, null, $pipes);
        $process->start($this->loop);

        $db = new ProcessIoDatabase($process);
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
}

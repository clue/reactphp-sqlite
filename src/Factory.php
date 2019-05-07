<?php

namespace Clue\React\SQLite;

use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use Clue\React\SQLite\Io\ProcessIoDatabase;
use React\Stream\DuplexResourceStream;
use React\Promise\Deferred;
use React\Stream\ThroughStream;

class Factory
{
    private $loop;
    private $bin = PHP_BINARY;
    private $useSocket;

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

        // use socket I/O for Windows only, use faster process pipes everywhere else
        $this->useSocket = DIRECTORY_SEPARATOR === '\\';

        // if this is the php-cgi binary, check if we can execute the php binary instead
        $candidate = \str_replace('-cgi', '', $this->bin);
        if ($candidate !== $this->bin && \is_executable($candidate)) {
            $this->bin = $candidate; // @codeCoverageIgnore
        }

        // if `php` is a symlink to the php binary, use the shorter `php` name
        // this is purely cosmetic feature for the process list
        if (\realpath($this->which('php')) === $this->bin) {
            $this->bin = 'php'; // @codeCoverageIgnore
        }
    }

    /**
     * Opens a new database connection for the given SQLite database file.
     *
     * This method returns a promise that will resolve with a `DatabaseInterface` on
     * success or will reject with an `Exception` on error. The SQLite extension
     * is inherently blocking, so this method will spawn an SQLite worker process
     * to run all SQLite commands and queries in a separate process without
     * blocking the main process. On Windows, it uses a temporary network socket
     * for this communication, on all other platforms it communicates over
     * standard process I/O pipes.
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
        return $this->useSocket ? $this->openSocketIo($filename, $flags) : $this->openProcessIo($filename, $flags);
    }

    private function openProcessIo($filename, $flags = null)
    {
        $command = 'exec ' . \escapeshellarg($this->bin) . ' sqlite-worker.php';

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

        $process = new Process($command, __DIR__ . '/../res', null, $pipes);
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

    private function openSocketIo($filename, $flags = null)
    {
        $command = \escapeshellarg($this->bin) . ' sqlite-worker.php';

        // launch process without default STDIO pipes
        $null = \DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';
        $pipes = array(
            array('file', $null, 'r'),
            array('file', $null, 'w'),
            \defined('STDERR') ? \STDERR : \fopen('php://stderr', 'w')
        );

        // start temporary socket on random address
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            return \React\Promise\reject(
                new \RuntimeException('Unable to start temporary socket I/O server: ' . $errstr, $errno)
            );
        }

        // pass random server address to child process to connect back to parent process
        stream_set_blocking($server, false);
        $command .= ' ' . stream_socket_get_name($server, false);

        $process = new Process($command, __DIR__ . '/../res', null, $pipes);
        $process->start($this->loop);

        $deferred = new Deferred(function () use ($process, $server) {
            $this->loop->removeReadStream($server);
            fclose($server);
            $process->terminate();

            throw new \RuntimeException('Opening database cancelled');
        });

        // time out after a few seconds if we don't receive a connection
        $timeout = $this->loop->addTimer(5.0, function () use ($server, $deferred, $process) {
            $this->loop->removeReadStream($server);
            fclose($server);
            $process->terminate();

            $deferred->reject(new \RuntimeException('No connection detected'));
        });

        $this->loop->addReadStream($server, function () use ($server, $timeout, $filename, $flags, $deferred, $process) {
            // accept once connection on server socket and stop server socket
            $this->loop->cancelTimer($timeout);
            $peer = stream_socket_accept($server, 0);
            $this->loop->removeReadStream($server);
            fclose($server);

            // use this one connection as fake process I/O streams
            $connection = new DuplexResourceStream($peer, $this->loop, -1);
            $process->stdin = $process->stdout = $connection;
            $connection->on('close', function () use ($process) {
                $process->terminate();
            });
            $process->on('exit', function () use ($connection) {
                $connection->close();
            });

            $db = new ProcessIoDatabase($process);
            $args = array($filename);
            if ($flags !== null) {
                $args[] = $flags;
            }

            $db->send('open', $args)->then(function () use ($deferred, $db) {
                $deferred->resolve($db);
            }, function ($e) use ($deferred, $db) {
                $db->close();
                $deferred->reject($e);
            });
        });

        return $deferred->promise();
    }

    /**
     * @param string $bin
     * @return string|null
     * @codeCoverageIgnore
     */
    private function which($bin)
    {
        foreach (\explode(\PATH_SEPARATOR, \getenv('PATH')) as $path) {
            if (\is_executable($path . \DIRECTORY_SEPARATOR . $bin)) {
                return $path . \DIRECTORY_SEPARATOR . $bin;
            }
        }
        return null;
    }
}

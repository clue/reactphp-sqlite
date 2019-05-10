<?php

namespace Clue\React\SQLite\Io;

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/**
 * @internal
 */
class LazyDatabase extends EventEmitter implements DatabaseInterface
{
    private $filename;
    private $flags;
    /** @var Factory */
    private $factory;
    private $loop;

    private $closed = false;
    /**@var ?DatabaseInterface */
    private $disconnecting;
    /** @var ?\React\Promise\PromiseInterface */
    private $promise;
    private $idlePeriod = 60.0;
    /** @var ?\React\EventLoop\TimerInterface */
    private $idleTimer;
    private $pending = 0;

    public function __construct($target, $flags, array $options, Factory $factory, LoopInterface $loop)
    {
        $this->filename = $target;
        $this->flags = $flags;
        $this->factory = $factory;
        $this->loop = $loop;

        if (isset($options['idle'])) {
            $this->idlePeriod = (float)$options['idle'];
        }
    }

    /**
     * @return \React\Promise\PromiseInterface
     */
    private function db()
    {
        if ($this->promise !== null) {
            return $this->promise;
        }

        if ($this->closed) {
            return \React\Promise\reject(new \RuntimeException('Connection closed'));
        }

        // force-close connection if still waiting for previous disconnection
        if ($this->disconnecting !== null) {
            $this->disconnecting->close();
            $this->disconnecting = null;
        }

        $this->promise = $promise = $this->factory->open($this->filename, $this->flags);
        $promise->then(function (DatabaseInterface $db) {
            // connection completed => remember only until closed
            $db->on('close', function () {
                $this->promise = null;

                if ($this->idleTimer !== null) {
                    $this->loop->cancelTimer($this->idleTimer);
                    $this->idleTimer = null;
                }
            });
        }, function () {
            // connection failed => discard connection attempt
            $this->promise = null;
        });

        return $promise;
    }

    public function exec($sql)
    {
        return $this->db()->then(function (DatabaseInterface $db) use ($sql) {
            $this->awake();
            return $db->exec($sql)->then(
                function ($result) {
                    $this->idle();
                    return $result;
                },
                function ($error) {
                    $this->idle();
                    throw $error;
                }
            );
        });
    }

    public function query($sql, array $params = [])
    {
        return $this->db()->then(function (DatabaseInterface $db) use ($sql, $params) {
            $this->awake();
            return $db->query($sql, $params)->then(
                function ($result) {
                    $this->idle();
                    return $result;
                },
                function ($error) {
                    $this->idle();
                    throw $error;
                }
            );
        });
    }

    public function quit()
    {
        if ($this->promise === null && !$this->closed) {
            $this->close();
            return \React\Promise\resolve();
        }

        return $this->db()->then(function (DatabaseInterface $db) {
            $db->on('close', function () {
                $this->close();
            });
            return $db->quit();
        });
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // force-close connection if still waiting for previous disconnection
        if ($this->disconnecting !== null) {
            $this->disconnecting->close();
            $this->disconnecting = null;
        }

        // either close active connection or cancel pending connection attempt
        if ($this->promise !== null) {
            $this->promise->then(function (DatabaseInterface $db) {
                $db->close();
            });
            if ($this->promise !== null) {
                $this->promise->cancel();
                $this->promise = null;
            }
        }

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function awake()
    {
        ++$this->pending;

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
    }

    private function idle()
    {
        --$this->pending;

        if ($this->pending < 1 && $this->idlePeriod >= 0) {
            $this->idleTimer = $this->loop->addTimer($this->idlePeriod, function () {
                $this->promise->then(function (DatabaseInterface $db) {
                    $this->disconnecting = $db;
                    $db->quit()->then(
                        function () {
                            // successfully disconnected => remove reference
                            $this->disconnecting = null;
                        },
                        function () use ($db) {
                            // soft-close failed => force-close connection
                            $db->close();
                            $this->disconnecting = null;
                        }
                    );
                });
                $this->promise = null;
                $this->idleTimer = null;
            });
        }
    }
}

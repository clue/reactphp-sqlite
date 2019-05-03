<?php

namespace Clue\React\SQLite\Io;

use Clue\React\NDJson\Decoder;
use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Result;
use Evenement\EventEmitter;
use React\ChildProcess\Process;
use React\Promise\Deferred;

/**
 * The internal `ProcessDatabase` class is responsible for communicating with
 * your SQLite database process via process I/O pipes, managing the connection
 * state and sending your database queries.
 *
 * @internal see DatabaseInterface instead
 * @see DatabaseInterface
 */
class ProcessIoDatabase extends EventEmitter implements DatabaseInterface
{
    private $process;
    private $pending = array();
    private $id = 0;
    private $closed = false;

    /**
     * @internal see Factory instead
     * @see \Clue\React\SQLite\Factory
     * @param Process $process
     */
    public function __construct(Process $process)
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

    public function exec($sql)
    {
        return $this->send('exec', array($sql))->then(function ($data) {
            $result = new Result();
            $result->changed = $data['changed'];
            $result->insertId = $data['insertId'];

            return $result;
        });
    }

    public function query($sql, array $params = array())
    {
        // base64-encode any string that is not valid UTF-8 without control characters (BLOB)
        foreach ($params as &$value) {
            if (\is_string($value) && \preg_match('/[\x00-\x08\x11\x12\x14-\x1f\x7f]/u', $value) !== 0) {
                $value = ['base64' => \base64_encode($value)];
            }
        }

        return $this->send('query', array($sql, $params))->then(function ($data) {
            $result = new Result();
            $result->changed = $data['changed'];
            $result->insertId = $data['insertId'];
            $result->columns = $data['columns'];

            // base64-decode string result values for BLOBS
            $result->rows = [];
            foreach ($data['rows'] as $row) {
                foreach ($row as &$value) {
                    if (isset($value['base64'])) {
                        $value = \base64_decode($value['base64']);
                    }
                }
                $result->rows[] = $row;
            }

            return $result;
        });
    }

    public function quit()
    {
        $promise = $this->send('close', array());

        if ($this->process->stdin === $this->process->stdout) {
            $promise->then(function () { $this->process->stdin->close(); });
        } else {
            $this->process->stdin->end();
        }

        return $promise;
    }

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

    /** @internal */
    public function send($method, array $params)
    {
        if ($this->closed || !$this->process->stdin->isWritable()) {
            return \React\Promise\reject(new \RuntimeException('Database closed'));
        }

        $id = ++$this->id;
        $this->process->stdin->write(\json_encode(array(
            'id' => $id,
            'method' => $method,
            'params' => $params
        ), \JSON_UNESCAPED_SLASHES | (\PHP_VERSION_ID >= 50606 ? JSON_PRESERVE_ZERO_FRACTION : 0)) . "\n");

        $deferred = new Deferred();
        $this->pending[$id] = $deferred;

        return $deferred->promise();
    }
}

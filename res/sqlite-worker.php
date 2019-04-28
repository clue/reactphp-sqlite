<?php

// This child worker process will be started by the main process to start communication over process pipe I/O
//
// Communication happens via newline-delimited JSON-RPC messages, see:
// $ php res/sqlite-worker.php
// < {"id":0,"method":"open","params":["test.db"]}
// > {"id":0,"result":true}
//
// Or via socket connection (used for Windows, which does not support non-blocking process pipe I/O)
// $ nc localhost 8080
// $ php res/sqlite-worker.php localhost:8080

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use React\EventLoop\Factory;
use React\Stream\DuplexResourceStream;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;
use React\Stream\WritableResourceStream;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // local project development, go from /res to /vendor
    require __DIR__ . '/../vendor/autoload.php';
} else {
    // project installed as dependency, go upwards from /vendor/clue/reactphp-sqlite/res
    require __DIR__ . '/../../../autoload.php';
}

$loop = Factory::create();

if (isset($_SERVER['argv'][1])) {
    // socket address given, so try to connect through socket (Windows)
    $socket = stream_socket_client($_SERVER['argv'][1]);
    $stream = new DuplexResourceStream($socket, $loop);

    // pipe input through a wrapper stream so that an error on the input stream
    // will not immediately close the output stream without a chance to report
    // this error through the output stream.
    $through = new ThroughStream();
    $stream->on('data', function ($data) use ($through) {
        $through->write($data);
    });

    $in = new Decoder($through);
    $out = new Encoder($stream);
} else {
    // no socket address given, use process I/O pipes
    $in = new Decoder(new ReadableResourceStream(\STDIN, $loop));
    $out = new Encoder(new WritableResourceStream(\STDOUT, $loop));
}

// report error when input is invalid NDJSON
$in->on('error', function (Exception $e) use ($out) {
    $out->end(array(
        'error' => array(
            'code' => -32700, // parse error
            'message' => 'input error: ' . $e->getMessage()
        )
    ));
});

$db = null;
$in->on('data', function ($data) use (&$db, $in, $out) {
    if (!isset($data->id, $data->method, $data->params) || !\is_scalar($data->id) || !\is_string($data->method) || !\is_array($data->params)) {
        // input is valid JSON, but not JSON-RPC => close input and end output with error
        $in->close();
        $out->end(array(
            'error' => array(
                'code' => -32600, // invalid message
                'message' => 'malformed message'
            )
        ));
        return;
    }

    if ($data->method === 'open' && \count($data->params) === 1 && \is_string($data->params[0])) {
        // open database with one parameter: $filename
        try {
            $db = new SQLite3(
                $data->params[0]
            );

            $out->write(array(
                'id' => $data->id,
                'result' => true
            ));
        } catch (Exception $e) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $e->getMessage())
            ));
        }
    } elseif ($data->method === 'open' && \count($data->params) === 2 && \is_string($data->params[0]) && \is_int($data->params[1])) {
        // open database with two parameters: $filename, $flags
        try {
            $db = new SQLite3(
                $data->params[0],
                $data->params[1]
            );

            $out->write(array(
                'id' => $data->id,
                'result' => true
            ));
        } catch (Exception $e) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $e->getMessage())
            ));
        }
    } elseif ($data->method === 'exec' && $db !== null && \count($data->params) === 1 && \is_string($data->params[0])) {
        // execute statement and suppress PHP warnings
        $ret = @$db->exec($data->params[0]);

        if ($ret === false) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $db->lastErrorMsg())
            ));
        } else {
            $out->write(array(
                'id' => $data->id,
                'result' => array(
                    'insertId' => $db->lastInsertRowID(),
                    'changed' => $db->changes()
                )
            ));
        }
    } elseif ($data->method === 'query' && $db !== null && \count($data->params) === 2 && \is_string($data->params[0]) && \is_array($data->params[1])) {
        // execute statement and suppress PHP warnings
        if (\count($data->params[1]) === 0) {
            $result = @$db->query($data->params[0]);
        } else {
            $statement = $db->prepare($data->params[0]);
            foreach ($data->params[1] as $index => $value) {
                $statement->bindValue(
                    $index + 1,
                    $value,
                    $value === null ? \SQLITE3_NULL : \is_int($value) || \is_bool($value) ? \SQLITE3_INTEGER : \is_float($value) ? \SQLITE3_FLOAT : \SQLITE3_TEXT
                );
            }
            $result = @$statement->execute();
        }

        if ($result === false) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $db->lastErrorMsg())
            ));
        } else {
            $columns = array();
            for ($i = 0, $n = $result->numColumns(); $i < $n; ++$i) {
                $columns[] = $result->columnName($i);
            }

            $rows = array();
            while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
                $rows[] = $row;
            }
            $result->finalize();

            $out->write(array(
                'id' => $data->id,
                'result' => array(
                    'columns' => $columns,
                    'rows' => $rows,
                    'insertId' => $db->lastInsertRowID(),
                    'changed' => $db->changes()
                )
            ));
        }
    } elseif ($data->method === 'query' && $db !== null && \count($data->params) === 2 && \is_string($data->params[0]) && \is_object($data->params[1])) {
        $statement = $db->prepare($data->params[0]);
        foreach ($data->params[1] as $index => $value) {
            $statement->bindValue(
                $index,
                $value,
                $value === null ? \SQLITE3_NULL : \is_int($value) || \is_bool($value) ? \SQLITE3_INTEGER : \is_float($value) ? \SQLITE3_FLOAT : \SQLITE3_TEXT
            );
        }
        $result = @$statement->execute();

        if ($result === false) {
            $out->write(array(
                'id' => $data->id,
                'error' => array('message' => $db->lastErrorMsg())
            ));
        } else {
            $columns = array();
            for ($i = 0, $n = $result->numColumns(); $i < $n; ++$i) {
                $columns[] = $result->columnName($i);
            }

            $rows = array();
            while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
                $rows[] = $row;
            }
            $result->finalize();

            $out->write(array(
                'id' => $data->id,
                'result' => array(
                    'columns' => $columns,
                    'rows' => $rows,
                    'insertId' => $db->lastInsertRowID(),
                    'changed' => $db->changes()
                )
            ));
        }
    } elseif ($data->method === 'close' && $db !== null && \count($data->params) === 0) {
        // close database and remove reference
        $db->close();
        $db = null;

        $out->write(array(
            'id' => $data->id,
            'result' => null
        ));
    } else {
        // no matching method found => report soft error and keep stream alive
        $out->write(array(
            'id' => $data->id,
            'error' => array(
                'code' => -32601, // invalid method
                'message' => 'invalid method call'
            )
        ));
    }
});

$loop->run();

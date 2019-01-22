<?php

use React\EventLoop\Factory;
use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;
use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$in = new Decoder(new ReadableResourceStream(\STDIN, $loop));
$out = new Encoder(new WritableResourceStream(\STDOUT, $loop));

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
                    $value === null ? \SQLITE3_NULL : \is_int($value) ? \SQLITE3_INTEGER : \SQLITE3_TEXT
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
                $value === null ? \SQLITE3_NULL : \is_int($value) ? \SQLITE3_INTEGER : \SQLITE3_TEXT
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

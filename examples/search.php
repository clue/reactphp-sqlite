<?php

use Clue\React\SQLite\Database;
use Clue\React\SQLite\Factory;
use Clue\React\SQLite\Result;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$search = isset($argv[1]) ? $argv[1] : 'foo';
$factory->open('test.db')->then(function (Database $db) use ($search){
    $db->query('SELECT * FROM foo WHERE bar LIKE ?', ['%' . $search . '%'])->then(function (Result $result) {
        echo 'Found ' . count($result->rows) . ' rows: ' . PHP_EOL;
        echo implode("\t", $result->columns) . PHP_EOL;
        foreach ($result->rows as $row) {
            echo implode("\t", $row) . PHP_EOL;
        }
    }, 'printf');
    $db->quit();
}, 'printf');

$loop->run();

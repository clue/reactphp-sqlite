<?php

use React\EventLoop\Factory;
use Clue\React\SQLite\Database;
use Clue\React\SQLite\Result;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$n = isset($argv[1]) ? $argv[1] : 1;
Database::open($loop, 'test.db')->then(function (Database $db) use ($n) {
    $db->exec('CREATE TABLE IF NOT EXISTS foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');

    for ($i = 0; $i < $n; ++$i) {
        $db->exec("INSERT INTO foo (bar) VALUES ('This is a test')")->then(function (Result $result) {
            echo 'New row ' . $result->insertId . PHP_EOL;
        });
    }

    $db->quit();
}, 'printf');

$loop->run();

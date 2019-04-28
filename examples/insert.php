<?php

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use Clue\React\SQLite\Result;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$n = isset($argv[1]) ? $argv[1] : 1;
$factory->open('test.db')->then(function (DatabaseInterface $db) use ($n) {
    $db->exec('CREATE TABLE IF NOT EXISTS foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');

    for ($i = 0; $i < $n; ++$i) {
        $db->exec("INSERT INTO foo (bar) VALUES ('This is a test')")->then(function (Result $result) {
            echo 'New row ' . $result->insertId . PHP_EOL;
        });
    }

    $db->quit();
}, 'printf');

$loop->run();

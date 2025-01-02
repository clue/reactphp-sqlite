<?php

namespace Clue\Tests\React\SQLite;

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

class FunctionalFactoryTest extends TestCase
{
    public function testOpenReturnsPromiseWhichFulfillsWithConnectionForMemoryPath()
    {
        $factory = new Factory();
        $promise = $factory->open(':memory:');

        $promise->then(function (DatabaseInterface $db) {
            echo 'open.';
            $db->on('close', function () {
                echo 'close.';
            });

            $db->close();
        }, function (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

        $this->expectOutputString('open.close.');
        Loop::run();
    }

    public function testOpenReturnsPromiseWhichFulfillsWithConnectionForMemoryPathAndExplicitPhpBinary()
    {
        $factory = new Factory(null, PHP_BINARY);
        $promise = $factory->open(':memory:');

        $promise->then(function (DatabaseInterface $db) {
            echo 'open.';
            $db->on('close', function () {
                echo 'close.';
            });

                $db->close();
        }, function (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

        $this->expectOutputString('open.close.');
        Loop::run();
    }

    public function testOpenReturnsPromiseWhichRejectsWithExceptionWhenPathIsInvalid()
    {
        $factory = new Factory();
        $promise = $factory->open('/dev/foo/bar');

        $promise->then(function (DatabaseInterface $db) {
            echo 'open.';
            $db->close();
        }, function (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

        // Unable to open database: unable to open database file
        // Unable to open database: bad parameter or other API misuse (only between PHP 7.4.0 and PHP 7.4.7 as per https://3v4l.org/9SjgK)
        $this->expectOutputRegex('/^' . preg_quote('Error: Unable to open database: ', '/') . '.*$/');
        Loop::run();
    }

    public function testOpenReturnsPromiseWhichRejectsWithExceptionWhenExplicitPhpBinaryExitsImmediately()
    {
        $factory = new Factory(null, 'echo');

        $ref = new \ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, true);

        $promise = $factory->open(':memory:');

        $promise->then(function (DatabaseInterface $db) {
            echo 'open.';
            $db->close();
        }, function (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        });

        $this->expectOutputString('Error: Database process died while setting up connection' . PHP_EOL);
        Loop::run();
    }
}

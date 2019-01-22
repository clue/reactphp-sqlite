<?php

use React\EventLoop\Factory;
use Clue\React\SQLite\Database;
use PHPUnit\Framework\TestCase;
use Clue\React\SQLite\Result;

class FunctionalDatabaseTest extends TestCase
{
    public function testOpenMemoryDatabaseResolvesWithDatabaseAndRunsUntilClose()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $promise->then(
            $this->expectCallableOnceWith($this->isInstanceOf('Clue\React\SQLite\Database'))
        );

        $promise->then(function (Database $db) {
            $db->close();
        });

        $loop->run();
    }

    public function testOpenMemoryDatabaseResolvesWithDatabaseAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $promise->then(
            $this->expectCallableOnceWith($this->isInstanceOf('Clue\React\SQLite\Database'))
        );

        $promise->then(function (Database $db) {
            $db->quit();
        });

        $loop->run();
    }

    public function testOpenInvalidPathRejects()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, '/dev/foo/bar');

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        $loop->run();
    }

    public function testOpenInvalidFlagsRejects()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, '::memory::', SQLITE3_OPEN_READONLY);

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        $loop->run();
    }

    public function testQuitResolvesAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (Database $db) use ($once){
            $db->quit()->then($once);
        });

        $loop->run();
    }

    public function testQuitTwiceWillRejectSecondCall()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (Database $db) use ($once){
            $db->quit();
            $db->quit()->then(null, $once);
        });

        $loop->run();
    }

    public function testQueryIntegerResolvesWithResultWithTypeIntegerAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $data = null;
        $promise->then(function (Database $db) use (&$data){
            $db->query('SELECT 1 AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => 1)), $data);
    }

    public function testQueryStringResolvesWithResultWithTypeStringAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $data = null;
        $promise->then(function (Database $db) use (&$data){
            $db->query('SELECT "hellö" AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => 'hellö')), $data);
    }

    public function testQueryIntegerPlaceholderPositionalResolvesWithResultWithTypeIntegerAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $data = null;
        $promise->then(function (Database $db) use (&$data){
            $db->query('SELECT ? AS value', array(1))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => 1)), $data);
    }

    public function testQueryIntegerPlaceholderNamedResolvesWithResultWithTypeIntegerAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $data = null;
        $promise->then(function (Database $db) use (&$data){
            $db->query('SELECT :value AS value', array('value' => 1))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => 1)), $data);
    }

    public function testQueryNullPlaceholderPositionalResolvesWithResultWithTypeNullAndRunsUntilQuit()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $data = null;
        $promise->then(function (Database $db) use (&$data){
            $db->query('SELECT ? AS value', array(null))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => null)), $data);
    }

    public function testQueryRejectsWhenQueryIsInvalid()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (Database $db) use ($once){
            $db->query('nope')->then(null, $once);

            $db->quit();
        });

        $loop->run();
    }

    public function testQueryRejectsWhenClosedImmediately()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (Database $db) use ($once){
            $db->query('SELECT 1')->then(null, $once);

            $db->close();
        });

        $loop->run();
    }

    public function testExecCreateTableResolvesWithResultWithoutRows()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $data = 'n/a';
        $promise->then(function (Database $db) use (&$data){
            $db->exec('CREATE TABLE foo (bar STRING)')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertNull($data);
    }

    public function testExecRejectsWhenClosedImmediately()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (Database $db) use ($once){
            $db->exec('USE a')->then(null, $once);

            $db->close();
        });

        $loop->run();
    }

    public function testExecRejectsWhenAlreadyClosed()
    {
        $loop = Factory::create();

        $promise = Database::open($loop, ':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (Database $db) use ($once){
            $db->close();
            $db->exec('USE a')->then(null, $once);
        });

        $loop->run();
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
        ->expects($this->never())
        ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
        ->expects($this->once())
        ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($value)
    {
        $mock = $this->createCallableMock();
        $mock
        ->expects($this->once())
        ->method('__invoke')
        ->with($value);

        return $mock;
    }

    protected function createCallableMock()
    {
        return $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
    }
}

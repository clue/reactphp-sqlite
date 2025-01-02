<?php

use Clue\React\SQLite\Io\BlockingDatabase;
use Clue\React\SQLite\Result;
use PHPUnit\Framework\TestCase;

class BlockingDatabaseTest extends TestCase
{
    public function testCtorThrowsForInvalidPath()
    {
        if (method_exists($this, 'expectException')) {
            // PHPUnit 5.2+
            $this->expectException('Exception');
        } else {
            // legacy PHPUnit
            $this->setExpectedException('Exception');
        }
        new BlockingDatabase('/dev/foo/bar');
    }

    public function testExecReturnsRejectedPromiseForInvalidQuery()
    {
        $db = new BlockingDatabase(':memory:');

        $promise = $db->exec('FOO-BAR');

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testExecAfterCloseReturnsRejectedPromise()
    {
        $db = new BlockingDatabase(':memory:');

        $db->close();
        $promise = $db->exec('CREATE TABLE foo (bar string)');

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testExecReturnsFulfilledPromiseWithEmptyResultFromCreateTableStatement()
    {
        $db = new BlockingDatabase(':memory:');

        $promise = $db->exec('CREATE TABLE foo (bar string)');

        $result = new Result();
        $promise->then($this->expectCallableOnceWith($result));
    }

    public function testQueryReturnsRejectedPromiseForInvalidQuery()
    {
        $db = new BlockingDatabase(':memory:');

        $promise = $db->query('FOO-BAR');

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testQueryReturnsRejectedPromiseForWriteQueryToReadonlyDatabase()
    {
        $db = new BlockingDatabase(':memory:', SQLITE3_OPEN_READONLY);

        $promise = $db->query('CREATE TABLE foo (bar string)');

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testQueryReturnsRejectedPromiseForSelectFromUnknownTableWithPlaceholder()
    {
        $db = new BlockingDatabase(':memory:', SQLITE3_OPEN_READONLY);

        $promise = $db->query('SELECT ? FROM unknown', [42]);

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testQueryAfterCloseReturnsRejectedPromise()
    {
        $db = new BlockingDatabase(':memory:');

        $db->close();
        $promise = $db->query('CREATE TABLE foo (bar string)');

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testQueryReturnsFulfilledPromiseWithIntegerResult()
    {
        $db = new BlockingDatabase(':memory:', SQLITE3_OPEN_READONLY);

        $promise = $db->query('SELECT 1 AS value');

        $result = new Result();
        $result->columns = ['value'];
        $result->rows = [
            [
                'value' => 1
            ]
        ];
        $promise->then($this->expectCallableOnceWith($result));
    }

    public function provideDataWillBeReturnedWithType()
    {
        return [
            [0, 'INTEGER'],
            [1, 'INTEGER'],
            [1.5, 'REAL'],
            [1.0, 'REAL'],
            [null, 'NULL'],
            ['hello', 'TEXT'],
            ['hellö', 'TEXT'],
            ["hello\tworld\r\n", 'TEXT'],
            ["hello w\xF6rld!", 'BLOB'],
            ["hello\x7fö", 'BLOB'],
            ["\x03\x02\x001", 'BLOB'],
            ["a\000b", 'BLOB']
        ];
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithType
     * @param mixed $value
     * @param string $type
     */
    public function testQueryReturnsFulfilledPromiseWithResultFromPlaceholder($value, $type)
    {
        $db = new BlockingDatabase(':memory:', SQLITE3_OPEN_READONLY);

        $promise = $db->query('SELECT ? AS value, UPPER(TYPEOF(?)) AS type', [$value, $value]);

        $result = new Result();
        $result->columns = ['value', 'type'];
        $result->rows = [
            [
                'value' => $value,
                'type' => $type
            ]
        ];
        $promise->then($this->expectCallableOnceWith($result));
    }

    public function provideDataWillBeReturnedWithOtherType()
    {
        return [
            [true, 1],
            [false, 0],
        ];
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithOtherType
     * @param mixed $value
     * @param mixed $expected
     */
    public function testQueryReturnsFulfilledPromiseWithResultFromPlaceholderCasted($value, $expected)
    {
        $db = new BlockingDatabase(':memory:', SQLITE3_OPEN_READONLY);

        $promise = $db->query('SELECT ? AS value', [$value]);

        $result = new Result();
        $result->columns = ['value'];
        $result->rows = [
            [
                'value' => $expected
            ]
        ];
        $promise->then($this->expectCallableOnceWith($result));
    }

    public function testQueryReturnsFulfilledPromiseWithEmptyResultFromCreateTableStatement()
    {
        $db = new BlockingDatabase(':memory:');

        $promise = $db->query('CREATE TABLE foo (bar string)');

        $result = new Result();
        $promise->then($this->expectCallableOnceWith($result));
    }

    public function testQuitReturnsFulfilledPromiseAndEmitsCloseEvent()
    {
        $db = new BlockingDatabase(':memory:');
        $db->on('close', $this->expectCallableOnce());

        $promise = $db->quit();
        $promise->then($this->expectCallableOnce());
    }

    public function testQuitAfterCloseReturnsRejectedPromiseAndDoesNotEmitCloseEvent()
    {
        $db = new BlockingDatabase(':memory:');
        $db->close();

        $db->on('close', $this->expectCallableNever());

        $promise = $db->quit();
        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testCloseEmitsCloseEvent()
    {
        $db = new BlockingDatabase(':memory:');
        $db->on('close', $this->expectCallableOnce());

        $db->close();
    }

    public function testCloseTwiceEmitsCloseEventOnce()
    {
        $db = new BlockingDatabase(':memory:');
        $db->on('close', $this->expectCallableOnce());

        $db->close();
        $db->close();
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

<?php

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use Clue\React\SQLite\Result;
use PHPUnit\Framework\TestCase;

class FunctionalDatabaseTest extends TestCase
{
    public function provideSocketFlags()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [[true]];
        } else {
            return [[false], [true]];
        }
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testOpenMemoryDatabaseResolvesWithDatabaseAndRunsUntilClose($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $promise->then(
            $this->expectCallableOnceWith($this->isInstanceOf('Clue\React\SQLite\DatabaseInterface'))
        );

        $promise->then(function (DatabaseInterface $db) {
            $db->close();
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testOpenMemoryDatabaseResolvesWithDatabaseAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $promise->then(
            $this->expectCallableOnceWith($this->isInstanceOf('Clue\React\SQLite\DatabaseInterface'))
        );

        $promise->then(function (DatabaseInterface $db) {
            $db->quit();
        });

        $loop->run();
    }

    public function testOpenMemoryDatabaseShouldNotInheritActiveFileDescriptors()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        if (@stream_socket_server('tcp://' . $address) !== false) {
            $this->markTestSkipped('Platform does not prevent binding to same address (Windows?)');
        }

        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $promise = $factory->open(':memory:');

        // close server and ensure we can start a new server on the previous address
        // the pending SQLite process should not inherit the existing server socket
        fclose($server);

        $server = @stream_socket_server('tcp://' . $address);
        if ($server === false) {
            // There's a very short race condition where the forked php process
            // first has to `dup()` the file descriptor specs before invoking
            // `exec()` to switch to the actual `ssh` child process. We don't
            // need to wait for the child process to be ready, but only for the
            // forked process to close the file descriptors. This happens ~80%
            // of times on single core machines and almost never on multi core
            // systems, so simply wait 5ms (plenty of time!) and retry again.
            usleep(5000);
            $server = stream_socket_server('tcp://' . $address);
        }

        $this->assertTrue(is_resource($server));
        fclose($server);

        $promise->then(function (DatabaseInterface $db) {
            $db->close();
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testOpenInvalidPathRejects($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open('/dev/foo/bar');

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testOpenInvalidFlagsRejects($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open('::memory::', SQLITE3_OPEN_READONLY);

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQuitResolvesAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->quit()->then($once);
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQuitResolvesAndRunsUntilQuitWhenParentHasManyFileDescriptors($flag)
    {
        $servers = array();
        for ($i = 0; $i < 100; ++$i) {
            $servers[] = stream_socket_server('tcp://127.0.0.1:0');
        }

        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->quit()->then($once);
        });

        $loop->run();

        foreach ($servers as $server) {
            fclose($server);
        }
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQuitTwiceWillRejectSecondCall($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->quit();
            $db->quit()->then(null, $once);
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQueryIntegerResolvesWithResultWithTypeIntegerAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT 1 AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => 1)), $data);
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQueryStringResolvesWithResultWithTypeStringAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT "hellö" AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => 'hellö')), $data);
    }

    public function provideSqlDataWillBeReturnedWithType()
    {
        return array_merge(
            [
                ['42', 42],
                ['2.5', 2.5],
                ['1.0', 1.0],
                ['null', null],
                ['"hello"', 'hello'],
                ['"hellö"', 'hellö'],
                ['X\'01020300\'', "\x01\x02\x03\x00"],
                ['X\'3FF3\'', "\x3f\xf3"]
            ],
            (SQLite3::version()['versionNumber'] < 3023000) ? [] : [
                // boolean identifiers exist only as of SQLite 3.23.0 (2018-04-02)
                // @link https://www.sqlite.org/lang_expr.html#booleanexpr
                ['true', 1],
                ['false', 0]
            ]
        );
    }

    /**
     * @dataProvider provideSqlDataWillBeReturnedWithType
     * @param mixed $value
     * @param mixed $expected
     */
    public function testQueryValueInStatementResolvesWithResultWithTypeAndRunsUntilQuit($value, $expected)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT ' . $value . ' AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => $expected)), $data);
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
            [utf8_decode('hello wörld!'), 'BLOB'],
            ["hello\x7fö", 'BLOB'],
            ["\x03\x02\x001", 'BLOB'],
            ["a\000b", 'BLOB']
        ];
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithType
     * @param mixed $value
     */
    public function testQueryValuePlaceholderPositionalResolvesWithResultWithExactTypeAndRunsUntilQuit($value, $type)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT ? AS value, UPPER(TYPEOF(?)) as type', array($value, $value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => $value, 'type' => $type)), $data);
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithType
     * @param mixed $value
     */
    public function testQueryValuePlaceholderNamedResolvesWithResultWithExactTypeAndRunsUntilQuit($value, $type)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT :value AS value, UPPER(TYPEOF(:value)) AS type', array('value' => $value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => $value, 'type' => $type)), $data);
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
    public function testQueryValuePlaceholderPositionalResolvesWithResultWithOtherTypeAndRunsUntilQuit($value, $expected)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT ? AS value', array($value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => $expected)), $data);
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithOtherType
     * @param mixed $value
     * @param mixed $expected
     */
    public function testQueryValuePlaceholderNamedResolvesWithResultWithOtherTypeAndRunsUntilQuit($value, $expected)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT :value AS value', array('value' => $value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => $expected)), $data);
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQueryRejectsWhenQueryIsInvalid($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->query('nope')->then(null, $once);

            $db->quit();
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQueryRejectsWhenClosedImmediately($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->query('SELECT 1')->then(null, $once);

            $db->close();
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testExecCreateTableResolvesWithResultWithoutRows($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = 'n/a';
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->exec('CREATE TABLE foo (bar STRING)')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertNull($data);
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testExecRejectsWhenClosedImmediately($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->exec('USE a')->then(null, $once);

            $db->close();
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testExecRejectsWhenAlreadyClosed($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->close();
            $db->exec('USE a')->then('var_dump', $once);
        });

        $loop->run();
    }

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQueryInsertResolvesWithResultWithLastInsertIdAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');
            $db->query('INSERT INTO foo (bar) VALUES (?)', ['test'])->then(function (Result $result) use (&$data) {
                $data = $result->insertId;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(1, $data);
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

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
        $server = stream_socket_server('tcp://' . $address);
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

    /**
     * @dataProvider provideSocketFlags
     * @param bool $flag
     */
    public function testQueryIntegerPlaceholderPositionalResolvesWithResultWithTypeIntegerAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT ? AS value', array(1))->then(function (Result $result) use (&$data) {
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
    public function testQueryIntegerPlaceholderNamedResolvesWithResultWithTypeIntegerAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT :value AS value', array('value' => 1))->then(function (Result $result) use (&$data) {
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
    public function testQueryNullPlaceholderPositionalResolvesWithResultWithTypeNullAndRunsUntilQuit($flag)
    {
        $loop = React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $ref = new ReflectionProperty($factory, 'useSocket');
        $ref->setAccessible(true);
        $ref->setValue($factory, $flag);

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT ? AS value', array(null))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        $loop->run();

        $this->assertSame(array(array('value' => null)), $data);
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

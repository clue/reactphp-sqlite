<?php

namespace Clue\Tests\React\SQLite;

use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use Clue\React\SQLite\Result;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

class FunctionalDatabaseTest extends TestCase
{
    public function provideSocketFlag()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [[true]];
        } else {
            return [[false], [true]];
        }
    }

    public function providePhpBinaryAndSocketFlag()
    {
        return array_merge([
            [
                null,
                null
            ],
            [
                '',
                null
            ],
            [
                null,
                true
            ]
        ], DIRECTORY_SEPARATOR === '\\' ? [] : [
            [
                null,
                false
            ]
        ]);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testOpenMemoryDatabaseResolvesWithDatabaseAndRunsUntilClose($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $promise->then(
            $this->expectCallableOnceWith($this->isInstanceOf('Clue\React\SQLite\DatabaseInterface'))
        );

        $promise->then(function (DatabaseInterface $db) {
            $db->close();
        });

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testOpenMemoryDatabaseResolvesWithDatabaseAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $promise->then(
            $this->expectCallableOnceWith($this->isInstanceOf('Clue\React\SQLite\DatabaseInterface'))
        );

        $promise->then(function (DatabaseInterface $db) {
            $db->quit();
        });

        Loop::run();
    }

    public function testOpenMemoryDatabaseShouldNotInheritActiveFileDescriptors()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        if (@stream_socket_server('tcp://' . $address) !== false) {
            $this->markTestSkipped('Platform does not prevent binding to same address (Windows?)');
        }

        $factory = new Factory();

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

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testOpenInvalidPathRejects($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open('/dev/foo/bar');

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testOpenInvalidPathWithNullByteRejects($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open("test\0.db");

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testOpenInvalidFlagsRejects($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open('::memory::', SQLITE3_OPEN_READONLY);

        $promise->then(
            null,
            $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'))
        );

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQuitResolvesAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->quit()->then($once);
        });

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQuitResolvesAndRunsUntilQuitWhenParentHasManyFileDescriptors($php, $useSocket)
    {
        $servers = array();
        for ($i = 0; $i < 100; ++$i) {
            $servers[] = stream_socket_server('tcp://127.0.0.1:0');
        }

        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->quit()->then($once);
        });

        Loop::run();

        foreach ($servers as $server) {
            fclose($server);
        }
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQuitTwiceWillRejectSecondCall($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnce();
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->quit();
            $db->quit()->then(null, $once);
        });

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQueryIntegerResolvesWithResultWithTypeIntegerAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT 1 AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame(array(array('value' => 1)), $data);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQueryStringResolvesWithResultWithTypeStringAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT "hellö" AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame(array(array('value' => 'hellö')), $data);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQueryInvalidTableRejectsWithExceptionAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT 1 FROM foo')->then('var_dump', function (\Exception $e) use (&$data) {
                $data = $e->getMessage();
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame('no such table: foo', $data);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQueryInvalidTableWithPlaceholderRejectsWithExceptionAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->query('SELECT ? FROM foo', [1])->then('var_dump', function (\Exception $e) use (&$data) {
                $data = $e->getMessage();
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame('no such table: foo', $data);
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
            (\SQLite3::version()['versionNumber'] < 3023000) ? [] : [
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
        $factory = new Factory();

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT ' . $value . ' AS value')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

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
            ["hello w\xF6rld!", 'BLOB'],
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
        $factory = new Factory();

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT ? AS value, UPPER(TYPEOF(?)) as type', array($value, $value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame(array(array('value' => $value, 'type' => $type)), $data);
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithType
     * @param mixed $value
     */
    public function testQueryValuePlaceholderNamedResolvesWithResultWithExactTypeAndRunsUntilQuit($value, $type)
    {
        $factory = new Factory();

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT :value AS value, UPPER(TYPEOF(:value)) AS type', array('value' => $value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

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
        $factory = new Factory();

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT ? AS value', array($value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame(array(array('value' => $expected)), $data);
    }

    /**
     * @dataProvider provideDataWillBeReturnedWithOtherType
     * @param mixed $value
     * @param mixed $expected
     */
    public function testQueryValuePlaceholderNamedResolvesWithResultWithOtherTypeAndRunsUntilQuit($value, $expected)
    {
        $factory = new Factory();

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data, $value){
            $db->query('SELECT :value AS value', array('value' => $value))->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

        $this->assertSame(array(array('value' => $expected)), $data);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQueryRejectsWhenQueryIsInvalid($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->query('nope')->then(null, $once);

            $db->quit();
        });

        Loop::run();
    }

    /**
     * @dataProvider provideSocketFlag
     * @param bool $useSocket
     */
    public function testQueryRejectsWhenClosedImmediately($useSocket)
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'useSocket');
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($factory, $useSocket);

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->query('SELECT 1')->then(null, $once);

            $db->close();
        });

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testExecCreateTableResolvesWithResultWithoutRows($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = 'n/a';
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->exec('CREATE TABLE foo (bar STRING)')->then(function (Result $result) use (&$data) {
                $data = $result->rows;
            });

            $db->quit();
        });

        Loop::run();

        $this->assertNull($data);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testExecRejectsWhenClosedImmediately($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->exec('USE a')->then(null, $once);

            $db->close();
        });

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testExecRejectsWhenAlreadyClosed($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $once = $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException'));
        $promise->then(function (DatabaseInterface $db) use ($once){
            $db->close();
            $db->exec('USE a')->then('var_dump', $once);
        });

        Loop::run();
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQueryInsertResolvesWithEmptyResultSetWithLastInsertIdAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');
            $db->query('INSERT INTO foo (bar) VALUES (?)', ['test'])->then(function (Result $result) use (&$data) {
                $data = $result;
            }, 'printf');

            $db->quit();
        });

        Loop::run();

        $this->assertInstanceOf('Clue\React\SQLite\Result', $data);
        $this->assertSame(1, $data->insertId);
        $this->assertNull($data->columns);
        $this->assertNull($data->rows);
    }

    /**
     * @dataProvider providePhpBinaryAndSocketFlag
     * @param ?string $php
     * @param ?bool $useSocket
     */
    public function testQuerySelectEmptyResolvesWithEmptyResultSetWithColumnsAndNoRowsAndRunsUntilQuit($php, $useSocket)
    {
        $factory = new Factory(null, $php);

        if ($useSocket !== null) {
            $ref = new \ReflectionProperty($factory, 'useSocket');
            if (PHP_VERSION_ID < 80100) {
                $ref->setAccessible(true);
            }
            $ref->setValue($factory, $useSocket);
        }

        $promise = $factory->open(':memory:');

        $data = null;
        $promise->then(function (DatabaseInterface $db) use (&$data){
            $db->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');
            $db->query('SELECT * FROM foo LIMIT 0')->then(function (Result $result) use (&$data) {
                $data = $result;
            }, 'printf');

            $db->quit();
        });

        Loop::run();

        $this->assertInstanceOf('Clue\React\SQLite\Result', $data);
        $this->assertSame(['id', 'bar'], $data->columns);
        $this->assertSame([], $data->rows);
    }

    public function testCancelOpenWithSocketRejectsPromise()
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'useSocket');
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($factory, true);

        $promise = $factory->open(':memory:');
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Opening database cancelled', $exception->getMessage());
    }

    public function testOpenWithSocketWillRejectWhenSocketConnectionTimesOut()
    {
        $timer = null;
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(5.0, $this->callback(function (callable $callback) use (&$timer) {
            $timer = $callback;
            return true;
        }));
        $loop->expects($this->never())->method('cancelTimer');

        $factory = new Factory($loop);

        $ref = new \ReflectionProperty($factory, 'useSocket');
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($factory, true);

        $promise = $factory->open(':memory:');

        $this->assertNotNull($timer);
        $timer();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Opening database socket timed out', $exception->getMessage());
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

<?php

use PHPUnit\Framework\TestCase;
use Clue\React\SQLite\DatabaseInterface;
use React\Stream\ThroughStream;

class DatabaseTest extends TestCase
{
    public function testDatabaseWillEmitErrorWhenStdoutReportsNonNdjsonStream()
    {
        $stdout = new ThroughStream();

        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->any())->method('isWritable')->willReturn(true);

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->stdin = $stdin;
        $process->stdout = $stdout;

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionMethod($database, '__construct');
        $ref->setAccessible(true);
        $ref->invoke($database, $process);

        $database->on('error', $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $database->on('close', $this->expectCallableOnce());

        $stdout->write("foo\nbar\n");
    }

    public function testDatabaseWillEmitErrorWhenStdoutReportsNdjsonButNotJsonRpcStream()
    {
        $stdout = new ThroughStream();

        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->any())->method('isWritable')->willReturn(true);

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->stdin = $stdin;
        $process->stdout = $stdout;

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionMethod($database, '__construct');
        $ref->setAccessible(true);
        $ref->invoke($database, $process);

        $database->on('error', $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $database->on('close', $this->expectCallableOnce());

        $stdout->write("null\n");
    }

    public function testExecWillWriteExecMessageToProcessAndReturnPromise()
    {
        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->any())->method('isWritable')->willReturn(true);
        $stdin->expects($this->once())->method('write')->with('{"id":1,"method":"exec","params":["USE a"]}' . "\n");

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->stdin = $stdin;

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionProperty($database, 'process');
        $ref->setAccessible(true);
        $ref->setValue($database, $process);

        $promise = $database->exec('USE a');

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testQueryWillWriteQueryMessageToProcessAndReturnPromise()
    {
        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->any())->method('isWritable')->willReturn(true);
        $stdin->expects($this->once())->method('write')->with('{"id":1,"method":"query","params":["SELECT 1",[]]}' . "\n");

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->stdin = $stdin;

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionProperty($database, 'process');
        $ref->setAccessible(true);
        $ref->setValue($database, $process);

        $promise = $database->query('SELECT 1');

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testQuitWillWriteCloseMessageToProcessAndEndInputAndReturnPromise()
    {
        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->any())->method('isWritable')->willReturn(true);
        $stdin->expects($this->once())->method('write')->with('{"id":1,"method":"close","params":[]}' . "\n");
        $stdin->expects($this->once())->method('end');

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->stdin = $stdin;

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionProperty($database, 'process');
        $ref->setAccessible(true);
        $ref->setValue($database, $process);

        $promise = $database->quit();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testQuitWillRejectPromiseWhenStdinAlreadyClosed()
    {
        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->any())->method('isWritable')->willReturn(false);
        $stdin->expects($this->never())->method('write');

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->stdin = $stdin;

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionProperty($database, 'process');
        $ref->setAccessible(true);
        $ref->setValue($database, $process);

        $promise = $database->quit();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testCloseWillCloseStreamsAndTerminateProcess()
    {
        $stdout = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stdout->expects($this->once())->method('close');

        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->once())->method('close');

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->expects($this->once())->method('terminate');
        $process->pipes = array($stdin, $stdout);

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionProperty($database, 'process');
        $ref->setAccessible(true);
        $ref->setValue($database, $process);

        $database->on('close', $this->expectCallableOnce());
        $database->close();
    }

    public function testCloseTwiceWillCloseStreamsAndTerminateProcessOnce()
    {
        $stdout = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $stdout->expects($this->once())->method('close');

        $stdin = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $stdin->expects($this->once())->method('close');

        $process = $this->getMockBuilder('React\ChildProcess\Process')->disableOriginalConstructor()->getMock();
        $process->expects($this->once())->method('terminate');
        $process->pipes = array($stdin, $stdout);

        /* @var DatabaseInterface $database */
        $ref = new ReflectionClass('Clue\React\SQLite\Io\ProcessIoDatabase');
        $database = $ref->newInstanceWithoutConstructor();

        $ref = new ReflectionProperty($database, 'process');
        $ref->setAccessible(true);
        $ref->setValue($database, $process);

        $database->on('close', $this->expectCallableOnce());
        $database->close();
        $database->close();
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

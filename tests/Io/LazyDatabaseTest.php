<?php

namespace Clue\Tests\React\Redis;

use Clue\React\SQLite\Io\LazyDatabase;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\Promise;

class LazyDatabaseTest extends TestCase
{
    private $factory;
    private $loop;
    private $db;

    public function setUp()
    {
        $this->factory = $this->getMockBuilder('Clue\React\SQLite\Factory')->disableOriginalConstructor()->getMock();
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->db = new LazyDatabase('localhost', null, [], $this->factory, $this->loop);
    }

    public function testExecWillCreateUnderlyingDatabaseAndReturnPendingPromise()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('open')->willReturn($promise);

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->db->exec('CREATE');

        $promise->then($this->expectCallableNever());
    }

    public function testExecTwiceWillCreateOnceUnderlyingDatabase()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('open')->willReturn($promise);

        $this->db->exec('CREATE');
        $this->db->exec('CREATE');
    }

    public function testExecWillRejectWhenCreateUnderlyingDatabaseRejects()
    {
        $ex = new \RuntimeException();
        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\reject($ex));

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->db->exec('CREATE');
        $promise->then(null, $this->expectCallableOnceWith($ex));
    }

    public function testExecAgainAfterPreviousExecRejectedBecauseCreateUnderlyingDatabaseRejectsWillTryToOpenDatabaseAgain()
    {
        $ex = new \RuntimeException();
        $this->factory->expects($this->exactly(2))->method('open')->willReturnOnConsecutiveCalls(
            \React\Promise\reject($ex),
            new Promise(function () { })
        );

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->db->exec('CREATE');
        $promise->then(null, $this->expectCallableOnceWith($ex));

        $this->db->exec('CREATE');
    }

    public function testExecWillResolveWhenUnderlyingDatabaseResolvesExecAndStartIdleTimer()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->with('CREATE')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(60, $this->anything());

        $promise = $this->db->exec('CREATE');
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testExecWillResolveWhenUnderlyingDatabaseResolvesExecAndStartIdleTimerWithIdleTimeFromOptions()
    {
        $this->db = new LazyDatabase(':memory:', null, ['idle' => 10.0], $this->factory, $this->loop);

        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->with('CREATE')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(10.0, $this->anything());

        $promise = $this->db->exec('CREATE');
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testExecWillResolveWhenUnderlyingDatabaseResolvesExecAndNotStartIdleTimerWhenIdleOptionIsNegative()
    {
        $this->db = new LazyDatabase(':memory:', null, ['idle' => -1], $this->factory, $this->loop);

        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->with('CREATE')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->db->exec('CREATE');
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testExecWillRejectWhenUnderlyingDatabaseRejectsExecAndStartIdleTimer()
    {
        $error = new \RuntimeException();
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->with('CREATE')->willReturn(\React\Promise\reject($error));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer');

        $promise = $this->db->exec('CREATE');
        $deferred->resolve($client);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testExecWillRejectAndNotEmitErrorOrCloseWhenFactoryRejectsUnderlyingDatabase()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->on('error', $this->expectCallableNever());
        $this->db->on('close', $this->expectCallableNever());

        $promise = $this->db->exec('CREATE');
        $deferred->reject($error);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testExecAfterPreviousFactoryRejectsUnderlyingDatabaseWillCreateNewUnderlyingConnection()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->exactly(2))->method('open')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->db->exec('CREATE');
        $deferred->reject($error);

        $this->db->exec('CREATE');
    }

    public function testExecAfterPreviousUnderlyingDatabaseAlreadyClosedWillCreateNewUnderlyingConnection()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve('PONG'));

        $this->factory->expects($this->exactly(2))->method('open')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($client),
            new Promise(function () { })
        );

        $this->db->exec('CREATE');
        $client->emit('close');

        $this->db->exec('CREATE');
    }

    public function testExecAfterCloseWillRejectWithoutCreatingUnderlyingConnection()
    {
        $this->factory->expects($this->never())->method('open');

        $this->db->close();
        $promise = $this->db->exec('CREATE');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testExecAfterExecWillNotStartIdleTimerWhenFirstExecResolves()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec'))->getMock();
        $client->expects($this->exactly(2))->method('exec')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $this->db->exec('CREATE');
        $this->db->exec('CREATE');
        $deferred->resolve();
    }

    public function testExecAfterExecWillStartAndCancelIdleTimerWhenSecondExecStartsAfterFirstResolves()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec'))->getMock();
        $client->expects($this->exactly(2))->method('exec')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->db->exec('CREATE');
        $deferred->resolve();
        $this->db->exec('CREATE');
    }

    public function testExecFollowedByIdleTimerWillQuitUnderlyingConnectionWithoutCloseEvent()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec', 'quit', 'close'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('quit')->willReturn(\React\Promise\resolve());
        $client->expects($this->never())->method('close');

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->db->on('close', $this->expectCallableNever());

        $this->db->exec('CREATE');

        $this->assertNotNull($timeout);
        $timeout();
    }

    public function testExecFollowedByIdleTimerWillCloseUnderlyingConnectionWhenQuitFails()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->setMethods(array('exec', 'quit', 'close'))->disableOriginalConstructor()->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('quit')->willReturn(\React\Promise\reject());
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->db->on('close', $this->expectCallableNever());

        $this->db->exec('CREATE');

        $this->assertNotNull($timeout);
        $timeout();
    }

    public function testExecAfterIdleTimerWillCloseUnderlyingConnectionBeforeCreatingSecondConnection()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->setMethods(array('exec', 'quit', 'close'))->disableOriginalConstructor()->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->exactly(2))->method('open')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($client),
            new Promise(function () { })
        );

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->db->on('close', $this->expectCallableNever());

        $this->db->exec('CREATE');

        $this->assertNotNull($timeout);
        $timeout();

        $this->db->exec('CREATE');
    }

    public function testQueryWillCreateUnderlyingDatabaseAndReturnPendingPromise()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('open')->willReturn($promise);

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->db->query('CREATE');

        $promise->then($this->expectCallableNever());
    }

    public function testQueryTwiceWillCreateOnceUnderlyingDatabase()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('open')->willReturn($promise);

        $this->db->query('CREATE');
        $this->db->query('CREATE');
    }

    public function testQueryWillResolveWhenUnderlyingDatabaseResolvesQueryAndStartIdleTimer()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('query')->with('SELECT :id', ['id' => 42])->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(60.0, $this->anything());

        $promise = $this->db->query('SELECT :id', ['id' => 42]);
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testQueryWillRejectWhenUnderlyingDatabaseRejectsQueryAndStartIdleTimer()
    {
        $error = new \RuntimeException();
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('query')->with('CREATE')->willReturn(\React\Promise\reject($error));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer');

        $promise = $this->db->query('CREATE');
        $deferred->resolve($client);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testQueryWillRejectAndNotEmitErrorOrCloseWhenFactoryRejectsUnderlyingDatabase()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->on('error', $this->expectCallableNever());
        $this->db->on('close', $this->expectCallableNever());

        $promise = $this->db->query('CREATE');
        $deferred->reject($error);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testQueryAfterPreviousFactoryRejectsUnderlyingDatabaseWillCreateNewUnderlyingConnection()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->exactly(2))->method('open')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
            );

        $this->db->query('CREATE');
        $deferred->reject($error);

        $this->db->query('CREATE');
    }

    public function testQueryAfterPreviousUnderlyingDatabaseAlreadyClosedWillCreateNewUnderlyingConnection()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('query'))->getMock();
        $client->expects($this->once())->method('query')->willReturn(\React\Promise\resolve('PONG'));

        $this->factory->expects($this->exactly(2))->method('open')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($client),
            new Promise(function () { })
            );

        $this->db->query('CREATE');
        $client->emit('close');

        $this->db->query('CREATE');
    }

    public function testQueryAfterCloseWillRejectWithoutCreatingUnderlyingConnection()
    {
        $this->factory->expects($this->never())->method('open');

        $this->db->close();
        $promise = $this->db->query('CREATE');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryAfterQueryWillNotStartIdleTimerWhenFirstQueryResolves()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('query'))->getMock();
        $client->expects($this->exactly(2))->method('query')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
            );

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $this->db->query('CREATE');
        $this->db->query('CREATE');
        $deferred->resolve();
    }

    public function testQueryAfterQueryWillStartAndCancelIdleTimerWhenSecondQueryStartsAfterFirstResolves()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('query'))->getMock();
        $client->expects($this->exactly(2))->method('query')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
            );

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->db->query('CREATE');
        $deferred->resolve();
        $this->db->query('CREATE');
    }

    public function testQueryFollowedByIdleTimerWillQuitUnderlyingConnectionWithoutCloseEvent()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('query', 'quit', 'close'))->getMock();
        $client->expects($this->once())->method('query')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('quit')->willReturn(\React\Promise\resolve());
        $client->expects($this->never())->method('close');

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->db->on('close', $this->expectCallableNever());

        $this->db->query('CREATE');

        $this->assertNotNull($timeout);
        $timeout();
    }

    public function testCloseWillEmitCloseEventWithoutCreatingUnderlyingDatabase()
    {
        $this->factory->expects($this->never())->method('open');

        $this->db->on('close', $this->expectCallableOnce());

        $this->db->close();
    }

    public function testCloseTwiceWillEmitCloseEventOnce()
    {
        $this->db->on('close', $this->expectCallableOnce());

        $this->db->close();
        $this->db->close();
    }

    public function testCloseAfterExecWillCancelUnderlyingDatabaseConnectionWhenStillPending()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $this->factory->expects($this->once())->method('open')->willReturn($promise);

        $this->db->exec('CREATE');
        $this->db->close();
    }

    public function testCloseAfterExecWillEmitCloseWithoutErrorWhenUnderlyingDatabaseConnectionThrowsDueToCancellation()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException('Discarded');
        });
        $this->factory->expects($this->once())->method('open')->willReturn($promise);

        $this->db->on('error', $this->expectCallableNever());
        $this->db->on('close', $this->expectCallableOnce());

        $this->db->exec('CREATE');
        $this->db->close();
    }

    public function testCloseAfterExecWillCloseUnderlyingDatabaseConnectionWhenAlreadyResolved()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('close');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->exec('CREATE');
        $deferred->resolve($client);
        $this->db->close();
    }

    public function testCloseAfterExecWillCancelIdleTimerWhenExecIsAlreadyResolved()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec', 'close'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->db->exec('CREATE');
        $deferred->resolve();
        $this->db->close();
    }

    public function testCloseAfterExecRejectsWillEmitClose()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec', 'close'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close')->willReturnCallback(function () use ($client) {
            $client->emit('close');
        });

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $ref = $this->db;
        $ref->exec('CREATE')->then(null, function () use ($ref, $client) {
            $ref->close();
        });
        $ref->on('close', $this->expectCallableOnce());
        $deferred->reject(new \RuntimeException());
    }

    public function testCloseAfterQuitAfterExecWillCloseUnderlyingConnectionWhenQuitIsStillPending()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $this->db->exec('CREATE');
        $this->db->quit();
        $this->db->close();
    }

    public function testCloseAfterExecAfterIdleTimeoutWillCloseUnderlyingConnectionWhenQuitIsStillPending()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->db->exec('CREATE');

        $this->assertNotNull($timeout);
        $timeout();

        $this->db->close();
    }

    public function testQuitWillCloseDatabaseIfUnderlyingConnectionIsNotPendingAndResolveImmediately()
    {
        $this->db->on('close', $this->expectCallableOnce());
        $promise = $this->db->quit();

        $promise->then($this->expectCallableOnce());
    }

    public function testQuitAfterQuitWillReject()
    {
        $this->db->quit();
        $promise = $this->db->quit();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQuitAfterExecWillQuitUnderlyingDatabase()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\DatabaseInterface')->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('quit');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->exec('CREATE');
        $deferred->resolve($client);
        $promise = $this->db->quit();

        $promise->then($this->expectCallableOnce());
    }

    public function testQuitAfterExecWillCloseDatabaseWhenUnderlyingDatabaseEmitsClose()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec', 'quit'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('quit')->willReturn(\React\Promise\resolve());

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->exec('CREATE');
        $deferred->resolve($client);

        $this->db->on('close', $this->expectCallableOnce());
        $promise = $this->db->quit();

        $client->emit('close');
        $promise->then($this->expectCallableOnce());
    }

    public function testEmitsNoErrorEventWhenUnderlyingDatabaseEmitsError()
    {
        $error = new \RuntimeException();

        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->exec('CREATE');
        $deferred->resolve($client);

        $this->db->on('error', $this->expectCallableNever());
        $client->emit('error', array($error));
    }

    public function testEmitsNoCloseEventWhenUnderlyingDatabaseEmitsClose()
    {
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn(\React\Promise\resolve());

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('open')->willReturn($deferred->promise());

        $this->db->exec('CREATE');
        $deferred->resolve($client);

        $this->db->on('close', $this->expectCallableNever());
        $client->emit('close');
    }

    public function testEmitsNoCloseEventButWillCancelIdleTimerWhenUnderlyingConnectionEmitsCloseAfterExecIsAlreadyResolved()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\SQLite\Io\ProcessIoDatabase')->disableOriginalConstructor()->setMethods(array('exec'))->getMock();
        $client->expects($this->once())->method('exec')->willReturn($deferred->promise());

        $this->factory->expects($this->once())->method('open')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->db->on('close', $this->expectCallableNever());

        $this->db->exec('CREATE');
        $deferred->resolve();

        $client->emit('close');
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

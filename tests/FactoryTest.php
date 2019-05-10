<?php

use PHPUnit\Framework\TestCase;
use Clue\React\SQLite\Factory;

class FactoryTest extends TestCase
{
    public function testLoadLazyReturnsDatabaseImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $factory = new Factory($loop);

        $db = $factory->openLazy(':memory:');

        $this->assertInstanceOf('Clue\React\SQLite\DatabaseInterface', $db);
    }

    public function testLoadLazyWithIdleOptionsReturnsDatabaseWithIdleTimeApplied()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $factory = new Factory($loop);

        $db = $factory->openLazy(':memory:', null, ['idle' => 10.0]);

        $ref = new ReflectionProperty($db, 'idlePeriod');
        $ref->setAccessible(true);
        $value = $ref->getValue($db);

        $this->assertEquals(10.0, $value);
    }
}

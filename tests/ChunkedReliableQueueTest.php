<?php
namespace Altmetric;

use Altmetric\ChunkedReliableQueue;
use PHPUnit_Framework_TestCase as TestCase;
use Psr\Log\NullLogger;
use Redis;

class ChunkedReliableQueueTest extends TestCase
{
    public function testRewindSetsCurrentToAFullChunk()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2, 3);

        $queue->rewind();

        $this->assertSame(array('1', '2'), $queue->current());
    }

    public function testRewindSetsCurrentToAPartialChunk()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1);

        $queue->rewind();

        $this->assertSame(array('1'), $queue->current());
    }

    public function testNextSetsCurrentToPoppedWork()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2, 3, 4);

        $queue->rewind();
        $queue->next();

        $this->assertSame(array('3', '4'), $queue->current());
    }

    public function testRewindPullsAChunkOfUnfinishedWork()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1, 2);

        $queue->rewind();

        $this->assertSame(array('1', '2'), $queue->current());
    }

    public function testNextFinishesWorkAndStoreCurrentInWorkingOn()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2, 3, 4);

        $queue->rewind();
        $queue->next();

        $this->assertSame(array('4', '3'), $this->redis->lRange('reliable-queue-test.working_on.alice', 0, -1));
    }

    public function testKeyIsQueueName()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');

        $this->assertEquals('reliable-queue-test', $queue->key());
    }

    public function testValidIsAlwaysTrue()
    {
        $queue = $this->buildChunkedQueue('alice', 2, 'reliable-queue-test');

        $this->assertTrue($queue->valid());
    }

    public function setUp()
    {
        $this->logger = new NullLogger();
        $this->redis = new Redis();
        $this->redis->connect('localhost');
    }

    public function tearDown()
    {
        $this->redis->del('reliable-queue-test', 'reliable-queue-test.working_on.alice');
    }

    private function buildChunkedQueue($name, $size, $queue)
    {
        return new ChunkedReliableQueue($name, $size, $queue, $this->redis, $this->logger);
    }
}

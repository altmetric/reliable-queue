<?php
namespace Altmetric;

use Altmetric\ReliableQueue;
use PHPUnit_Framework_TestCase as TestCase;
use Psr\Log\NullLogger;
use Redis;

class ReliableQueueTest extends TestCase
{
   public function testRewindPushesAnyUnfinishedWork()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test.working_on.alice', 1, 2, 3, 4, 5);

        $queue->rewind();

        $this->assertSame(['5', '4', '3', '2'], $this->redis->lRange('queue-worker-test', 0, 5));
    }

    public function testWorkerIsAlwaysValid()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');

        $this->assertTrue($queue->valid());
    }

    public function testRewindPullsTheFirstUnfinishedPieceOfWork()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testRewindSetsTheKeyToTheQueueName()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('queue-worker-test', $queue->key());
    }

    public function testRewindPullsTheFirstPieceOfWorkIfNoneUnfinished()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1, 2);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testNextSetsCurrentToPoppedWork()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertEquals('2', $queue->current());
    }

    public function testNextFinishesWorkAndStoresCurrentInWorkingOn()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertSame(['2'], $this->redis->lRange('queue-worker-test.working_on.alice', 0, -1));
    }

    public function testEnqueueingWork()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $queue[] = 'foo';
        $queue[] = 'bar';

        $this->assertSame(['bar', 'foo'], $this->redis->lRange('queue-worker-test', 0, -1));
    }

    public function testAccessingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1, 2, 3);

        $this->assertEquals('2', $queue[1]);
    }

    public function testAccessingMissingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1);

        $this->assertNull($queue[1]);
    }

    public function testSettingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1, 2);
        $queue[1] = 'foo';

        $this->assertSame(['2', 'foo'], $this->redis->lRange('queue-worker-test', 0, -1));
    }

    public function testUnsettingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'queue-worker-test');
        $this->redis->lPush('queue-worker-test', 1, 3, 2, 3, 2, 3, 1);
        unset($queue[3]);

        $this->assertSame(['1', '3', '2', '2', '3', '1'], $this->redis->lRange('queue-worker-test', 0, -1));
    }

    public function setUp()
    {
        $this->logger = new NullLogger();
        $this->redis = new Redis();
        $this->redis->connect('localhost');
    }

    public function tearDown()
    {
        $this->redis->flushdb();
    }

    private function buildReliableQueue($name, $queue)
    {
        return new ReliableQueue($name, $queue, $this->redis, $this->logger);
    }
}

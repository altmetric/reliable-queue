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
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1, 2, 3, 4, 5);

        $queue->rewind();

        $this->assertSame(array('5', '4', '3', '2'), $this->redis->lRange('reliable-queue-test', 0, 5));
    }

    public function testWorkerIsAlwaysValid()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');

        $this->assertTrue($queue->valid());
    }

    public function testRewindPullsTheFirstUnfinishedPieceOfWork()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testRewindSetsTheKeyToTheQueueName()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('reliable-queue-test', $queue->key());
    }

    public function testRewindPullsTheFirstPieceOfWorkIfNoneUnfinished()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testNextSetsCurrentToPoppedWork()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertEquals('2', $queue->current());
    }

    public function testNextFinishesWorkAndStoresCurrentInWorkingOn()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertSame(array('2'), $this->redis->lRange('reliable-queue-test.working_on.alice', 0, -1));
    }

    public function testEnqueueingWork()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $queue[] = 'foo';
        $queue[] = 'bar';

        $this->assertSame(array('bar', 'foo'), $this->redis->lRange('reliable-queue-test', 0, -1));
    }

    public function testAccessingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2, 3);

        $this->assertEquals('2', $queue[1]);
    }

    public function testAccessingMissingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1);

        $this->assertNull($queue[1]);
    }

    public function testSettingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 2);
        $queue[1] = 'foo';

        $this->assertSame(array('2', 'foo'), $this->redis->lRange('reliable-queue-test', 0, -1));
    }

    public function testUnsettingWorkByOffset()
    {
        $queue = $this->buildReliableQueue('alice', 'reliable-queue-test');
        $this->redis->lPush('reliable-queue-test', 1, 3, 2, 3, 2, 3, 1);
        unset($queue[3]);

        $this->assertSame(array('1', '3', '2', '2', '3', '1'), $this->redis->lRange('reliable-queue-test', 0, -1));
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

    private function buildReliableQueue($name, $queue)
    {
        return new ReliableQueue($name, $queue, $this->redis, $this->logger);
    }
}

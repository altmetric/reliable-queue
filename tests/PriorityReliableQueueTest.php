<?php
namespace Altmetric;

use Altmetric\ReliableQueue;
use PHPUnit_Framework_TestCase as TestCase;
use Psr\Log\NullLogger;
use Redis;

class PriorityReliableQueueTest extends TestCase
{
    public function testWorkerIsAlwaysValid()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));

        $this->assertTrue($queue->valid());
    }

   public function testRewindPushesAnyUnfinishedWork()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test'));
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1, 2, 3, 4, 5);

        $queue->rewind();

        $this->assertSame(array('5', '4', '3', '2'), $this->redis->lRange('reliable-queue-test', 0, 5));
    }

   public function testRewindPushesAnyUnfinishedWorkForAllQueues()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test-2.working_on.alice', 1, 2, 3, 4, 5);

        $queue->rewind();

        $this->assertSame(array('5', '4', '3', '2'), $this->redis->lRange('reliable-queue-test-2', 0, 5));
    }

    public function testRewindPullsTheFirstUnfinishedPieceOfWork()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testRewindPullsTheFirstUnfinishedPieceOfWorkFromAllQueues()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test-2.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testRewindSetsTheKeyToTheQueueName()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('reliable-queue-test', $queue->key());
    }

    public function testRewindSetsTheKeyToTheQueueNameForOtherQueues()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test-2.working_on.alice', 1);

        $queue->rewind();

        $this->assertEquals('reliable-queue-test-2', $queue->key());
    }

    public function testRewindPullsTheFirstPieceOfWorkIfNoneUnfinished()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test', 1, 2);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testRewindPullsTheFirstPieceOfWorkFromAnyQueueIfNoneUnfinished()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test-2', 1, 2);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testRewindPullsTheFirstPieceOfWorkFromFirstQueueBeforeSecond()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test', 1, 2);
        $this->redis->lPush('reliable-queue-test-2', 3, 4);

        $queue->rewind();

        $this->assertEquals('1', $queue->current());
    }

    public function testNextSetsCurrentToPoppedWork()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertEquals('2', $queue->current());
    }

    public function testNextSetsCurrentToPoppedWorkFromAnyQueue()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test-2', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertEquals('2', $queue->current());
    }

    public function testNextSetsCurrentToPoppedWorkFromFirstQueueBeforeSecond()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test', 1, 2);
        $this->redis->lPush('reliable-queue-test-2', 3, 4);

        $queue->rewind();
        $queue->next();

        $this->assertEquals('2', $queue->current());
    }

    public function testNextOnlyPullsFromSecondQueueOnceFirstIsEmpty()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test', 1, 2);
        $this->redis->lPush('reliable-queue-test-2', 3, 4);

        $queue->rewind();
        $queue->next();
        $queue->next();

        $this->assertEquals('3', $queue->current());
    }

    public function testNextFinishesWorkAndStoresCurrentInWorkingOn()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertSame(array('2'), $this->redis->lRange('reliable-queue-test.working_on.alice', 0, -1));
    }

    public function testNextFinishesWorkAndStoresCurrentInWorkingOnForAnyQueue()
    {
        $queue = $this->buildPriorityReliableQueue('alice', array('reliable-queue-test', 'reliable-queue-test-2'));
        $this->redis->lPush('reliable-queue-test-2', 1, 2);

        $queue->rewind();
        $queue->next();

        $this->assertSame(array('2'), $this->redis->lRange('reliable-queue-test-2.working_on.alice', 0, -1));
    }

    public function setUp()
    {
        $this->logger = new NullLogger();
        $this->redis = new Redis();
        $this->redis->connect('localhost');
    }

    public function tearDown()
    {
        $this->redis->del('reliable-queue-test', 'reliable-queue-test-2', 'reliable-queue-test.working_on.alice', 'reliable-queue-test-2.working_on.alice');
    }

    private function buildPriorityReliableQueue($name, array $queues)
    {
        return new PriorityReliableQueue($name, $queues, $this->redis, $this->logger);
    }
}

<?php
namespace Altmetric;

use Redis;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ChunkedReliableQueue implements \Iterator
{
    public $name;
    public $queue;
    public $size;
    public $workingQueue;
    private $reliableQueue;
    private $redis;
    private $logger;
    private $value;

    public function __construct($name, $size, $queue, Redis $redis, LoggerInterface $logger = null)
    {
        $this->name = $name;
        $this->size = $size;
        $this->queue = $queue;
        $this->redis = $redis;
        $this->workingQueue = "{$queue}.working_on.{$name}";
        $this->reliableQueue = new ReliableQueue($name, $queue, $redis, $logger);

        if ($logger === null) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }
    }

    public function rewind()
    {
        $this->reliableQueue->rewind();
        $this->fetchNewWork();
    }

    public function valid()
    {
        return true;
    }

    public function current()
    {
        return $this->value;
    }

    public function key()
    {
        return $this->queue;
    }

    public function next()
    {
        $this->finishCurrentWork();
        $this->reliableQueue->next();
        $this->fetchNewWork();
    }

    private function finishCurrentWork()
    {
        $pipeline = $this->redis->multi();

        foreach ($this->current() as $item) {
            $pipeline->lRem($this->workingQueue, $item, 0);
        }

        $pipeline->exec();
    }

    private function fetchNewWork()
    {
        $reply = $this->reliableQueue->current();
        $replies = $this->eagerlyFetchWork();
        array_unshift($replies, $reply);

        $this->value = $replies;
    }

    private function eagerlyFetchWork()
    {
        $replies = array();
        $pipeline = $this->redis->multi();

        for ($i = 1; $i < $this->size; $i += 1) {
            $pipeline->rPopLPush($this->queue, $this->workingQueue);
        }

        foreach ($pipeline->exec() as $reply) {
            if ($reply === false) {
                break;
            }

            $replies[] = $reply;
        }

        return $replies;
    }
}

<?php
namespace Altmetric;

use Redis;
use Psr\Log\LoggerInterface;

class ChunkedReliableQueue implements \Iterator
{
    public $name;
    public $queue;
    public $size;
    public $workingQueue;
    private $redis;
    private $logger;
    private $value;

    public function __construct($name, $size, $queue, Redis $redis, LoggerInterface $logger)
    {
        $this->name = $name;
        $this->size = $size;
        $this->queue = $queue;
        $this->redis = $redis;
        $this->logger = $logger;
        $this->workingQueue = "{$queue}.working_on.{$name}";
    }

    public function rewind()
    {
        while ($reply = $this->redis->rPopLPush($this->workingQueue, $this->queue)) {
            $this->logger->debug("Pushed unfinished work from {$this->workingQueue} to {$this->queue}: {$reply}");
        }

        $this->logger->debug("Popping work from {$this->queue}");
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
        while (true) {
            $reply = $this->redis->bRPopLPush($this->queue, $this->workingQueue, 30);
            if ($reply) {
                $replies = $this->eagerlyFetchWork();
                array_unshift($replies, $reply);

                $this->value = $replies;
                break;
            }

            $this->logger->debug("Timeout waiting for new work from {$this->queue}, trying again");
        }
    }

    private function eagerlyFetchWork()
    {
        $replies = [];
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

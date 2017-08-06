<?php
namespace Altmetric;

use Redis;
use Psr\Log\LoggerInterface;

class PriorityReliableQueue implements \Iterator
{
    public $name;
    public $queues;
    private $redis;
    private $logger;
    private $value;
    private $queue;
    private $workingQueue;

    public function __construct($name, array $queues, Redis $redis, LoggerInterface $logger)
    {
        $this->name = $name;
        $this->redis = $redis;
        $this->logger = $logger;

        foreach ($queues as $queue) {
            $this->queues[$queue] = "{$queue}.working_on.{$name}";
        }
    }

    public function rewind()
    {
        foreach ($this->queues as $queue => $workingQueue) {
            while ($reply = $this->redis->rPopLPush($workingQueue, $queue)) {
                $this->logger->debug("Pushed unfinished work from {$workingQueue} to {$queue}: {$reply}");
            }
        }

        $this->logger->debug('Popping work from queues: ' . implode(', ', $this->queues));
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
        $workingQueue = $this->currentWorkingQueue();
        $numberRemoved = $this->redis->lRem($workingQueue, $this->current(), 0);
        if ($numberRemoved) {
            $this->logger->debug("Removed {$numberRemoved} finished item(s) from {$workingQueue}");
        }
    }

    private function fetchNewWork()
    {
        while (true) {
            foreach ($this->queues as $queue => $workingQueue) {
                $reply = $this->redis->rPopLPush($queue, $workingQueue);
                if ($reply) {
                    $this->value = $reply;
                    $this->queue = $queue;
                    $this->workingQueue = $workingQueue;
                    break 2;
                }
            }

            $this->logger->debug('No work in queues ' . implode(', ', $this->queues) . ', trying again');
        }
    }

    private function currentWorkingQueue()
    {
        return $this->workingQueue;
    }
}


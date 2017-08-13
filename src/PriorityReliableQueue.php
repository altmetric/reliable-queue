<?php
namespace Altmetric;

use Redis;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PriorityReliableQueue implements \Iterator
{
    public $name;
    public $queues;
    private $redis;
    private $logger;
    private $value;
    private $weightedQueues;
    private $workingQueues;
    private $workingQueue;

    public function __construct($name, array $queues, Redis $redis, LoggerInterface $logger = null)
    {
        $this->name = $name;
        $this->queues = $queues;
        $this->redis = $redis;

        if ($logger === null) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }

        $numberOfQueues = count($queues);
        foreach ($queues as $index => $queue) {
            $this->workingQueues[$queue] = "{$queue}.working_on.{$name}";

            /*
             * Fill weighted queues with duplicate queues proportional to their
             * priority, e.g. critical, default, low will produce the following
             * array:
             *
             *     Array
             *     (
             *         [0] => critical
             *         [1] => critical
             *         [2] => critical
             *         [3] => default
             *         [4] => default
             *         [5] => low
             *     )
             */
            $n = $numberOfQueues - $index;
            for ($i = 0; $i < $n; $i++) {
                $this->weightedQueues[] = $queue;
            }
        }
    }

    public function rewind()
    {
        foreach ($this->workingQueues as $queue => $workingQueue) {
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
            $randomQueue = mt_rand(0, count($this->weightedQueues) - 1);
            $queue = $this->weightedQueues[$randomQueue];
            $workingQueue = $this->workingQueues[$queue];

            $reply = $this->redis->bRPopLPush($queue, $workingQueue, 1);
            if ($reply) {
                $this->value = $reply;
                $this->queue = $queue;
                $this->workingQueue = $workingQueue;
                break;
            }

            $this->logger->debug('No work in queues ' . implode(', ', $this->queues) . ', trying again');
        }
    }

    private function currentWorkingQueue()
    {
        return $this->workingQueue;
    }
}


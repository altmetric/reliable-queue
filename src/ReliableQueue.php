<?php
namespace Altmetric;

use Redis;
use Psr\Log\LoggerInterface;

class ReliableQueue implements \Iterator
{
    public $name;
    public $queue;
    public $workingQueue;
    private $redis;
    private $logger;
    private $value;

    public function __construct($name, $queue, Redis $redis, LoggerInterface $logger)
    {
        $this->name = $name;
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
        $numberRemoved = $this->redis->lRem($this->workingQueue, $this->current(), 0);
        if ($numberRemoved) {
            $this->logger->debug("Removed {$numberRemoved} finished item(s) from {$this->workingQueue}");
        }
    }

    private function fetchNewWork()
    {
        while (true) {
            $reply = $this->redis->bRPopLPush($this->queue, $this->workingQueue, 30);
            if ($reply) {
                $this->value = $reply;
                break;
            }

            $this->logger->debug("Timeout waiting for new work from {$this->queue}, trying again");
        }
    }
}

<?php
namespace Altmetric;

use Redis;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ReliableQueue implements \Iterator, \ArrayAccess
{
    public $name;
    public $queue;
    public $workingQueue;
    private $redis;
    private $logger;
    private $value;

    public function __construct($name, $queue, Redis $redis, LoggerInterface $logger = null)
    {
        $this->name = $name;
        $this->queue = $queue;
        $this->redis = $redis;
        $this->workingQueue = "{$queue}.working_on.{$name}";

        if ($logger === null) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }
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

    public function offsetExists($offset)
    {
        return $this->offsetGet($offset) !== null;
    }

    public function offsetGet($offset)
    {
        $value = $this->redis->lIndex($this->queue, $offset);
        $value = $value === false ? null : $value;

        return $value;
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->redis->lPush($this->queue, $value);
        } else {
            $this->redis->lSet($this->queue, $offset, $value);
        }
    }

    public function offsetUnset($offset)
    {
        $tempName = uniqid('altmetric/reliable-queue-deletion-');
        $this
            ->redis
            ->multi()
            ->lSet($this->queue, $offset, $tempName)
            ->lRem($this->queue, $tempName, 1)
            ->exec();
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

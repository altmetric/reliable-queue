# Reliable Queue [![Build Status](https://travis-ci.org/altmetric/reliable-queue.svg?branch=master)](https://travis-ci.org/altmetric/reliable-queue)

A PHP library for reliable queueing backed by [Redis](http://redis.io/).

**Current version:** 0.3.0  
**Supported PHP versions:** 5.4, 5.5, 5.6, 7

## Installation

```shell
$ composer require altmetric/reliable-queue
```

## Usage

```php
<?php
use Altmetric\ReliableQueue;
use Altmetric\ChunkedReliableQueue;

$queue = new ReliableQueue('unique-worker-name', 'to-do-queue', $redis, $logger);
$queue[] = 'some-work';
$queue[] = 'some-more-work';

foreach ($queue as $work) {
    // Perform some action on each piece of work in the to-do-queue
}

$queue = new ChunkedReliableQueue('unique-worker-name', 100, 'to-do-queue', $redis, $logger);

foreach ($queue as $chunk) {
    // $chunk will be an array of up to 100 pieces of work
}
```

## API Documentation

### `public ReliableQueue::__construct(string $name, string $queue, Redis $redis, LoggerInterface $logger)`

```php
$queue = new \Altmetric\ReliableQueue('unique-worker-name', 'to-do-queue', $redis, $logger);
```

Instantiate a new reliable queue object with the following arguments:

* `$name`: a unique `string` name for this worker so that we can pick up any
  unfinished work in the event of a crash;
* `$queue`: the `string` key of the list in Redis to use as the queue;
* `$redis`: a [`Redis`](https://github.com/phpredis/phpredis) client object for
  communication with Redis;
* `$logger`: a
  [`Psr\Log\LoggerInterface`](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md)-compliant
  logger.

The returned object implements both the
[`Iterator`](http://php.net/manual/en/class.iterator.php) (and therefore
[`Traversable`](http://php.net/manual/en/class.traversable.php)) and
[`ArrayAccess`](http://php.net/manual/en/class.arrayaccess.php) interface in
PHP.

This means that it can be iterated over with `foreach`, yielding the queue name
and a value on every iteration. Internally, the library will block for new work
but this is invisible from a client's perspective.

```php
foreach ($queue as $key => $work) {
    // $key will be the queue key name in Redis
    // $work will be the value popped from the queue
}
```

You can also modify the queue as if it were an array by using the typical array
operations:

```php
$queue[] = 'work';  // enqueues work
$queue[1];          // returns work at index 1 if it exists
$queue[1] = 'work'; // sets work to index 1 in the queue
unset($queue[1]);   // remove work at index 1 from the queue
```

### `public ChunkedReliableQueue::__construct(string $name, int $size, string $queue, Redis $redis, LoggerInterface $logger)`

```php
$queue = new \Altmetric\ChunkedReliableQueue('unique-worker-name', 100, 'to-do-queue', $redis, $logger);
```

Instantiate a new chunked, reliable queue object with the following arguments:

* `$name`: a unique `string` name for this worker so that we can pick up any
  unfinished work in the event of a crash;
* `$size`: an integer maximum size of chunk to return on each iteration;
* `$queue`: the `string` key of the list in Redis to use as the queue;
* `$redis`: a [`Redis`](https://github.com/phpredis/phpredis) client object for
  communication with Redis;
* `$logger`: a
  [`Psr\Log\LoggerInterface`](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md)-compliant
  logger.

The returned object implements the
[`Iterator`](http://php.net/manual/en/class.iterator.php) (and therefore
[`Traversable`](http://php.net/manual/en/class.traversable.php)) interface in
PHP.

This means that it can be iterated over with `foreach`, yielding the queue name
and an array of up to `$size` elements on every iteration. Internally, the
library will block for new work but this is invisible from a client's
perspective.

If the queue contains sufficient items, the chunk of work will contain at most
`$size` elements but if there is not enough work, it may return less (but
always at least 1 value).

## References

* [Pattern: Reliable queue](http://redis.io/commands/rpoplpush#pattern-reliable-queue)

## License

Copyright © 2016 Altmetric LLP

Distributed under the MIT License.

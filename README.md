# Reliable Queue [![Build Status](https://travis-ci.org/altmetric/reliable-queue.svg?branch=master)](https://travis-ci.org/altmetric/reliable-queue)

A PHP library for reliable queueing backed by [Redis](http://redis.io/).

**Current version:** 0.1.0  
**Supported PHP versions:** 5.4, 5.5, 5.6, 7

## Installation

```shell
$ composer require altmetric/reliable-queue
```

## Usage

```php
<?php
use Altmetric\ReliableQueue;

$queue = new ReliableQueue('unique-worker-name', 'to-do-queue', $redis, $logger);

foreach ($queue as $work) {
    // Perform some action that has been pushed to to-do-queue
}
```

## References

* [Pattern: Reliable queue](http://redis.io/commands/rpoplpush#pattern-reliable-queue)

## License

Copyright © 2016 Altmetric LLP

Distributed under the MIT License.

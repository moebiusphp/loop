Co\Loop
=======

A rock-solid event loop implementation for PHP, focused on 
interoperability. Works with stock PHP, but supports the Ev
extension for higher connection counts. Can also run on top of the
React, Amp or Revolt event-loops.

```
<?php
require("vendor/autoload.php");

Co\Loop::defer(function() {
    echo "This callback was deferred\n";
});

// Promise oriented
Co\Loop::delay(1.5)->then(function() {
    echo "This callback was delayed by 1.5 seconds\n";
});
```

"Promise Oriented"?
-------------------

While most event-loop implementations are callback oriented, this
event loop uses promises to notify about events occurring.

This approach provides a more predicatable way to work with events,
and is adaptable to any existing backend implementation. Promises
are also well supported in existing frameworks.


Reference
---------

The most important function to create asynchronous code, is the
`Co\Loop::defer()` function. This function schedules a callable
to be invoked on the next loop iteration and is the base for all
other event-loop functionality.

 * `Co\Loop::defer(callable $handler): void` schedules an event to
   be invoked on the next iteration of the loop.

 * `Co\Loop::readable(resource $stream): PromiseInterface` returs
   a promise which will be resolved when reading from the `$stream`
   will not block.

 * `Co\Loop::writable(resource $stream): PromiseInterface` returns
   a promise which will be resolved when writing to the `$stream`
   will not block.

 * `Co\Loop::delay(float $time): PromiseInterface` returns a
   promise which will be resolved when `$time` seconds have passed.

 * `Co\Loop::signal(int $signal): PromiseInterface` returns a
   promise which will be resolved when signal number `$signal` is
   received.

 * `Co\Loop::maxDelay(float $time): void` lets you notify the
   event loop how long time can be spent idle. 

To cancel listening for an event, you can simply resolve the promise
yourself by calling `$promise->reject()`.

> This package is part of Moebius, a coroutine library for PHP.

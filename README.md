Co\Loop
=======

A rock-solid event loop implementation for PHP, focused on 
interoperability.

This event loop runs on top of other event loops and allows 
you to write interoperable applications that work on top of
various event loops without modifications.

The event loop is designed so that it can be used in existing
applications to resolve promises that you are controlling -
similar to the way GuzzleHttp has an internal event loop for
enabling concurrent HTTP requests.


API
---

`Co\Loop::defer(callable $callback, float $delay=0)` will
schedule callback to be executed next, or after a predetermined
delay.

`Co\Loop::queueMicrotask(callable $callback)` will schedule
a callback to be executed as soon as possible - before any
deferred callbacks.

`new Co\Loop\Readable(resource $fd): Promise` will create a
watcher promise which will be fulfilled every time the resource
becomes readable.

`new Co\Loop\Writable(resource $fd): Promise` will create a
watcher promise which will be fulfilled every time the resource
becomes readable.

`new Co\Loop\Signal(int $signum): Promise` will create a
watcher promise which will be fulfilled every time the signal
is received by the process.


Reusable watcher promises
-------------------------

Watcher promises behave the same way as normal promises, except
they can be listened to many times. To restart listening to
a promise, you must call `$promise->then(callable $listener)`
again.


Example
-------

```
<?php
    require("vendor/autoload.php");

    Co\Loop::defer(function() {
        echo "This callback was deferred\n";
    });

    Co\Loop::defer(function() {
        echo "This is 0.5 seconds later\n";
    }, 0.5);

    $fp = fopen(__FILE__, 'rn');

    // Watchers are promises that can be reused

    $readable = new Co\Loop\Readable($fp);
    $readable->then(function($fp) {
        return fgets($fp);
    })->then(function($line) {
        echo "Got line '".trim($line)."'\n";
    });



```

Promise Oriented
----------------

Every event loop implementation does their things in a different
way. React even does things differently accross features.

To be able to provide a consistent and future proof API for
listening for events, we've chosen to implement all events as
promises. This means that if you're waiting for a resource to
become readable, you'll receive a Promise object. If you are
handling a posix signal, you'll receive a promise object.

The only API which does not use promises is the `Co\Loop::defer()`
and `Co\Loop::queueMicrotask()` functions, since these are
neccesary to be able to resolve promises and poll streams for
events.

To cancel an event listener, you can use `$promise->cancel()` -
which will cancel the event for all handlers - or you can use
`$promise->reject()` if you only want to cancel the event for
yourself.


API reference
-------------

The most important function to create asynchronous code, is the
`Co\Loop::defer()` function. This function schedules a callable
to be invoked on the next loop iteration and is the base for all
other event-loop functionality.

 * `Co\Loop::defer(callable $handler): void` schedules a callback to
   be invoked on the next iteration of the loop.

 * `Co\Loop::queueMicrotask(callable $handler): void` schedules a
   callback to be invoked immediately **inside the current tick
   cycle**. This function is normally not used, but is useful for
   ensuring the correct execution order for event handlers.

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

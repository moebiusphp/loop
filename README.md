Co\Loop
=======

An event loop focused on interoperability between the most
popular event loops for PHP:

 * The Ev PECL extension
 * React
 * Amp
 * `stream_select()` built-in PHP combined with
   `register_shutdown_function()`.

The API is identical across all backend drivers, and ensures
that your code will work together with legacy libraries
designed for either Amp or React.

This event loop is part of Moebius, a coroutine framework for
PHP 8.1. With Moebius you will be able to use coroutines in
your existing projects - even if they don't use an event loop
today.


The entire API
--------------

`Co\Loop::defer(callable $callback)` will schedule callback
to be executed next.

`Co\Loop::queueMicrotask(callable $callback)` will schedule
a callback to be executed as soon as possible - before any
deferred callbacks.

`Co\Loop::delay(float $time, callable $callback): EventHandle`
will schedule a callback to be executed later.

`Co\Loop::readable(resource $fd, callable $callback): EventHandle`
will schedule a callback to be executed whenever `$fd` becomes
readable.

`Co\Loop::writable(resource $fd, callable $callback): EventHandle`
will schedule a callback to be executed whenever `$fd` becomes
writable.

`Co\Loop::signal(int $signum, callable $callback): EventHandle`
will schedule a callback to be executed whenever the application
receives a signal.

`Co\Loop::run(callable $keepRunningFunc=null): void` will run
the event loop until the `$keepRunningFunc` returns false or
the event loop is empty.


EventHandle class
-----------------

Some methods return an EventHandle object. This object can be
used to suspend and resume the event listener, or you can
cancel it.

To cancel an event listener:

```
$eventHandle->cancel();
```

To suspend an event listener (does not work for delay-events):

```
$eventHandle->suspend();
```

To resume a suspended event listener:

```
$eventHandle->resume();
```


Promise-based API
-----------------

You can listen for events using a Promise-based API. For example
you can listen for signals using the `Co\Loop\Signal` class:

```
$signal = new Co\Loop\Signal(SIGTERM);
$signal->then(function() {
    echo "We received a SIGTERM signal\n";
});
```

Promise classes exists for Readable, Writable, Signal, Delay and
Interval.


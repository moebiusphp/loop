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

TLRD
----

A primitive example to illustrate how to write asynchronous
code with `Co\Loop`.

```
<?php
require('vendor/autoload.php');

/**
 * This function returns a Promise about something that will
 * be available eventually. It will immediately return a Promise
 * object which is a *promise about a future value*.
 */
function read_file(string $filename) {
    return new Co\Promise(function($ready, $failure) use ($filename) {
        // Open the file in read non-blocking mode
        $fp = fopen($filename, 'rn');

        // Wait for the file to become readable
        Co\Loop::readable($fp, function($fp) use ($ready) {

            // Call the $ready callback with the value
            $ready(stream_get_contents($fp));

            // Close the file
            fclose($fp);

        });
    });    
}

/**
 * Now we will read two files in parallel using our above function.
 */
$file1 = read_file('file-1.txt');
$file2 = read_file('file-2.txt');

/**
 * ALTERNATIVE 1
 * 
 * The traditional way of waiting for results from a promise is via 
 * the "then" method. This can lead to the well known "callback hell".
 */
$file1->then(function($contents) {
    echo "FILE 1: ".$contents."\n\n";
}, function($error) {
    echo "Failed to read file 1\n";
});
$file2->then(function($contents) {
    echo "FILE 2: ".$contents."\n\n";
}, function($error) {
    echo "Failed to read file 1\n";
});


/**
 * ALTERNATIVE 2
 *
 * A much easier approach to waiting for promises is to use the
 * `Co\Loop::await()` function. It will block your application,
 * while allowing promises to run - until the promise is fulfilled
 * or rejected.
 */
echo "FILE 1: ".Co\Loop::await($file1);
echo "FILE 2: ".Co\Loop::await($file2);
```


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


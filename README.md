Moebius\Loop
============

An event-loop API for writing asynchronous code in traditional
PHP code, while also working seamlessly with common asynchronous
frameworks like React or Amp.

Moebius Loop provides an elegant and consistent API for working
with non-blocking I/O which works equally well in classic
synchronous PHP code as with fully asynchronous frameworks
like React or Amp.

Laravel example
---------------

Moebius\Loop can be used with most frameworks such as Laravel
or Symfony. The only challenge with using asynchronous code
with these frameworks, is that the framework will not wait
for your promises to finish.

The solution is to use the `Moebius\Loop::await($promise)` 
function. As long as all the code that is asynchronous is
written to use `Moebius\Loop` directly, or written for either
React or Amp - then you can use `Moebius\Loop` to resolve the
promise.

```php
<?php
namespace App\Http\Controllers;
 
use Moebius\Loop;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Profile;
 
class UserController extends Controller
{
    public function show($id)
    {
        /**
         * Go get a performance boost:
         *
         * We MUST use async functions which return a promise,
         * and internally use the event-loop API to wait for
         * IO resources to become readable or writable.
         */
        $user = User::asyncFindOrFail($id);
        $profile = Profile::asyncFindOrFail($id);

        /**
         * Also we SHOULD send off as many async calls as possible
         * before using the `Loop::await()` function to resolve
         * these promises.
         *
         * Both promises we created above will be running while you
         * await the `$user` promise. The `$profile` promise may
         * even finish first - but that does not matter below:
         */
        return view('user.profile', [
            'user' => Loop::await($user),
            'profile' => Loop::await($profile),
        ]);
    }
}
```

As you can see, it is easy to use asynchronous code within any
framework.


API reference
-------------

### `Moebius\Loop::getTime()`

Get the current event loop time. This is a time indicating a number
of seconds from an arbitrary point in time.

### `Moebius\Loop::await(object $promise, float $timeout=null): mixed`

Run the event loop until the promise resolves or the timeout is
reached.

### `Moebius\Loop::run(Closure $shouldResumeFunc=null): void`

Run the event loop until `Moebius\Loop::stop()` is called. If a
callback is provided, the loop will keep running until the callback
returns a falsey value.

### `Moebius\Loop::delay(float $time, Closure $callback): Closure`

Run the callback after `$time` seconds have passed. The returned
callback will abort the timer.

### `Moebius\Loop::interval(float $interval, Closure $callback): Closure`

Run the callback after `$interval` seconds have passed, and keep
repeating the callback every `$interval` second. The returned callback
will abort the interval.

### `Moebius\Loop::readable(resource $resource, Closure $callback): Closure`

Run the callback on every tick as long as reading from the stream
resource will not block. This can also be used to accept new connections
on a server socket. The returned callback will abort watching the stream.

### `Moebius\Loop::writable(resource $resource, Closure $callback): Closure`

Run the callback on every tick as long as writing to the stream resource
will not block. The returned callback will abort watching the stream.

### `Moebius\Loop::read(resource $resource, Closure $callback): Closure`

Read a chunk of data from the stream resource and call the `$callback`
with the chunk of data that was read. This function will stop as soon
as EOF is called, unless the returned callback is called to abort the
read operation first.

### `Moebius\Loop::signal(int $signalNumber, Closure $callback): Closure`

Run the callback whenever a signal is received by the process. The
callback will continue to be called every time the signal is received,
until the returned callback is invoked.


Example
-------

A primitive example to illustrate how to write asynchronous
code with `Moebius\Loop`.

> This code will run with both Amp and React, and if you are
> not using one of these in a more classic environment such
> as Laravel or Symfony.

```php
<?php
require('vendor/autoload.php');

/**
 * This function returns a Promise about something that will
 * be available eventually. It will immediately return a Promise
 * object which is a *promise about a future value*.
 */
function async_read_file(string $filename) {
    return new Moebius\Promise(function($ready, $failure) use ($filename) {
        // Open the file in read non-blocking mode
        $fp = fopen($filename, 'rn');

        // Wait for the file to become readable
        Moebius\Loop::readable($fp, function($fp) use ($ready) {

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
$file1 = async_read_file('file-1.txt');
$file2 = async_read_file('file-2.txt');

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
 * `Moebius\Loop::await()` function. It will block your application,
 * while allowing promises to run - until the promise is fulfilled
 * or rejected.
 */
echo "FILE 1: ".Moebius\Loop::await($file1);
echo "FILE 2: ".Moebius\Loop::await($file2);
```


The entire API
--------------

`Moebius\Loop::defer(callable $callback)` will schedule callback
to be executed next.

`Moebius\Loop::queueMicrotask(callable $callback)` will schedule
a callback to be executed as soon as possible - before any
deferred callbacks.

`Moebius\Loop::delay(float $time, callable $callback): EventHandle`
will schedule a callback to be executed later.

`Moebius\Loop::readable(resource $fd, callable $callback): EventHandle`
will schedule a callback to be executed whenever `$fd` becomes
readable.

`Moebius\Loop::writable(resource $fd, callable $callback): EventHandle`
will schedule a callback to be executed whenever `$fd` becomes
writable.

`Moebius\Loop::signal(int $signum, callable $callback): EventHandle`
will schedule a callback to be executed whenever the application
receives a signal.

`Moebius\Loop::run(callable $keepRunningFunc=null): void` will run
the event loop until the `$keepRunningFunc` returns false or
the event loop is empty.


EventHandle class
-----------------

When subscribing to events (IO, timer, interval or signal) you
will receive an EventHandle class. This handle can be used to
suspend or cancel the event listener.

Example:

```php
    $readable = Moebius\Loop::readable($fp, function($fp) {
        // read stream
    });

    // Disable listening for events
    $readable->suspend();

    // Enable listening for events
    $readable->resume();

    // Cancel listening for events (can't be resumed)
    $readable->cancel();
```


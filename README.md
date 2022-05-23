Moebius\Loop
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

*COMING*

We are working on our Moebius framework, which will make all your
existing code asynchronous automatically using PHP 8.1 Fibers. 

You'll simply be calling `Moebius\Loop::async(User::findOrFail($id))`
in the above example. A working prototype is available at
https://packagist.org/packages/moebius/coroutine

```php
    use Moebius\Coroutine as Co;

    public function show($id)
    {
        $user = Co::go(User::findOrFail(...), $id);
        $profile = Co::go(User::findOrFail(...), $id);

        return view('user.profile', [
            'user' => Co::await($user),
            'profile' => Co::await($profile)
        ]);
    }
```

TLRD
----

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
you can listen for signals using the `Moebius\Loop\Signal` class:

```
$signal = new Moebius\Loop\Signal(SIGTERM);
$signal->then(function() {
    echo "We received a SIGTERM signal\n";
});
```

Promise classes exists for Readable, Writable, Signal, Delay and
Interval.


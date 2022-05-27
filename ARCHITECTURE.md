# moebius/loop

Provides a single API to write interoperable asynchronous applications
in PHP. By using moebius/loop your code will work asynchronously with
both Amp, React and Swoole - or without any of those frameworks installed.


## The run-cycle

An event loop is a simple loop which approximately follows the following
semantics:

 1. If there are any poll tasks, run all the poll tasks.

 2. Queue any timers that have expired. If no timers have expired,
    check how much time can be spent in IO polling.

 3. Perform IO polling.

 4. Run all queued tasks.

 5. If there are queued timers, poll tasks or IO streams being watched,
    continue at step 1.


The event loop is scheduled to start by using the PHP function 
`register_shutdown_function()` - so that it is given an opportunity to
run any tasks when the normal run of the application terminates.

It is also possible to run the event loop at any time by calling the
`Moebius\Loop::run()` function.


### Blocking event loop

If the entire application is waiting on external events such as process
signals or I/O events, the event loop should yield CPU-time to the operating
system by sleeping.

The loop has two modes:

 1. Blocking mode means that no CPU-time is yielded. This is the mode when
    there exists tasks in the defer queue or in the microtask queue.

 2. If there are no pending jobs in the defer queue or in the microtask queue,
    the application can sleep for up to 0.1 seconds before running another
    iteration.


## Scheduling callbacks

Callbacks can be scheduled in a variety of ways, depending on your
use case. There are two primary ways to schedule callbacks:

 1. Immediate callbacks, which will run as soon as possible.

 2. Event callbacks, which will run based on some external
    circumstance such as a network event, a filesystem event or
    a process signal.


### `Loop::defer(Closure $callback)`

Add a callback to the normal callback queue for the NEXT iteration
of the event loop.


### `Loop::queueMicrotask(Closure $callback, mixed $argument=null)

Add a callback to the microtask queue. Microtasks are run as soon
as the currently executing callback is running.

Microtasks can be queued with an optional argument. The primary
purpose of this queue is to run event listeners (such as promise
subscribers).


### `Loop::deadline(string $name, ?float $deadline, ?Closure $callback)`

Schedule a callback to run on the next iteration of the event loop,
after AT MOST `$deadline` seconds.

This facility is intended for implementing event watchers such as timers
and intervals.

The `$name` argument allows the callback to be reconfigured or overwritten
at any time.

The `$deadline` parameter is used to calculate how much CPU time can be
yielded to the application.


## 5 native event types

Moebius\Loop supports 5 native event types. These event types can form the
basis of other event types.

|-------------------|------------------------------------------|--------|
| Event Type        | API method                               | Notes  |
|-------------------|------------------------------------------|--------|
| `Event::READABLE` | `Loop::readable(resource $fp, $callback) |        |
| `Event::WRITABLE` | `Loop::writable(resource $fp, $callback) |        |
| `Event::TIMER`    | `Loop::delay(float $time, $callback)     | [^1]   |
| `Event::INTERVAL` | `Loop::interval(float $time, $callback)  |        |
| `Event::SIGNAL`   | `Loop::signal(int $signum, $callback)    | [^2]   |
|-------------------|------------------------------------------|--------|

[^1]: Timer events can't be suspended, only cancelled. Trying to suspend
    a timer will throw a LogicException.
[^2]: This event type will not keep the event loop running until they are
    removed.


### `Event::READABLE`

On every event loop iteration the callback will be invoked if the stream
resource is determined to be readable.

When there are subscribers to this event type, the application will never
terminate.


### `Event::WRITABLE`

On every event loop iteration the callback will be invoked if the stream
resource is determined to be readable.

When there are subscribers to this event type, the application will never
terminate.


### `Event::TIMER`

The callback will be invoked after `$delay` seconds.

When there are pending timers, the application will never terminate.


### `Event::INTERVAL`

The callback will be invoked after `$interval` seconds, and then invoked
again ever `$interval` seconds.

When there are active interval timers, the application will never terminate
unless the event loop is stopped.


### `Event::SIGNAL`

The callback will be invokoed on the next loop iteration after a process
signal has been received by the process.

This event type will NOT keep the event loop alive.




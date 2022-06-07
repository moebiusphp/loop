# moebius/loop

Moebius Loop is a powerful event-loop API for writing asynchronous applications
in PHP. The API automatically detects if you are running existing event loops such
as ext-ev, React or Amp, and will integrate with those event loops automatically.

It falls back to a pure-PHP based implementation if no other event loop is installed.


## Rationale

Moebius\Loop provides a more capable event-loop implementation for PHP, while retaining
compatability with other event loop implementations:

 * The `Moebius\Loop::queueMicrotask()` API is very useful for some types of events (
    such as promise resolving and event listeners), but it is unfortunately not supported
    by the other event loop implementations for PHP. We manage to simulate it in other 
    event loops by piggybacking on other callbacks.

    See [this guide from Mozilla](https://developer.mozilla.org/en-US/docs/Web/API/HTML_DOM_API/Microtask_guide)
    for information about how to use queueMicrotask() properly. 

 * The `Moebius\Loop::poll()` API allows you to schedule a callback to run immediately
    before asynchronous IO and timers are polled. This is also not supported in other
    event loop implementations - but it is useful if you want to provide an API for
    other event types.


The original purpose of this event loop implementation was to provide a compatability
layer between Moebius Coroutines and existing event loop implementations.

Over time it has become clear that event loops for PHP also are limited in functionality,
so Moebius provides some innovations to the scene.


## Reference

The primary API for library and application authors belongs to the [`Loop`](./Loop.md) class.

 * [`Moebius\Loop::defer(Closure $callback): void`](./Loop/defer.md) enqueues a callback
    to run as part of the normal callback queue.

 * [`Moebius\Loop::queueMicrotask(Closure $callback, $argument=null): void`](./Loop/queueMicrotask.md)
    queues a callback to run immediately *before* the next normal callback.

    > PS: This API is intended for scheduling tasks to run immediately, and as long as new
    > microtasks are being enqueued - no other event types will be scheduled.

 * [`Moebius\Loop::readable(resource $fp, $callback=null): Handle`](./Loop/readable.md)
    registers a callback to run the next time reading from the stream resource `$fp` will
    not block. (It is important to set streams as non-blocking using the PHP function
    `stream_set_blocking($fp, false);`)

 * [`Moebius\Loop::writable(resource $fp, $callback=null): Handle`](./Loop/writable.md)
    registers a callback to run the next time writing to the stream resource `$fp` will
    not block. (It is important to set streams as non-blocking using the PHP function
    `stream_set_blocking($fp, false);`)

 * [`Moebius\Loop::delay(float $time, $callback=null): Handle`](./Loop/delay.md) registers
    a callback to run in `$time` seconds. The callback may be slightly delayed, but should
    not be called earlier.

 * [`Moebius\Loop::getTime(): float`](./Loop/getTime.md) returns a reference time in seconds
    since an arbitrary point in time. The time should be monotonic and ignore any time
    adjustments on the server.

 * [`Moebius\Loop::await(object $promise, float $timeout=null): mixed`](./Loop/await.md)
    will run the event loop until the promise gets resolved, or until `$timeout` seconds
    have passed. The return value is the result from the promise, or if the promise is
    rejected - the function throws the error. If `$timeout` is reached, the return value
    is the promise.

Advanced API for special use-cases. Note that these functions can may affect how your 
application behaves in ways that are harder to debug.

 * [`Moebius\Loop::poll(Closure $callback): void`] registers a callback to run AFTER the
    deferred callbacks (or before any IO-polling occurs). If primary use-case is to perform
    polling of other external event sources which are not directly supported by this API
    (for example if you're using `pcntl_signal()` to listen for signals, you can use this
    API to schedule the `pcntl_signal_dispatch()` method).

    > PS: This API is mapped to `Moebius\Loop::defer()` if you're using `react/event-loop`
    > or `amphp/amp`, since these event-loop implementations does not directly support this.

 * [`Moebius\Loop::run(): void`](./Loop/run.md) starts running the event loop. The function
    returns when there are no more events pending, or when `Moebius\Loop::stop()` is called.

    > PS: This function can easily cause deadlocks in your application if not used with great
    > care. An alternative to running the event loop yourself is to use `Moebius\Loop::await()`.

 * [`Moebius\Loop::stop(): void`](./Loop/stop.md) stops the running event loop. Calling this
    method will cause the event loop to stop processing new deferred callbacks, but it MAY
    run callbacks which are already queued.


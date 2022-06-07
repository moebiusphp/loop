# Loop::defer()

```php
Moebius\Loop::defer(Closure $callback): void
```

The `defer()` method enqueues a task to run as part of the event loop's queue.
The primary purpose of deferring a function is to allow other events to be taken
care of before resuming work.

When the event loop detects a subscribed event, it will enqueue the callback in
the deferred tasks queue.

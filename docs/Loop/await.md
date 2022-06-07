# Loop::await()

```php
Moebius\Loop::await(object $promise, float $timeout=null): mixed
```

The `await()` method will run the event loop until the promise gets resolved, or until `$timeout`
seconds have passed. The return value is the result from the promise, or if the promise is
rejected - the function throws the error. If `$timeout` is reached, the return value is the promise.

This is the preferred way to run events from the event loop *during the synchronous stage* of your
application.

# Moebius\Loop

The `Moebius\Loop` class is the primary API for working with the event loop.

## Public methods and quick examples

### defer()

[Loop::defer(Closure $callback)](./Loop/defer.md)

```php
Moebius\Loop::defer(function() {
    echo "World\n";
});
echo "Hello ";
```


### queueMicrotask()

[Loop::queueMicrotask(Closure $callback, mixed $argument=null)](./Loop/queryMicrotask.md)

```php
Moebius\Loop::queueMicrotask(function() {
    echo "Hello ";
});
Moebius\Loop::defer(function() {
    echo "World\n";
});
```


### readable()

[Loop::readable(resource $fp, Closure $callback=null)](./Loop/readable.md)

```php
$fp = fopen(__FILE__, 'r');
Moebius\Loop::readable($fp, function($fp) {
    echo fread($fp, 65536);
});
```


### writable()

[Loop::writable(resource $fp, Closure $callback=null)](./Loop/writable.md)

```php
$fp = tmpfile();
Moebius\Loop::writable($fp, function($fp) {
    fwrite($fp, "Hello World!\n");
});


### delay()

[Loop::delay(float $time, Closure $callback=null)](./Loop/delay.md)

```php
Moebius\Loop::delay(0.5, function() {
    echo "World!\n";
});
echo "Hello ";
```


### getTime()

[Loop::getTime()](./Loop/getTime.md)

```php
echo Moebius\Loop::getTime(); // 190330.96539925
```


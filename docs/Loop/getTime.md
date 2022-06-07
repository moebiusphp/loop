# Loop::getTime()

```php
Moebius\Loop::getTime(): float
```

This method returns a reference time in seconds since an arbitrary point in time. 
The time should be monotonic and ignore any time adjustments on the server.

The time returned is updated once per cycle. For a more precice time measurement,
you should use the PHP function [`hrtime()`](https://www.php.net/manual/en/function.hrtime.php).

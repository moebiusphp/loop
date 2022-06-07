# Loop::delay()

```php
Moebius\Loop::delay(float $time, $callback=null): Handle
``` 

Schedules a callback to run in `$time` seconds. There is no guarantee that the callback
will be run on the exact time that it is scheduled for, but it will never run sooner.


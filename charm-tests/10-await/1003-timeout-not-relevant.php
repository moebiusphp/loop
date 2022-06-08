<?php
require(__DIR__."/../../vendor/autoload.php");
use Moebius\Loop;

echo "Checking that a timeout does not cause the event loop to run longer than needed\n";

$promise = new Moebius\Deferred();

Loop::delay(0.1, function() use ($promise) {
    $promise->fulfill("done");
});

$t = microtime(true);
$result = Loop::await($promise, 15);
$t = microtime(true) - $t;

echo "Timeout: $t seconds\n";
assert($t >= 0.099, "Timeout before 0.1 seconds");
assert($t <= 0.101. "Timeout after 0.11 seconds");

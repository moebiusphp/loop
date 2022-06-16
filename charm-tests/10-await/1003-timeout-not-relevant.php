<?php
require(__DIR__."/../../vendor/autoload.php");
use Moebius\Loop;

echo "Checking that a timeout does not cause the event loop to run longer than needed\n";

$promise = new Moebius\Deferred();

$t = microtime(true);
Loop::delay(0.1, function() use ($promise, &$t) {
    echo "Delay: ".(microtime(true)-$t)." seconds\n";
    $promise->fulfill("done");
});

$result = Loop::await($promise, 15);
$t = microtime(true) - $t;

echo "Timeout: $t seconds\n";
assert($t >= 0.099, "Timeout before 0.099 seconds (more than 1 ms early)");
assert($t <= 0.101. "Timeout after 0.101 seconds (more than 1 ms late)");

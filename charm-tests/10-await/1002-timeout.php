<?php
require(__DIR__."/../../vendor/autoload.php");
use Moebius\Loop;

$promise = new Moebius\Deferred();

$t = microtime(true);
$result = Loop::await($promise, 0.1);
$t = microtime(true) - $t;

echo "Timeout: $t seconds\n";
assert($t >= 0.099, "Timeout before 0.1 seconds");
assert($t <= 0.101. "Timeout after 0.11 seconds");

<?php
require(__DIR__.'/../vendor/autoload.php');
use Moebius\Loop;

$t = microtime(true);
$a = Loop::delay(0.1, function() {
    echo "C\n";
    global $t;
    assert(microtime(true) - $t > 0.1, "Timers executed too fast");
});
$b = Loop::delay(0.05, function() {
    echo "B";
    global $t;
    assert(microtime(true) - $t > 0.05, "Timers executed too fast");
});
$c = Loop::defer(function() {
    echo "A";
});

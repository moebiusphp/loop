<?php
use Moebius\Loop;

$t = microtime(true);
$a = Loop::delay(0.1, function() {
    echo "C\n";
    global $t;
    $tt = microtime(true) - $t;
    assert($tt > 0.1, "Timers executed too fast $tt");
});
$b = Loop::delay(0.05, function() {
    echo "B";
    global $t;
    $tt = microtime(true) - $t;
    assert($tt > 0.05, "Timers executed too fast");
});
$c = Loop::defer(function() {
    echo "A";
});

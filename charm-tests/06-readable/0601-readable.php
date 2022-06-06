<?php
use Moebius\Loop;

$fp = fopen(__FILE__, 'r');
Loop::readable($fp, function($a) use ($fp) {
    assert(is_resource($a));
    assert($a === $fp);
    echo "OK!";
});
Loop::delay(0.25, function() {
    echo "\n";
});

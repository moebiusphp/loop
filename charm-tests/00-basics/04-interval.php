<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

$count = 0;

$stopper = Loop::interval(0.1, function($arg) use (&$count, &$stopper) {
    if (++$count === 2) {
        $stopper();
        echo "OK\n";
    }
}, "OK");


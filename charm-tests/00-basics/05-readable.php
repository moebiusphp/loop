<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

$count = 0;

$fp = fopen(__FILE__, 'r');

$stopper = Loop::readable($fp, function($arg) use (&$stopper, &$fp) {
    if ($arg === $fp) {
        echo "OK\n";
    }
    $stopper();
});


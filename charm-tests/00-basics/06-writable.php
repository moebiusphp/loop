<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

$count = 0;
$n = tempnam(sys_get_temp_dir(), 'test-writable');
$fp = fopen($n, 'w');
unlink($n);

$stopper = Loop::writable($fp, function($arg) use (&$stopper, &$fp) {
    if ($arg === $fp) {
        echo "OK\n";
    }
    $stopper();
});


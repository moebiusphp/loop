<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

$fp = fopen(__FILE__, 'r');

$buffer = '';

$stopper = Loop::read($fp, function($chunk) use (&$buffer) {
    if ($chunk === '') {
        if ($buffer === file_get_contents(__FILE__)) {
            echo "OK\n";
            return;
        }
    }
    $buffer .= $chunk;
});

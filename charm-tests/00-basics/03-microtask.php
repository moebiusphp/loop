<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

Loop::queueMicrotask(function($arg) {
    echo $arg."\n";
}, "OK");


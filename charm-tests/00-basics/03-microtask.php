<?php
use Moebius\Loop;

Loop::queueMicrotask(function($arg) {
    echo $arg."\n";
}, "OK");


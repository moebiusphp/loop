<?php
require(__DIR__.'/../vendor/autoload.php');
use Co\Loop;

Loop::delay(0.1)->then(function() {
    echo "C\n";
});
Loop::delay(0.05)->then(function() {
    echo "B";
});
Loop::defer(function() {
    echo "A";
});

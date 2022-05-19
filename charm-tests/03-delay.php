<?php
require(__DIR__.'/../vendor/autoload.php');
use Co\Loop;

$a = Loop::delay(0.1, function() {
    echo "C\n";
});
$b = Loop::delay(0.05, function() {
    echo "B";
});
$c = Loop::defer(function() {
    echo "A";
});

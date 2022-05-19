<?php
require(__DIR__.'/../vendor/autoload.php');
use Co\Loop;

Loop::queueMicrotask(function() {
    echo "B";
});
Loop::defer(function() {
    echo "D";
    Loop::defer(function() {
        echo "G";
    });
    Loop::queueMicrotask(function() {
        echo "E";
    });
});
Loop::queueMicrotask(function() {
    echo "C";
});
Loop::defer(function() {
    echo "F";
    Loop::defer(function() {
        echo "H\n";
    });
});
echo "A";

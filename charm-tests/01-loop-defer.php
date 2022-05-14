<?php
require(__DIR__.'/../vendor/autoload.php');
use Co\Loop;

Loop::defer(function() {
    echo "B";
});
Loop::defer(function() {
    echo "C\n";
});
echo "A";

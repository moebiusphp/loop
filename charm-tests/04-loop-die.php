<?php
require(__DIR__.'/../vendor/autoload.php');
use Co\Loop;

Loop::defer(function() {
    echo "B";
    die(); // this should prevent C
});
Loop::defer(function() {
    assert(false, "This should not happen");
    echo "C\n";
});
echo "A";

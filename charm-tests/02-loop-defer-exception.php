<?php
require(__DIR__.'/../vendor/autoload.php');
use Moebius\Loop;

Loop::defer(function() {
    echo "B";
});
Loop::defer(function() {
    echo "C";
    throw new \Exception("This should prevent D");
});
Loop::defer(function() {
    assert(false, "This should not happen");
    echo "D\n";
});
echo "A";

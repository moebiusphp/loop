<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

Loop::defer(function() {
    echo "B";
});
Loop::defer(function() {
    echo "C\n";
});
echo "A";

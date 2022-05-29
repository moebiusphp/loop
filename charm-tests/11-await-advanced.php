<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Loop;

(new Moebius\Loop\Timer(0.1))->then(function() {
    echo "A";
});

$timer = new Moebius\Loop\Timer(0.2);
$timer->then(function() {
    echo "B";
});

Loop::await($timer);

echo "C";

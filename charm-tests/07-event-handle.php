<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Loop;

$tests = function() {

    yield [false, Loop::delay(0, function() {
        assert(false, "Delay shouldn't run");
        echo "Delay shouldn't run\n";
    })];

    $fp = fopen(__FILE__, 'r');
    yield [true, Loop::readable($fp, function() {
        assert(false, "Readable shouldn't run");
        echo "Readable shouldn't run\n";
    })];

    yield [true, Loop::signal(15, function() {
        assert(false, "Signal shouldn't run");
        echo "Signal shouldn't run\n";
    })];

};

foreach ($tests() as [$suspendable, $handle]) {
    echo "A";
    $handle->cancel();
    echo "B";
    try {
        $handle->resume();
    } catch (\LogicException $e) {
        echo "C";
    }
    try {
        $handle->suspend();
    } catch (\LogicException $e) {
        echo "D";
    }
    echo "\n";
}

foreach ($tests() as [$suspendable, $handle]) {
    echo "A";
    if ($suspendable) {
        $handle->suspend();
        echo "B";
        $handle->resume();
        echo "C";
        $handle->cancel();
    } else {
        try {
            $handle->suspend();
        } catch (\LogicException $e) {
            echo "B";
        }
        $handle->cancel();
        echo "C";
    }
    echo "\n";
}

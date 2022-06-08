<?php
// Deferred events is only required to run in the order they were inserted
// in the loop they were added to

use Moebius\Loop;

$fp = fopen(__FILE__, 'r');

$loop = Loop::get();
$loop->readable($fp)->then(function() use ($loop) {
    assert(Loop::test_driver_is($loop), "Readable not invoked within the child loop");
    echo "Got readable in correct context\n";
})->then(function() use ($loop) {
    assert(Loop::test_driver_is($loop), "Secondary then() for readable not invoked within the child loop");
    echo "Got secondary readable in correct context\n";
});;

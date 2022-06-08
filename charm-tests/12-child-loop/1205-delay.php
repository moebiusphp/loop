<?php
// Deferred events is only required to run in the order they were inserted
// in the loop they were added to

use Moebius\Loop;

$fp = tmpfile();

$loop = Loop::get();
$loop->delay(0.1)->then(function() use ($loop) {
    assert(Loop::test_driver_is($loop), "Delay not invoked within the child loop");
    echo "Got delay in correct context\n";
})->then(function() use ($loop) {
    assert(Loop::test_driver_is($loop), "Secondary then() for delay not invoked within the child loop");
    echo "Got secondary delay in correct context\n";
});;

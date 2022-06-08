<?php
// Deferred events is only required to run in the order they were inserted
// in the loop they were added to

use Moebius\Loop;

$fp = tmpfile();

$loop = Loop::get();
$loop->writable($fp)->then(function() use ($loop) {
    assert(Loop::test_driver_is($loop), "Writable not invoked within the child loop");
    echo "Got writable in correct context\n";
})->then(function() use ($loop) {
    assert(Loop::test_driver_is($loop), "Secondary then() for writable not invoked within the child loop");
    echo "Got secondary writable in correct context\n";
});;

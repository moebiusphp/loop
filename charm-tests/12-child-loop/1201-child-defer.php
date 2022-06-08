<?php
// Deferred events is only required to run in the order they were inserted
// in the loop they were added to

use Moebius\Loop;


$child = Loop::get();
$child->defer(function() {
    echo "Deferred in child loop 1\n";
    Loop::defer(function() {
        echo "This should be in child loop 1\n";
    });
});

Loop::defer(function() use ($child) {
    assert(Loop::test_driver_is(null), "Not in the root loop");
    echo "Deferred in the main loop\n";
    Loop::defer(function() {
        echo "This should also be in child loop 1\n";
    });
});

$child->defer(function() use ($child) {
    assert(Loop::test_driver_is($child), "Not invoked in child loop 1");
    echo "Deferred in child loop 1\n";
    Loop::defer(function() use ($child) {
        assert(Loop::test_driver_is($child), "Not invoked in child loop 1");
        echo "This should also be in child loop 1\n";
    });
});


$child2 = Loop::get();
$child2->defer(function() use ($child2) {
    assert(Loop::test_driver_is($child2), "Not invoked in child loop 2");
    echo "Deferred in the child loop 2\n";
});

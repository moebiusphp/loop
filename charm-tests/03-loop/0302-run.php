<?php

use Moebius\Loop;
// nothing is enqueued

Loop::defer(function() {
    echo "K";
});
Loop::defer(function() {
    echo "!";
});
Loop::queueMicrotask(function($v) {
    echo $v;
}, "O");

Loop::run();
Loop::defer(function() {
    echo "\n";
});

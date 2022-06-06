<?php
use Moebius\Loop;

Loop::poll(function() {
    echo "K";
    Loop::queueMicrotask(function() {
        echo "!";
    });
    Loop::poll(function() {
        echo "\n";
    });
});
Loop::defer(function() {
    echo "O";
});
Loop::run();

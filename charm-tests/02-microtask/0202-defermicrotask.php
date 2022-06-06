<?php
use Moebius\Loop;

Loop::defer(function() {
    echo "K";
    Loop::queueMicrotask(function() {
        echo "!\n";
    });
});
Loop::queueMicrotask(function() {
    echo "O";
});

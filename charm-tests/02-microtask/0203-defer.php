<?php
use Moebius\Loop;

Loop::queueMicrotask(function() {
    Loop::defer(function() {
        echo "!\n";
    });
    Loop::queueMicrotask(function() {
        echo "O";
    });
    Loop::queueMicrotask(function() {
        echo "K";
    });
});

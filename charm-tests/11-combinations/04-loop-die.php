<?php
use Moebius\Loop;

Loop::defer(function() {
    echo "B";
    die("C\n"); // this should prevent D
});

Loop::defer(function() {
    assert(false, "This should not happen");
    echo "D\n";
});
echo "A";

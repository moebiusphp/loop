<?php
use Moebius\Loop;

Loop::defer(function() {
    Loop::defer(function() {
        echo "K\n";
    });
    echo "O";
});

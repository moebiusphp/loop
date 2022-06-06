<?php
use Moebius\Loop;

Loop::defer(function() {
    echo "OK\n";
});


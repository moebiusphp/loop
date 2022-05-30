<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

Loop::defer(function() {
    echo "OK\n";
});


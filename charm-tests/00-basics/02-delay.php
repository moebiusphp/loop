<?php
require(__DIR__.'/../../vendor/autoload.php');
use Moebius\Loop;

Loop::delay(0.1, function() {
    echo "OK\n";
});


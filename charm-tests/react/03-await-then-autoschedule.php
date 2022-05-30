<?php
require(__DIR__.'/../../vendor/autoload.php');

$p = new Moebius\Promise(function($yes) {
    $yes(true);
});

Moebius\Loop::await($p);

Moebius\Loop::defer(function() {
    echo "OK\n";
});

<?php
require(__DIR__.'/../../vendor/autoload.php');

if (!class_exists(React\EventLoop\Loop::class)) {
    echo "--SKIP--\n";
    return;
}

$p = new Moebius\Promise(function($yes) {
    $yes(true);
});

Moebius\Loop::await($p);

Moebius\Loop::defer(function() {
    echo "OK\n";
});

<?php
use Moebius\Loop;

$stopper = Loop::signal(SIGALRM, function($arg) use (&$stopper) {
    echo "OK\n";
    $stopper();
});

Loop::delay(0.1, function() {
    posix_kill(getmypid(), SIGALRM);
});

echo "autorunning?";

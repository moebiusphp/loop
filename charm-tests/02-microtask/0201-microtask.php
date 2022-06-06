<?php
use Moebius\Loop;

Loop::queueMicrotask(function() {
    echo "OK!\n";
});

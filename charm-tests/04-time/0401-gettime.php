<?php

$time = Moebius\Loop::getTime();
usleep(100000);
$elapsed = round((Moebius\Loop::getTime() - $time)*1000) / 1000;

assert($elapsed >= 0.1, "Elapsed $elapsed < 0.1");
assert($elapsed <= 0.101, "Elapsed $elapsed > 0.101");

echo "OK!\n";

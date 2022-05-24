<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Loop;
use Moebius\Loop\Timer;

$start = microtime(true);
$timer = new Timer(0.1);

$timer->then(function() use ($timer) {
    echo "A";
    global $start;
    assert(microtime(true) - $start > 0.1, "Timer spent too little time");
    $timer->then(function() use ($timer) {
        echo "B";
        global $start;
        assert(microtime(true) - $start > 0.2, "Timer spent too little time");
        $timer->then(function() {
            echo "C\n";
            global $start;
            assert(microtime(true) - $start > 0.3, "Timer spent too little time");
        });
    });
});

//die();
/*
$fn = tempnam(sys_get_temp_dir(), 'coco');

posix_mkfifo($fn.".fifo", 0600);
exec("(sleep 0.5; echo ABC > ".escapeshellarg($fn.".fifo").") > /dev/null &");
echo "fopen\n";
$fp = fopen($fn.".fifo", "rn");

$readable = new Readable($fp);
echo "await\n";
$returnedFP = Loop::await($readable);

assert($fp === $returnedFP, "Watcher didn't resolve with the resource");

echo fgetc($fp)."\n";

$readable = new Readable($fp);
while (!feof($fp)) {
    Loop::await($readable);
    echo fgetc($fp)."\n";
}
*/

<?php

$ch = curl_init("https://www.google.com/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$handler = Moebius\Loop::curl($ch);

$handler->then(function($result) {
    assert(strlen($result) > 2000, "Fetch failed");
}, function($e) {
    assert(false, $e);
});

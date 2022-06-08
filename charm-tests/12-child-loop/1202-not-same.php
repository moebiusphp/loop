<?php
// Deferred events is only required to run in the order they were inserted
// in the loop they were added to

use Moebius\Loop;

assert(Loop::get() !== Loop::get(), "Loop::get() returned same child");

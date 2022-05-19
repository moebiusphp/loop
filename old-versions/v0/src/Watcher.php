<?php
namespace Co\Loop;

use Co\PromiseInterface;

abstract class Watcher implements PromiseInterface {

    private array $onFulfilled = [];
    private array $onRejected = [];

    public function then(callable $onFulfilled=null, callable $onRejected=null, callable $void=null): PromiseInterface {
    }

    public function cancel(): void {
    }

}

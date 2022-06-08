<?php
namespace Moebius\Loop;

use Closure;

interface ChildEventLoopInterface extends DriverInterface {

    public function __construct(DriverInterface $parent, Closure $swapDefaultLoopFunction);

    public function getParent(): DriverInterface;

}

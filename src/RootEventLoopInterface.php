<?php
namespace Moebius\Loop;

use Closure;

interface RootEventLoopInterface extends DriverInterface {

    /**
     * Set the exception handler for events run with the event loop driver
     */
    public function __construct(Closure $exceptionHandler);

}

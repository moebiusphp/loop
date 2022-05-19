<?php
namespace Co\Loop;

class DriverFactory {

    /**
     * Selects a driver for running the event loop by prioritizing
     * the most popular drivers first, and finally falling back to
     * stand-alone drivers.
     */
    public static function getDriver(callable $exceptionHandler): DriverInterface {
        if (class_exists(\React\EventLoop\Loop::class)) {
            return new Drivers\ReactDriver($exceptionHandler);
        }
        if (class_exists(\Amp\Loop::class)) {
            return new Drivers\AmpDriver($exceptionHandler);
        }
        if (class_exists(\Revolt\EventLoop::class)) {
            return new Drivers\RevoltDriver($exceptionHandler);
        }
        if (class_exists(\Ev::class, false)) {
            return new Drivers\EvDriver($exceptionHandler);
        }
        return new Drivers\StreamSelectDriver($exceptionHandler);
    }

}

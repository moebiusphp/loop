<?php
namespace Co\Loop;

final class DriverFactory {

    private static ?\DriverInterface $driver = null;

    public static function discoverDriver(callable $exceptionHandler): DriverInterface {
        $library = self::discover();

        switch ($library) {
            case 'react/event-loop':
                return new Drivers\ReactDriver($exceptionHandler);
            case 'amphp/amp':
                return new Drivers\AmpDriver($exceptionHandler);
        }

        if (class_exists(\Ev::class, false)) {
            return new Drivers\EvDriver($exceptionHandler);
        } else {
            return new Drivers\StreamSelectDriver($exceptionHandler);
        }
    }


    /**
     * Identify which event loop implementation we prefer to run on top of.
     *
     * @return ?string The installed event loop package name, or NULL if none 
     *                 was discovered.
     */
    private static function discover(): ?string {
        static $library = false;

        if ($library !== false) {
            return $library;
        }

        /**
         * Check for a set of packages and a class and method that must exist
         * for confirmation.
         */
        foreach ([
            'react/event-loop' => [ \React\EventLoop\Loop::class, 'futureTick' ],
            'amphp/amp' => [ \Amp\Loop::class, 'defer' ],
            'revolt/event-loop' => [ \Revolt\EventLoop::class, 'defer' ],
            'sabre/event' => [ \Sabre\Event\Loop\Loop::class, 'nextTick' ],
        ] as $library => $deferInfo) {
            if (class_exists($deferInfo[0]) && method_exists($deferInfo[0], $deferInfo[1])) {
                $candidates[] = $library;
            }
        }

        if (empty($candidates)) {
            return $library = null;
        }
        if (count($candidates) > 1) {
            throw new ConflictException("Unable to autodiscover the correct event loop library to use; discovered ".implode(", ", $candidates));
        }
        return $library = $candidates[0];
    }
}

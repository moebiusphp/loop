<?php
namespace Co\Loop;

final class AdapterManager {

    private static ?AdapterInterface $adapter = null;

    /**
     * Set the adapter that Co\Loop should use. This function is intended
     * for future loop implementations which wants to integrate with `Co\Loop`.
     */
    public static function setAdapter(AdapterInterface $adapter): void {
        self::$adapter = $adapter;
    }

    /**
     * Return an event loop adapter which provides information about how
     * `Co\Loop` should integrate.
     */
    public static function getAdapter(): ?AdapterInterface {
        if (self::$adapter) {
            return self::$adapter;
        }

        $discovered = self::discover();

        switch ($discovered) {
            case 'react/event-loop' :
                return self::$adapter = new Adapters\ReactAdapter();
            case 'amphp/amp' :
                return self::$adapter = new Adapters\AmpAdapter();
            case 'revolt/event-loop' :
                return self::$adapter = new Adapters\RevoltAdapter();
            case 'sabre/event' :
                return self::$adapter = new Adapters\SabreAdapter();
            default :
                if ($discovered !== null) {
                    throw new \LogicException("Unknown error trying to discover event loop implementation (got $discovered)");
                }
        }

        /**
         * Fall back to built-in adapters because interoperability
         * is not needed.
         */
        if (class_exists(\Ev::class)) {
            return self::$adapter = new Adapters\EvAdapter();
        } else {
            return self::$adapter = new Adapters\StreamSelectAdapter();
        }
    }

    /**
     * Identify which event loop implementation we prefer to run on top of.
     *
     * @return ?string The installed event loop package name, or NULL if none 
     *                 was discovered.
     */
    private static function discover(): ?string {
        static $library = null;

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
            if (class_exists($deferInfo) && method_exists($deferInfo[0], $deferInfo[1])) {
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

<?php
namespace Moebius\Loop;

use Closure;
use Composer\Semver\VersionParser;
use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;

final class Factory {

    private static $driver = null, $exceptionHandler = null, $logger = null;

    public static function setDriver(DriverInterface $factory): void {
        self::$factory = $factory;
    }

    public static function getDriver(): DriverInterface {
        $exceptionHandler = self::getExceptionHandler();
        if (self::$driver === null) {
            if (InstalledVersions::isInstalled('react/event-loop') && InstalledVersions::satisfies(new VersionParser, 'react/event-loop', '>=1.2 <2.0')) {
                self::$driver = new Drivers\ReactDriver($exceptionHandler);
            } elseif (InstalledVersions::isInstalled('amphp/amp') && InstalledVersions::satisfies(new VersionParser, 'amphp/amp', '>=2.1 <3.0')) {
                self::$driver = new Drivers\AmpDriver($exceptionHandler);
            } elseif (class_exists(\Ev::class)) {
                self::$driver = new Drivers\EvDriver($exceptionHandler);
            } else {
                self::$driver = new Drivers\StreamSelectDriver($exceptionHandler);
            }

            if (getenv('DEBUG')) {
                self::$driver = new Drivers\DebugDriver(self::$driver);
            }
        }
        return self::$driver;
    }

    public static function setExceptionHandler(Closure $callback): void {
        self::$exceptionHandler = $callback;
    }

    public static function getExceptionHandler(): Closure {
        if (!self::$exceptionHandler) {
            self::$exceptionHandler = self::defaultExceptionHandler(...);
        }
        return self::$exceptionHandler;
    }

    public static function setLogger(LoggerInterface $logger): void {
        self::$logger = $logger;
    }

    public static function getLogger(): LoggerInterface {
        if (!self::$logger) {
            self::$logger = \Charm\FallbackLogger::get();
        }
        return self::$logger;
    }

    private static function defaultExceptionHandler(\Throwable $e) {
        $message = "[{className} code={code}] {message} in {file}:{line}\n{trace}";
        $context = [
            "className" => get_class($e),
            "code" => $e->getCode(),
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "trace" => $e->getTraceAsString()
        ];
        self::getLogger()->error($message, $context);
    }

}

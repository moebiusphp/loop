<?php
namespace Moebius\Loop;

use Closure;
use Composer\Semver\VersionParser;
use Composer\InstalledVersions;

class DriverFactory implements FactoryInterface {

    private static $factory = null;

    public static function getFactory(): FactoryInterface {
        if (self::$factory === null) {
            self::$factory = new self();
        }
        return self::$factory;
    }

    public static function setFactory(FactoryInterface $factory): void {
        self::$factory = $factory;
    }

    /**
     * Create a new driver based on detected criteria.
     */
    public function getDriver(): DriverInterface {
        $exceptionHandler = $this->getExceptionHandler();

        if (InstalledVersions::isInstalled('react/event-loop') && InstalledVersions::satisfies(new VersionParser, 'react/event-loop', '>=1.2 <2.0')) {
//echo "using React\n";
            return new Drivers\ReactDriver($exceptionHandler);
        } elseif (InstalledVersions::isInstalled('amphp/amp') && InstalledVersions::satisfies(new VersionParser, 'amphp/amp', '>=2.1 <3.0')) {
//echo "using Amp\n";
            return new Drivers\AmpDriver($exceptionHandler);
        } elseif (false && class_exists(\Ev::class)) {
//echo "using Ev\n";
            return new Drivers\EvDriver($exceptionHandler);
        } else {
//echo "using StreamSelect\n";
            return new Drivers\StreamSelectDriver($exceptionHandler);
        }
    }

    public function getExceptionHandler(): Closure {
        return static function(\Throwable $e) {
            fwrite(STDERR, gmdate('Y-m-d H:i:s').' ['.get_class($e).' #'.$e->getCode().'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\n\t".strtr($e->getMessage(), ["\n" => "\n\t"])."\n");
        };
    }

}

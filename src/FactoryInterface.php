<?php
namespace Moebius\Loop;

interface FactoryInterface {

    /**
     * Return a new driver instance.
     */
    public function getDriver(): DriverInterface;

}

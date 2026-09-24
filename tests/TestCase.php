<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    /**
     * Symfony guarda hosts y proxies de confianza en estado estatico: algunas pruebas simulan
     * production/staging (donde TrustHosts actua) y no deben contaminar a las demas.
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        TrustHosts::flushState();
        TrustProxies::flushState();

        parent::tearDown();
    }
}

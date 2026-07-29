<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but the throwaway sqlite database.
     *
     * phpunit.xml pins the connection to sqlite/:memory:, but those <env>
     * entries are read through env() — and a cached bootstrap/cache/config.php
     * bypasses env() entirely. So if someone runs `php artisan config:cache`
     * (or leaves a stale cache behind) the suite silently inherits the real
     * MySQL credentials, and the first RefreshDatabase test drops every table
     * in the live database. That has happened; this guard makes it impossible
     * to happen quietly again.
     *
     * The check hangs off refreshApplication() rather than setUp() because
     * Laravel runs setUpTraits() — where RefreshDatabase performs its
     * migration — inside the same setUp() call. By then the damage is done.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || ! in_array($database, [':memory:', null], true)) {
            throw new RuntimeException(
                "Refusing to run tests against the '{$connection}' connection (database: ".
                var_export($database, true).'). Tests must use sqlite/:memory:. This almost '.
                'always means a cached config is overriding phpunit.xml — run '.
                '`php artisan config:clear` and try again.'
            );
        }
    }
}

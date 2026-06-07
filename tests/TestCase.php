<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // PHPUnit's <env force="true"> updates getenv()/putenv() and $_ENV but NOT $_SERVER.
        // The dotenv ServerConstAdapter reads $_SERVER first, which still has the Docker
        // env value ("hris"). We must sync $_SERVER here before the Application bootstraps
        // so that Env::get("DB_DATABASE") returns "HRIS_test" as phpunit.xml intends.
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE'] as $key) {
            $val = getenv($key);
            if ($val !== false) {
                $_SERVER[$key] = $val;
            }
        }

        Env::enablePutenv();

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = config('database.connections.mysql.database');
        if ($db === 'hris') {
            $this->fail(
                'SAFETY ABORT: Tests are running against the production database "hris". '.
                'Ensure DB_DATABASE=HRIS_test is set and the HRIS_test database exists. '.
                "Run: docker exec hris-dev-db mysql -uroot -p'adminPa55w0rd!' --socket=/var/lib/mysql/mysql.sock ".
                "-e \"CREATE DATABASE IF NOT EXISTS HRIS_test; GRANT ALL PRIVILEGES ON HRIS_test.* TO 'hris-app'@'%';\""
            );
        }
    }
}

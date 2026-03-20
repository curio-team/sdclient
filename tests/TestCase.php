<?php

namespace Curio\SdClient\Tests;

use Curio\SdClient\SdClientHelper;
use Curio\SdClient\SdClientServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            SdClientServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'SdApi' => \Curio\SdClient\Facades\SdApi::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('sdclient.url', 'https://login.example.com');
        $app['config']->set('sdclient.client_id', 'test-client-id');
        $app['config']->set('sdclient.client_secret', 'test-client-secret');
        $app['config']->set('sdclient.user_model', \App\Models\User::class);
        $app['config']->set('sdclient.app_for', 'teachers');
        $app['config']->set('sdclient.use_migration', 'yes');
        $app['config']->set('sdclient.api_log', 'no');
        $app['config']->set('sdclient.ssl_verify_peer', 'yes');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../src/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        SdClientHelper::resetCache();
    }
}

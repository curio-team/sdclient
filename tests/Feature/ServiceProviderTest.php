<?php

namespace Curio\SdClient\Tests\Feature;

use Curio\SdClient\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
  public function test_config_is_merged()
  {
    $this->assertNotNull(config('sdclient.url'));
    $this->assertNotNull(config('sdclient.client_id'));
  }

  public function test_routes_are_registered()
  {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    $sdclientRoutes = $routes->filter(function ($route) {
      return str_starts_with($route->uri(), 'sdclient/');
    });

    $uris = $sdclientRoutes->pluck('uri')->toArray();

    $this->assertContains('sdclient/redirect', $uris);
    $this->assertContains('sdclient/callback', $uris);
    $this->assertContains('sdclient/logout', $uris);
  }

  public function test_routes_use_web_middleware()
  {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    $redirectRoute = $routes->first(function ($route) {
      return $route->uri() === 'sdclient/redirect';
    });

    $this->assertNotNull($redirectRoute);
    $this->assertContains('web', $redirectRoute->middleware());
  }
}

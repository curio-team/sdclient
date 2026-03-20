<?php

namespace Curio\SdClient\Tests\Unit;

use Curio\SdClient\HttpClientFactory;
use Curio\SdClient\Tests\TestCase;
use GuzzleHttp\Client;

class HttpClientFactoryTest extends TestCase
{
  public function test_make_returns_guzzle_client()
  {
    $factory = new HttpClientFactory();

    $this->assertInstanceOf(Client::class, $factory->make());
  }

  public function test_make_with_ssl_verify_disabled()
  {
    config()->set('sdclient.ssl_verify_peer', 'no');

    $factory = new HttpClientFactory();
    $client = $factory->make();

    // The client is created — verifying it doesn't throw with the curl config
    $this->assertInstanceOf(Client::class, $client);
  }

  public function test_make_merges_additional_config()
  {
    $factory = new HttpClientFactory();
    $client = $factory->make(['timeout' => 5]);

    $this->assertInstanceOf(Client::class, $client);
    $this->assertEquals(5, $client->getConfig('timeout'));
  }

  public function test_make_with_ssl_disabled_preserves_additional_config()
  {
    config()->set('sdclient.ssl_verify_peer', 'no');

    $factory = new HttpClientFactory();
    $client = $factory->make(['timeout' => 10]);

    $this->assertInstanceOf(Client::class, $client);
    $this->assertEquals(10, $client->getConfig('timeout'));
  }
}

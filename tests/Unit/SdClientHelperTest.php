<?php

namespace Curio\SdClient\Tests\Unit;

use Curio\SdClient\SdClientHelper;
use Curio\SdClient\Tests\TestCase;
use Lcobucci\JWT\Configuration;

class SdClientHelperTest extends TestCase
{
  public function test_get_token_config_returns_configuration_instance()
  {
    $config = SdClientHelper::getTokenConfig();

    $this->assertInstanceOf(Configuration::class, $config);
  }

  public function test_get_token_config_returns_same_cached_instance()
  {
    $config1 = SdClientHelper::getTokenConfig();
    $config2 = SdClientHelper::getTokenConfig();

    $this->assertSame($config1, $config2);
  }

  public function test_get_token_config_aborts_when_client_secret_is_null()
  {
    config()->set('sdclient.client_secret', null);

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    SdClientHelper::getTokenConfig();
  }

  public function test_get_token_config_has_validation_constraints()
  {
    $config = SdClientHelper::getTokenConfig();
    $constraints = $config->validationConstraints();

    $this->assertNotEmpty($constraints);
  }
}

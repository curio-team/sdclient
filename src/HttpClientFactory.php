<?php

namespace Curio\SdClient;

use GuzzleHttp\Client;

class HttpClientFactory
{
    public function make(array $config = []): Client
    {
        if (config('sdclient.ssl_verify_peer') === 'no') {
            $config = array_merge(['curl' => [CURLOPT_SSL_VERIFYPEER => false]], $config);
        }

        return new Client($config);
    }
}

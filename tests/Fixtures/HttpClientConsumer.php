<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Fixtures;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientConsumer
{
    public function __construct(public readonly HttpClientInterface $httpClient, public readonly HttpClientInterface $exampleClient)
    {
    }
}

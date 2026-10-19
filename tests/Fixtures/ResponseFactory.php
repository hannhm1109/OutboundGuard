<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

final class ResponseFactory
{
    public function __invoke(): MockResponse
    {
        return new MockResponse('ok', ['total_time' => 0.025]);
    }
}

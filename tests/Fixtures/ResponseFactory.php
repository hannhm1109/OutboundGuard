<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

final class ResponseFactory
{
    public function __invoke(string $method, string $url): MockResponse
    {
        $duration = match (parse_url($url, PHP_URL_PATH)) {
            '/products/42' => 0.2,
            '/shipping' => 0.6,
            '/taxes' => 0.04,
            default => 0.025,
        };

        return new MockResponse('ok', ['total_time' => $duration]);
    }
}

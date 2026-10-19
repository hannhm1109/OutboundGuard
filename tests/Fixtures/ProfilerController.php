<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProfilerController
{
    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public function checkout(): Response
    {
        foreach (['/products/42?page=1', '/products/42?page=2', '/products/42?page=3', '/shipping', '/taxes'] as $path) {
            $url = 'https://api.example.com'.$path.(str_contains($path, '?') ? '&' : '?').'token=profiler-secret-query';
            $this->client->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer profiler-secret-authorization'],
            ])->getContent();
        }

        return self::empty();
    }

    public static function empty(): Response
    {
        return new Response('<!DOCTYPE html><html><head><title>Checkout fixture</title></head><body><h1>Checkout</h1></body></html>');
    }

    public function stream(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            $this->client->request('GET', 'https://api.example.com/shipping')->getContent();
            echo 'streamed';
        }, 200, ['Content-Type' => 'text/plain']);
    }
}

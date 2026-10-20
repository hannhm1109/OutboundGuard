<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Integration;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\DataCollector\OutboundGuardDataCollector;
use Hanane\OutboundGuardBundle\Metadata\CallOutcome;
use Hanane\OutboundGuardBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Twig\Environment;

final class ProfilerIntegrationTest extends TestCase
{
    public function testStoredProfileRendersTheRealPanelAndToolbarAndSurvivesTheNextRequest(): void
    {
        $kernel = new TestKernel(capturePaths: true, guardConfig: [
            'max_calls' => 3,
            'max_total_duration_ms' => 800,
            'slow_call_threshold_ms' => 500,
        ], profilerEnabled: true);
        $browser = new KernelBrowser($kernel);
        $browser->disableReboot();

        try {
            $browser->request('GET', '/checkout');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            $profile = $browser->getProfile();
            self::assertInstanceOf(Profile::class, $profile);
            $data = $profile->getCollector('outbound_guard');
            self::assertInstanceOf(OutboundGuardDataCollector::class, $data);
            self::assertSame(5, $data->getReport()->callCount);
            self::assertSame(1240.0, $data->getReport()->totalDurationMs);
            self::assertSame(2, $data->getReport()->duplicateCallCount);
            self::assertSame(1, $data->getReport()->slowCallCount);
            self::assertStringNotContainsString('profiler-secret-', serialize($data));

            $panel = $browser->request('GET', '/_profiler/'.$profile->getToken().'?panel=outbound_guard');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertCount(3, $panel->filter('.outbound-guard-calls tbody tr'));
            self::assertCount(4, $panel->filter('[data-warning-code]'));
            self::assertStringContainsString('https://api.example.com/products/42', $panel->filter('.outbound-guard-calls')->text());
            self::assertStringContainsString('1240.00', $panel->filter('#collector-content')->text());
            self::assertStringNotContainsString('profiler-secret-', $panel->filter('#collector-content')->html());

            $toolbar = $browser->request('GET', '/_wdt/'.$profile->getToken());
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertCount(1, $toolbar->filter('.sf-toolbar-block-outbound_guard.sf-toolbar-status-red'));
            self::assertStringContainsString('5', $toolbar->filter('.sf-toolbar-block-outbound_guard .sf-toolbar-value')->text());

            $browser->request('GET', '/empty');
            $emptyProfile = $browser->getProfile();
            self::assertInstanceOf(Profile::class, $emptyProfile);
            $emptyData = $emptyProfile->getCollector('outbound_guard');
            self::assertInstanceOf(OutboundGuardDataCollector::class, $emptyData);
            self::assertSame(0, $emptyData->getReport()->callCount);
            self::assertSame([], $emptyData->getReport()->warnings);
            self::assertSame(5, $data->getReport()->callCount);

            $emptyPanel = $browser->request('GET', '/_profiler/'.$emptyProfile->getToken().'?panel=outbound_guard');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertStringContainsString('No outbound HTTP calls were collected.', $emptyPanel->filter('#collector-content')->text());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDefaultPrivacyAndHtmlEscapingInTheRenderedPanel(): void
    {
        $kernel = new TestKernel(profilerEnabled: true);
        $kernel->boot();

        try {
            $twig = $kernel->getContainer()->get('test.twig');
            self::assertInstanceOf(Environment::class, $twig);
            $template = $twig->load('@OutboundGuard/Collector/outbound_guard.html.twig');
            $collector = new OutboundCallCollector();
            $collector->start('GET', 'https://api.example.com/reset/path-secret?token=query-secret');
            $profiler = new OutboundGuardDataCollector($collector);
            $profiler->collect(new Request(), new Response());
            $html = $template->renderBlock('panel', ['collector' => $profiler]);

            self::assertStringContainsString('[path hidden]', $html);
            self::assertStringContainsString('including 1 pending', $html);
            self::assertStringContainsString('Unknown', $html);
            self::assertStringNotContainsString('path-secret', $html);
            self::assertStringNotContainsString('query-secret', $html);

            $pathsEnabled = new OutboundCallCollector(true);
            for ($i = 0; $i < 2; ++$i) {
                $pathsEnabled->start('GET', 'https://api.example.com/<script>alert(1)</script>')->finish(CallOutcome::Success, 1.0);
            }
            $profiler = new OutboundGuardDataCollector($pathsEnabled);
            $profiler->collect(new Request(), new Response());
            $html = $template->renderBlock('panel', ['collector' => $profiler]);

            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
            self::assertStringContainsString('data-warning-code="duplicate_call"', $html);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCallsStartedInsideAStreamedResponseAreIncludedByLateCollection(): void
    {
        $kernel = new TestKernel(profilerEnabled: true);
        $kernel->boot();

        try {
            // Older BrowserKit versions terminate before sending streamed content. Model the real lifecycle explicitly.
            $request = Request::create('/stream');
            $response = $kernel->handle($request);
            self::assertInstanceOf(StreamedResponse::class, $response);
            ob_start();
            try {
                $response->sendContent();
            } finally {
                $content = ob_get_clean();
            }
            self::assertSame('streamed', $content);
            $kernel->terminate($request, $response);
            $profiler = $kernel->getContainer()->get('profiler');
            self::assertInstanceOf(Profiler::class, $profiler);
            $profile = $profiler->loadProfileFromResponse($response);
            self::assertInstanceOf(Profile::class, $profile);
            $data = $profile->getCollector('outbound_guard');
            self::assertInstanceOf(OutboundGuardDataCollector::class, $data);
            self::assertSame(1, $data->getReport()->callCount);
            self::assertSame(600.0, $data->getReport()->totalDurationMs);
            self::assertSame(1, $data->getReport()->slowCallCount);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDisablingTheBundleAlsoDisablesItsProfilerCollector(): void
    {
        $kernel = new TestKernel(guardEnabled: false, profilerEnabled: true);
        $browser = new KernelBrowser($kernel);

        try {
            $browser->request('GET', '/empty');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            $profile = $browser->getProfile();
            self::assertInstanceOf(Profile::class, $profile);
            self::assertFalse($profile->hasCollector('outbound_guard'));
        } finally {
            $kernel->shutdown();
        }
    }
}

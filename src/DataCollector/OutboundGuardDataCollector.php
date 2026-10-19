<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\DataCollector;

use Hanane\OutboundGuardBundle\Analysis\Budget;
use Hanane\OutboundGuardBundle\Analysis\OutboundReport;
use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/** Only the safe report in $data is serialized by Symfony, never the collector or its key. */
final class OutboundGuardDataCollector extends DataCollector implements LateDataCollectorInterface
{
    public function __construct(private readonly OutboundCallCollector $collector)
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = ['report' => $this->collector->getReport()];
    }

    public function lateCollect(): void
    {
        // Streamed responses and termination listeners can finish outbound work after kernel.response.
        $this->data = ['report' => $this->collector->getReport()];
    }

    public function getReport(): OutboundReport
    {
        $report = is_array($this->data) ? ($this->data['report'] ?? null) : null;

        return $report instanceof OutboundReport ? $report : new OutboundReport(new Budget());
    }

    public function getName(): string
    {
        return 'outbound_guard';
    }

    public function reset(): void
    {
        $this->data = [];
    }
}

<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Collector;

use Hanane\OutboundGuardBundle\Analysis\Budget;
use Hanane\OutboundGuardBundle\Analysis\OutboundAnalyzer;
use Hanane\OutboundGuardBundle\Analysis\OutboundReport;
use Hanane\OutboundGuardBundle\Metadata\OutboundCall;
use Hanane\OutboundGuardBundle\Metadata\RequestTarget;
use Symfony\Contracts\Service\ResetInterface;

/** Request-local in-memory metrics, also injectable into application tests. */
final class OutboundCallCollector implements ResetInterface
{
    /** @var list<OutboundCall> */
    private array $calls = [];
    private string $fingerprintKey;

    public function __construct(
        private readonly bool $capturePaths = false,
        private readonly Budget $budget = new Budget(),
        private readonly OutboundAnalyzer $analyzer = new OutboundAnalyzer(),
    ) {
        $this->reset();
    }

    /** @internal The URL is sanitized immediately, never retained. */
    public function start(string $method, string $url): OutboundCall
    {
        $method = strtoupper($method);
        $call = new OutboundCall($method, RequestTarget::fromUrl($method, $url, $this->fingerprintKey, $this->capturePaths));
        $this->calls[] = $call;

        return $call;
    }

    /** @return list<OutboundCall> */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public function getCallCount(): int
    {
        return count($this->calls);
    }

    public function getReport(): OutboundReport
    {
        return $this->analyzer->analyze($this->calls, $this->budget);
    }

    /** Sum of known terminal transfer durations, not incoming-request wall-clock time. */
    public function getTotalDurationMs(): float
    {
        return array_sum(array_map(static fn (OutboundCall $call): float => $call->getDurationMs() ?? 0.0, $this->calls));
    }

    public function getSlowestCall(): ?OutboundCall
    {
        $slowest = null;

        foreach ($this->calls as $call) {
            $duration = $call->getDurationMs();

            if (null !== $duration && (null === $slowest || $duration > $slowest->getDurationMs())) {
                $slowest = $call;
            }
        }

        return $slowest;
    }

    public function reset(): void
    {
        // Callbacks retain their own call object, so old responses cannot mutate this new collection.
        $this->calls = [];
        $this->fingerprintKey = random_bytes(32);
    }
}

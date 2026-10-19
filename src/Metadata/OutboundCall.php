<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Metadata;

/** One logical request; mutable only as its asynchronous transfer is observed. */
final class OutboundCall
{
    private ?int $statusCode = null;
    private ?float $durationMs = null;
    private CallOutcome $outcome = CallOutcome::Pending;

    public function __construct(
        public readonly string $method,
        public readonly RequestTarget $target,
    ) {
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    public function getOutcome(): CallOutcome
    {
        return $this->outcome;
    }

    public function isSuccessful(): ?bool
    {
        if (null !== $this->statusCode && $this->statusCode >= 400) {
            return false;
        }

        return match ($this->outcome) {
            CallOutcome::Pending => null,
            CallOutcome::Success => true,
            default => false,
        };
    }

    /** @internal Called by the monitoring client without consuming response headers. */
    public function recordStatus(?int $statusCode): void
    {
        if (null !== $statusCode && $statusCode >= 100 && $statusCode <= 599) {
            $this->statusCode = $statusCode;
        }
    }

    /** @internal A call can finish only once, even if its response is read repeatedly. */
    public function finish(CallOutcome $outcome, ?float $durationMs): void
    {
        if (CallOutcome::Pending !== $this->outcome) {
            return;
        }

        $this->outcome = $outcome;
        $this->durationMs = null !== $durationMs && is_finite($durationMs) && $durationMs >= 0 ? $durationMs : null;
    }
}

<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Metadata;

enum CallOutcome: string
{
    case Pending = 'pending';
    case Success = 'success';
    case HttpError = 'http_error';
    case TransportError = 'transport_error';
    case Cancelled = 'cancelled';
}

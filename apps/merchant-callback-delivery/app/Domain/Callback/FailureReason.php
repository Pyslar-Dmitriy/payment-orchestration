<?php

declare(strict_types=1);

namespace App\Domain\Callback;

enum FailureReason: string
{
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case Non2xx = 'non_2xx';
    case InvalidResponse = 'invalid_response';
    case TlsError = 'tls_error';
}

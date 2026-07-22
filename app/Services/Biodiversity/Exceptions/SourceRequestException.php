<?php

namespace App\Services\Biodiversity\Exceptions;

use RuntimeException;

final class SourceRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}

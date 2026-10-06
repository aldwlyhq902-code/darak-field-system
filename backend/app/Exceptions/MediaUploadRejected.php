<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An evidence upload step the device must react to: a stable code the app
 * switches on, the HTTP status, and any hint (e.g. the offset to resume from).
 */
class MediaUploadRejected extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}

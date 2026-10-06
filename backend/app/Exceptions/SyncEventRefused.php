<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A device event refused for a business reason the technician can act on.
 * The message is written for the device and is safe to return verbatim; the
 * code is what the app switches on.
 */
class SyncEventRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

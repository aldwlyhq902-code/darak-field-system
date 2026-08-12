<?php

namespace App\Exceptions;

use RuntimeException;

/** A device event is structurally valid JSON but unsafe for its event type. */
class InvalidSyncPayload extends RuntimeException {}

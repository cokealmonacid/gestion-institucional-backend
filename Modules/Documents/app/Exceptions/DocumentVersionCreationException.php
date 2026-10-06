<?php

namespace Modules\Documents\Exceptions;

use RuntimeException;

class DocumentVersionCreationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}

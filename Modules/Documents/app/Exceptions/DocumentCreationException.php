<?php

namespace Modules\Documents\Exceptions;

use RuntimeException;

class DocumentCreationException extends RuntimeException
{
    /** @param array<string, array<int, string>>|null $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?array $fields = null,
    ) {
        parent::__construct($message);
    }
}

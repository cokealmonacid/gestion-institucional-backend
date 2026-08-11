<?php

namespace Modules\Institution\Exceptions;

use RuntimeException;

class NodeCreationException extends RuntimeException
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

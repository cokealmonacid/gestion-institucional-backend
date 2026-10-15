<?php

namespace Modules\Institution\Exceptions;

class InstitutionAccessException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 409, public readonly array $details = [])
    {
        parent::__construct($errorCode);
    }
}

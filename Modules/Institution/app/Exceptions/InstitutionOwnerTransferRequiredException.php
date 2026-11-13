<?php

namespace Modules\Institution\Exceptions;

use RuntimeException;

class InstitutionOwnerTransferRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transfer institution ownership through administration before changing or removing the owner.');
    }
}

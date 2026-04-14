<?php

namespace App\Enums;

enum RoleType: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Reader = 'reader';
}

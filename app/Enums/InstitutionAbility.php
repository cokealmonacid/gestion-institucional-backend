<?php

namespace App\Enums;

enum InstitutionAbility: string
{
    case View = 'institution.view';
    case ManageUsers = 'users.manage';
    case ManageNodes = 'nodes.manage';
    case ManageDocuments = 'documents.manage';
    case ManageVersions = 'versions.manage';
    case ManageTags = 'tags.manage';
    case TagDocuments = 'documents.tag';
    case ManageComments = 'comments.manage';
    case ViewTraceability = 'traceability.view';
}

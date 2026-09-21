<?php

namespace Modules\Documents\Enums;

enum DocumentEventType: string
{
    case Created = 'document.created';
    case VersionUploaded = 'document.version_uploaded';
    case CurrentVersionChanged = 'document.current_version_changed';
    case ResponsibleAssigned = 'document.responsible_assigned';
    case ResponsibleChanged = 'document.responsible_changed';
    case ResponsibleRemoved = 'document.responsible_removed';
}

<?php

namespace Modules\Documents\Enums;

enum DocumentEventType: string
{
    case Created = 'document.created';
    case Renamed = 'document.renamed';
    case VersionUploaded = 'document.version_uploaded';
    case CurrentVersionChanged = 'document.current_version_changed';
    case VersionNoteUpdated = 'document.version_note_updated';
    case VersionNoteCleared = 'document.version_note_cleared';
    case ResponsibleAssigned = 'document.responsible_assigned';
    case ResponsibleChanged = 'document.responsible_changed';
    case ResponsibleRemoved = 'document.responsible_removed';
}

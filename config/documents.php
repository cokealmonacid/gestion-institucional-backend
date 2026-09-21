<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Document file storage disk
    |--------------------------------------------------------------------------
    |
    | Keep document storage separate from Laravel's global filesystem default.
    | Set this to "r2" after Cloudflare R2 credentials are configured.
    |
    */

    'storage_disk' => env('DOCUMENTS_FILESYSTEM_DISK'),
];

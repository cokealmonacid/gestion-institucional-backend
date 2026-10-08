<?php

namespace App\Services\Demo;

use Illuminate\Support\Facades\Storage;

class UniversidadDelRioDemoFileCleaner
{
    public function deleteExact(string $diskName, string $path): bool
    {
        try {
            $disk = Storage::disk($diskName);
            $disk->delete($path);

            return ! $disk->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }
}

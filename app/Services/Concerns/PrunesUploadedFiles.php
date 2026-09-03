<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Every bulk-upload action (Events/Members/Categories/CompReasons/
 * PrepayList) writes the uploaded spreadsheet to local disk via Filament's
 * FileUpload component before this app ever reads it — that file is never
 * cleaned up on its own, so the upload directory grows without bound over
 * time (every test run alone leaves one behind, since the physical file
 * write isn't part of the RefreshDatabase transaction). Called after a
 * successful upload to trim the directory back down to the most recent few.
 */
trait PrunesUploadedFiles
{
    private function pruneUploads(string $directory, int $keep = 4): void
    {
        $disk = Storage::disk('local');

        collect($disk->files($directory))
            ->sortByDesc(fn (string $path) => $disk->lastModified($path))
            ->values()
            ->slice($keep)
            ->each(fn (string $path) => $disk->delete($path));
    }
}

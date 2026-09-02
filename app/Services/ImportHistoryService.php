<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessReceipt;
use App\Models\History;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pulls receipt files from Google Drive and hands them to the same queue job
 * the Telegram bot uses, so there is only one parsing path.
 */
class ImportHistoryService
{
    public static function sync(): int
    {
        $files = \Yaza\LaravelGoogleDriveStorage\Gdrive::all('Groceries');
        $queued = 0;

        foreach ($files as $file) {
            // The old code deduped on extraMetadata()['filename'] but saved under
            // extraMetadata()['name'], so the check never matched. Use one value.
            $name = $file->extraMetadata()['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            if (self::existRecord($name)) {
                continue;
            }

            try {
                $fileContent = \Yaza\LaravelGoogleDriveStorage\Gdrive::get($file->path());

                if (! $fileContent) {
                    continue;
                }

                if (! Storage::put('sync/'.$name, $fileContent->file)) {
                    continue;
                }

                self::import(Storage::path('sync/'.$name), $name);
                $queued++;
            } catch (Throwable $e) {
                Log::error('Google Drive sync failed for a file.', [
                    'file' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $queued;
    }

    public static function existRecord(string $fileName): bool
    {
        return History::where('filename', $fileName)->exists();
    }

    public static function import(string $filePath, string $filename): void
    {
        ProcessReceipt::dispatch($filePath, $filename);
    }
}

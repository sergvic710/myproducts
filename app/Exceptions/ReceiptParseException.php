<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ReceiptParseException extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self("Receipt file not found: {$path}");
    }

    public static function unsupportedType(string $path, ?string $mimeType): self
    {
        return new self(sprintf(
            'Unsupported receipt type "%s" for %s. Send a PDF or an image.',
            $mimeType ?? 'unknown',
            basename($path)
        ));
    }

    public static function fileTooLarge(string $path, int $size, int $limit): self
    {
        return new self(sprintf(
            'Receipt %s is %d MB, the limit is %d MB.',
            basename($path),
            (int) round($size / 1024 / 1024),
            (int) round($limit / 1024 / 1024)
        ));
    }

    public static function emptyResponse(string $path): self
    {
        return new self('The model returned no data for '.basename($path));
    }

    public static function unknownShop(string $shop): self
    {
        return new self("Shop \"{$shop}\" is not in the shops table. Add it first.");
    }

    public static function missingFallbackCategory(string $name): self
    {
        return new self("Fallback category \"{$name}\" is missing from the categories table.");
    }
}

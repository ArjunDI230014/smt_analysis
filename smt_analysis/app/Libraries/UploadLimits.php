<?php

namespace App\Libraries;

class UploadLimits
{
    public static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (float) $value;
        $multiplier = match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        return max(0, (int) ($number * $multiplier));
    }

    public static function current(): array
    {
        $post = self::bytes((string) ini_get('post_max_size'));
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        // Reserve space for the multipart boundaries and form fields.
        $effective = min(DailyCsatImporter::MAX_BYTES, $upload, $post > 0 ? max(0, $post - 65536) : PHP_INT_MAX);
        return ['bytes' => $effective, 'label' => self::label($effective), 'limited' => $effective < DailyCsatImporter::MAX_BYTES];
    }

    public static function label(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 2, '.', ''), '0'), '.') . ' MB';
    }
}

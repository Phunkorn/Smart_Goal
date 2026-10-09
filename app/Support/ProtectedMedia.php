<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ProtectedMedia
{
    public const ATTACHMENT_DISK = 'local';

    public const LEGACY_ATTACHMENT_DISK = 'public';

    public const PROFILE_DISK = 'public';

    public static function storeAttachment(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, self::ATTACHMENT_DISK);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Unable to store protected attachment.');
        }

        return $path;
    }

    public static function deleteAttachment(?string $path): void
    {
        if (! self::isSafeRelativePath($path)) {
            return;
        }

        Storage::disk(self::ATTACHMENT_DISK)->delete($path);
        Storage::disk(self::LEGACY_ATTACHMENT_DISK)->delete($path);
    }

    public static function attachmentAbsolutePath(string $path): ?string
    {
        foreach ([self::ATTACHMENT_DISK, self::LEGACY_ATTACHMENT_DISK] as $disk) {
            $absolutePath = self::absolutePath($disk, $path);

            if ($absolutePath !== null) {
                return $absolutePath;
            }
        }

        return null;
    }

    public static function profileAbsolutePath(string $path): ?string
    {
        return self::absolutePath(self::PROFILE_DISK, $path);
    }

    /**
     * ชื่อไฟล์ที่ควรใช้บน Content-Disposition — แหล่งความจริงเดียวของทุกชนิดไฟล์แนบ
     *
     * ถ้าไม่ส่งชื่อออกไปกับ response เบราว์เซอร์จะตั้งชื่อไฟล์จากส่วนท้ายของ URL
     * ซึ่ง route ไฟล์แนบทุกเส้นลงท้ายด้วย id ผู้ใช้จึงได้ไฟล์ชื่อ "224" ที่ไม่มีนามสกุล
     * และเปิดด้วยโปรแกรมเดิมไม่ได้
     *
     * ชื่อเดิมมาจากผู้ใช้ จึงตัด path separator ทั้งสองแบบออกก่อนคืนค่า
     * มิฉะนั้น Symfony จะโยน InvalidArgumentException เมื่อชื่อมี / หรือ \ ติดมา
     */
    public static function dispositionName(?string $originalName, ?string $filePath): ?string
    {
        foreach ([$originalName, $filePath] as $candidate) {
            $name = basename(str_replace('\\', '/', trim((string) $candidate)));

            if ($name !== '' && $name !== '.' && $name !== '..') {
                return $name;
            }
        }

        return null;
    }

    public static function isSafeRelativePath(?string $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function absolutePath(string $disk, string $path): ?string
    {
        if (! self::isSafeRelativePath($path) || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $root = realpath(Storage::disk($disk)->path(''));
        $absolutePath = realpath(Storage::disk($disk)->path($path));

        if ($root === false || $absolutePath === false) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($rootPrefix) : $rootPrefix;
        $comparisonPath = DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($absolutePath) : $absolutePath;

        return str_starts_with($comparisonPath, $comparisonRoot) ? $absolutePath : null;
    }
}

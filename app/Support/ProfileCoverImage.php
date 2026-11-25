<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ProfileCoverImage
{
    public static function belongsTo(int $owner, ?string $path): bool
    {
        return ProtectedMedia::isSafeRelativePath($path) && str_starts_with($path, 'profile-covers/'.$owner.'/');
    }

    public static function delete(int $owner, ?string $path): void
    {
        if (self::belongsTo($owner, $path)) {
            Storage::disk(ProtectedMedia::ATTACHMENT_DISK)->delete($path);
        }
    }

    public static function store(UploadedFile $file, int $owner): string
    {
        $limits = config('profile-appearance.cover');
        $path = $file->getPathname();
        $dimensions = @getimagesize($path);
        if (! $dimensions || $file->getSize() > $limits['max_kilobytes'] * 1024) {
            throw self::invalid('ไฟล์ภาพปกไม่ถูกต้องหรือมีขนาดเกินที่กำหนด');
        }

        [$width, $height, $type] = $dimensions;
        if ($width < 1 || $height < 1 || max($width, $height) > $limits['max_dimension'] || $width * $height > $limits['max_pixels']) {
            throw self::invalid('ภาพปกมีจำนวนพิกเซลเกินที่กำหนด');
        }

        $decoder = match ($type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        };
        if (! $decoder) {
            throw self::invalid('ชนิดไฟล์ภาพปกไม่รองรับ');
        }
        if (! function_exists($decoder) || ! function_exists('imagewebp')) {
            throw self::invalid('เครื่องแม่ข่ายยังไม่รองรับการอัปโหลดภาพปก');
        }

        $ratio = min(1, $limits['output_dimension'] / max($width, $height));
        $outputWidth = max(1, (int) round($width * $ratio));
        $outputHeight = max(1, (int) round($height * $ratio));
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        // Budget the source, resampled/rotated buffers and room for the encoder.
        $estimatedPeak = memory_get_usage(true) + 5 * $width * $height
            + 8 * $outputWidth * $outputHeight + 16 * 1024 * 1024;
        if ($memoryLimit > 0 && $estimatedPeak > $memoryLimit) {
            throw self::invalid('ภาพปกมีขนาดเกินที่ระบบประมวลผลได้ กรุณาเลือกภาพที่มีขนาดเล็กลง');
        }

        $source = @$decoder($path);
        if (! $source) {
            throw self::invalid('ไม่สามารถอ่านไฟล์ภาพปกได้ กรุณาเลือกภาพนิ่งที่สมบูรณ์');
        }

        // Resample once on upload. The stored file is also the Work Board image.
        $image = imagecreatetruecolor($outputWidth, $outputHeight);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $width, $height);
        unset($source);
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $image = self::orient($image, (int) (@exif_read_data($path)['Orientation'] ?? 1));
        }

        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Unable to open a temporary cover file.');
        }
        try {
            if (! @imagewebp($image, $output, $limits['webp_quality']) || ftell($output) < 1) {
                throw new RuntimeException('Unable to encode profile cover.');
            }
            rewind($output);
            $destination = 'profile-covers/'.$owner.'/'.Str::uuid().'.webp';
            $disk = Storage::disk(ProtectedMedia::ATTACHMENT_DISK);
            if (! $disk->put($destination, $output)) {
                $disk->delete($destination);
                throw new RuntimeException('Unable to store profile cover.');
            }

            return $destination;
        } finally {
            fclose($output);
        }
    }

    private static function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => null,
        };

        return $angle === null ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['profile_cover' => $message])->errorBag('appearance');
    }
}

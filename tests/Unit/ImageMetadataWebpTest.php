<?php

namespace Tests\Unit;

use App\Support\ImageMetadata;
use PHPUnit\Framework\TestCase;

/**
 * WebP: ตัด EXIF (GPS ฯลฯ) และ XMP ออก ล้างธงใน VP8X และคง Orientation ไว้เมื่อไม่ใช่ 1
 */
class ImageMetadataWebpTest extends TestCase
{
    public function test_exif_and_xmp_chunks_are_removed_and_flags_cleared(): void
    {
        $exif = $this->tiffWithOrientation(1).'GPS-LAT-13.7563';
        $path = $this->write($this->webp([
            $this->chunk('VP8X', chr(0x08 | 0x04 | 0x10)."\0\0\0".str_repeat("\0", 6)),
            $this->chunk('VP8 ', str_repeat("\x01", 11)),
            $this->chunk('EXIF', $exif),
            $this->chunk('XMP ', '<x:xmpmeta>secret</x:xmpmeta>'),
        ]));

        $this->assertTrue(ImageMetadata::strip($path, 'image/webp'));
        $clean = (string) file_get_contents($path);

        $this->assertStringNotContainsString('GPS-LAT', $clean);
        $this->assertStringNotContainsString('xmpmeta', $clean);
        $this->assertStringNotContainsString('EXIF', $clean);
        $this->assertSame(0x10, ord($clean[20]), 'เหลือธง alpha เดิม ธง EXIF/XMP ถูกล้าง');
        $this->assertSame(strlen($clean) - 8, unpack('V', substr($clean, 4, 4))[1], 'ขนาด RIFF ถูกคำนวณใหม่');
        $this->assertStringContainsString(str_repeat("\x01", 11), $clean, 'ข้อมูลภาพไม่ถูกแตะ');
    }

    public function test_a_non_default_orientation_survives_as_a_minimal_exif_chunk(): void
    {
        $path = $this->write($this->webp([
            $this->chunk('VP8X', "\0\0\0\0".str_repeat("\0", 6)),
            $this->chunk('VP8 ', str_repeat("\x02", 10)),
            $this->chunk('EXIF', "Exif\0\0".$this->tiffWithOrientation(6).'CAMERA-MODEL'),
        ]));

        $this->assertTrue(ImageMetadata::strip($path, 'image/webp'));
        $clean = (string) file_get_contents($path);

        $this->assertStringNotContainsString('CAMERA-MODEL', $clean);
        $this->assertStringContainsString('EXIF', $clean);
        $this->assertSame(0x08, ord($clean[20]) & 0x08, 'ธง EXIF ยังอยู่เพราะมี Orientation');
    }

    public function test_simple_webp_files_and_garbage_are_left_alone(): void
    {
        $simple = $this->webp([$this->chunk('VP8L', str_repeat("\x03", 9))]);
        $path = $this->write($simple);
        $this->assertTrue(ImageMetadata::strip($path, 'image/webp'));
        $this->assertSame(strlen($simple), strlen((string) file_get_contents($path)));

        $garbage = $this->write('RIFF....NOTWEBP');
        $this->assertFalse(ImageMetadata::strip($garbage, 'image/webp'));
        $this->assertSame('RIFF....NOTWEBP', file_get_contents($garbage));
    }

    private function tiffWithOrientation(int $orientation): string
    {
        return 'MM'.pack('n', 0x2A).pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0).pack('N', 0);
    }

    private function chunk(string $type, string $payload): string
    {
        return $type.pack('V', strlen($payload)).$payload.(strlen($payload) % 2 ? "\0" : '');
    }

    /** @param  list<string>  $chunks */
    private function webp(array $chunks): string
    {
        $body = 'WEBP'.implode('', $chunks);

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    private function write(string $data): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'webp');
        file_put_contents($path, $data);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }
}

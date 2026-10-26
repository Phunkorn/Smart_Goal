<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketAttachment;
use App\Support\ImageMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * รูปที่แนบกับ Ticket ต้องไม่พกพิกัด GPS / รุ่นเครื่อง / ข้อความ metadata ไปถึงผู้เปิดดู
 * แต่ภาพต้องเหมือนเดิมทุกพิกเซลและยังหมุนถูกทาง (App\Support\ImageMetadata)
 */
class TicketPhotoPrivacyTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_gps_and_camera_details_are_removed_but_orientation_and_pixels_are_kept(): void
    {
        $this->assertTrue(function_exists('exif_read_data'), 'ต้องมี ext-exif เพื่อตรวจผล');
        $upload = $this->jpegWithExif(orientation: 6);
        $before = exif_read_data($upload->getRealPath());
        $this->assertArrayHasKey('GPSLatitude', $before, 'fixture มี GPS จริง');
        $this->assertSame('TestPhone', $before['Make']);
        $pixelsBefore = $this->pixels($upload->getRealPath());

        $stored = $this->submitWith($upload);

        $after = @exif_read_data($stored) ?: [];
        $this->assertArrayNotHasKey('GPSLatitude', $after);
        $this->assertArrayNotHasKey('GPSLongitude', $after);
        $this->assertArrayNotHasKey('Make', $after);
        $this->assertSame(6, $after['Orientation'] ?? null, 'ยังหมุนภาพถูกทาง');
        $this->assertStringNotContainsString('TestPhone', (string) file_get_contents($stored));
        $this->assertSame($pixelsBefore, $this->pixels($stored), 'ไม่เข้ารหัสภาพใหม่ — พิกเซลเท่าเดิม');
        $this->assertSame(filesize($stored), TicketAttachment::query()->sole()->file_size);
    }

    public function test_an_upright_photo_keeps_no_exif_at_all(): void
    {
        $stored = $this->submitWith($this->jpegWithExif(orientation: 1));

        $this->assertStringNotContainsString("Exif\0\0", (string) file_get_contents($stored));
        $this->assertNotFalse(imagecreatefromjpeg($stored));
        $this->assertStringStartsWith("\xFF\xD8\xFF\xE0", (string) file_get_contents($stored), 'APP0 (JFIF) ยังอยู่หน้าสุด');
    }

    public function test_png_text_and_exif_chunks_are_removed(): void
    {
        $stored = $this->submitWith($this->pngWithMetadata());
        $contents = (string) file_get_contents($stored);

        $this->assertStringNotContainsString('13.7563,100.5018', $contents);
        $this->assertStringNotContainsString('eXIf', $contents);
        $this->assertStringNotContainsString('tEXt', $contents);
        $this->assertNotFalse(imagecreatefrompng($stored));
        $this->assertSame([40, 30], array_slice(getimagesize($stored), 0, 2));
    }

    public function test_an_unreadable_structure_is_left_untouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tkbad');
        file_put_contents($path, "\xFF\xD8\xFF\xE1\x7F\xFFtruncated");

        $this->assertFalse(ImageMetadata::strip($path, 'image/jpeg'));
        $this->assertSame("\xFF\xD8\xFF\xE1\x7F\xFFtruncated", file_get_contents($path));
    }

    private function submitWith(UploadedFile $photo): string
    {
        $this->actingAs($this->staff($this->department('Sales')))
            ->post(route('tickets.store'), [...$this->internalForm(), 'photos' => [$photo]])
            ->assertRedirect();

        return Storage::disk('local')->path(TicketAttachment::query()->sole()->file_path);
    }

    private function pixels(string $path): string
    {
        $image = imagecreatefromjpeg($path);
        $hash = '';
        for ($y = 0; $y < imagesy($image); $y += 7) {
            for ($x = 0; $x < imagesx($image); $x += 7) {
                $hash .= imagecolorat($image, $x, $y).',';
            }
        }

        return md5($hash);
    }

    /** JPEG จาก GD + EXIF (little-endian) ที่มี Make, Orientation และ GPS IFD */
    private function jpegWithExif(int $orientation): UploadedFile
    {
        $image = imagecreatetruecolor(64, 48);
        imagefilledrectangle($image, 0, 0, 31, 47, imagecolorallocate($image, 200, 40, 40));
        imagefilledrectangle($image, 32, 0, 63, 47, imagecolorallocate($image, 40, 40, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $entry = fn (int $tag, int $type, int $count, string $value) => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");
        $tiff = 'II'.pack('vV', 0x2A, 8)
            .pack('v', 3)
            .$entry(0x010F, 2, 10, pack('V', 50))           // Make → offset 50
            .$entry(0x0112, 3, 1, pack('v', $orientation))  // Orientation
            .$entry(0x8825, 4, 1, pack('V', 60))            // GPS IFD → offset 60
            .pack('V', 0)
            ."TestPhone\0"                                  // 50..59
            .pack('v', 2)                                   // GPS IFD at 60
            .$entry(0x0001, 2, 2, "N\0")                    // GPSLatitudeRef
            .$entry(0x0002, 5, 3, pack('V', 90))            // GPSLatitude → offset 90
            .pack('V', 0)
            .pack('VVVVVV', 13, 1, 45, 1, 1234, 100);       // 90..113
        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // ต่อท้าย APP0 (JFIF) ของ GD เหมือนไฟล์จากกล้องทั่วไป
        $app0 = 2 + unpack('n', substr($jpeg, 4, 2))[1];
        $path = tempnam(sys_get_temp_dir(), 'tkexif');
        file_put_contents($path, substr($jpeg, 0, 2 + $app0).$app1.substr($jpeg, 2 + $app0));

        return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }

    private function pngWithMetadata(): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $extra = $chunk('tEXt', "Location\0".'13.7563,100.5018').$chunk('eXIf', 'MM'.pack('nN', 0x2A, 8).pack('n', 0).pack('N', 0));

        // ต่อหลัง IHDR (8 + 25 ไบต์)
        $path = tempnam(sys_get_temp_dir(), 'tkpngm');
        file_put_contents($path, substr($png, 0, 33).$extra.substr($png, 33));

        return new UploadedFile($path, 'shot.png', 'image/png', null, true);
    }
}

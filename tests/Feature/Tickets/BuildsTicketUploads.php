<?php

namespace Tests\Feature\Tickets;

use App\Support\TicketSubmissionGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ไฟล์และค่าฟอร์มสำหรับเทสต์การแจ้งปัญหา
 */
trait BuildsTicketUploads
{
    protected function photo(string $name = 'screen.jpg', int $width = 640, int $height = 480): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /** ไฟล์ PDF ที่ถูกเปลี่ยนนามสกุลเป็น .jpg — MIME จากเนื้อไฟล์ไม่ตรงกับนามสกุล */
    protected function disguisedPdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tkpdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");

        return new UploadedFile($path, 'invoice.jpg', 'image/jpeg', null, true);
    }

    /**
     * PNG ไม่กี่สิบไบต์ที่ประกาศขนาดภาพ 20,000×20,000 พิกเซล (decompression bomb)
     * ส่วนหัวถูกต้องตามมาตรฐาน finfo จึงรายงาน image/png และ getimagesize() อ่านขนาดได้
     */
    protected function decompressionBombPng(): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(''))
            .$chunk('IEND', '');

        $path = tempnam(sys_get_temp_dir(), 'tkpng');
        file_put_contents($path, $png);

        return new UploadedFile($path, 'huge.png', 'image/png', null, true);
    }

    /** token ของฟอร์มสาธารณะที่ออกไว้ก่อนหน้า (ค่าเริ่มต้น 10 วินาที — ผ่านเวลากรอกขั้นต่ำ) */
    protected function formToken(int $secondsAgo = 10): string
    {
        Carbon::setTestNow(now()->subSeconds($secondsAgo));
        $token = TicketSubmissionGuard::issueToken();
        Carbon::setTestNow();

        return $token;
    }

    /** @return array<string, mixed> */
    protected function publicForm(array $overrides = []): array
    {
        return [
            'requester_name' => 'สมชาย ใจดี',
            'location_id' => 'other',
            'location_name' => 'สาขาบางนา',
            'description' => 'คอมพิวเตอร์เครื่องแคชเชียร์เปิดไม่ติด',
            // ฟอร์มสาธารณะบังคับเบอร์โทร 10 หลัก
            'contact' => '0812345678',
            'submission_key' => (string) Str::uuid(),
            TicketSubmissionGuard::TOKEN_FIELD => $this->formToken(),
            TicketSubmissionGuard::HONEYPOT_FIELD => '',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    protected function internalForm(array $overrides = []): array
    {
        return [
            'location_id' => 'other',
            'location_name' => 'ชั้น 3 ฝ่ายบัญชี',
            'description' => 'เครื่องพิมพ์ชั้น 3 พิมพ์ไม่ออก',
            'submission_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }
}

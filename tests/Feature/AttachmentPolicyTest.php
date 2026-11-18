<?php

namespace Tests\Feature;

use App\Support\AttachmentPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * เดิมเพดาน "5 ไฟล์ / 10 MB" และรายการชนิดไฟล์ถูกฮาร์ดโค้ดกระจายกว่า 20 จุด
 * และตรวจนามสกุลกับ MIME เป็นสองรายการอิสระ ซึ่งเปิดช่องเมื่อรับ .zip
 * เพราะ .docx .xlsx .pptx คือไฟล์ ZIP โดยโครงสร้าง
 */
class AttachmentPolicyTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_zip_and_pdf_are_accepted_alongside_the_office_formats(): void
    {
        $this->assertContains('zip', AttachmentPolicy::extensions());
        $this->assertContains('pdf', AttachmentPolicy::extensions());
        $this->assertSame(20, AttachmentPolicy::MAX_FILES);
        $this->assertSame(1024, AttachmentPolicy::maxMegabytes());
        $this->assertSame('1 GB', AttachmentPolicy::maxSizeLabel());
        $this->assertNull(AttachmentPolicy::rejectionReason($this->zip('งาน.zip')));
    }

    public function test_plain_text_is_accepted_but_a_renamed_archive_is_not(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sgtxt');
        file_put_contents($path, "บันทึกงาน\nข้อความทั่วไป\n");
        $this->temporaryFiles[] = $path;

        $this->assertContains('txt', AttachmentPolicy::extensions());
        $this->assertNull(AttachmentPolicy::rejectionReason(new UploadedFile($path, 'notes.txt', 'application/zip', null, true)));
        $this->assertStringContainsString('ไม่ตรงกับนามสกุล', AttachmentPolicy::rejectionReason($this->zip('fake.txt')));
    }

    /**
     * หัวใจของการเปิดรับ ZIP: ต้องจับคู่ "นามสกุล ↔ MIME" ไม่ใช่ตรวจสองรายการแยกกัน
     * มิฉะนั้นไฟล์ ZIP ที่เปลี่ยนชื่อเป็น .docx จะผ่านทันทีเพราะ MIME ของ zip อยู่ในรายการรวม
     */
    public function test_a_zip_renamed_to_docx_is_still_rejected(): void
    {
        $reason = AttachmentPolicy::rejectionReason($this->zip('ปลอม.docx'));

        $this->assertNotNull($reason);
        $this->assertStringContainsString('ไม่ตรงกับนามสกุล', $reason);
    }

    public function test_unknown_extensions_are_named_in_the_error(): void
    {
        $reason = AttachmentPolicy::rejectionReason($this->zip('ตัวติดตั้ง.exe'));

        $this->assertNotNull($reason);
        $this->assertStringContainsString('.exe', $reason);
        $this->assertStringContainsString('ZIP', $reason);
    }

    public function test_ui_strings_and_rules_all_come_from_the_same_list(): void
    {
        $accept = AttachmentPolicy::acceptAttribute();

        // ข้อความ "ต้องเป็นไฟล์ชนิด: ..." ของกฎ validation ต้องมาจากรายการเดียวกัน
        $html = tempnam(sys_get_temp_dir(), 'sghtml');
        file_put_contents($html, '<!doctype html><html><body>page</body></html>');
        $this->temporaryFiles[] = $html;

        $validator = Validator::make(
            ['file' => new UploadedFile($html, 'page.html', 'text/html', null, true)],
            ['file' => AttachmentPolicy::fileRules()]
        );
        $this->assertTrue($validator->fails());
        $rejection = $validator->errors()->first('file');

        foreach (AttachmentPolicy::extensions() as $extension) {
            $this->assertStringContainsString('.'.$extension, $accept);
            $this->assertStringContainsString($extension, $rejection);
        }

        $this->assertStringContainsString('20 ไฟล์', AttachmentPolicy::limitsLabel());
        $this->assertStringContainsString('1 GB', AttachmentPolicy::limitsLabel());
        $this->assertStringContainsString('7', AttachmentPolicy::tooManyMessage(7));
    }

    private function zip(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sgzip');
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString('readme.txt', 'smart goal');
        $archive->close();
        $this->temporaryFiles[] = $path;

        // ส่ง MIME ปลอมจาก client เข้าไปด้วย เพื่อพิสูจน์ว่าระบบอ่านจากเนื้อไฟล์จริงเท่านั้น
        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }
}

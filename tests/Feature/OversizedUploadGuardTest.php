<?php

namespace Tests\Feature;

use App\Support\ByteSize;
use Tests\TestCase;

/**
 * post_max_size คุมขนาด "ทั้ง request" ไม่ใช่รายไฟล์ เมื่อเพดานไฟล์แนบเป็นระดับกิกะไบต์
 * การแนบหลายไฟล์ใหญ่พร้อมกันจึงชนเพดานนี้ได้จริง
 *
 * Laravel มี ValidatePostSize ดักไว้ให้แล้ว จึงไม่เขียน middleware ซ้ำ
 * แต่ข้อความเริ่มต้น "The POST data is too large." เป็นภาษาอังกฤษและไม่บอกเพดาน
 */
class OversizedUploadGuardTest extends TestCase
{
    public function test_ini_shorthand_sizes_are_parsed_not_cast(): void
    {
        // (int) "2G" ได้ 2 ซึ่งผิดไปพันล้านเท่า
        $this->assertSame(2 * 1024 ** 3, ByteSize::toBytes('2G'));
        $this->assertSame(512 * 1024 ** 2, ByteSize::toBytes('512M'));
        $this->assertSame(8192 * 1024, ByteSize::toBytes('8192K'));
        $this->assertSame(1024, ByteSize::toBytes('1024'));
        $this->assertSame(0, ByteSize::toBytes(''));
    }

    public function test_sizes_are_reported_in_units_people_read(): void
    {
        $this->assertSame('2 GB', ByteSize::humanize(2 * 1024 ** 3));
        $this->assertSame('1.5 GB', ByteSize::humanize((int) (1.5 * 1024 ** 3)));
        $this->assertSame('50 MB', ByteSize::humanize(50 * 1024 ** 2));
        $this->assertSame('900 bytes', ByteSize::humanize(900));
    }

    public function test_an_oversized_upload_is_explained_in_thai_with_the_real_limit(): void
    {
        $limit = ByteSize::fromIni('post_max_size');
        $this->assertGreaterThan(0, $limit, 'เทสต์นี้ต้องการ post_max_size ที่ตั้งค่าไว้');

        $response = $this->call('POST', route('mytasks.store'), [], [], [], [
            'CONTENT_LENGTH' => (string) ($limit + 1),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('ok', false);
        $this->assertStringContainsString('ใหญ่เกิน', $response->json('message'));
        // ต้องบอกเพดานจริงของเซิร์ฟเวอร์ ไม่ใช่ข้อความลอย ๆ
        $this->assertStringContainsString(ByteSize::humanize($limit), $response->json('message'));
        $this->assertStringNotContainsString('POST data is too large', $response->getContent());
    }

    public function test_a_normal_request_is_not_blocked(): void
    {
        // คำขอปกติมี body จริง จึงต้องผ่านด่านนี้ไปเจอการตรวจสิทธิ์ตามปกติ
        $response = $this->post(route('mytasks.store'), ['job_topic' => 'งานทดสอบ']);

        $this->assertNotSame(413, $response->getStatusCode());
    }
}

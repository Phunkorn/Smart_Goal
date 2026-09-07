<?php

namespace Tests\Feature;

use App\Support\WorkspaceDesign;
use App\Support\WorkspaceDocumentValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * การกรองเนื้อหากระดานที่ส่งมาจากเบราว์เซอร์
 *
 * ทุกคนในแผนกยิง JSON อะไรเข้ามาก็ได้ และเนื้อหานั้นถูกเรนเดอร์ให้เพื่อนร่วมงาน
 * ทุกคนเห็น ตัวกรองนี้จึงเป็นด่านสำคัญ ไม่ใช่แค่การตรวจความเรียบร้อย
 *
 * ไม่ต้องใช้ฐานข้อมูล เพราะ WorkspaceDocumentValidator เป็นฟังก์ชันบริสุทธิ์
 */
class WorkspaceDocumentValidationTest extends TestCase
{
    public function test_unknown_keys_are_dropped_instead_of_passed_through(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'schema' => 99,
            'elements' => [[
                'id' => 'e-1',
                'type' => 'rect',
                'z' => 3,
                'x' => 10,
                'y' => 20,
                'w' => 100,
                'h' => 50,
                'stroke' => '#dc2626',
                'strokeWidth' => 2,
                'fill' => 'none',
                'onclick' => 'alert(1)',
                'href' => 'javascript:alert(1)',
            ]],
        ]);

        $element = $clean['elements'][0];

        $this->assertSame(1, $clean['schema'], 'เวอร์ชันสคีมาถูกกำหนดโดยเซิร์ฟเวอร์ ไม่ใช่โดยผู้ส่ง');
        $this->assertArrayNotHasKey('onclick', $element);
        $this->assertArrayNotHasKey('href', $element);
        $this->assertSame(
            ['id', 'type', 'z', 'x', 'y', 'w', 'h', 'stroke', 'strokeWidth', 'fill'],
            array_keys($element)
        );
    }

    public function test_an_unsupported_element_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [['id' => 'e-1', 'type' => 'script']],
        ]);
    }

    /**
     * รหัสชิ้นงานถูกใช้เป็นตัวเลือกใน DOM การปล่อยอักขระอิสระทำให้ selector พังได้
     */
    public function test_element_ids_are_limited_to_a_safe_character_set(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1"]><img src=x onerror=alert(1)>',
                'type' => 'pen',
                'points' => [[0, 0]],
            ]],
        ]);
    }

    /**
     * สีต้องเป็น #rrggbb เท่านั้น ค่าที่อ้างทรัพยากรภายนอกต้องถูกแทนด้วยค่าเริ่มต้น
     */
    public function test_colors_outside_the_hex_format_fall_back_to_the_default(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'pen',
                'stroke' => 'url(http://evil.example/x.svg#a)',
                'points' => [[0, 0], [1, 1]],
            ]],
        ]);

        $this->assertSame(WorkspaceDesign::DEFAULT_STROKE, $clean['elements'][0]['stroke']);
    }

    public function test_text_longer_than_the_cap_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'sticky',
                'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                'text' => str_repeat('ก', WorkspaceDesign::MAX_TEXT_LENGTH + 1),
            ]],
        ]);
    }

    /**
     * ข้อความไทยที่มีอักขระพิเศษต้องผ่านได้ตามปกติ
     *
     * การกรองต้องกันคีย์ที่อันตราย ไม่ใช่กรองเนื้อความที่ผู้ใช้ตั้งใจพิมพ์
     * ความปลอดภัยของการแสดงผลอยู่ที่ renderer.js ที่ใช้ textContent
     */
    public function test_ordinary_thai_text_with_symbols_is_preserved_verbatim(): void
    {
        $note = 'ทดสอบ <b> & "คำพูด" 100% ครับ';

        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'sticky',
                'x' => 0, 'y' => 0, 'w' => 180, 'h' => 180,
                'text' => $note,
            ]],
        ]);

        $this->assertSame($note, $clean['elements'][0]['text']);
    }

    public function test_too_many_elements_is_rejected(): void
    {
        $elements = [];

        for ($i = 0; $i <= WorkspaceDesign::MAX_ELEMENTS; $i++) {
            $elements[] = ['id' => 'e-'.$i, 'type' => 'pen', 'points' => [[0, 0]]];
        }

        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize(['elements' => $elements]);
    }

    public function test_duplicate_element_ids_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 'e-1', 'type' => 'pen', 'points' => [[0, 0]]],
                ['id' => 'e-1', 'type' => 'pen', 'points' => [[1, 1]]],
            ],
        ]);
    }

    public function test_non_finite_coordinates_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'rect',
                'x' => 'NaN', 'y' => 0, 'w' => 10, 'h' => 10,
            ]],
        ]);
    }

    /**
     * พิกัดที่หลุดขอบถูกบีบกลับ ไม่ใช่ทำให้ทั้งกระดานบันทึกไม่ได้
     */
    public function test_out_of_bounds_coordinates_are_clamped_rather_than_rejected(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'rect',
                'x' => WorkspaceDesign::WORLD_BOUND * 10,
                'y' => -WorkspaceDesign::WORLD_BOUND * 10,
                'w' => -50,
                'h' => 10,
            ]],
        ]);

        $element = $clean['elements'][0];

        $this->assertSame((float) WorkspaceDesign::WORLD_BOUND, $element['x']);
        $this->assertSame(-(float) WorkspaceDesign::WORLD_BOUND, $element['y']);
        $this->assertSame(0.0, $element['w'], 'ขนาดติดลบทำให้กรอบพลิกด้าน จึงถูกบีบเป็นศูนย์');
    }

    public function test_a_stroke_without_points_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [['id' => 'e-1', 'type' => 'pen', 'points' => []]],
        ]);
    }

    public function test_an_image_referencing_another_boards_attachment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'image',
                'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
                'attachmentId' => 999,
            ]],
        ], allowedAttachmentIds: [1, 2, 3]);
    }

    public function test_an_image_referencing_an_owned_attachment_is_accepted(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'e-1',
                'type' => 'image',
                'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
                'attachmentId' => '2',
            ]],
        ], allowedAttachmentIds: [1, 2, 3]);

        $this->assertSame(2, $clean['elements'][0]['attachmentId']);
    }

    public function test_a_document_over_the_byte_cap_is_rejected(): void
    {
        $elements = [];

        // ข้อความยาวหลายชิ้นรวมกันจนเกินเพดานไบต์ โดยจำนวนชิ้นยังไม่ถึงเพดานชิ้นงาน
        for ($i = 0; $i < 1200; $i++) {
            $elements[] = [
                'id' => 'e-'.$i,
                'type' => 'text',
                'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                'text' => str_repeat('ก', WorkspaceDesign::MAX_TEXT_LENGTH),
            ];
        }

        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize(['elements' => $elements]);
    }

    public function test_a_payload_without_an_elements_list_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize(['schema' => 1]);
    }

    public function test_an_associative_elements_map_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => ['first' => ['id' => 'e-1', 'type' => 'pen', 'points' => [[0, 0]]]],
        ]);
    }
}

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

    /**
     * เส้นตรงและลูกศรเก็บ w, h เป็นระยะถึงจุดปลาย ไม่ใช่ขนาดกรอบ
     *
     * ก่อนแก้ ค่าติดลบถูกบีบเป็นศูนย์ เส้นที่ลากไปทางซ้ายหรือขึ้นบน (รวมเส้นทแยงที่
     * ล็อกด้วย Shift และเส้นที่ถูกหมุน) จึงหดเป็นจุดเดียวหลังโหลดใหม่
     */
    public function test_line_and_arrow_keep_their_direction_after_saving(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 'l-1', 'type' => 'line', 'x' => 300, 'y' => 300, 'w' => -102.5, 'h' => -102.5],
                ['id' => 'a-1', 'type' => 'arrow', 'x' => 0, 'y' => 0, 'w' => 40, 'h' => -WorkspaceDesign::WORLD_BOUND * 10],
            ],
        ]);

        [$line, $arrow] = $clean['elements'];

        $this->assertSame(-102.5, $line['w']);
        $this->assertSame(-102.5, $line['h']);
        $this->assertSame(-(float) WorkspaceDesign::WORLD_BOUND, $arrow['h'], 'ยังถูกบีบไม่ให้หลุดขอบผืนผ้าใบ');
    }

    public function test_rotation_is_kept_for_box_elements_and_normalized_into_one_turn(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 'r-1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 45.456],
                ['id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => -90],
                ['id' => 't-1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 450],
                ['id' => 'e-1', 'type' => 'ellipse', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => '30'],
            ],
        ]);

        $this->assertSame(
            [45.46, 270.0, 90.0, 30.0],
            array_column($clean['elements'], 'rotation')
        );
        $this->assertSame(
            ['id', 'type', 'z', 'x', 'y', 'w', 'h', 'rotation', 'stroke', 'strokeWidth', 'fill'],
            array_keys($clean['elements'][0]),
            'rotation ต้องอยู่ใน allow-list ของรูปทรง ไม่ใช่คีย์ที่หลุดผ่านมา'
        );
    }

    public function test_an_image_keeps_its_rotation(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'i-1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100,
                'attachmentId' => 2, 'rotation' => 15,
            ]],
        ], allowedAttachmentIds: [2]);

        $this->assertSame(15.0, $clean['elements'][0]['rotation']);
    }

    /**
     * มุม 0 ไม่ถูกเก็บ เอกสารที่ไม่ได้หมุนอะไรจึงเหมือนก่อนมีฟีเจอร์นี้ทุกประการ
     */
    public function test_a_zero_or_full_turn_rotation_is_not_stored(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 'r-0', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0],
                ['id' => 'r-360', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 720],
                ['id' => 'r-tiny', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => -0.001],
            ],
        ]);

        foreach ($clean['elements'] as $element) {
            $this->assertArrayNotHasKey('rotation', $element, $element['id']);
        }
    }

    /**
     * เอกสารที่บันทึกไว้ก่อนมีฟีเจอร์หมุนต้องผ่านตัวกรองได้เหมือนเดิมทุกประการ
     */
    public function test_an_old_document_without_rotation_passes_unchanged(): void
    {
        $old = [
            'id' => 'r-1', 'type' => 'rect', 'z' => 1,
            'x' => 10.0, 'y' => 20.0, 'w' => 30.0, 'h' => 40.0,
            'stroke' => '#1f2937', 'strokeWidth' => 2, 'fill' => 'none',
        ];

        $clean = WorkspaceDocumentValidator::sanitize(['schema' => 1, 'elements' => [$old]]);

        $this->assertSame($old, $clean['elements'][0]);
    }

    /**
     * ดินสอ เส้นตรง และลูกศรหมุนที่ตัวพิกัด จึงไม่มีมุมให้เก็บ คีย์ที่ส่งมาต้องถูกตัดทิ้ง
     */
    public function test_rotation_is_dropped_for_point_based_elements(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 'p-1', 'type' => 'pen', 'points' => [[0, 0]], 'rotation' => 45],
                ['id' => 'l-1', 'type' => 'line', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 0, 'rotation' => 45],
            ],
        ]);

        $this->assertArrayNotHasKey('rotation', $clean['elements'][0]);
        $this->assertArrayNotHasKey('rotation', $clean['elements'][1]);
    }

    public function test_a_non_numeric_rotation_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'r-1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                'rotation' => 'rotate(45deg) url(x)',
            ]],
        ]);
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

    public function test_bold_and_italic_are_kept_for_sticky_and_text(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'bold' => true, 'italic' => true],
                ['id' => 't-1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'bold' => true, 'italic' => true],
            ],
        ]);

        foreach ($clean['elements'] as $element) {
            $this->assertTrue($element['bold']);
            $this->assertTrue($element['italic']);
        }
    }

    /**
     * ค่าอื่นที่ไม่ใช่ boolean true ถือเป็นค่าปริยาย (ไม่หนา/ไม่เอียง) และไม่ถูกเก็บ
     * เอกสารที่ไม่มีฟีเจอร์นี้จึงมีหน้าตาเหมือนก่อนมีฟีเจอร์นี้ทุกประการ
     */
    public function test_bold_and_italic_are_omitted_unless_strictly_true(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'bold' => false, 'italic' => false],
                ['id' => 's-2', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
                ['id' => 's-3', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'bold' => 'true', 'italic' => 1],
            ],
        ]);

        foreach ($clean['elements'] as $element) {
            $this->assertArrayNotHasKey('bold', $element, $element['id']);
            $this->assertArrayNotHasKey('italic', $element, $element['id']);
        }
    }

    public function test_letter_spacing_is_clamped_to_the_accepted_range(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'letterSpacing' => 3],
                ['id' => 's-2', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'letterSpacing' => 999],
                ['id' => 's-3', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'letterSpacing' => -999],
            ],
        ]);

        $this->assertSame(3, $clean['elements'][0]['letterSpacing']);
        $this->assertSame(WorkspaceDesign::MAX_LETTER_SPACING, $clean['elements'][1]['letterSpacing']);
        $this->assertSame(WorkspaceDesign::MIN_LETTER_SPACING, $clean['elements'][2]['letterSpacing']);
    }

    /**
     * ระยะห่างปกติ (0) ไม่ถูกเก็บ ด้วยเหตุผลเดียวกับมุมหมุน 0 องศา
     */
    public function test_a_zero_or_missing_letter_spacing_is_not_stored(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'letterSpacing' => 0],
                ['id' => 's-2', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
            ],
        ]);

        foreach ($clean['elements'] as $element) {
            $this->assertArrayNotHasKey('letterSpacing', $element, $element['id']);
        }
    }

    /**
     * เอกสารรูปแบบเก่าที่ไม่มีสามคีย์นี้เลยต้องผ่านตัวกรองได้เหมือนเดิมทุกประการ
     */
    public function test_an_old_text_document_without_the_new_style_keys_passes_unchanged(): void
    {
        $old = [
            'id' => 't-1', 'type' => 'text', 'z' => 0,
            'x' => 0.0, 'y' => 0.0, 'w' => 10.0, 'h' => 10.0,
            'text' => 'เดิม', 'color' => '#1f2937', 'fontSize' => 18,
        ];

        $clean = WorkspaceDocumentValidator::sanitize(['schema' => 1, 'elements' => [$old]]);

        $this->assertSame($old, $clean['elements'][0]);
    }

    /**
     * bold/italic/letterSpacing เป็นสไตล์เฉพาะของกล่องข้อความ ชนิดอื่นต้องไม่รับคีย์เหล่านี้
     * ผ่าน allow-list เดิมโดยไม่ต้องแก้ไขเพิ่ม
     */
    public function test_bold_italic_and_letter_spacing_do_not_leak_onto_non_text_types(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'r-1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                'bold' => true, 'italic' => true, 'letterSpacing' => 4,
            ]],
        ]);

        $element = $clean['elements'][0];

        $this->assertArrayNotHasKey('bold', $element);
        $this->assertArrayNotHasKey('italic', $element);
        $this->assertArrayNotHasKey('letterSpacing', $element);
    }

    public function test_line_aligns_are_kept_for_sticky_and_text_when_not_left(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                [
                    'id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                    'lineAligns' => ['0' => 'center', '1' => 'right'],
                ],
                [
                    'id' => 't-1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                    'lineAligns' => [2 => 'right'],
                ],
            ],
        ]);

        $this->assertSame(['0' => 'center', '1' => 'right'], $clean['elements'][0]['lineAligns']);
        $this->assertSame(['2' => 'right'], $clean['elements'][1]['lineAligns']);
    }

    /**
     * ชิดซ้ายคือค่าปริยายของแต่ละบรรทัด ไม่ถูกเก็บ ด้วยเหตุผลเดียวกับ
     * letterSpacing 0 ค่าที่ไม่อยู่ในชุดปิดตายสามค่า คีย์ที่ไม่ใช่เลขจำนวนเต็ม
     * ไม่ติดลบ และแผนที่ว่างเปล่า ก็ตกไปที่ไม่ถูกเก็บเช่นกัน ไม่ใช่ถูกปฏิเสธ
     * ทั้งเอกสาร เพราะเป็นข้อมูลตกแต่งที่ขาดไปได้โดยไม่กระทบเนื้อหา
     */
    public function test_line_aligns_entries_are_dropped_individually_when_invalid(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [
                ['id' => 's-1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'lineAligns' => ['0' => 'left']],
                ['id' => 's-2', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
                ['id' => 's-3', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'lineAligns' => ['0' => 'justify']],
                ['id' => 's-4', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'lineAligns' => ['-1' => 'center', 'x' => 'center']],
                [
                    'id' => 's-5', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                    'lineAligns' => ['0' => 'left', '1' => 'center', '2' => 'nope'],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('lineAligns', $clean['elements'][0], 's-1');
        $this->assertArrayNotHasKey('lineAligns', $clean['elements'][1], 's-2');
        $this->assertArrayNotHasKey('lineAligns', $clean['elements'][2], 's-3');
        $this->assertArrayNotHasKey('lineAligns', $clean['elements'][3], 's-4');
        $this->assertSame(['1' => 'center'], $clean['elements'][4]['lineAligns'], 's-5');
    }

    /**
     * lineAligns เป็นสไตล์เฉพาะของกล่องข้อความเช่นเดียวกับ bold/italic/letterSpacing
     * ชนิดอื่นต้องไม่รับคีย์นี้ ผ่าน allow-list เดิมโดยไม่ต้องแก้ไขเพิ่ม
     */
    public function test_line_aligns_does_not_leak_onto_non_text_types(): void
    {
        $clean = WorkspaceDocumentValidator::sanitize([
            'elements' => [[
                'id' => 'r-1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
                'lineAligns' => ['0' => 'center'],
            ]],
        ]);

        $this->assertArrayNotHasKey('lineAligns', $clean['elements'][0]);
    }
}

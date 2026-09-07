<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * ตรวจและทำความสะอาดเนื้อหากระดานที่ส่งมาจากเบราว์เซอร์
 *
 * ทำไมต้องมีคลาสนี้
 * ---------------------------------------------------------------
 * ทุกคนในแผนกยิง JSON อะไรเข้ามาก็ได้ผ่าน endpoint บันทึกอัตโนมัติ และเนื้อหานั้น
 * จะถูกเรนเดอร์ให้เพื่อนร่วมงานทุกคนเห็น ถ้าไม่กรอง กระดานจะกลายเป็นช่องทาง
 * stored XSS และเป็นช่องให้ยัดข้อมูลขนาดใหญ่จนฐานข้อมูลรับไม่ไหว
 *
 * หลักการคือ allow-list ล้วน ๆ คีย์ที่ไม่รู้จักถูกตัดทิ้ง ไม่ใช่ปล่อยผ่าน ผลลัพธ์ที่
 * คืนออกไปจึงเป็นอาร์เรย์ที่ประกอบขึ้นใหม่ทั้งก้อน ไม่ใช่อินพุตเดิมที่ลบบางคีย์
 *
 * การป้องกัน XSS ต้องมีสองชั้น ชั้นนี้จำกัดว่าคีย์ไหนมีได้และเป็นชนิดใด ส่วน
 * renderer.js ต้องเขียนข้อความด้วย textContent เท่านั้น ห้าม innerHTML
 * เพราะข้อความอิสระอย่างโน้ตย่อมมีอักขระอะไรก็ได้โดยชอบธรรม
 */
final class WorkspaceDocumentValidator
{
    /**
     * ตรวจเอกสารทั้งก้อนแล้วคืนเวอร์ชันที่ทำความสะอาดแล้ว
     *
     * @param  array<int, int>  $allowedAttachmentIds  id ของไฟล์แนบที่เป็นของกระดานใบนี้
     *                                                 element รูปภาพที่อ้าง id นอกรายการนี้
     *                                                 จะถูกปฏิเสธ เพื่อกันการดึงรูปข้ามกระดาน
     * @return array{schema: int, elements: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function sanitize(mixed $document, array $allowedAttachmentIds = []): array
    {
        if (! is_array($document) || ! array_key_exists('elements', $document)) {
            self::reject('รูปแบบข้อมูลกระดานไม่ถูกต้อง');
        }

        $elements = $document['elements'];

        if (! is_array($elements) || ! array_is_list($elements)) {
            self::reject('รายการชิ้นงานบนกระดานต้องเป็นลำดับ');
        }

        if (count($elements) > WorkspaceDesign::MAX_ELEMENTS) {
            self::reject('กระดานนี้มีชิ้นงานเกิน '.WorkspaceDesign::MAX_ELEMENTS.' ชิ้น กรุณาลบบางส่วนออกก่อน');
        }

        $allowed = array_map('intval', $allowedAttachmentIds);
        $clean = [];
        $seenIds = [];
        $imageCount = 0;

        foreach ($elements as $index => $element) {
            $sanitized = self::sanitizeElement($element, $index, $allowed);

            // id ซ้ำทำให้การเลือกและการลบฝั่งหน้าจอทำงานผิด เพราะทั้งสองอ้างจาก id
            if (isset($seenIds[$sanitized['id']])) {
                self::reject('พบรหัสชิ้นงานซ้ำกันบนกระดาน');
            }

            $seenIds[$sanitized['id']] = true;

            if ($sanitized['type'] === 'image') {
                $imageCount++;
            }

            $clean[] = $sanitized;
        }

        if ($imageCount > WorkspaceDesign::MAX_IMAGES) {
            self::reject('กระดานหนึ่งใบแนบรูปได้ไม่เกิน '.WorkspaceDesign::MAX_IMAGES.' รูป');
        }

        $result = ['schema' => 1, 'elements' => $clean];

        // ตรวจขนาดหลังทำความสะอาด เพราะสิ่งที่จะถูกเขียนลงฐานข้อมูลคือก้อนนี้
        // ไม่ใช่ก้อนที่ผู้ใช้ส่งมา (ซึ่งอาจมีคีย์ขยะที่เราตัดทิ้งไปแล้ว)
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > WorkspaceDesign::MAX_DOCUMENT_BYTES) {
            self::reject('เนื้อหากระดานใหญ่เกินกำหนด กรุณาแยกเป็นหลายกระดาน');
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $allowedAttachmentIds
     * @return array<string, mixed>
     */
    private static function sanitizeElement(mixed $element, int $index, array $allowedAttachmentIds): array
    {
        if (! is_array($element)) {
            self::reject('ชิ้นงานลำดับที่ '.($index + 1).' ไม่ถูกต้อง');
        }

        $type = $element['type'] ?? null;

        if (! is_string($type) || ! in_array($type, WorkspaceDesign::ELEMENT_TYPES, true)) {
            self::reject('พบชิ้นงานชนิดที่ระบบไม่รองรับบนกระดาน');
        }

        $clean = [
            'id' => self::elementId($element['id'] ?? null),
            'type' => $type,
            'z' => self::integer($element['z'] ?? 0, 0, WorkspaceDesign::MAX_ELEMENTS),
        ];

        if ($type === 'pen') {
            return $clean + [
                'stroke' => self::color($element['stroke'] ?? null, WorkspaceDesign::DEFAULT_STROKE),
                'strokeWidth' => self::strokeWidth($element['strokeWidth'] ?? null),
                'points' => self::points($element['points'] ?? null),
            ];
        }

        // ชิ้นงานที่เหลือทั้งหมดอธิบายด้วยกรอบสี่เหลี่ยม (x, y, w, h) เหมือนกัน
        $clean += [
            'x' => self::coordinate($element['x'] ?? null),
            'y' => self::coordinate($element['y'] ?? null),
            'w' => self::size($element['w'] ?? null),
            'h' => self::size($element['h'] ?? null),
        ];

        return match ($type) {
            'rect', 'ellipse', 'line', 'arrow' => $clean + [
                'stroke' => self::color($element['stroke'] ?? null, WorkspaceDesign::DEFAULT_STROKE),
                'strokeWidth' => self::strokeWidth($element['strokeWidth'] ?? null),
                'fill' => self::optionalColor($element['fill'] ?? null),
            ],
            'sticky' => $clean + [
                'text' => self::text($element['text'] ?? null),
                'fill' => self::color($element['fill'] ?? null, WorkspaceDesign::DEFAULT_STICKY_COLOR),
                'fontSize' => self::integer($element['fontSize'] ?? 16, 8, 96),
            ],
            'text' => $clean + [
                'text' => self::text($element['text'] ?? null),
                'color' => self::color($element['color'] ?? null, WorkspaceDesign::DEFAULT_STROKE),
                'fontSize' => self::integer($element['fontSize'] ?? 18, 8, 96),
            ],
            'image' => $clean + [
                'attachmentId' => self::attachmentId($element['attachmentId'] ?? null, $allowedAttachmentIds),
            ],
        };
    }

    /**
     * รหัสชิ้นงานที่ฝั่งเบราว์เซอร์สร้าง
     *
     * จำกัดชุดอักขระให้แคบ เพราะค่านี้ถูกใส่ลงใน attribute ของ DOM และถูกใช้เป็น
     * ตัวเลือกใน querySelector การปล่อยอักขระอิสระจะทำให้ selector พังหรือถูกหลอกได้
     */
    private static function elementId(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
            self::reject('รหัสชิ้นงานบนกระดานไม่ถูกต้อง');
        }

        return $value;
    }

    private static function color(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        // รับเฉพาะ #rrggbb เพื่อไม่ให้ค่าอย่าง url(...) หรือ expression หลุดเข้าไปใน
        // attribute ของ SVG ที่เบราว์เซอร์ตีความเป็นการอ้างทรัพยากรภายนอก
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $fallback;
    }

    private static function optionalColor(mixed $value): string
    {
        if ($value === null || $value === 'none') {
            return 'none';
        }

        return self::color($value, 'none');
    }

    private static function strokeWidth(mixed $value): int
    {
        $width = self::integer($value ?? WorkspaceDesign::DEFAULT_STROKE_WIDTH, 1, 64);

        return $width;
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        if (mb_strlen($value) > WorkspaceDesign::MAX_TEXT_LENGTH) {
            self::reject('ข้อความบนกระดานยาวเกิน '.WorkspaceDesign::MAX_TEXT_LENGTH.' ตัวอักษร');
        }

        return $value;
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private static function points(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            self::reject('เส้นที่วาดบนกระดานไม่มีจุดพิกัด');
        }

        if (count($value) > WorkspaceDesign::MAX_POINTS_PER_STROKE) {
            self::reject('เส้นที่วาดมีจุดมากเกินไป');
        }

        return array_map(static function (mixed $point): array {
            if (! is_array($point) || count($point) !== 2) {
                self::reject('จุดพิกัดบนเส้นที่วาดไม่ถูกต้อง');
            }

            return [self::coordinate($point[0] ?? null), self::coordinate($point[1] ?? null)];
        }, $value);
    }

    /**
     * พิกัดบนผืนผ้าใบ
     *
     * NAN และ INF ผ่าน is_numeric ไม่ได้อยู่แล้ว แต่ต้องกัน string ที่เป็นตัวเลข
     * ด้วย เพราะ json_decode ของ payload ที่เขียนเองอาจส่งมาเป็น "12" ค่าที่เกิน
     * ขอบเขตถูกบีบกลับเข้ากรอบแทนการปฏิเสธ เพื่อไม่ให้ผู้ใช้เสียงานทั้งกระดาน
     * เพียงเพราะลากชิ้นงานหลุดขอบไปหนึ่งชิ้น
     */
    private static function coordinate(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            self::reject('พิกัดของชิ้นงานบนกระดานไม่ถูกต้อง');
        }

        $bound = (float) WorkspaceDesign::WORLD_BOUND;

        return round(max(-$bound, min($bound, (float) $value)), 2);
    }

    private static function size(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            self::reject('ขนาดของชิ้นงานบนกระดานไม่ถูกต้อง');
        }

        // ขนาดติดลบทำให้กรอบพลิกด้าน การเลือกและการย่อขยายจะคำนวณผิด
        return round(max(0.0, min((float) WorkspaceDesign::WORLD_BOUND, (float) $value)), 2);
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return $min;
        }

        return (int) max($min, min($max, (int) $value));
    }

    /**
     * @param  array<int, int>  $allowedAttachmentIds
     */
    private static function attachmentId(mixed $value, array $allowedAttachmentIds): int
    {
        if (! is_numeric($value)) {
            self::reject('รูปภาพบนกระดานไม่มีไฟล์แนบที่อ้างถึง');
        }

        $id = (int) $value;

        // กันการอ้างไฟล์แนบของกระดานใบอื่น ซึ่งจะเป็นการข้ามการตรวจสิทธิ์ของ
        // MediaController ที่ตัดสินจากกระดานเจ้าของไฟล์
        if (! in_array($id, $allowedAttachmentIds, true)) {
            self::reject('รูปภาพที่อ้างถึงไม่ใช่ไฟล์แนบของกระดานนี้');
        }

        return $id;
    }

    /**
     * @throws ValidationException
     */
    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}

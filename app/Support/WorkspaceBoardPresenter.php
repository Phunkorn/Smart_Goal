<?php

namespace App\Support;

use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;
use Illuminate\Support\Collection;

/**
 * เตรียมข้อมูลกระดานให้อยู่ในรูปที่ฝั่งเบราว์เซอร์ใช้ได้
 *
 * หน้าที่สำคัญที่สุดคือการเติม URL ของรูปภาพ
 * ---------------------------------------------------------------
 * เอกสารของกระดานเก็บเฉพาะ attachmentId ไม่เคยเก็บ path หรือ URL ของไฟล์
 * เพราะเอกสารเป็นข้อมูลที่ผู้ใช้ส่งเข้ามาเองได้ ถ้าเก็บ path ไว้ในนั้น ผู้ใช้จะ
 * แก้ให้ชี้ไปที่ไฟล์ของกระดานอื่นหรือไฟล์ของระบบได้
 *
 * ตอนอ่าน คลาสนี้จับคู่ attachmentId กับแถวจริงในฐานข้อมูล แล้วเติม src ที่เป็น
 * route ของ MediaController ซึ่งตรวจสิทธิ์ทุกครั้งที่มีคนขอไฟล์ ตรงตามกฎใน
 * CLAUDE.md ที่ห้ามเปิดเผย private path ใน Blade, JSON หรือ JavaScript
 */
final class WorkspaceBoardPresenter
{
    /**
     * เอกสารพร้อม URL ของรูปที่ผ่านการตรวจสิทธิ์แล้ว
     *
     * ชิ้นงานรูปภาพที่อ้าง attachmentId ที่ไม่มีอยู่จริงจะถูกกรองทิ้ง แทนการ
     * ปล่อยผ่านแล้วให้เบราว์เซอร์แสดงรูปแตก กรณีนี้เกิดได้เมื่อมีคนลบรูปทิ้ง
     * ขณะที่อีกคนเปิดกระดานค้างไว้
     *
     * @param  array<string, mixed>  $document
     * @return array{schema: int, elements: array<int, array<string, mixed>>}
     */
    public static function documentForClient(WorkspaceBoard $board, array $document): array
    {
        $elements = $document['elements'] ?? [];

        if (! is_array($elements) || $elements === []) {
            return ['schema' => 1, 'elements' => []];
        }

        $attachments = self::attachmentsById($board);

        $resolved = collect($elements)
            ->map(function (array $element) use ($attachments): ?array {
                if (($element['type'] ?? null) !== 'image') {
                    return $element;
                }

                $attachment = $attachments->get((int) ($element['attachmentId'] ?? 0));

                if ($attachment === null) {
                    return null;
                }

                return [
                    ...$element,
                    'src' => route('media.workspace-board-attachments.show', $attachment),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return ['schema' => 1, 'elements' => $resolved];
    }

    /**
     * รายการรูปที่แนบอยู่บนกระดาน สำหรับให้หน้าจอแทรกลงผืนผ้าใบ
     *
     * @return array<int, array<string, mixed>>
     */
    public static function attachmentsForClient(WorkspaceBoard $board): array
    {
        return $board->attachments()
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceBoardAttachment $attachment): array => self::attachmentForClient($attachment))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachmentForClient(WorkspaceBoardAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'width' => $attachment->image_width,
            'height' => $attachment->image_height,
            'src' => route('media.workspace-board-attachments.show', $attachment),
        ];
    }

    /**
     * @return Collection<int, WorkspaceBoardAttachment>
     */
    private static function attachmentsById(WorkspaceBoard $board): Collection
    {
        return $board->relationLoaded('attachments')
            ? $board->attachments->keyBy('id')
            : $board->attachments()->get()->keyBy('id');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderUpdate;
use App\Services\NotificationService;
use App\Services\TaskCommentService;
use App\Support\Concerns\ValidatesAttachments;
use App\Support\ProtectedMedia;
use App\Support\TaskCommentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ความคิดเห็นในงาน พร้อมรูปประกอบ
 *
 * ยังเรียก assertAllowedAttachments() ของ ValidatesAttachments เพื่อรักษาการตรวจ
 * คู่นามสกุลกับ MIME ที่อ่านจากเนื้อไฟล์จริง (กันไฟล์ที่เปลี่ยนนามสกุลมาหลอก)
 * แต่ไม่ใช้ attachmentRules() ตรง ๆ เพราะกติกาที่ใช้ร่วมกับใบงานอนุญาต docx/xlsx/zip
 * และไฟล์ขนาดถึง 1 GB ซึ่งไม่เหมาะกับรูปที่แสดงอยู่ในฟองแชท
 *
 * ห้ามแก้ AttachmentPolicy ให้แคบลงเพื่อฟีเจอร์นี้ เพราะใช้ร่วมกับงานโครงการ
 * การกรองที่แคบกว่าจึงประกอบขึ้นที่นี่ โดยยังผ่านการ์ดเดิมทุกชั้น
 */
class TaskCommentController extends Controller
{
    use ValidatesAttachments;

    /** รูปต่อหนึ่งความคิดเห็น มากกว่านี้ฟองแชทจะอ่านไม่รู้เรื่อง */
    private const MAX_IMAGES = 4;

    /** นามสกุลรูปที่ฟองแชทเรนเดอร์ได้จริง */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const IMAGE_MAX_KILOBYTES = 10240;

    public function store(Request $request, WorkOrder $task, TaskCommentService $comments, TaskCommentPresenter $presenter): JsonResponse
    {
        $task->loadMissing(['collaborators', 'user', 'creator']);
        $this->authorize('comment', $task);
        $request->merge(['message' => trim((string) $request->input('message'))]);

        $validated = $request->validate([
            // ข้อความไม่บังคับเมื่อมีรูป การส่งภาพหน้าจอเปล่า ๆ เป็นการสื่อสารที่สมบูรณ์ในตัวเอง
            'message' => ['required_without:images', 'nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => [
                'file',
                'mimes:'.implode(',', self::IMAGE_EXTENSIONS),
                'max:'.self::IMAGE_MAX_KILOBYTES,
            ],
            'reply_to_id' => ['nullable', 'integer'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer'],
            // "@all" กล่าวถึงทุกคนในงานนี้ — เซิร์ฟเวอร์คำนวณรายชื่อผู้รับเองจาก mentionCandidates()
            // เสมอ ไม่เชื่อรายชื่อจาก client จึงรับแค่ true/false ไม่รับ id ตรงมา
            'mention_all' => ['sometimes', 'boolean'],
        ]);

        // ข้อความต้นทางต้องเป็นคอมเมนต์จริงของงานใบเดียวกัน ไม่งั้นถือว่าไม่มีการตอบกลับ
        // (เงียบ ไม่ error เพราะเป็นการป้องกันข้อมูลเพี้ยนจาก client ไม่ใช่ความผิดของผู้ใช้)
        $replyTo = isset($validated['reply_to_id'])
            ? WorkOrderUpdate::query()
                ->where('work_order_id', $task->job_id)
                ->where('is_comment', true)
                ->find($validated['reply_to_id'])
            : null;

        // การ์ดเดิมของระบบ ตรวจว่านามสกุลกับ MIME ที่อ่านจากเนื้อไฟล์เข้าคู่กัน
        $this->assertAllowedAttachments($request, 'images');
        $this->assertImagesOnly($request);

        // เก็บไฟล์นอก transaction ถ้าเก็บข้างในแล้ว transaction ล้ม
        // ไฟล์จะค้างบนดิสก์โดยไม่มีแถวอ้างถึงและไม่มีใครลบได้อีก
        $stored = $this->collectStoredAttachments($request, 'images', 'comment-attachments/'.$task->job_id);

        // collectStoredAttachments() ใช้ร่วมกันทุกโดเมนจึงไม่คืนขนาดไฟล์
        // เติมที่นี่แทนการเปลี่ยนสัญญาของ trait เพื่อผู้เรียกรายเดียว
        foreach ($stored as $index => $file) {
            $absolute = ProtectedMedia::attachmentAbsolutePath($file['path']);
            $stored[$index]['byte_size'] = $absolute ? (int) filesize($absolute) : 0;
        }

        try {
            $comment = $comments->post(
                $task,
                $request->user(),
                (string) ($validated['message'] ?? ''),
                $stored,
                $replyTo,
                array_map('intval', $validated['mentions'] ?? []),
                (bool) ($validated['mention_all'] ?? false)
            );
        } catch (Throwable $exception) {
            foreach ($stored as $file) {
                ProtectedMedia::deleteAttachment($file['path']);
            }

            throw $exception;
        }

        return response()->json([
            'ok' => true,
            'comment' => $presenter->comment($comment, $request->user()),
        ], 201);
    }

    /**
     * กรองให้เหลือเฉพาะนามสกุลรูปที่ฟองแชทรองรับ
     *
     * ซ้อนอยู่หลัง assertAllowedAttachments() ซึ่งอนุญาตเอกสารและ zip ด้วย
     * ต้องเช็คทั้งสองชั้น ไม่ใช่เลือกอย่างใดอย่างหนึ่ง ชั้นแรกคือการกันไฟล์ปลอมนามสกุล
     * ชั้นนี้คือการจำกัดขอบเขตของฟีเจอร์
     */
    private function assertImagesOnly(Request $request): void
    {
        foreach ($request->file('images', []) as $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'images' => 'แนบได้เฉพาะรูปภาพ ('.implode(', ', self::IMAGE_EXTENSIONS).')',
                ]);
            }
        }
    }

    public function markRead(Request $request, WorkOrder $task, TaskCommentService $comments, NotificationService $notifications, TaskCommentPresenter $presenter): JsonResponse
    {
        $task->loadMissing(['collaborators', 'updates.user', 'updates.attachments']);
        $this->authorize('viewComments', $task);
        $latestId = $comments->markRead($task, $request->user());

        return response()->json([
            'ok' => true,
            'latest_comment_id' => $latestId,
            'unread_count' => $notifications->unreadCount($request->user()),
            'receipts' => $presenter->receipts($task),
        ]);
    }

    /**
     * ปักหมุด/เลิกปักหมุด — สิทธิ์เดียวกับการคอมเมนต์ (ตกลงกับผู้ใช้แล้วว่าใครคอมเมนต์ได้ก็ปักได้)
     * จึงไม่มี ability ใหม่แยกใน WorkOrderPolicy เพราะขอบเขตเหมือนกันทุกประการ
     */
    public function pin(Request $request, WorkOrder $task, WorkOrderUpdate $comment, TaskCommentService $comments, TaskCommentPresenter $presenter): JsonResponse
    {
        $this->authorizeCommentTarget($task, $comment);
        $comment = $comments->pin($task, $comment, $request->user());

        return response()->json(['ok' => true, 'comment' => $presenter->comment($comment, $request->user())]);
    }

    public function unpin(Request $request, WorkOrder $task, WorkOrderUpdate $comment, TaskCommentService $comments, TaskCommentPresenter $presenter): JsonResponse
    {
        $this->authorizeCommentTarget($task, $comment);
        $comment = $comments->unpin($comment);

        return response()->json(['ok' => true, 'comment' => $presenter->comment($comment, $request->user())]);
    }

    /**
     * {task}/{comment} ผูกกันด้วย implicit route model binding ธรรมดา ไม่ได้ scope กับ WorkOrder
     * จึงต้องเช็คเองว่าคอมเมนต์ที่ขอปักเป็นของงานใบนี้จริง ไม่งั้นส่ง id คอมเมนต์ของงานอื่นมาปักได้
     */
    private function authorizeCommentTarget(WorkOrder $task, WorkOrderUpdate $comment): void
    {
        abort_if((int) $comment->work_order_id !== (int) $task->job_id || ! $comment->is_comment, 404);
        $this->authorize('comment', $task);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkLogAttachment;
use App\Models\WorkOrderListAttachment;
use App\Models\WorkOrderUpdateAttachment;
use App\Models\WorkspaceBoardAttachment;
use App\Support\ProtectedMedia;
use App\Support\TaskAttachmentPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MediaController extends Controller
{
    public function profile(User $user): BinaryFileResponse
    {
        $path = (string) $user->profile_image;

        abort_unless(str_starts_with($path, 'profiles/'), 404);

        return $this->fileResponse(ProtectedMedia::profileAbsolutePath($path));
    }

    /**
     * เสิร์ฟไฟล์แนบของงาน
     *
     * ?download=1 คือการบังคับให้เบราว์เซอร์บันทึกไฟล์ด้วยชื่อเดิมที่ผู้ใช้อัปโหลดขึ้นมา
     * แทนการเปิดดูในแท็บ ชื่อบนดิสก์เป็น hash การดาวน์โหลดจึงต้องออกชื่อจากที่นี่
     *
     * สิทธิ์เปิดดูกับสิทธิ์ดาวน์โหลดเป็นเรื่องเดียวกัน จึงใช้ route เดียวและจุดตรวจสิทธิ์เดียว
     */
    public function taskAttachment(Request $request, JobImage $attachment): BinaryFileResponse
    {
        $job = $attachment->job()->with('collaborators')->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $job);

        return $this->attachmentResponse(
            $attachment->file_path,
            'job-attachments/'.$job->job_id.'/',
            TaskAttachmentPresenter::name($attachment),
            $request->boolean('download')
        );
    }

    public function projectAttachment(
        Request $request,
        WorkOrderListAttachment $attachment
    ): BinaryFileResponse {
        $project = $attachment->project()->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $project);

        return $this->attachmentResponse(
            $attachment->file_path,
            'project-attachments/'.$project->id.'/',
            $attachment->original_name,
            $request->boolean('download')
        );
    }

    /**
     * ไฟล์แนบของบันทึกงานประจำวัน
     *
     * ตรวจสิทธิ์จากบันทึกงานต้นทางเสมอ ไม่ใช่จากตัวไฟล์ ผู้ที่เปิดดูได้จึงเป็นชุด
     * เดียวกับผู้ที่เห็นบันทึกนั้น (เจ้าของ / หัวหน้าแผนก / admin) ตาม WorkLogPolicy
     */
    public function workLogAttachment(
        Request $request,
        WorkLogAttachment $attachment
    ): BinaryFileResponse {
        $log = $attachment->workLog()->with('user')->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $log);

        return $this->attachmentResponse(
            $attachment->file_path,
            'work-log-attachments/'.$log->id.'/',
            $attachment->original_name,
            $request->boolean('download')
        );
    }

    /**
     * รูปภาพบนกระดานไอเดีย
     *
     * ตรวจสิทธิ์จาก "กระดานเจ้าของไฟล์" ไม่ใช่จากตัวไฟล์ กระดานที่ตั้งเป็น
     * เฉพาะแผนกจึงกันรูปของตัวเองจากคนนอกแผนกได้ด้วยกติกาชุดเดียวกับที่กัน
     * ตัวกระดานเอง โดยไม่ต้องมีการตรวจสิทธิ์ชุดที่สองให้เพี้ยนออกจากกัน
     */
    public function workspaceBoardAttachment(
        Request $request,
        WorkspaceBoardAttachment $attachment
    ): BinaryFileResponse {
        $board = $attachment->board()->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $board);

        return $this->attachmentResponse(
            $attachment->file_path,
            'workspace-board-attachments/'.$board->id.'/',
            $attachment->original_name,
            $request->boolean('download')
        );
    }

    /**
     * รูปที่แนบมากับความคิดเห็นในงาน
     *
     * ตรวจด้วยสิทธิ์ "อ่านความคิดเห็น" ไม่ใช่ "เขียนความคิดเห็น" เพราะหัวหน้าแผนก
     * และผู้ร่วมโปรเจกต์ที่อ่านบทสนทนาได้ต้องเห็นรูปประกอบด้วย มิฉะนั้นข้อความจะ
     * อ้างถึงภาพที่อีกฝ่ายเปิดไม่ได้
     */
    public function commentAttachment(
        Request $request,
        WorkOrderUpdateAttachment $attachment
    ): BinaryFileResponse {
        $comment = $attachment->comment()->firstOrFail();
        $task = $comment->workOrder()->firstOrFail();

        Gate::forUser($request->user())->authorize('viewComments', $task);

        return $this->attachmentResponse(
            $attachment->file_path,
            'comment-attachments/'.$task->job_id.'/',
            $attachment->original_name,
            $request->boolean('download')
        );
    }

    /**
     * Compatibility endpoint for links generated before attachments received
     * typed, policy-aware routes. Database ownership is resolved before a file
     * is served, so an arbitrary public-disk path is never enough for access.
     */
    public function legacy(Request $request, string $path): BinaryFileResponse
    {
        abort_unless(ProtectedMedia::isSafeRelativePath($path), 404);

        $taskAttachment = JobImage::query()->where('file_path', $path)->first();
        if ($taskAttachment) {
            return $this->taskAttachment($request, $taskAttachment);
        }

        $projectAttachment = WorkOrderListAttachment::query()->where('file_path', $path)->first();
        if ($projectAttachment) {
            return $this->projectAttachment($request, $projectAttachment);
        }

        $profileOwner = User::query()->where('profile_image', $path)->first();
        if ($profileOwner) {
            return $this->profile($profileOwner);
        }

        abort(404);
    }

    private function attachmentResponse(
        string $path,
        string $expectedPrefix,
        ?string $originalName = null,
        bool $forceDownload = false
    ): BinaryFileResponse {
        abort_unless(
            ProtectedMedia::isSafeRelativePath($path) && str_starts_with($path, $expectedPrefix),
            404
        );

        return $this->fileResponse(
            ProtectedMedia::attachmentAbsolutePath($path),
            ProtectedMedia::dispositionName($originalName, $path),
            $forceDownload
        );
    }

    private function fileResponse(
        ?string $absolutePath,
        ?string $fileName = null,
        bool $forceDownload = false
    ): BinaryFileResponse {
        abort_unless($absolutePath !== null, 404);

        $response = response()->file($absolutePath, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        if ($fileName !== null) {
            /*
             * ต้องส่งชื่อไฟล์ไปกับทุก response ไม่ใช่เฉพาะตอน ?download=1
             *
             * response()->file() ไม่ตั้ง Content-Disposition ให้เอง เบราว์เซอร์จึงตั้งชื่อ
             * ไฟล์ที่บันทึกจากส่วนท้ายของ URL ซึ่งเป็น id ของไฟล์แนบ ผู้ใช้ที่กดลิงก์ไฟล์
             * Excel หรือ Word จึงได้ไฟล์ชื่อ "224" ที่ไม่มีนามสกุลและเปิดไม่ออก
             *
             * inline ยังเปิดดูในแท็บได้เหมือนเดิม ต่างกันแค่ชื่อที่ใช้ตอนบันทึก
             * ส่วน attachment (?download=1) คือการบังคับให้บันทึกแทนการเปิดดู
             *
             * ชื่อไฟล์ไทยถูกส่งเป็น filename*= แบบ UTF-8 และ Symfony เติม fallback ASCII ให้เอง
             */
            $response->setContentDisposition(
                $forceDownload
                    ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                    : ResponseHeaderBag::DISPOSITION_INLINE,
                $fileName
            );
        }

        return $response;
    }
}

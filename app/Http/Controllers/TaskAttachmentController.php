<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\JobImage;
use App\Models\WorkOrder;
use App\Support\AuditTrail;
use App\Support\Concerns\ValidatesAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TaskAttachmentController extends Controller
{
    use RespondsWithTaskResult;
    use ValidatesAttachments;

    public function uploadAttachments(Request $request, $id)
    {
        $job = WorkOrder::with(['collaborators', 'images'])->findOrFail($id);
        $user = Auth::user();

        $this->authorize('update', $job);
        abort_if((int) $job->job_status === 4 && $user?->role !== 'admin', 403);

        $request->validate([
            'completion_attachments' => ['required', 'array', 'min:1', 'max:5'],
            'completion_attachments.*' => ['file', 'mimes:'.implode(',', self::ALLOWED_ATTACHMENT_EXTENSIONS), 'max:'.self::ATTACHMENT_MAX_KB],
        ]);

        $incomingCount = count($request->file('completion_attachments', []));
        if ($job->images->count() + $incomingCount > 5) {
            return $this->jsonOrBack($request, false, 'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน', 422);
        }

        $this->assertAllowedAttachments($request, 'completion_attachments');

        $this->storeFiles($request, $job, 'completion_attachments');
        AuditTrail::log('attachments_uploaded', $job, 'เพิ่มไฟล์อ้างอิงงาน: '.$job->job_topic, [
            'field' => 'completion_attachments',
            'count' => count($request->file('completion_attachments', [])),
        ]);

        return $this->jsonOrBack($request, true, 'เพิ่มไฟล์อ้างอิงงานสำเร็จ');
    }

    public function destroyAttachment(Request $request, $id, JobImage $attachment)
    {
        $job = WorkOrder::findOrFail($id);
        $this->authorize('update', $job);
        abort_unless((int) $attachment->job_id === (int) $job->job_id, 404);
        abort_if((int) $job->job_status === 4 && Auth::user()?->role !== 'admin', 403);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();
        AuditTrail::log('attachment_deleted', $job, 'ลบไฟล์อ้างอิงงาน: '.$job->job_topic);

        return $this->jsonOrBack($request, true, 'ลบไฟล์แนบแล้ว');
    }
}

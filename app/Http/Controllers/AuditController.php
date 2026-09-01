<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Services\AuditLogQuery;
use App\Support\AuditSnapshot;
use App\Support\TrashRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Audit Log — บันทึกตรวจสอบของผู้ดูแลระบบ
 *
 * รวมหน้า "บันทึกระบบ" กับ "ถังขยะ" เดิมไว้ที่เดียว เพราะทั้งสองตอบคำถามเดียวกัน
 * คือใครทำอะไรกับข้อมูล การลบหนึ่งครั้งเขียนบันทึกทั้งสองฝั่งพร้อมกันอยู่แล้ว
 */
class AuditController extends Controller
{
    public function __construct(private readonly AuditLogQuery $audit) {}

    public function index(Request $request): View
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $tab = AuditLogQuery::tab($request);

        // การล้างของหมดอายุมีค่าใช้จ่ายและลบข้อมูลจริง จึงทำเฉพาะตอนเปิดแท็บถังขยะ
        // เหมือนพฤติกรรมเดิมของหน้าถังขยะ ไม่ใช่ทุกครั้งที่เปิด Audit Log
        if ($tab === 'trash') {
            TrashRetention::purgeExpired();
        }

        return view('admin.audit.index', [
            'tab' => $tab,
            'users' => $this->audit->actorOptions(),
            ...match ($tab) {
                'activity' => $this->activityData($request),
                'trash' => $this->trashData($request),
                default => $this->overviewData($request),
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewData(Request $request): array
    {
        $recentActivity = $this->audit->activity($request)->latest('created_at')->limit(12)->get();
        $recentTrash = $this->audit->trash($request)->latest('deleted_at')->limit(6)->get()
            ->each(fn (TrashLog $trash) => $trash->summary = TrashRetention::summary($trash));

        return [
            'stats' => $this->audit->overview($request),
            'recentActivity' => $recentActivity,
            'recentTrash' => $recentTrash,
            'resolvableProfileImages' => AuditSnapshot::resolvableProfileImages($recentActivity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityData(Request $request): array
    {
        $logs = $this->audit->activity($request)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return [
            'logs' => $logs,
            'actions' => ActivityLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'subjectTypes' => ActivityLog::query()
                ->whereNotNull('subject_type')
                ->select('subject_type')
                ->distinct()
                ->orderBy('subject_type')
                ->pluck('subject_type'),
            'resolvableProfileImages' => AuditSnapshot::resolvableProfileImages($logs->items()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trashData(Request $request): array
    {
        $trashLogs = $this->audit->trash($request)
            ->latest('deleted_at')
            ->paginate(20)
            ->through(function (TrashLog $trash) {
                $trash->summary = TrashRetention::summary($trash);
                $trash->readable = AuditSnapshot::readableTrashPayload($trash);

                return $trash;
            })
            ->withQueryString();

        return [
            'trashLogs' => $trashLogs,
            'stats' => $this->audit->trashStats($request),
            'entityTypes' => TrashLog::query()->select('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'departments' => TrashRetention::departmentOptions(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Services\AuditLogQuery;
use App\Services\AuditRevertService;
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

        // การเปิดหน้าเพื่อ "ดู" ต้องไม่ทำลายข้อมูล
        //
        // เดิมการเปิดแท็บถังขยะสั่ง TrashRetention::purgeExpired() ทันที ผู้ดูแลระบบ
        // ที่แค่เข้ามาดูว่ามีอะไรถูกลบบ้างจึงลบข้อมูลถาวรไปโดยไม่รู้ตัว
        //
        // การล้างตามอายุมีคำสั่ง trash:purge-expired ที่ถูกตั้งเวลาไว้ใน
        // routes/console.php ทำหน้าที่นี้อยู่แล้ว การเรียกซ้ำตอน render จึงไม่เคย
        // จำเป็น ส่วนการลบถาวรแบบตั้งใจอยู่ที่ปุ่มใน TrashController

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
     * ย้อนค่าที่ถูกแก้ทับกลับไปเป็นค่าเดิม
     *
     * ต่างจากถังขยะตรงที่ข้อมูลไม่ได้ถูกลบ แต่ถูกเขียนทับ ค่าเดิมยังอยู่ครบใน
     * changes.before ของบันทึกกิจกรรมอยู่แล้ว ปลายทางนี้จึงเป็นการอ่านค่าที่มีอยู่
     * กลับไปเขียนคืน โดยผู้ใช้เลือกได้ว่าจะย้อนฟิลด์ไหนบ้าง ไม่ใช่ทั้งแถว
     */
    public function revert(Request $request, ActivityLog $log, AuditRevertService $reverts)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'max:64'],
        ]);

        $reverted = $reverts->revert($log, $validated['fields'], Auth::user());

        return back()->with('success', 'ย้อนค่าเดิมแล้ว '.count($reverted).' รายการ');
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

        // ปุ่มย้อนค่าขึ้นเฉพาะแถวที่ย้อนได้จริง คำนวณครั้งเดียวตรงนี้แทนการให้ Blade
        // ไปถาม service ซ้ำในลูป ซึ่งจะยิง query ต่อแถว
        $reverts = app(AuditRevertService::class);

        return [
            'logs' => $logs,
            'revertableFields' => collect($logs->items())
                ->mapWithKeys(fn (ActivityLog $log) => [$log->id => $reverts->revertableFields($log)])
                ->filter(fn (array $rows) => $rows !== [])
                ->all(),
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
            // ปุ่ม "ล้างของหมดอายุ" ทำงานกับทั้งระบบ ไม่ใช่เฉพาะที่ตัวกรองแสดงอยู่
            'expiredCount' => $this->audit->expiredTrashCount(),
        ];
    }
}

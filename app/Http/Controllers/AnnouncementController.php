<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\Announcement;
use App\Services\AnnouncementQueryService;
use App\Services\AnnouncementService;
use App\Support\AnnouncementDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ศูนย์ประกาศของหัวหน้าแผนก
 *
 * สิทธิ์ทั้งหมดอยู่ที่ AnnouncementPolicy ส่วน route ครอบด้วย role:user อีกชั้น
 * เพื่อกัน admin และ viewer ตั้งแต่ก่อนเข้าถึง controller
 */
class AnnouncementController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly AnnouncementQueryService $query,
        private readonly AnnouncementService $announcements,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Announcement::class);

        $user = $request->user()->loadMissing('department');
        $tab = AnnouncementQueryService::normalizeTab($request->query('tab'));
        $search = Str::limit(trim((string) $request->query('q', '')), 100, '');
        $today = AnnouncementDesign::today();

        return view('announcements.index', [
            'announcements' => $this->query->manageList($user, $tab, $search, $today),
            'tabCounts' => $this->query->tabCounts($user, $search, $today),
            'tab' => $tab,
            'search' => $search,
            'today' => $today,
            'departmentName' => $user->department?->department_name,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Announcement::class);

        $this->announcements->create($request->user(), $this->validated($request));

        return $this->jsonOrBack($request, true, 'สร้างประกาศเรียบร้อยแล้ว');
    }

    public function update(Request $request, Announcement $announcement)
    {
        Gate::authorize('update', $announcement);

        $this->announcements->update($announcement, $this->validated($request, $announcement));

        return $this->jsonOrBack($request, true, 'บันทึกการแก้ไขประกาศเรียบร้อยแล้ว');
    }

    public function destroy(Request $request, Announcement $announcement)
    {
        Gate::authorize('delete', $announcement);

        $this->announcements->delete($announcement);

        return $this->jsonOrBack($request, true, 'ลบประกาศเรียบร้อยแล้ว');
    }

    /**
     * วันเริ่มแสดงย้อนหลังไม่ได้ ตอนแก้ไขยอมให้คงวันเดิมที่ผ่านมาแล้วไว้ได้
     * (ประกาศที่กำลังแสดงอยู่ต้องแก้ข้อความได้โดยไม่ถูกบังคับให้เลื่อนวันเริ่ม)
     *
     * @return array{title:string,body:string,audience:string,starts_on:string,ends_on:?string}
     */
    private function validated(Request $request, ?Announcement $announcement = null): array
    {
        $today = AnnouncementDesign::today();
        $earliestStart = $announcement?->starts_on && $announcement->starts_on->format('Y-m-d') < $today
            ? $announcement->starts_on->format('Y-m-d')
            : $today;

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.AnnouncementDesign::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.AnnouncementDesign::BODY_MAX],
            'audience' => ['required', Rule::in(array_keys(AnnouncementDesign::AUDIENCES))],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$earliestStart],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'title.required' => 'กรุณาระบุหัวข้อประกาศ',
            'title.max' => 'หัวข้อประกาศต้องไม่เกิน '.AnnouncementDesign::TITLE_MAX.' ตัวอักษร',
            'body.required' => 'กรุณาระบุรายละเอียดประกาศ',
            'body.max' => 'รายละเอียดประกาศต้องไม่เกิน '.number_format(AnnouncementDesign::BODY_MAX).' ตัวอักษร',
            'audience.required' => 'กรุณาเลือกกลุ่มผู้รับประกาศ',
            'audience.in' => 'กลุ่มผู้รับประกาศไม่ถูกต้อง',
            'starts_on.required' => 'กรุณาระบุวันที่เริ่มแสดง',
            'starts_on.date_format' => 'วันที่เริ่มแสดงไม่ถูกต้อง',
            'starts_on.after_or_equal' => 'วันที่เริ่มแสดงต้องไม่ย้อนหลัง',
            'ends_on.date_format' => 'วันที่สิ้นสุดไม่ถูกต้อง',
            'ends_on.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มแสดง',
        ]);

        return [
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'audience' => $validated['audience'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
        ];
    }
}

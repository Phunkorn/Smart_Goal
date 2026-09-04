<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class MeetingQueryService
{
    public const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    private const ATTENDEE_ROLES = ['admin', 'user', 'viewer'];

    public const PERIODS = [
        'upcoming' => 'กำลังจะมาถึง',
        'today' => 'วันนี้',
        'next_7_days' => '7 วันข้างหน้า',
        'this_month' => 'เดือนนี้',
        'past' => 'ที่ผ่านมา',
        'all' => 'ทั้งหมด',
    ];

    /**
     * @param  User|null  $scopedEmployee  บังคับให้รายการเป็นของสมาชิกคนนี้เท่านั้น
     *                                     ใช้กับหน้าที่บริบทถูกกำหนดโดย route แล้ว เช่น Admin Member Workspace
     *                                     ค่านี้ชนะ `?employee=` ที่ส่งมากับ request เสมอ และไม่ผ่าน
     *                                     normalizeEmployeeId() จึง scope ถูกแม้สมาชิกถูกปิดใช้งานอยู่
     */
    public function indexData(Request $request, User $viewer, ?User $scopedEmployee = null): array
    {
        $filters = $this->normalizeFilters($request, $viewer);

        if ($scopedEmployee) {
            $filters['employee_id'] = (int) $scopedEmployee->id;
        }
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $query = $this->visibleQuery($viewer)
            ->with([
                'creator:id,name,department_id,deleted_at',
                'creator.department:id,department_name',
                'attendees:id,name,department_id,profile_image',
                'attendees.department:id,department_name',
            ]);

        if ($filters['employee_id']) {
            $employeeId = $filters['employee_id'];
            $query->where(function (Builder $query) use ($employeeId): void {
                $query->where('created_by', $employeeId)
                    ->orWhereHas('attendees', fn (Builder $attendees) => $attendees->whereKey($employeeId));
            });
        }

        if ($filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('title', 'like', $search)
                    ->orWhere('description', 'like', $search)
                    ->orWhere('location', 'like', $search);
            });
        }

        $this->applyPeriod($query, $filters['period'], $now);

        $meetings = $query
            ->orderBy('starts_at', in_array($filters['period'], ['past', 'all'], true) ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return [
            'meetings' => $meetings,
            'filters' => $filters,
            'periodOptions' => self::PERIODS,
            // บริบทที่ถูกล็อกไว้กับสมาชิกคนเดียวต้องไม่มีตัวเลือกสลับไปดูคนอื่น
            // partial ซ่อน <select name="employee"> เองเมื่อรายการนี้ว่าง
            'employeeOptions' => $scopedEmployee ? collect() : $this->employeeOptions($viewer),
            'attendeeOptions' => $viewer->can('create', Meeting::class) ? $this->attendeeOptions() : collect(),
            'attendeeDepartments' => $viewer->can('create', Meeting::class) ? $this->attendeeDepartments() : collect(),
            'inspectedEmployee' => $scopedEmployee
                ? $scopedEmployee->loadMissing('department')
                : ($filters['employee_id'] ? User::with('department')->find($filters['employee_id']) : null),
            'nowBangkok' => $now,
        ];
    }

    public function detailData(Request $request, User $viewer, Meeting $meeting): array
    {
        $meeting->load([
            'creator.department',
            'attendees.department',
        ]);
        $employeeId = $this->normalizeEmployeeId($request, $viewer);
        $employeeIsRelated = $employeeId
            && ((int) $meeting->created_by === (int) $employeeId || $meeting->attendees->contains('id', $employeeId));

        return [
            'meeting' => $meeting,
            'attendeeOptions' => $viewer->can('update', $meeting) ? $this->attendeeOptions() : collect(),
            'attendeeDepartments' => $viewer->can('update', $meeting) ? $this->attendeeDepartments() : collect(),
            'inspectedEmployee' => $employeeIsRelated ? User::with('department')->find($employeeId) : null,
            'nowBangkok' => CarbonImmutable::now(self::BUSINESS_TIMEZONE),
        ];
    }

    /**
     * ประชุมที่ทับซ้อนช่วงเวลาที่ขอ สำหรับวางบนปฏิทินของ Workspace
     *
     * สิทธิ์ถูกบังคับที่ SQL ผ่าน visibleQuery() ตัวเดียวกับหน้ารายการประชุม
     * ห้ามกรองสิทธิ์ฝั่ง frontend และห้ามดึงทั้งระบบโดยไม่มีขอบเขต
     * เมื่อมี subject ให้จำกัดซ้ำเฉพาะประชุมที่บุคคลนั้นจัดหรือเข้าร่วม สำหรับหน้าตรวจงานสมาชิก
     *
     * ไม่มี subject แปลว่านี่คือ "ปฏิทินของฉัน" จึงต้องใช้ผู้ที่ล็อกอินเป็น subject
     * visibleQuery() อย่างเดียวไม่พอสำหรับหัวหน้าแผนก เพราะมันคืนประชุมทุกใบที่คนในแผนก
     * เป็นผู้จัดหรือเข้าร่วม ปฏิทินส่วนตัวของหัวหน้าจึงขึ้นประชุมที่ตัวเองไม่ได้จัด
     * และไม่ได้ถูกเชิญ ปนกับนัดหมายจริงของตัวเองจนแยกไม่ออก
     *
     * Admin เป็นข้อยกเว้นที่ตั้งใจ: ปฏิทินของ Admin คือปฏิทินภาพรวมทั้งองค์กร
     * (ดู MyTasksCalendarMeetingsTest::test_endpoint_scopes_results_per_viewer)
     *
     * ส่วนหน้ารายการประชุมยังใช้ visibleQuery() เต็มขอบเขตตามเดิม เพราะเป็นหน้ากำกับดูแล
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function calendarMeetings(User $viewer, CarbonInterface $from, CarbonInterface $to, ?User $subject = null): Collection
    {
        $windowStart = CarbonImmutable::instance($from)->utc();
        $windowEnd = CarbonImmutable::instance($to)->utc();
        $subject ??= $viewer->role === 'admin' ? null : $viewer;

        return $this->visibleQuery($viewer)
            ->when($subject, function (Builder $query) use ($subject): void {
                $query->where(function (Builder $related) use ($subject): void {
                    $related->where('created_by', $subject->id)
                        ->orWhereHas('attendees', fn (Builder $attendees) => $attendees->whereKey($subject->id));
                });
            })
            // ปฏิทินแสดงผู้จัดและผู้เข้าร่วมเป็น avatar จึงต้อง eager load ไว้ตั้งแต่ต้น
            // มิฉะนั้นการวาดตารางประชุมจะยิงคิวรีต่อหนึ่งแถว
            ->with(['creator:id,name,profile_image', 'attendees:id,name,profile_image'])
            ->where('starts_at', '<=', $windowEnd)
            ->where('ends_at', '>=', $windowStart)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'title', 'location', 'starts_at', 'ends_at', 'created_by'])
            ->map(function (Meeting $meeting): array {
                $startsAt = CarbonImmutable::instance($meeting->starts_at)->setTimezone(self::BUSINESS_TIMEZONE);
                $endsAt = CarbonImmutable::instance($meeting->ends_at)->setTimezone(self::BUSINESS_TIMEZONE);

                return [
                    'id' => 'meeting-'.$meeting->id,
                    'type' => 'meeting',
                    'title' => $meeting->title,
                    'location' => $meeting->location ?: 'ไม่ระบุสถานที่',
                    'organizer' => $meeting->creator?->name ?? 'ไม่ระบุผู้จัด',
                    'organizerAvatar' => $meeting->creator?->profile_image ? route('media.profile', $meeting->creator) : null,
                    // รูปเป็น URL ที่ผ่าน MediaController เสมอ ห้ามประกอบ path จาก storage ตรง ๆ
                    'attendees' => $meeting->attendees->map(fn (User $person): array => [
                        'name' => $person->name,
                        'avatar_url' => $person->profile_image ? route('media.profile', $person) : null,
                    ])->values()->all(),
                    'start' => $startsAt->format('Y-m-d'),
                    'due' => $endsAt->format('Y-m-d'),
                    'startTime' => $startsAt->format('H:i'),
                    'endTime' => $endsAt->format('H:i'),
                    // ปฏิทินต้องรู้ปลายทางทั้งสองแบบตั้งแต่ต้น: ดูย่อในหน้าเดิม และเปิดหน้าเต็ม
                    'entityId' => $meeting->id,
                    'quickViewUrl' => route('mytasks.quickview.meeting', $meeting),
                    'detailUrl' => route('meetings.show', $meeting),
                    'url' => route('meetings.show', $meeting),
                ];
            })
            ->values();
    }

    public function visibleQuery(User $viewer): Builder
    {
        $query = Meeting::query();

        if ($viewer->isDepartmentHead()) {
            $query->where(function (Builder $query) use ($viewer): void {
                $query->whereHas('creator', fn (Builder $creator) => $creator
                    ->where('department_id', $viewer->department_id))
                    ->orWhereHas('attendees', fn (Builder $attendees) => $attendees
                        ->where('department_id', $viewer->department_id));
            });
        } elseif (! in_array($viewer->role, ['admin', 'viewer'], true)) {
            $query->where(function (Builder $query) use ($viewer): void {
                $query->where('created_by', $viewer->id)
                    ->orWhereHas('attendees', fn (Builder $attendees) => $attendees->whereKey($viewer->id));
            });
        }

        return $query;
    }

    public function eligibleAttendeeIds(array $attendeeIds): array
    {
        $ids = collect($attendeeIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->attendeeEligibilityQuery()
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function normalizeFilters(Request $request, User $viewer): array
    {
        $period = $request->string('period')->toString();
        $period = array_key_exists($period, self::PERIODS) ? $period : 'upcoming';

        return [
            'search' => mb_substr(trim($request->string('search')->toString()), 0, 100),
            'period' => $period,
            'employee_id' => $this->normalizeEmployeeId($request, $viewer),
        ];
    }

    private function normalizeEmployeeId(Request $request, User $viewer): ?int
    {
        if (! in_array($viewer->role, ['admin', 'viewer'], true) && ! $viewer->isDepartmentHead()) {
            return null;
        }

        $employeeId = $request->integer('employee');

        return User::query()
            ->whereKey($employeeId)
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($viewer->isDepartmentHead(), fn (Builder $query) => $query
                ->where('department_id', $viewer->department_id))
            ->value('id');
    }

    private function employeeOptions(User $viewer)
    {
        if (! in_array($viewer->role, ['admin', 'viewer'], true) && ! $viewer->isDepartmentHead()) {
            return collect();
        }

        return User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($viewer->isDepartmentHead(), fn (Builder $query) => $query
                ->where('department_id', $viewer->department_id))
            ->orderBy('name')
            ->get(['id', 'name', 'department_id']);
    }

    private function attendeeOptions()
    {
        return $this->attendeeEligibilityQuery()
            ->with('department:id,department_name')
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'department_id', 'profile_image']);
    }

    private function attendeeDepartments()
    {
        return Department::query()
            ->orderBy('department_name')
            ->get(['id', 'department_name']);
    }

    private function attendeeEligibilityQuery(): Builder
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('role', self::ATTENDEE_ROLES);
    }

    private function applyPeriod(Builder $query, string $period, CarbonImmutable $now): void
    {
        if ($period === 'past') {
            $query->where('ends_at', '<', $now->utc());

            return;
        }

        if ($period === 'upcoming') {
            $query->where('ends_at', '>=', $now->utc());

            return;
        }

        if ($period === 'all') {
            return;
        }

        [$windowStart, $windowEnd] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'next_7_days' => [$now, $now->addDays(7)->endOfDay()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };

        $query->where('starts_at', '<=', $windowEnd->utc())
            ->where('ends_at', '>=', $windowStart->utc());
    }
}

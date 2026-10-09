<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingAttendeeResponseEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\CalendarAttendeeRoster;
use App\Support\InvitationCandidates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class MeetingQueryService
{
    public const BUSINESS_TIMEZONE = 'Asia/Bangkok';

    public const PERIODS = [
        'upcoming' => 'กำลังจะมาถึง',
        'today' => 'วันนี้',
        'next_7_days' => '7 วันข้างหน้า',
        'this_month' => 'เดือนนี้',
        'past' => 'ที่ผ่านมา',
        'all' => 'ทั้งหมด',
        'custom' => 'กำหนดช่วงวันที่เอง',
    ];

    public const CUSTOM_PERIOD = 'custom';

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

        $this->applyPeriod($query, $filters['period'], $now, $filters['date_from'], $filters['date_to']);

        $meetings = $query
            ->with(['project:id,name', 'task:job_id,job_topic'])
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
            'attendeeOptions' => $viewer->can('create', Meeting::class) ? $this->attendeeOptions(old('attendees', [])) : collect(),
            'attendeeDepartments' => $viewer->can('create', Meeting::class) ? $this->attendeeDepartments() : collect(),
            'projectOptions' => $viewer->can('create', Meeting::class) ? $this->meetingProjectOptions($viewer) : collect(),
            'inspectedEmployee' => $scopedEmployee
                ? $scopedEmployee->loadMissing('department')
                : ($filters['employee_id'] ? User::with('department')->find($filters['employee_id']) : null),
            'nowBangkok' => $now,
        ];
    }

    /**
     * ตัวเลือกที่ฟอร์ม "นัดประชุม" ต้องใช้ โดยไม่ต้องดึงรายการประชุมทั้งหน้ามาด้วย
     *
     * ปฏิทินประชุมและนัดหมายเปิดฟอร์มนี้จากการ์ด "+ เพิ่ม" จึงต้องการแค่ตัวเลือก
     * ไม่ต้องการ paginator ของ indexData()
     *
     * @return array<string, mixed>
     */
    public function meetingCreateFormData(User $viewer): array
    {
        if (! $viewer->can('create', Meeting::class)) {
            return ['attendeeOptions' => collect(), 'attendeeDepartments' => collect(), 'projectOptions' => collect()];
        }

        return [
            'attendeeOptions' => $this->attendeeOptions(old('attendees', [])),
            'attendeeDepartments' => $this->attendeeDepartments(),
            'projectOptions' => $this->meetingProjectOptions($viewer),
        ];
    }

    public function detailData(Request $request, User $viewer, Meeting $meeting): array
    {
        $meeting->load([
            'creator.department',
            'attendees.department',
            'project:id,name',
            'task:job_id,job_topic',
        ]);
        $employeeId = $this->normalizeEmployeeId($request, $viewer);
        $employeeIsRelated = $employeeId
            && ((int) $meeting->created_by === (int) $employeeId || $meeting->attendees->contains('id', $employeeId));

        return [
            'meeting' => $meeting,
            'attendeeOptions' => $viewer->can('update', $meeting) ? $this->attendeeOptions($meeting->attendees->pluck('id')->all()) : collect(),
            'attendeeDepartments' => $viewer->can('update', $meeting) ? $this->attendeeDepartments() : collect(),
            'projectOptions' => $viewer->can('update', $meeting) ? $this->meetingProjectOptions($viewer) : collect(),
            'inspectedEmployee' => $employeeIsRelated ? User::with('department')->find($employeeId) : null,
            // คำเชิญของตัวเอง (null เมื่อผู้เปิดหน้าไม่ได้ถูกเชิญ) กับคำตอบของทุกคนซึ่งเป็นข้อมูล
            // ของผู้สร้างประชุมคนเดียว — ดู MeetingPolicy::viewAttendeeResponses
            'viewerInvitation' => $this->viewerInvitation($meeting, $viewer),
            'attendeeResponseSummary' => $viewer->can('viewAttendeeResponses', $meeting)
                ? $this->attendeeResponseSummary($meeting)
                : null,
            'nowBangkok' => CarbonImmutable::now(self::BUSINESS_TIMEZONE),
        ];
    }

    /**
     * คำเชิญของผู้เปิดหน้านี้ สำหรับการ์ดตอบรับเข้าร่วมประชุม
     *
     * isReinvitation บอกว่าคำเชิญรอบนี้มาจากการที่ผู้สร้างประชุมกด "เชิญอีกครั้ง" เพื่อให้
     * การ์ดพูดได้ตรงกว่าคำเชิญครั้งแรก อ่านจากประวัติที่ MeetingAttendeeResponseService
     * เขียนไว้แล้ว ไม่ได้เพิ่มสถานะหรือคอลัมน์ใหม่เพื่อการแสดงผล
     *
     * คิวรีประวัติถูกถามเฉพาะตอนที่ยังรอตอบอยู่ สถานะที่ตอบแล้วไม่ใช้ข้อความนี้
     *
     * @return array{status: string, respondedAt: CarbonImmutable|null, declineReason: string|null, isReinvitation: bool}|null
     */
    public function viewerInvitation(Meeting $meeting, User $viewer): ?array
    {
        $meeting->loadMissing('attendees');
        $invitation = $meeting->attendees->firstWhere('id', $viewer->id)?->pivot;

        if (! $invitation) {
            return null;
        }

        return [
            'status' => $invitation->status,
            'respondedAt' => $invitation->responded_at?->copy()->timezone(self::BUSINESS_TIMEZONE),
            'declineReason' => $invitation->decline_reason,
            // Blade ใช้ค่านี้แทนการเทียบกับค่าคงที่ของโมเดล จึงใช้การ์ดคำเชิญใบเดียวกันได้ทั้งสอง domain
            'hasAccepted' => $invitation->hasAccepted(),
            'isReinvitation' => $invitation->isAwaitingResponse()
                && $this->latestInvitationEvent($meeting, $viewer) === MeetingAttendeeResponseEvent::EVENT_REINVITED,
        ];
    }

    private function latestInvitationEvent(Meeting $meeting, User $viewer): ?string
    {
        return MeetingAttendeeResponseEvent::query()
            ->forMeeting($meeting)
            ->where('user_id', $viewer->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('event');
    }

    /**
     * คำตอบของผู้เข้าร่วมทุกคนในประชุมใบหนึ่ง พร้อมประวัติการตอบย้อนหลังของแต่ละคน
     *
     * ประวัติถูกดึงมาทีเดียวแล้วจัดกลุ่มในหน่วยความจำ ไม่ใช่คิวรีต่อผู้เข้าร่วมหนึ่งคน
     *
     * @return array{attendees: Collection<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function attendeeResponseSummary(Meeting $meeting): array
    {
        $meeting->loadMissing('attendees.department');

        $historyByAttendee = MeetingAttendeeResponseEvent::query()
            ->forMeeting($meeting)
            ->inChronologicalOrder()
            ->get()
            ->groupBy('user_id');

        $attendees = $meeting->attendees
            ->map(fn (User $meetingAttendee): array => [
                'name' => $meetingAttendee->name,
                'department' => $meetingAttendee->department?->department_name ?? 'ไม่ระบุแผนก',
                'attendee' => $meetingAttendee,
                'status' => $meetingAttendee->pivot->status,
                'respondedAt' => $meetingAttendee->pivot->responded_at?->copy()->timezone(self::BUSINESS_TIMEZONE),
                'declineReason' => $meetingAttendee->pivot->decline_reason,
                'canBeReinvited' => $meetingAttendee->pivot->hasDeclined(),
                'reinviteUrl' => route('meetings.attendance.reinvite', [$meeting, $meetingAttendee]),
                'history' => collect($historyByAttendee[$meetingAttendee->id] ?? [])
                    ->map(fn (MeetingAttendeeResponseEvent $responseEvent): array => [
                        'event' => $responseEvent->event,
                        'occurredAt' => $responseEvent->occurred_at->copy()->timezone(self::BUSINESS_TIMEZONE),
                        'declineReason' => $responseEvent->decline_reason,
                    ])
                    ->values(),
            ])
            ->values();

        return [
            'attendees' => $attendees,
            'totals' => [
                MeetingAttendee::STATUS_ACCEPTED => $attendees->where('status', MeetingAttendee::STATUS_ACCEPTED)->count(),
                MeetingAttendee::STATUS_PENDING => $attendees->where('status', MeetingAttendee::STATUS_PENDING)->count(),
                MeetingAttendee::STATUS_DECLINED => $attendees->where('status', MeetingAttendee::STATUS_DECLINED)->count(),
            ],
        ];
    }

    /**
     * ประชุมที่ทับซ้อนช่วงเวลาที่ขอ สำหรับวางบนปฏิทินของ Workspace
     *
     * สิทธิ์ถูกบังคับที่ SQL ผ่าน calendarScopedQuery() ห้ามกรองสิทธิ์ฝั่ง frontend
     * และห้ามดึงทั้งระบบโดยไม่มีขอบเขต
     *
     * ขอบเขตคือ "แผนกของผู้เปิดปฏิทิน" เสมอ ไม่มีโหมดล็อกกับสมาชิกคนเดียว —
     * Workspace ของสมาชิกใช้รายการประชุม (indexData) ไม่ใช่ปฏิทินใบนี้
     *
     * ส่วนหน้ารายการประชุมยังใช้ visibleQuery() เต็มขอบเขตตามเดิม เพราะเป็นหน้ากำกับดูแล
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function calendarMeetings(User $viewer, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $windowStart = CarbonImmutable::instance($from)->utc();
        $windowEnd = CarbonImmutable::instance($to)->utc();

        return $this->calendarScopedQuery($viewer)
            /*
             * ผู้จัดกับผู้เข้าร่วมถูก eager load ไว้ตั้งแต่ต้น มิฉะนั้นการวาดตารางประชุม
             * จะยิงคิวรีต่อหนึ่งแถว (และเอกสาร Export กวาดได้ถึง 366 วัน)
             *
             * คอลัมน์ของผู้เข้าร่วมต้องครบตามที่ RoleLabel::withDepartment() ใช้ —
             * role, is_active, is_department_head, department_id — ไม่งั้น
             * User::isDepartmentHead() จะอ่านค่าที่ไม่ได้ select เป็น null แล้ว
             * หัวหน้าแผนกจะถูกแสดงเป็น "พนักงาน" แบบไม่มีอะไรฟ้อง
             *
             * attendees.department ก็ต้องมี department_id อยู่ใน select ด้านบนก่อน
             * ไม่งั้นความสัมพันธ์จะ resolve เป็น null ทุกคนเงียบ ๆ
             *
             * ทั้งหมดเป็น constrained load สองคิวรีคงที่ ไม่ขึ้นกับจำนวนรายการ
             */
            ->with([
                'creator:id,name,profile_image',
                'attendees:id,name,department_id,role,is_department_head,is_active',
                'attendees.department:id,department_name',
            ])
            ->where('starts_at', '<=', $windowEnd)
            ->where('ends_at', '>=', $windowStart)
            ->orderBy('starts_at')
            ->orderBy('id')
            /*
             * description เข้ามาเพื่อให้บล็อกรายงานรายนัดในเอกสาร Export มีเนื้อหา
             * เหมือนฝั่งนัดหมาย (ของเดิมฝั่งประชุมส่ง detail เป็น null ตายตัว)
             */
            ->get(['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'created_by'])
            ->map(function (Meeting $meeting): array {
                $startsAt = CarbonImmutable::instance($meeting->starts_at)->setTimezone(self::BUSINESS_TIMEZONE);
                $endsAt = CarbonImmutable::instance($meeting->ends_at)->setTimezone(self::BUSINESS_TIMEZONE);

                return [
                    'id' => 'meeting-'.$meeting->id,
                    'type' => 'meeting',
                    'title' => $meeting->title,
                    'description' => $meeting->description,
                    'location' => $meeting->location ?: 'ไม่ระบุสถานที่',
                    'organizer' => $meeting->creator?->name ?? 'ไม่ระบุผู้จัด',
                    // รูปเป็น URL ที่ผ่าน MediaController เสมอ ห้ามประกอบ path จาก storage ตรง ๆ
                    'organizerAvatar' => $meeting->creator?->profile_image ? route('media.profile', $meeting->creator) : null,
                    'attendees' => CalendarAttendeeRoster::rows($meeting->attendees),
                    'start' => $startsAt->format('Y-m-d'),
                    'due' => $endsAt->format('Y-m-d'),
                    'startTime' => $startsAt->format('H:i'),
                    'endTime' => $endsAt->format('H:i'),
                    'entityId' => $meeting->id,
                    'url' => route('meetings.show', $meeting),
                ];
            })
            ->values();
    }

    /**
     * ขอบเขตของ "ปฏิทินประชุมและนัดหมาย" — ระดับแผนก ไม่ใช่ปฏิทินส่วนตัว
     *
     * กติกา
     * ---------------------------------------------------------------
     *   1. ประชุมที่ "แผนกของผู้เปิดปฏิทินเป็นเจ้าของ" — ทุกบทบาทในแผนกเห็นทั้งหมด
     *      แม้ไม่ได้ถูกเชิญ เพราะปฏิทินนี้คือตารางงานร่วมของแผนก
     *   2. ประชุมที่ผู้เปิดปฏิทินจัดเอง หรือถูกเชิญเอง — เห็นเสมอ แม้เป็นของแผนกอื่น
     *
     * "เจ้าของแผนก" ของประชุมคือแผนกของผู้จัด (creator) ไม่ใช่แผนกของผู้เข้าร่วม
     * ---------------------------------------------------------------
     * ถ้านับแผนกของผู้เข้าร่วมด้วย ประชุมของแผนกอื่นที่เพียงเชิญคนในแผนกเราไปร่วม
     * หนึ่งคน จะกลายเป็นประชุมที่คนทั้งแผนกเราเห็น ซึ่งเป็นการเปิดข้อมูลของแผนกนั้น
     * ให้คนที่ไม่เกี่ยวข้องเลย — ตรงข้ามกับข้อกำหนดว่าผู้ที่ถูกเชิญข้ามแผนก
     * ต้องเห็น "เฉพาะประชุมที่ตนได้รับเชิญ" ไม่ใช่ข้อมูลอื่นของแผนกนั้น
     *
     * การเห็นไม่เท่ากับสิทธิ์แก้ไข — MeetingPolicy::update()/delete() ไม่ถูกแตะ
     * ยังเป็นของผู้จัดกับ admin เท่านั้น
     *
     * ไม่เรียก visibleQuery() เพราะกฎของหน้ารายการ (หัวหน้าแผนกเห็นประชุมทุกใบที่
     * คนในแผนกเข้าร่วม) กว้างกว่ากฎของปฏิทินในมิติผู้เข้าร่วม การซ้อนสองกฎจะได้
     * ขอบเขตที่อธิบายไม่ได้ว่ามาจากที่ไหน
     */
    public function calendarScopedQuery(User $viewer): Builder
    {
        return Meeting::query()->where(function (Builder $query) use ($viewer): void {
            $query->where('created_by', $viewer->id)
                ->orWhereHas('attendees', fn (Builder $attendees) => $attendees->whereKey($viewer->id));

            // department_id ที่เป็น null ต้องไม่จับคู่กับ null ของคนอื่น ไม่งั้นผู้ใช้ที่ยัง
            // ไม่ถูกจัดแผนกจะเห็นประชุมของทุกคนที่ยังไม่ถูกจัดแผนกเหมือนกัน
            if ($viewer->department_id) {
                $query->orWhereHas('creator', fn (Builder $creator) => $creator
                    ->where('department_id', $viewer->department_id));
            }
        });
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
        return InvitationCandidates::eligibleIds($attendeeIds);
    }

    private function normalizeFilters(Request $request, User $viewer): array
    {
        $period = $request->string('period')->toString();
        $period = array_key_exists($period, self::PERIODS) ? $period : 'upcoming';

        $customRange = $period === self::CUSTOM_PERIOD
            ? $this->parseCustomRange($request->query('date_from'), $request->query('date_to'))
            : null;

        // ช่วงกำหนดเองที่ไม่ผ่านการตรวจ (รูปแบบผิด, from หลัง to) ถูกทิ้งทั้งคู่แล้วกลับไปใช้ค่าเริ่มต้น
        // ไม่ใช่แก้ให้ครึ่งเดียว เหมือนหลักการเดียวกับ App\Support\ReportPeriod::custom()
        if ($period === self::CUSTOM_PERIOD && $customRange === null) {
            $period = 'upcoming';
        }

        return [
            'search' => mb_substr(trim($request->string('search')->toString()), 0, 100),
            'period' => $period,
            'date_from' => $customRange !== null ? $customRange[0]->format('Y-m-d') : null,
            'date_to' => $customRange !== null ? $customRange[1]->format('Y-m-d') : null,
            'employee_id' => $this->normalizeEmployeeId($request, $viewer),
        ];
    }

    /**
     * ช่วงวันที่ที่ผู้ใช้กำหนดเองสำหรับตัวกรองรายการประชุม — ต้องเป็นวันที่จริงรูปแบบ Y-m-d
     * ทั้งคู่ และ date_from ไม่หลัง date_to มิฉะนั้นถือว่าทั้งคู่ใช้ไม่ได้
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function parseCustomRange(mixed $from, mixed $to): ?array
    {
        $start = $this->parseDay($from);
        $end = $this->parseDay($to);

        if ($start === null || $end === null || $start->gt($end)) {
            return null;
        }

        return [$start, $end];
    }

    private function parseDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::BUSINESS_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        // วันที่ไม่มีจริงเช่น 2569-02-30 ถูก Carbon เลื่อนไปต้นเดือนถัดไปเงียบ ๆ จึงต้องเทียบกลับกับข้อความเดิม
        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
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

    /**
     * รายชื่อที่แสดงในตัวเลือก "ผู้เข้าร่วม" — จำกัดเฉพาะพนักงาน/หัวหน้าแผนก (role = user)
     *
     * admin และ viewer ยังคง "เลือกได้" ในทางเทคนิคผ่าน InvitationCandidates::eligibleIds()
     * (ดู MeetingManagementTest::test_create_normalizes_duplicate_attendees_and_accepts_active_organizational_roles)
     * แต่ต้องไม่ปรากฏเป็นตัวเลือกให้เลือกใหม่ในรายการเรียกดู เพราะไม่ใช่ผู้ถูกเชิญปกติ
     *
     * $includeIds คือผู้เข้าร่วมที่ถูกผูกไว้แล้ว (เช่น admin ที่ถูกเชิญไว้ก่อนหน้านี้) ต้องยังแสดง
     * ต่อไปแม้ไม่ใช่ role = user มิฉะนั้นการบันทึกฟอร์มซ้ำจะทำให้คนเหล่านั้นหลุดออกจากที่ประชุมเงียบ ๆ
     *
     * @param  array<int>  $includeIds
     */
    private function attendeeOptions(array $includeIds = [])
    {
        return InvitationCandidates::browsableOptions($includeIds);
    }

    private function attendeeDepartments()
    {
        return InvitationCandidates::departments();
    }

    /**
     * ตัวเลือกโปรเจกต์ + งานหลักสำหรับผูกกับการประชุมแบบไม่บังคับ
     *
     * โปรเจกต์ที่เลือกได้ต้อง "เห็นได้" ตามเกณฑ์เดียวกับ WorkOrderListPolicy::view() เป๊ะ ๆ
     * (เจ้าของ, admin/viewer, หรือมีงานที่ involving() อยู่ในนั้น) ไม่ใช่แค่โปรเจกต์ที่มีงาน
     * เพราะโปรเจกต์เพิ่งสร้างยังไม่มีงานเลยก็ต้องผูกกับประชุมได้ (เช่น ประชุม kickoff โปรเจกต์ใหม่)
     *
     * ส่วนงานหลักที่เสนอในแต่ละโปรเจกต์ยังกรองผ่าน WorkOrder::visibleInProjectsFor() ตัวเดียวกับ
     * ที่ MyTaskController ใช้ตัดสินว่าสมาชิกเห็นงานใบไหนได้บ้าง เพื่อไม่ให้ผู้ร่วมงานข้ามแผนก
     * เห็นงานพี่น้องที่ตัวเองไม่มีสิทธิ์เห็นอยู่ดี แม้จะเห็นชื่อโปรเจกต์ได้ก็ตาม
     *
     * คืนเฉพาะงานระดับบนสุด (ไม่มีงานย่อย) ตามที่ต้องการ
     *
     * @return Collection<int, array{id:int, name:string, tasks: array<int, array{id:int, name:string}>}>
     */
    public function meetingProjectOptions(User $viewer): Collection
    {
        return $this->meetingVisibleProjectsQuery($viewer)
            ->with(['workOrders' => fn (HasMany $query) => $query
                ->topLevel()
                ->whereNull('deleted_at')
                ->visibleInProjectsFor($viewer)
                ->orderBy('job_topic')
                ->select(['job_id', 'job_topic', 'work_order_list_id'])])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (WorkOrderList $list) => [
                'id' => $list->id,
                'name' => $list->name,
                'tasks' => $list->workOrders->map(fn (WorkOrder $task) => [
                    'id' => $task->job_id,
                    'name' => $task->job_topic,
                ])->values()->all(),
            ]);
    }

    /**
     * ตรวจว่า work_order_list_id / work_order_id ที่ส่งมาผูกกับประชุมอยู่ในขอบเขตที่ผู้ใช้
     * เห็นได้จริงหรือไม่ — ห้ามเชื่อค่าที่ client ส่งมาตรง ๆ เหมือน attendeeEligibility()
     */
    public function isMeetingTaskSelectable(User $viewer, int $workOrderId, ?int $workOrderListId): bool
    {
        return WorkOrder::query()
            ->topLevel()
            ->visibleInProjectsFor($viewer)
            ->whereNull('deleted_at')
            ->whereKey($workOrderId)
            ->when($workOrderListId, fn (Builder $query) => $query->where('work_order_list_id', $workOrderListId))
            ->exists();
    }

    public function isMeetingProjectSelectable(User $viewer, int $workOrderListId): bool
    {
        return $this->meetingVisibleProjectsQuery($viewer)->whereKey($workOrderListId)->exists();
    }

    /**
     * เกณฑ์ต้องตรงกับ WorkOrderListPolicy::view() ทุกประการ: เจ้าของ, admin/viewer,
     * หรือมีงานในโปรเจกต์นั้นที่ involving() ผู้ใช้อยู่
     */
    private function meetingVisibleProjectsQuery(User $viewer): Builder
    {
        return WorkOrderList::query()
            ->when(! in_array($viewer->role, ['admin', 'viewer'], true), fn (Builder $query) => $query
                ->where(function (Builder $list) use ($viewer): void {
                    $list->where('user_id', $viewer->id)
                        ->orWhereHas('workOrders', fn (Builder $workOrders) => $workOrders->involving($viewer));
                }));
    }

    private function applyPeriod(Builder $query, string $period, CarbonImmutable $now, ?string $dateFrom = null, ?string $dateTo = null): void
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

        if ($period === self::CUSTOM_PERIOD) {
            // normalizeFilters() รับประกันแล้วว่าถึงตรงนี้ date_from/date_to เป็นวันที่จริงรูปแบบ Y-m-d ทั้งคู่
            // และ date_from ไม่หลัง date_to มิฉะนั้น period จะถูกเปลี่ยนเป็น 'upcoming' ไปแล้วตั้งแต่ชั้นนั้น
            $windowStart = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, self::BUSINESS_TIMEZONE);
            $windowEnd = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, self::BUSINESS_TIMEZONE)->endOfDay();
        } else {
            [$windowStart, $windowEnd] = match ($period) {
                'today' => [$now->startOfDay(), $now->endOfDay()],
                'next_7_days' => [$now, $now->addDays(7)->endOfDay()],
                default => [$now->startOfMonth(), $now->endOfMonth()],
            };
        }

        $query->where('starts_at', '<=', $windowEnd->utc())
            ->where('ends_at', '>=', $windowStart->utc());
    }
}

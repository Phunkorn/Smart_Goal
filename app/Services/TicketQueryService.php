<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketLocation;
use App\Models\User;
use App\Support\TicketAgentAccess;
use App\Support\TicketDesign;
use App\Support\TicketSla;
use App\Support\TodayWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * คิวรีรายการ Ticket
 *
 * visibleTo() คือนิยามเดียวของ "ใครเห็น Ticket ใบไหนในรายการ" และต้องตรงกับ
 * TicketPolicy::view() แบบรายใบเสมอ (TicketVisibilityTest ตรึงไว้) ถ้าแก้ที่หนึ่ง
 * ต้องแก้อีกที่หนึ่งด้วย — มิฉะนั้นจะมี Ticket ที่อยู่ในรายการแต่เปิดไม่ได้ หรือกลับกัน
 *
 * ตัวกรองทุกตัวถูกทำให้เป็นค่าที่รู้จักก่อนใช้ (filters()) ค่าที่ไม่รู้จักถูกทิ้ง
 * การแก้ URL จึงได้อย่างมากแค่รายการที่ผู้ใช้เห็นได้อยู่แล้ว ไม่มีทางขยายขอบเขต
 */
class TicketQueryService
{
    public const PER_PAGE = 10;

    public const SCOPE_PARTICIPATING = 'participating';

    public const SCOPE_REQUESTED = 'requested';

    public const SCOPE_DEPARTMENT = 'department';

    public const LOCATION_OTHER = 'other';

    /** ตัวกรอง "แจ้งวันนี้" — วันธุรกิจตามเวลากรุงเทพ (TodayWorkspace) ไม่ใช่วันตาม UTC */
    public const DAY_TODAY = 'today';

    public function visibleTo(User $user): Builder
    {
        $query = Ticket::query();

        if (! TicketAgentAccess::isStaff($user)) {
            return $query->whereRaw('1 = 0');
        }

        if (TicketAgentAccess::seesAllTickets($user)) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->where('requester_id', $user->id);

            // ตรงกับ User::overseesDepartment() ที่ policy ใช้ — อ่านแผนกจาก snapshot ตอนแจ้ง
            if ($user->isDepartmentHead()) {
                $scope->orWhere('requester_department_id', $user->department_id);
            }
        });
    }

    /**
     * ขอบเขตที่ผู้ใช้คนนี้เลือกได้ (นอกจาก "ทั้งหมดที่เห็นได้")
     *
     * @return array<string, string>
     */
    public function scopeOptions(User $user): array
    {
        $options = [];

        if (TicketAgentAccess::isAgent($user)) {
            $options[self::SCOPE_PARTICIPATING] = 'ที่ฉันเข้าร่วม';
        }

        if (TicketAgentAccess::seesAllTickets($user) || $user->isDepartmentHead()) {
            $options[self::SCOPE_REQUESTED] = 'ที่ฉันแจ้ง';
        }

        if ($user->isDepartmentHead()) {
            $options[self::SCOPE_DEPARTMENT] = 'ของแผนกฉัน';
        }

        return $options;
    }

    /**
     * ทำให้ค่าจาก query string เป็นตัวกรองที่รู้จัก
     *
     * @param  array<string, mixed>  $input
     * @return array{q: string, status: string, location: string, scope: string, day: string}
     */
    public function filters(User $user, array $input): array
    {
        $status = (string) ($input['status'] ?? '');
        $scope = (string) ($input['scope'] ?? '');
        $location = (string) ($input['location'] ?? '');
        $day = (string) ($input['day'] ?? '');

        $locationIsKnown = $location === self::LOCATION_OTHER
            || (ctype_digit($location) && TicketLocation::query()->whereKey((int) $location)->exists());

        return [
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 100),
            'status' => array_key_exists($status, TicketDesign::statuses()) ? $status : '',
            'location' => $locationIsKnown ? $location : '',
            'scope' => array_key_exists($scope, $this->scopeOptions($user)) ? $scope : '',
            'day' => $day === self::DAY_TODAY ? self::DAY_TODAY : '',
        ];
    }

    /**
     * viewer_participating บอกว่าผู้ดูเป็นผู้เข้าร่วมปัจจุบันหรือไม่ (ซ่อนปุ่ม "เข้าร่วม" ในแถว) — subquery เดียว ไม่คิวรีรายแถว
     *
     * @param  array{q: string, status: string, location: string, scope: string, day: string}  $filters
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return $this->filtered($user, $filters)
            ->with('closingActivity')
            ->withExists(['activeParticipants as viewer_participating' => fn (Builder $query) => $query->where('user_id', $user->id)])
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * จำนวนต่อสถานะภายใต้ตัวกรองอื่นที่ใช้อยู่ — ใช้ในแท็บสถานะ (หนึ่งคิวรี)
     *
     * @param  array{q: string, status: string, location: string, scope: string, day: string}  $filters
     * @return array<string, int>
     */
    public function statusCounts(User $user, array $filters): array
    {
        $counts = $this->filtered($user, $filters)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(TicketDesign::statuses())
            ->map(fn ($design, string $status) => (int) ($counts[$status] ?? 0))
            ->all();
    }

    /* ---------------------------------------------------------------
     * หน้ารับแจ้งปัญหาของทีม IT (tickets.index เมื่อ seesAllTickets) — ข้อมูลจริงจากฐานเท่านั้น
     * ผู้เรียกต้องเห็น Ticket ทั้งหมดอยู่แล้ว (admin / agent) จึงไม่จำกัดขอบเขตเพิ่ม
     * ------------------------------------------------------------- */

    /**
     * จำนวน Ticket ทั้งระบบต่อสถานะ (ไม่ขึ้นกับตัวกรองของคิว) และจำนวนที่ปิดในวันนี้ตามเวลากรุงเทพ
     *
     * @return array{counts: array<string, int>, closed_today: int}
     */
    public function overview(): array
    {
        $counts = Ticket::query()->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        [$start, $end] = TodayWorkspace::businessDayBounds();

        return [
            'counts' => collect(TicketDesign::statuses())->map(fn ($design, string $status) => (int) ($counts[$status] ?? 0))->all(),
            'closed_today' => Ticket::query()
                ->where('status', Ticket::STATUS_CLOSED)
                ->whereBetween('closed_at', [$start, $end])
                ->count(),
        ];
    }

    /** จำนวน Ticket ที่แจ้งในวันนี้ตามเวลากรุงเทพ (created_at เก็บเป็น UTC จึงเทียบกับขอบเขตวันธุรกิจ) */
    public function createdToday(): int
    {
        [$start, $end] = TodayWorkspace::businessDayBounds();

        return Ticket::query()->whereBetween('created_at', [$start, $end])->count();
    }

    /**
     * ภาระงานของเจ้าหน้าที่ IT ปัจจุบัน — สามคิวรีเสมอ ไม่ขึ้นกับจำนวนคน
     *
     * working   = Ticket ที่ยังไม่ปิดและกำลังเข้าร่วมอยู่ (หลายคนเข้าร่วมใบเดียวกันได้ จึงไม่ใช่ "เจ้าของงาน")
     * follow_up = ในนั้น ใบที่สถานะ "งานที่ต้องติดตาม"
     * closed_today / closed_week = Ticket ที่ปิดอยู่และคนนี้เป็นผู้ปิดล่าสุด ภายในวันนี้ / 7 วันล่าสุด (เวลากรุงเทพ)
     * ไม่มีข้อมูลออนไลน์/ความพร้อมรับงาน ระบบจึงไม่แสดงสิ่งเหล่านั้น
     *
     * @return Collection<int, array{user: User, working: int, follow_up: int, closed_today: int, closed_week: int}>
     */
    public function teamWorkload(): Collection
    {
        $agents = TicketAgentAccess::agentsQuery()
            ->with('department')
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'profile_image']);

        if ($agents->isEmpty()) {
            return collect();
        }

        $ids = $agents->pluck('id');

        $working = DB::table('ticket_participants')
            ->join('tickets', 'tickets.id', '=', 'ticket_participants.ticket_id')
            ->whereIn('ticket_participants.user_id', $ids)
            ->whereNull('ticket_participants.left_at')
            ->where('tickets.status', '!=', Ticket::STATUS_CLOSED)
            ->groupBy('ticket_participants.user_id')
            ->selectRaw('ticket_participants.user_id, count(*) as working')
            ->selectRaw('sum(case when tickets.status = ? then 1 else 0 end) as follow_up', [Ticket::STATUS_FOLLOW_UP])
            ->get()
            ->keyBy('user_id');

        [$todayStart] = TodayWorkspace::businessDayBounds();
        $weekStart = $todayStart->copy()->subDays(6);

        $closed = Ticket::query()->toBase()
            ->where('status', Ticket::STATUS_CLOSED)
            ->whereIn('closed_by', $ids)
            ->where('closed_at', '>=', $weekStart)
            ->groupBy('closed_by')
            ->selectRaw('closed_by, count(*) as closed_week')
            ->selectRaw('sum(case when closed_at >= ? then 1 else 0 end) as closed_today', [$todayStart])
            ->get()
            ->keyBy('closed_by');

        return $agents
            ->map(fn (User $agent) => [
                'user' => $agent,
                'working' => (int) ($working[$agent->id]->working ?? 0),
                'follow_up' => (int) ($working[$agent->id]->follow_up ?? 0),
                'closed_today' => (int) ($closed[$agent->id]->closed_today ?? 0),
                'closed_week' => (int) ($closed[$agent->id]->closed_week ?? 0),
            ])
            ->sortBy([['working', 'desc'], [fn (array $a, array $b) => strcmp($a['user']->name, $b['user']->name)]])
            ->values();
    }

    /**
     * Ticket สถานะ "งานที่ต้องติดตาม" — วันติดตามที่ใกล้ที่สุดก่อน ใบที่ไม่ได้ระบุวันอยู่ท้าย
     *
     * @return Collection<int, Ticket>
     */
    public function followUps(int $limit): Collection
    {
        return Ticket::query()
            ->where('status', Ticket::STATUS_FOLLOW_UP)
            ->with(['activeParticipants' => fn ($query) => $query->orderBy('joined_at')])
            ->orderByRaw('case when follow_up_date is null then 1 else 0 end')
            ->orderBy('follow_up_date')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Ticket ที่ยังไม่ปิดและเกินเกณฑ์ SLA ปิดงาน — มีเฉพาะเมื่อตั้งเกณฑ์ไว้ (TicketSla)
     * คนละความหมายกับ followUps(): ใบหนึ่งเกิน SLA ได้ในทุกสถานะที่ยังไม่ปิด
     *
     * @return array{configured: bool, target_minutes: int|null, total: int, tickets: Collection<int, Ticket>}
     */
    public function overdue(int $limit): array
    {
        $target = TicketSla::resolutionMinutes();

        if ($target === null) {
            return ['configured' => false, 'target_minutes' => null, 'total' => 0, 'tickets' => collect()];
        }

        $query = Ticket::query()
            ->where('status', '!=', Ticket::STATUS_CLOSED)
            ->where('created_at', '<', now()->subMinutes($target));

        return [
            'configured' => true,
            'target_minutes' => $target,
            'total' => (clone $query)->count(),
            'tickets' => $query->orderBy('created_at')->limit($limit)->get(),
        ];
    }

    /** @param  array{q: string, status: string, location: string, scope: string, day: string}  $filters */
    private function filtered(User $user, array $filters): Builder
    {
        return $this->visibleTo($user)
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                // ไม่ escape % และ _ — การ escape ด้วย backslash ใช้ได้กับ MySQL แต่ไม่ใช่ SQLite
                // และ wildcard ที่ผู้ใช้พิมพ์ได้อย่างมากแค่ขยายผลค้นหาภายในรายการที่เห็นได้อยู่แล้ว
                $term = '%'.$filters['q'].'%';
                $query->where(fn (Builder $search) => $search
                    ->where('ticket_number', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('requester_name', 'like', $term));
            })
            ->when($filters['location'] === self::LOCATION_OTHER, fn (Builder $query) => $query->whereNull('location_id'))
            ->when(ctype_digit($filters['location']), fn (Builder $query) => $query->where('location_id', (int) $filters['location']))
            ->when($filters['scope'] === self::SCOPE_PARTICIPATING, fn (Builder $query) => $query
                ->whereHas('activeParticipants', fn (Builder $participant) => $participant->where('user_id', $user->id)))
            ->when($filters['scope'] === self::SCOPE_REQUESTED, fn (Builder $query) => $query->where('requester_id', $user->id))
            ->when($filters['scope'] === self::SCOPE_DEPARTMENT, fn (Builder $query) => $query
                ->where('requester_department_id', $user->department_id))
            // created_at เก็บเป็น UTC จึงเทียบกับขอบเขตวันธุรกิจกรุงเทพ — ตรงกับที่ createdToday() นับการ์ด "งานในวันนี้"
            ->when($filters['day'] === self::DAY_TODAY, fn (Builder $query) => $query
                ->whereBetween('created_at', TodayWorkspace::businessDayBounds()));
    }
}

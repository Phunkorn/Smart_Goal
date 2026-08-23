<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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

    public function indexData(Request $request, User $viewer): array
    {
        $filters = $this->normalizeFilters($request, $viewer);
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
            'employeeOptions' => $this->employeeOptions($viewer),
            'attendeeOptions' => $viewer->can('create', Meeting::class) ? $this->attendeeOptions() : collect(),
            'attendeeDepartments' => $viewer->can('create', Meeting::class) ? $this->attendeeDepartments() : collect(),
            'inspectedEmployee' => $filters['employee_id']
                ? User::with('department')->find($filters['employee_id'])
                : null,
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

    public function visibleQuery(User $viewer): Builder
    {
        $query = Meeting::query();

        if (! in_array($viewer->role, ['admin', 'viewer'], true)) {
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
        if (! in_array($viewer->role, ['admin', 'viewer'], true)) {
            return null;
        }

        $employeeId = $request->integer('employee');

        return User::query()
            ->whereKey($employeeId)
            ->where('role', 'user')
            ->where('is_active', true)
            ->value('id');
    }

    private function employeeOptions(User $viewer)
    {
        if (! in_array($viewer->role, ['admin', 'viewer'], true)) {
            return collect();
        }

        return User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
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

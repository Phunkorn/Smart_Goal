<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TicketAgent;
use App\Models\TicketLocation;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\TicketAgentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การตั้งค่า Smart Ticket ของ admin: แผนกที่รับผิดชอบ, สิทธิ์ agent รายบุคคล, การแจ้งเตือน, สาขา
 *
 * รูปแบบเดียวกับ DepartmentController::updateComputerCheckAccess(): ล็อกแถว → เทียบค่าเดิม →
 * เขียนเฉพาะเมื่อเปลี่ยนจริง → AuditTrail หนึ่งแถวพร้อม before/after
 * การตรวจสิทธิ์ (TicketPolicy::manageSettings) อยู่ที่ Form Request ของแต่ละ action
 * กติกาว่าใครเป็น agent อ่านจาก TicketAgentAccess ที่เดียว ที่นี่แค่เขียนค่าตั้ง
 */
class TicketSettingsService
{
    public function setDepartmentResponsible(Department $department, bool $responsible): bool
    {
        return DB::transaction(function () use ($department, $responsible): bool {
            $locked = Department::query()->lockForUpdate()->findOrFail($department->id);
            $before = (bool) $locked->ticket_responsible;

            if ($before === $responsible) {
                return false;
            }

            $locked->forceFill(['ticket_responsible' => $responsible])->save();

            AuditTrail::log(
                'updated',
                $locked,
                ($responsible ? 'Admin made' : 'Admin removed').' department responsible for Smart Ticket: '.$locked->department_name,
                ['before' => ['ticket_responsible' => $before], 'after' => ['ticket_responsible' => $responsible]]
            );

            return true;
        });
    }

    /**
     * ตั้งสิทธิ์ agent ของคนหนึ่งคน
     *
     * @param  bool|null  $enabled  true = เปิดรายคน, false = ปิดรายคน, null = ตามแผนก
     */
    public function setAgent(User $user, ?bool $enabled, bool $receivesAlerts): bool
    {
        // viewer และบัญชีปิดใช้งานเป็น agent ไม่ได้อยู่แล้ว (TicketAgentAccess) การตั้งค่าให้
        // จึงไม่มีผล และจะทำให้หน้าตั้งค่าแสดงข้อมูลที่ไม่ตรงกับสิทธิ์จริง
        if (! TicketAgentAccess::isStaff($user)) {
            throw ValidationException::withMessages([
                'user_id' => 'บัญชีนี้เป็นผู้ดูอย่างเดียวหรือถูกปิดใช้งาน จึงกำหนดเป็นเจ้าหน้าที่ Ticket ไม่ได้',
            ]);
        }

        /*
         * คำขอพร้อมกันสำหรับคนเดียวกัน (admin ดับเบิลคลิก / สองแท็บ)
         * - แถวยังไม่มี: insertOrIgnore ให้ unique(user_id) ตัดสินว่าใครสร้าง คนที่มาทีหลังไม่ error
         *   แล้วอ่านแถวนั้นใต้ล็อกและอัปเดตต่อ — ไม่มีแถวซ้ำ
         * - MySQL: SELECT … FOR UPDATE บนแถวที่ยังไม่มีถือ gap lock สองคำขอ insert พร้อมกันจึง deadlock ได้
         *   DB::transaction(…, 3) ลองใหม่เฉพาะ deadlock/lock timeout (causedByConcurrencyError) ไม่ใช่ทุก error
         */
        return DB::transaction(function () use ($user, $enabled, $receivesAlerts): bool {
            $row = TicketAgent::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $before = $this->agentState($user, $row);

            // ค่าตั้งต้นล้วน (ตามแผนก + รับแจ้งเตือน) ไม่ต้องมีแถว
            if ($enabled === null && $receivesAlerts) {
                $row?->delete();
            } else {
                if (! $row) {
                    // แถวกลาง (ตามแผนก + รับแจ้งเตือน) มีผลเท่ากับไม่มีแถว — ใส่ก่อนแล้วค่อยตั้งค่าจริง
                    $inserted = TicketAgent::query()->insertOrIgnore([
                        'user_id' => $user->id,
                        'is_enabled' => null,
                        'receives_alerts' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $row = TicketAgent::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

                    // อีกคำขอสร้างแถวไปก่อน — สถานะก่อนหน้าคือของแถวนั้น ไม่ใช่ "ไม่มีแถว"
                    if ($inserted === 0) {
                        $before = $this->agentState($user, $row);
                    }
                }

                $row->forceFill([
                    'is_enabled' => $enabled,
                    'receives_alerts' => $receivesAlerts,
                    'updated_by' => auth()->id(),
                ])->save();
            }

            $after = $this->agentState($user->fresh(), TicketAgent::query()->where('user_id', $user->id)->first());

            if ($before === $after) {
                return false;
            }

            AuditTrail::log(
                'updated',
                $user,
                'Admin changed Smart Ticket agent access for: '.$user->name,
                ['before' => $before, 'after' => $after]
            );

            return true;
        }, attempts: 3);
    }

    public function addLocation(string $name): TicketLocation
    {
        return DB::transaction(function () use ($name): TicketLocation {
            $location = TicketLocation::create([
                'name' => trim($name),
                'is_active' => true,
                'sort_order' => (int) TicketLocation::query()->max('sort_order') + 1,
            ]);

            AuditTrail::log('created', $location, 'Admin added Smart Ticket location: '.$location->name, [
                'after' => ['name' => $location->name, 'is_active' => true],
            ]);

            return $location;
        });
    }

    public function updateLocation(TicketLocation $location, string $name, bool $active): bool
    {
        return DB::transaction(function () use ($location, $name, $active): bool {
            $locked = TicketLocation::query()->lockForUpdate()->findOrFail($location->id);
            $before = ['name' => $locked->name, 'is_active' => $locked->is_active];
            $after = ['name' => trim($name), 'is_active' => $active];

            if ($before === $after) {
                return false;
            }

            // Ticket เก่าเก็บชื่อสาขาเป็น snapshot ไว้แล้ว การเปลี่ยนชื่อจึงไม่แก้ประวัติย้อนหลัง
            $locked->update($after);

            AuditTrail::log('updated', $locked, 'Admin updated Smart Ticket location: '.$after['name'], [
                'before' => $before,
                'after' => $after,
            ]);

            return true;
        });
    }

    /**
     * ข้อมูลของหน้าตั้งค่า
     *
     * @return array{departments: Collection, agents: Collection, candidates: Collection, locations: Collection}
     */
    public function overview(): array
    {
        $staff = fn (Builder $query) => $query->where('is_active', true)->whereIn('role', ['admin', 'user']);

        $departments = Department::query()
            ->withCount(['users as active_members_count' => $staff])
            ->orderBy('department_name')
            ->get();

        // ทุกคนที่สิทธิ์ agent ขึ้นกับการตั้งค่า: สมาชิกแผนกที่รับผิดชอบ + ทุกคนที่มีแถว override
        $people = User::query()
            ->tap($staff)
            ->where(function (Builder $query): void {
                $query->whereHas('ticketAgent')
                    ->orWhereHas('department', fn (Builder $department) => $department->where('ticket_responsible', true));
            })
            ->with(['department', 'ticketAgent'])
            ->orderBy('name')
            ->get();

        $agents = $people->map(fn (User $user) => [
            'user' => $user,
            'is_agent' => TicketAgentAccess::isAgent($user),
            'source' => TicketAgentAccess::source($user),
            'receives_alerts' => TicketAgentAccess::receivesAlerts($user),
        ]);

        $candidates = User::query()
            ->tap($staff)
            ->whereNotIn('id', $people->pluck('id'))
            ->with('department')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (User $user) => $user->department?->department_name ?? 'ไม่มีแผนก')
            ->sortKeys();

        return [
            'departments' => $departments,
            'agents' => $agents,
            'candidates' => $candidates,
            'locations' => TicketLocation::query()->ordered()->get(),
        ];
    }

    /** @return array{is_agent: bool, source: string, receives_alerts: bool} */
    private function agentState(User $user, ?TicketAgent $row): array
    {
        $user->setRelation('ticketAgent', $row);

        return [
            'is_agent' => TicketAgentAccess::isAgent($user),
            'source' => TicketAgentAccess::source($user),
            'receives_alerts' => TicketAgentAccess::receivesAlerts($user),
        ];
    }
}

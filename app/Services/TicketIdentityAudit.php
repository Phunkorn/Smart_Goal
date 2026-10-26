<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ตรวจว่าตัวตนทางประวัติของ Smart Ticket (user_ref_id / closed_by_ref_id) ถูก "สวม" โดยบัญชีใหม่หรือไม่
 *
 * ref เก็บ users.id โดยไม่มี FK จึงถูกต้องตราบที่ id ของบัญชีที่ลบถาวรไม่ถูกนำกลับมาใช้ใหม่
 * ซึ่งเกิดได้จาก: MySQL/MariaDB รุ่นเก่าที่ตั้งตัวนับ AUTO_INCREMENT ใหม่หลัง restart,
 * การกู้คืน (restore) เฉพาะตาราง users, การนำเข้าข้อมูลที่ระบุ id เอง หรือ ALTER TABLE … AUTO_INCREMENT
 *
 * หลักฐานที่ตรวจได้โดยไม่ต้องเปลี่ยน schema: ประวัติของ ref หนึ่งเกิดก่อนบัญชีที่ถือ id นั้นอยู่ตอนนี้
 * ถูกสร้าง — ประวัตินั้นต้องเป็นของคนก่อนหน้า รายงานจะรวมผลงานของสองคนเข้าด้วยกัน
 * คำสั่ง tickets:identity-check ใช้หลังกู้คืนฐานข้อมูลหรือนำเข้าผู้ใช้
 */
class TicketIdentityAudit
{
    /** @var array<string, array{0: string, 1: string}> ตาราง => [คอลัมน์ ref, คอลัมน์เวลาของประวัติ] */
    private const SOURCES = [
        'ticket_participants' => ['user_ref_id', 'created_at'],
        'ticket_activities' => ['user_ref_id', 'created_at'],
        'tickets' => ['closed_by_ref_id', 'closed_at'],
    ];

    /**
     * @return Collection<int, array{ref: int, account: string, account_created_at: CarbonImmutable, first_history_at: CarbonImmutable}>
     */
    public function collisions(): Collection
    {
        $firstSeen = [];

        foreach (self::SOURCES as $table => [$ref, $at]) {
            DB::table($table)
                ->whereNotNull($ref)
                ->whereNotNull($at)
                ->groupBy($ref)
                ->selectRaw("{$ref} as ref, min({$at}) as first_at")
                ->get()
                ->each(function ($row) use (&$firstSeen): void {
                    $at = CarbonImmutable::parse($row->first_at, 'UTC');
                    $ref = (int) $row->ref;
                    $firstSeen[$ref] = isset($firstSeen[$ref]) ? $firstSeen[$ref]->min($at) : $at;
                });
        }

        if ($firstSeen === []) {
            return collect();
        }

        return User::withTrashed()
            ->whereIn('id', array_keys($firstSeen))
            ->whereNotNull('created_at')
            ->get(['id', 'name', 'created_at'])
            ->filter(fn (User $user) => $user->created_at->gt($firstSeen[$user->id]))
            ->map(fn (User $user) => [
                'ref' => (int) $user->id,
                'account' => $user->name,
                'account_created_at' => CarbonImmutable::instance($user->created_at),
                'first_history_at' => $firstSeen[$user->id],
            ])
            ->values();
    }
}

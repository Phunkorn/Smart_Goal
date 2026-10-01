<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Support\AuditTrail;

/**
 * การเขียนข้อมูลของประกาศ (สร้าง แก้ไข ลบ)
 *
 * created_by และ department_id ถูกกำหนดที่นี่จากผู้ใช้ที่ทำรายการเท่านั้น
 * ไม่เคยรับจาก request (ดูเหตุผลที่ App\Models\Announcement)
 */
class AnnouncementService
{
    /**
     * @param  array{title:string,body:string,audience:string,starts_on:string,ends_on:?string}  $data
     */
    public function create(User $author, array $data): Announcement
    {
        $announcement = new Announcement($data);
        $announcement->created_by = $author->id;
        $announcement->department_id = $author->department_id;
        $announcement->save();

        AuditTrail::log(
            'announcement_created',
            $announcement,
            'สร้างประกาศ: '.$announcement->title,
            ['after' => $this->auditSnapshot($announcement)]
        );

        return $announcement;
    }

    /**
     * บันทึก audit เฉพาะเมื่อมีอะไรเปลี่ยนจริง เพื่อไม่ให้การกดบันทึกซ้ำทิ้งรายการว่างไว้
     *
     * @param  array{title:string,body:string,audience:string,starts_on:string,ends_on:?string}  $data
     */
    public function update(Announcement $announcement, array $data): Announcement
    {
        $before = $this->auditSnapshot($announcement);
        $announcement->fill($data);

        if (! $announcement->isDirty()) {
            return $announcement;
        }

        $announcement->save();

        AuditTrail::log(
            'announcement_updated',
            $announcement,
            'แก้ไขประกาศ: '.$announcement->title,
            ['before' => $before, 'after' => $this->auditSnapshot($announcement)]
        );

        return $announcement;
    }

    /**
     * ลบแบบ soft delete — ไม่เข้าถังขยะของ admin เพราะ admin ไม่มีสิทธิ์กับประกาศ
     * แถวยังอยู่ในฐานข้อมูลพร้อม audit log สำหรับการตรวจย้อนหลัง
     */
    public function delete(Announcement $announcement): void
    {
        AuditTrail::log(
            'announcement_deleted',
            $announcement,
            'ลบประกาศ: '.$announcement->title,
            ['before' => $this->auditSnapshot($announcement)]
        );

        $announcement->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Announcement $announcement): array
    {
        return [
            'title' => $announcement->title,
            'audience' => $announcement->audience,
            'department_id' => $announcement->department_id,
            'starts_on' => $announcement->starts_on?->format('Y-m-d'),
            'ends_on' => $announcement->ends_on?->format('Y-m-d'),
        ];
    }
}

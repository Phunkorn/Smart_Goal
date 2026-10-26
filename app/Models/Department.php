<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'department_name',
        // สิทธิ์ใช้งาน "ตรวจเช็กคอมพิวเตอร์ประจำวัน" — เขียนได้จากหน้าตั้งค่าของ admin เท่านั้น
        // (DepartmentController::updateComputerCheckAccess) ส่วนการตัดสินสิทธิ์อยู่ที่
        // App\Support\ComputerCheckAccess และ ComputerCheckSessionPolicy
        'computer_check_enabled',
        // แผนกที่รับผิดชอบ Smart Ticket — สมาชิก active เป็น agent โดยค่าตั้งต้น
        // เขียนได้จากหน้าตั้งค่า Ticket ของ admin เท่านั้น การตัดสินสิทธิ์อยู่ที่
        // App\Support\TicketAgentAccess และ TicketPolicy
        'ticket_responsible',
    ];

    protected function casts(): array
    {
        return [
            'computer_check_enabled' => 'boolean',
            'ticket_responsible' => 'boolean',
        ];
    }

    /** รอบตรวจเช็กคอมพิวเตอร์ที่ผูกกับแผนกนี้ — ใช้นับว่าแผนกนี้ใช้ฟังก์ชันอยู่จริงหรือไม่ */
    public function computerCheckSessions(): HasMany
    {
        return $this->hasMany(ComputerCheckSession::class, 'department_id');
    }

    /** นัดหมายที่แผนกนี้เป็นเจ้าของ — ดู Appointment::scopeVisibleTo() */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'department_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'department_id');
    }

    public function workspaceBoards(): HasMany
    {
        return $this->hasMany(WorkspaceBoard::class, 'department_id');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'department_id');
    }
}

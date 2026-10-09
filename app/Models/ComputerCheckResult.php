<?php

namespace App\Models;

use App\Support\ComputerCheckDesign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ผลการตรวจของหนึ่งเครื่องในหนึ่งรอบ
 *
 * อุปกรณ์ทั้งห้าชิ้นเก็บเป็นคอลัมน์แยก ไม่ใช่ JSON เพราะหน้าสรุปและรายงานต้อง
 * COUNT() ตามสถานะของอุปกรณ์แต่ละชิ้นได้ด้วย SQL ตรง ๆ และฐานข้อมูลทดสอบเป็น
 * SQLite แต่ production เป็น MySQL การ query ใน JSON ของสองตัวนี้เขียนต่างกัน
 */
class ComputerCheckResult extends Model
{
    protected $fillable = [
        'computer_check_session_id',
        'computer_check_workstation_id',
        'keyboard_mouse_status',
        'speaker_status',
        'microphone_status',
        'internet_status',
        'computer_status',
        'note',
        'checked_by',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ComputerCheckSession::class, 'computer_check_session_id');
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(ComputerCheckWorkstation::class, 'computer_check_workstation_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /**
     * สถานะของอุปกรณ์ทั้งห้า โดยคีย์เป็นชื่ออุปกรณ์ (ไม่ใช่ชื่อคอลัมน์)
     *
     * @return array<string, string>
     */
    public function deviceStatuses(): array
    {
        $statuses = [];

        foreach (ComputerCheckDesign::deviceKeys() as $device) {
            $statuses[$device] = (string) ($this->{$device.'_status'} ?? 'pending');
        }

        return $statuses;
    }

    /**
     * สถานะรวมของเครื่องนี้ — คำนวณที่ ComputerCheckDesign::rollUp() ที่เดียว
     */
    public function rolledUpStatus(): string
    {
        return ComputerCheckDesign::rollUp($this->deviceStatuses());
    }

    /** ตรวจครบทั้งห้าอุปกรณ์แล้วหรือยัง — ตัวนับ "ตรวจแล้ว X / ทั้งหมด" ใช้ค่านี้ */
    public function isFullyChecked(): bool
    {
        return in_array($this->rolledUpStatus(), ['normal', 'issue'], true);
    }

    /**
     * อุปกรณ์ที่มีปัญหาของเครื่องนี้ — ใช้ทำรายการปัญหาท้ายแบบตรวจ A4
     *
     * @return array<int, string> ชื่ออุปกรณ์ที่อ่านได้ เช่น "Microphone"
     */
    public function failedDeviceLabels(): array
    {
        $labels = [];

        foreach ($this->deviceStatuses() as $device => $status) {
            if ($status === 'issue') {
                $labels[] = ComputerCheckDesign::device($device)['label'];
            }
        }

        return $labels;
    }
}

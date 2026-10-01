<?php

namespace Tests;

use App\Models\DailyBriefAcknowledgement;
use App\Models\User;
use App\Support\AnnouncementDesign;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * รับทราบสรุปประจำวันของวันนี้แทนผู้ใช้
     *
     * สรุปประจำวันแสดงงานของผู้ใช้เองบนหน้าแรกของวันจนกว่าจะรับทราบ test ที่ตรวจว่า
     * "หน้านี้ต้องไม่มีข้อความ X" จึงต้องปิดสรุปไว้ก่อน เพื่อให้ตรวจเฉพาะเนื้อหาของหน้านั้นจริง
     * พฤติกรรมของสรุปเองอยู่ใน Tests\Feature\DailyBriefTest
     */
    protected function acknowledgeDailyBrief(User ...$users): void
    {
        foreach ($users as $user) {
            DailyBriefAcknowledgement::query()->firstOrCreate(
                ['user_id' => $user->id, 'brief_date' => AnnouncementDesign::today()],
                ['acknowledged_at' => now()],
            );
        }
    }
}

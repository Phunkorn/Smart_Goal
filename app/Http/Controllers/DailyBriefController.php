<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Services\DailyBriefService;
use Illuminate\Http\Request;

class DailyBriefController extends Controller
{
    use RespondsWithTaskResult;

    /**
     * กด "รับทราบและเข้าสู่ระบบ" — วันนั้นจะไม่แสดงสรุปซ้ำแม้ logout แล้ว login ใหม่
     *
     * brief_date คือวันที่ของสรุปที่ผู้ใช้เห็นจริงบนหน้าจอ ถ้าไม่ตรงกับวันนี้ (เปิดค้าง
     * ข้ามเที่ยงคืน) ตอบ 409 ให้หน้าเว็บโหลดสรุปของวันใหม่แทนการรับทราบแทนผู้ใช้
     */
    public function acknowledge(Request $request, DailyBriefService $brief)
    {
        $validated = $request->validate([
            'brief_date' => ['required', 'date_format:Y-m-d'],
        ]);

        if (! $brief->acknowledge($request->user(), $validated['brief_date'])) {
            return $this->jsonOrBack($request, false, 'สรุปประจำวันเปลี่ยนเป็นวันใหม่แล้ว กรุณาดูสรุปของวันนี้อีกครั้ง', 409);
        }

        return $this->jsonOrBack($request, true, 'รับทราบสรุปประจำวันแล้ว');
    }
}

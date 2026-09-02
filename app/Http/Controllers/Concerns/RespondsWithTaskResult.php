<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait RespondsWithTaskResult
{
    /**
     * @param  array<string, mixed>  $payload  ข้อมูลเพิ่มเติมสำหรับผู้เรียกแบบ AJAX
     *                                         เช่นรายการไฟล์ล่าสุด เพื่อให้หน้าจออัปเดตเองได้
     *                                         โดยไม่ต้อง reload ทั้งหน้าและทำให้ modal ปิดไป
     */
    private function jsonOrBack(Request $request, bool $ok, string $message, int $status = 200, array $payload = [])
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => $ok, 'message' => $message, ...$payload], $status);
        }

        return $ok
            ? back()->with('success', $message)
            : back()->withErrors(['status' => $message]);
    }
}

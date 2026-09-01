<?php

namespace App\Http\Controllers;

use App\Models\TrashLog;
use App\Services\AuditLogQuery;
use App\Support\TrashRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * การกระทำกับถังขยะ
 *
 * หน้าแสดงผลย้ายไปรวมกับบันทึกกิจกรรมที่ AuditController แล้ว
 * ที่นี่เหลือเฉพาะการกู้คืนและการส่งออก ซึ่งเป็น action ไม่ใช่หน้า จึงคง path และชื่อ route เดิมไว้
 */
class TrashController extends Controller
{
    public function __construct(private readonly AuditLogQuery $audit) {}

    public function restore(TrashLog $trash)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        TrashRetention::restore($trash);

        return back()->with('success', 'กู้คืนข้อมูลเรียบร้อยแล้ว');
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $fileName = 'trash-report-'.now()->format('Ymd-His').'.csv';
        $logs = $this->audit->trash($request)->latest('deleted_at')->get();

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ประเภท', 'ชื่อข้อมูล', 'แผนก', 'ผู้ลบ', 'อีเมลผู้ลบ', 'วันที่ลบ', 'ลบถาวรในอีก', 'ลบถาวรวันที่']);

            foreach ($logs as $trash) {
                $summary = TrashRetention::summary($trash);
                fputcsv($handle, [
                    $summary['entity_label'],
                    $summary['name'],
                    $summary['department'],
                    $trash->deletedBy?->name ?? 'ระบบ',
                    $trash->deletedBy?->email ?? '',
                    optional($trash->deleted_at)->format('Y-m-d H:i:s'),
                    $summary['days_left'] === null ? '-' : $summary['days_left'].' วัน',
                    optional($trash->purge_after)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

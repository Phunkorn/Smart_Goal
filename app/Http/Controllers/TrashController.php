<?php

namespace App\Http\Controllers;

use App\Models\TrashLog;
use App\Services\AuditLogQuery;
use App\Support\TrashRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /**
     * ลบถาวรทีละรายการตามคำสั่งของผู้ดูแลระบบ
     *
     * เดิมไม่มีทางลบข้อมูลออกจากฐานข้อมูลจริงเลยนอกจากรอครบ 30 วัน ข้อมูลที่ลบผิด
     * หรือข้อมูลอ่อนไหวที่ต้องเอาออกทันทีจึงค้างอยู่โดยไม่มีปุ่มให้กด
     *
     * ปลายทางนี้ทำลายข้อมูลอย่างถาวรและกู้กลับไม่ได้ ฝั่งหน้าจอจึงบังคับให้พิมพ์ชื่อ
     * รายการให้ตรงก่อนกดยืนยัน และที่นี่บันทึกกิจกรรมว่าใครเป็นผู้สั่งทุกครั้ง
     */
    public function purge(TrashLog $trash)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        TrashRetention::purge($trash, Auth::user());

        return back()->with('success', 'ลบข้อมูลออกจากระบบถาวรแล้ว');
    }

    /**
     * ล้างรายการที่หมดอายุทั้งหมดในครั้งเดียว
     *
     * คำสั่งตามเวลา trash:purge-expired ทำสิ่งเดียวกันทุกคืนอยู่แล้ว ปุ่มนี้มีไว้ให้
     * ผู้ดูแลระบบสั่งเองได้เมื่อจำเป็น โดยไม่ต้องรอรอบถัดไปและไม่ต้องเข้าเซิร์ฟเวอร์
     */
    public function purgeExpired()
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $count = TrashRetention::purgeExpired();

        return back()->with('success', $count > 0
            ? 'ลบรายการที่หมดเวลากู้คืนแล้ว '.$count.' รายการ'
            : 'ไม่มีรายการที่หมดเวลากู้คืน');
    }

    /**
     * กู้คืนหลายรายการที่เลือกไว้
     *
     * รายการที่กู้ไม่ได้จะถูกข้าม แล้วรายงานจำนวนกลับ ไม่ใช่ทำให้ทั้งชุดล้มเหลว
     */
    public function bulkRestore(Request $request)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $selected = $this->selected($request);

        if ($selected->isEmpty()) {
            return back()->with('success', 'ไม่มีรายการที่เลือก');
        }

        ['restored' => $restored, 'skipped' => $skipped] = TrashRetention::restoreMany($selected);

        return back()->with('success', $skipped > 0
            ? 'กู้คืนแล้ว '.$restored.' รายการ ข้าม '.$skipped.' รายการที่กู้คืนไม่ได้'
            : 'กู้คืนแล้ว '.$restored.' รายการ');
    }

    /**
     * ลบถาวรหลายรายการที่เลือกไว้
     *
     * ทำลายข้อมูลและไฟล์อย่างถาวรเช่นเดียวกับ purge() ฝั่งหน้าจอจึงบังคับให้พิมพ์
     * "จำนวนรายการ" ให้ตรงก่อนยืนยัน เพราะการลบเป็นชุดไม่มีชื่อรายการเดียวให้พิมพ์
     */
    public function bulkPurge(Request $request)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $selected = $this->selected($request);

        if ($selected->isEmpty()) {
            return back()->with('success', 'ไม่มีรายการที่เลือก');
        }

        $purged = TrashRetention::purgeMany($selected, Auth::user());

        return back()->with('success', 'ลบข้อมูลออกจากระบบถาวรแล้ว '.$purged.' รายการ');
    }

    /**
     * รายการที่คำสั่งแบบชุดจะทำงานด้วย
     *
     * รองรับสองแบบ: รายการ id ที่ติ๊กไว้ กับ scope=filtered ซึ่งหมายถึง "ทุกแถวตาม
     * ตัวกรองที่เปิดอยู่ตอนนี้" ไม่ใช่เฉพาะหน้าที่เห็น แบบหลังจำเป็นเพราะผู้ใช้ที่มีของค้าง
     * 300 รายการไม่มีทางติ๊กครบทีละหน้า และต้องอ่านตัวกรองจาก AuditLogQuery ตัวเดียวกับ
     * ที่หน้าจอใช้แสดงผล มิฉะนั้นสิ่งที่ถูกลบจะไม่ตรงกับสิ่งที่ผู้ใช้เห็น
     *
     * @return Collection<int, TrashLog>
     */
    private function selected(Request $request): Collection
    {
        $request->validate([
            'scope' => ['nullable', 'in:filtered'],
            'ids' => ['required_without:scope', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        if ($request->string('scope')->toString() === 'filtered') {
            return $this->audit->trash($request)->get();
        }

        return TrashLog::whereIn('id', $request->collect('ids')->map(fn ($id) => (int) $id))->get();
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

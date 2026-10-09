<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\AdminApprovalQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminApprovalController extends Controller
{
    /**
     * แท็บของหน้าคำขออนุมัติ — ?approval_queue= เป็นตัวกรองจริงฝั่ง server
     *
     * เดิมค่านี้ถูกอ่านแค่ใน JavaScript เพื่อเลื่อนหน้าจอ ทุกคิวยังถูกวาดพร้อมกันในกริดสองคอลัมน์
     * การ์ดของคิวที่ผู้ใช้ตั้งใจเปิดจึงถูกบีบอยู่ครึ่งจอข้างคิวว่างอีกสองคิว
     * ค่าที่ไม่รู้จักตกกลับเป็น 'all' เสมอ
     *
     * ไม่มีคิว 'collaborator' อีกแล้ว การเพิ่มผู้เข้าร่วมโปรเจกต์เสร็จทันทีโดยไม่ต้องอนุมัติ
     * (ดู CollaboratorInvitationService) สิ่งที่หัวหน้าแผนกต้องเห็นคือแท็บ 'outgoing'
     * ซึ่งเป็นหน้าติดตาม ไม่ใช่คิวตัดสินใจ
     */
    public const QUEUES = ['all', 'assignment', 'share', 'outgoing'];

    /**
     * แท็บที่เปิดให้เมื่อผู้ใช้ไม่ได้ระบุ ?approval_queue= มา
     *
     * admin — 'all' เหมือนเดิม เพราะหน้านี้คือกล่องขาเข้าของงานที่ไม่มีหัวหน้าแผนกรับผิดชอบ
     *
     * หัวหน้าแผนก — หลังจากการเพิ่มผู้เข้าร่วมโปรเจกต์ไม่ต้องอนุมัติอีกแล้ว หน้านี้
     * แทบไม่มีอะไรให้ตัดสิน จึงเปิดมาเป็นหน้า "ติดตามงานข้ามแผนกของทีม" เป็นค่าตั้งต้น
     * แต่ถ้ามีคำขอค้างอยู่จริง (มอบหมายงานข้ามแผนก หรือคำขอจากการแชร์งาน) ต้องพาไป
     * ที่ของค้างก่อน ไม่ใช่ซ่อนไว้ใต้แท็บที่เขาต้องเดาว่าต้องกด
     */
    private function defaultQueue(bool $isAdminViewer, int $pendingTotal): string
    {
        return $isAdminViewer || $pendingTotal > 0 ? 'all' : 'outgoing';
    }

    public function index(Request $request, AdminApprovalQuery $approvals): View
    {
        $this->authorize('approve', WorkOrder::class);

        $viewer = $request->user();

        // คิวนี้เปิดให้ทั้ง admin และหัวหน้าแผนก (ดู WorkOrderPolicy::approve())
        // แต่ขอบเขตที่เห็นต่างกัน หัวหน้าเห็นเฉพาะคำขอที่ปลายทางเป็นแผนกตัวเอง
        // (AdminApprovalQuery::scopeAssignments()) หัวเรื่องจึงต้องบอกขอบเขตจริง
        // ไม่ใช่เขียนว่า "ผู้ดูแลระบบ" เหมือนกันหมดจนหัวหน้าเข้าใจผิดว่าไม่ใช่หน้าที่ตน
        $isAdminViewer = $viewer->role === 'admin';
        $requestedQueue = $request->query('approval_queue');
        $data = $approvals->data($viewer);

        return view('admin.approvals.index', [
            ...$data,
            'approvalQueue' => is_string($requestedQueue) && in_array($requestedQueue, self::QUEUES, true)
                ? $requestedQueue
                : $this->defaultQueue($isAdminViewer, $data['approvalCounts']['total']),
            'outgoingCollaborations' => $approvals->outgoingCollaborations($viewer),
            'isAdminViewer' => $isAdminViewer,
            'approvalScopeLabel' => $isAdminViewer
                ? 'ทุกแผนกในระบบ'
                : 'แผนก'.($viewer->department?->department_name ?? 'ของคุณ'),
        ]);
    }
}

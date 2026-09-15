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
     */
    public const QUEUES = ['all', 'assignment', 'collaborator', 'share', 'outgoing'];

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

        return view('admin.approvals.index', [
            ...$approvals->data($viewer),
            'approvalQueue' => is_string($requestedQueue) && in_array($requestedQueue, self::QUEUES, true)
                ? $requestedQueue
                : 'all',
            'outgoingCollaborations' => $approvals->outgoingCollaborations($viewer),
            'isAdminViewer' => $isAdminViewer,
            'approvalScopeLabel' => $isAdminViewer
                ? 'ทุกแผนกในระบบ'
                : 'แผนก'.($viewer->department?->department_name ?? 'ของคุณ'),
        ]);
    }
}

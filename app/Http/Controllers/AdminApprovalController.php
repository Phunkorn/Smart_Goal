<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\AdminApprovalQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminApprovalController extends Controller
{
    public function index(Request $request, AdminApprovalQuery $approvals): View
    {
        $this->authorize('approve', WorkOrder::class);

        $viewer = $request->user();

        // คิวนี้เปิดให้ทั้ง admin และหัวหน้าแผนก (ดู WorkOrderPolicy::approve())
        // แต่ขอบเขตที่เห็นต่างกัน หัวหน้าเห็นเฉพาะคำขอที่ปลายทางเป็นแผนกตัวเอง
        // (AdminApprovalQuery::scopeAssignments()) หัวเรื่องจึงต้องบอกขอบเขตจริง
        // ไม่ใช่เขียนว่า "ผู้ดูแลระบบ" เหมือนกันหมดจนหัวหน้าเข้าใจผิดว่าไม่ใช่หน้าที่ตน
        $isAdminViewer = $viewer->role === 'admin';

        return view('admin.approvals.index', [
            ...$approvals->data($viewer),
            'isAdminViewer' => $isAdminViewer,
            'approvalScopeLabel' => $isAdminViewer
                ? 'ทุกแผนกในระบบ'
                : 'แผนก'.($viewer->department?->department_name ?? 'ของคุณ'),
        ]);
    }
}

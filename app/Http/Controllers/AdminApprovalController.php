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

        return view('admin.approvals.index', $approvals->data($request->user()));
    }
}

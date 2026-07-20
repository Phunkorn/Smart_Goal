<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $logs = ActivityLog::with('user')
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%' . $request->string('q') . '%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('description', 'like', $search)
                        ->orWhere('subject_type', 'like', $search)
                        ->orWhere('ip_address', 'like', $search);
                });
            })
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.activity-logs.index', compact('logs', 'actions', 'users'));
    }
}

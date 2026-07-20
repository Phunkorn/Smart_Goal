<?php

namespace App\Http\Controllers;

use App\Models\TrashLog;
use App\Models\User;
use App\Support\TrashRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class TrashController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        TrashRetention::purgeExpired();

        $baseQuery = $this->filteredQuery($request);

        $trashLogs = (clone $baseQuery)
            ->latest('deleted_at')
            ->paginate(20)
            ->through(function (TrashLog $trash) {
                $trash->summary = TrashRetention::summary($trash);

                return $trash;
            })
            ->withQueryString();

        $entityTypes = TrashLog::query()
            ->select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type');

        $users = User::withTrashed()->orderBy('name')->get(['id', 'name', 'email']);
        $departments = $this->departmentOptions();
        $stats = [
            'total' => (clone $baseQuery)->count(),
            'work_orders' => (clone $baseQuery)->where('entity_type', \App\Models\WorkOrder::class)->count(),
            'users' => (clone $baseQuery)->where('entity_type', User::class)->count(),
            'expired' => (clone $baseQuery)->whereNotNull('purge_after')->where('purge_after', '<=', now())->count(),
        ];

        return view('admin.trash.index', compact('trashLogs', 'entityTypes', 'users', 'departments', 'stats'));
    }

    public function restore(TrashLog $trash)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        TrashRetention::restore($trash);

        return back()->with('success', 'กู้คืนข้อมูลเรียบร้อยแล้ว');
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        $fileName = 'trash-report-' . now()->format('Ymd-His') . '.csv';
        $logs = $this->filteredQuery($request)->latest('deleted_at')->get();

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
                    $summary['days_left'] === null ? '-' : $summary['days_left'] . ' วัน',
                    optional($trash->purge_after)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredQuery(Request $request)
    {
        return TrashLog::with('deletedBy')
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('deleted_by'), fn ($query) => $query->where('deleted_by', $request->integer('deleted_by')))
            ->when($request->filled('department'), function ($query) use ($request) {
                $department = '%' . $request->string('department') . '%';
                $query->where('payload_json', 'like', $department);
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%' . $request->string('q') . '%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('entity_type', 'like', $search)
                        ->orWhere('entity_id', 'like', $search)
                        ->orWhere('payload_json', 'like', $search);
                });
            });
    }

    private function departmentOptions()
    {
        return collect(['IT', 'Marketing', 'Account', 'Callcenter'])
            ->merge(TrashLog::query()->pluck('payload_json')->flatMap(function ($payload) {
                $payload = is_string($payload) ? json_decode($payload, true) : $payload;

                return [
                    $payload['work_order']['department_name'] ?? null,
                    $payload['user']['department_name'] ?? null,
                    $payload['assignee']['department']['department_name'] ?? null,
                ];
            }))
            ->filter()
            ->unique()
            ->values();
    }
}

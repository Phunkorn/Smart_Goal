<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ProtectedMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            ->when($request->filled('subject_type'), fn ($query) => $query->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%' . $request->string('q') . '%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('description', 'like', $search)
                        ->orWhere('subject_type', 'like', $search)
                        ->orWhere('action', 'like', $search)
                        ->orWhere('subject_id', 'like', $search)
                        ->orWhere('ip_address', 'like', $search)
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', $search)
                                ->orWhere('email', 'like', $search)
                                ->orWhere('username', 'like', $search);
                        });
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

        $subjectTypes = ActivityLog::query()
            ->whereNotNull('subject_type')
            ->select('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        $users = User::orderBy('name')->get(['id', 'name', 'email']);
        $resolvableProfileImages = $this->resolvableProfileImages($logs->items());

        return view('admin.activity-logs.index', compact('logs', 'actions', 'subjectTypes', 'users', 'resolvableProfileImages'));
    }

    /**
     * activity log เก็บรูปโปรไฟล์ไว้เป็น "path ณ ตอนนั้น" ไม่ใช่สำเนาไฟล์ และ
     * UserController/SettingsController จะลบไฟล์เดิมทิ้งทุกครั้งที่เปลี่ยนรูป
     * path เก่าจึงมักชี้ไปยังไฟล์ที่ไม่มีอยู่แล้ว
     *
     * เมธอดนี้คืนเฉพาะ path ที่ยังเปิดดูได้จริง คือยังเป็นรูปโปรไฟล์ปัจจุบันของผู้ใช้
     * (เงื่อนไขเดียวกับที่ MediaController::legacy() ใช้ resolve) และไฟล์ยังอยู่บนดิสก์
     * เพื่อให้ view เลือกแสดง placeholder แทนการยิง <img> ที่จะได้ 404
     *
     * @param  array<int, ActivityLog>  $logs
     * @return Collection<string, int>  path => index สำหรับเรียก has() แบบ O(1)
     */
    private function resolvableProfileImages(array $logs): Collection
    {
        $paths = collect($logs)
            ->flatMap(function (ActivityLog $log): array {
                $changes = is_array($log->changes)
                    ? $log->changes
                    : json_decode($log->changes ?? '[]', true);

                return [
                    data_get($changes, 'before.profile_image'),
                    data_get($changes, 'old.profile_image'),
                    data_get($changes, 'after.profile_image'),
                    data_get($changes, 'new.profile_image'),
                ];
            })
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('profile_image', $paths)
            ->pluck('profile_image')
            ->filter(fn (string $path) => ProtectedMedia::profileAbsolutePath($path) !== null)
            ->values()
            ->flip();
    }
}

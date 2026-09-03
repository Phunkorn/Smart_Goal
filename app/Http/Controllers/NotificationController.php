<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use App\Models\WorkOrderList;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $notifications): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:all,unread'],
            'category' => ['nullable', 'string', 'in:all,task,review,comment,deadline,system'],
            'project' => ['nullable', 'integer'],
        ]);
        $items = $notifications->paginate($request->user(), $filters);
        $projectIds = SystemNotification::forUser($request->user())->centerEligible()
            ->whereNotNull('work_order_list_id')->distinct()->pluck('work_order_list_id');
        $projects = WorkOrderList::whereIn('id', $projectIds)->orderBy('name')->get();

        $groups = $items->getCollection()->groupBy(fn ($notice) => $notifications->groupLabel($notice->created_at));
        $unreadCount = $notifications->unreadCount($request->user());
        $readCount = SystemNotification::forUser($request->user())->whereNotNull('read_at')->count();

        $notificationService = $notifications;

        return view('notifications.index', compact('items', 'projects', 'filters', 'groups', 'unreadCount', 'readCount', 'notificationService'));
    }

    public function open(Request $request, int $notification, NotificationService $notifications): RedirectResponse
    {
        $notice = $this->owned($request, $notification)->load('workOrder.user.department');
        $notifications->markRead($notice);

        $target = $notifications->target($notice, $request->user());

        if ($notifications->targetUnavailable($notice, $request->user())) {
            return redirect()->route('notifications.index')
                ->with('warning', 'รายการนี้ไม่มีอยู่แล้วหรือคุณไม่มีสิทธิ์เข้าถึง');
        }

        return redirect()->to($target);
    }

    public function read(Request $request, int $notification, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $notifications->markRead($this->owned($request, $notification));
        return $this->response($request, $notifications, 'อ่านการแจ้งเตือนแล้ว');
    }

    public function unread(Request $request, int $notification, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $notifications->markRead($this->owned($request, $notification), false);
        return $this->response($request, $notifications, 'ทำเครื่องหมายว่ายังไม่อ่านแล้ว');
    }

    public function readAll(Request $request, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        SystemNotification::forUser($request->user())->unread()->update(['read_at' => now(), 'is_read' => true]);
        return $this->response($request, $notifications, 'อ่านการแจ้งเตือนทั้งหมดแล้ว');
    }

    public function destroy(Request $request, int $notification, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $this->owned($request, $notification)->delete();
        return $this->response($request, $notifications, 'ลบการแจ้งเตือนแล้ว', ['deleted_count' => 1]);
    }

    public function destroyRead(Request $request, NotificationService $notifications): JsonResponse|RedirectResponse
    {
        $deletedCount = SystemNotification::forUser($request->user())
            ->whereNotNull('read_at')
            ->delete();

        return $this->response($request, $notifications, 'ลบการแจ้งเตือนที่อ่านแล้วทั้งหมดแล้ว', [
            'deleted_count' => $deletedCount,
        ]);
    }

    private function owned(Request $request, int $notification): SystemNotification
    {
        return SystemNotification::forUser($request->user())->findOrFail($notification);
    }

    private function response(Request $request, NotificationService $notifications, string $message, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($extra + [
                'ok' => true,
                'unread_count' => $notifications->unreadCount($request->user()),
                'read_count' => SystemNotification::forUser($request->user())->whereNotNull('read_at')->count(),
            ]);
        }

        return back()->with('success', $message);
    }
}

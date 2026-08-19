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

        return view('notifications.index', compact('items', 'projects', 'filters', 'groups'));
    }

    public function open(Request $request, int $notification, NotificationService $notifications): RedirectResponse
    {
        $notice = $this->owned($request, $notification)->load('workOrder.user.department');
        $notifications->markRead($notice);

        return redirect()->to($notifications->target($notice, $request->user()));
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

    public function destroy(Request $request, int $notification): RedirectResponse
    {
        $this->owned($request, $notification)->delete();
        return back()->with('success', 'ลบการแจ้งเตือนแล้ว');
    }

    private function owned(Request $request, int $notification): SystemNotification
    {
        return SystemNotification::forUser($request->user())->findOrFail($notification);
    }

    private function response(Request $request, NotificationService $notifications, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) return response()->json(['ok' => true, 'unread_count' => $notifications->unreadCount($request->user())]);
        return back()->with('success', $message);
    }
}

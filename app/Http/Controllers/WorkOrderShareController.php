<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkOrder;
use App\Models\WorkOrderShare;
use App\Services\WorkOrderShareQuery;
use App\Services\WorkOrderShareService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * หน้าแชร์งาน และการเปิด/ปิดประกาศ
 *
 * สิทธิ์ทั้งหมดตัดสินที่ Policy การซ่อนปุ่มใน Blade เป็นเรื่องของการมองเห็นอย่างเดียว
 */
class WorkOrderShareController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly WorkOrderShareService $shares,
        private readonly WorkOrderShareQuery $query,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', WorkOrderShare::class);

        $user = $request->user();

        return view('shares.index', [
            'feed' => $this->query->feedFor($user),
            'myShares' => $this->query->mySharesFor($user),
            'incomingRequests' => $this->query->incomingRequestsFor($user),
            'myRequests' => $this->query->myRequestsFor($user),
            'activeTab' => in_array($request->query('tab'), ['feed', 'incoming', 'mine'], true)
                ? $request->query('tab')
                : 'feed',
        ]);
    }

    public function store(Request $request, int $id)
    {
        $task = WorkOrder::with('user')->findOrFail($id);
        $this->authorize('share', $task);

        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:department,organization'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $share = $this->shares->open($task, $request->user(), $validated['scope'], $validated['note'] ?? null);
        } catch (RuntimeException $exception) {
            return $this->jsonOrBack($request, false, $exception->getMessage(), 422);
        }

        return $this->jsonOrBack($request, true, 'แชร์งานแล้ว', 201, [
            'share_id' => $share->id,
            'scope' => $share->scope,
        ]);
    }

    public function destroy(Request $request, WorkOrderShare $share)
    {
        $this->authorize('close', $share);

        $this->shares->close($share, $request->user());

        return $this->jsonOrBack($request, true, 'ปิดประกาศแชร์งานแล้ว');
    }
}

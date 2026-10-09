<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CollaboratorInvitationService;
use App\Services\NotificationService;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskCollaboratorController extends Controller
{
    use RespondsWithTaskResult;

    public function addCollaborators(Request $request, $id, CollaboratorInvitationService $invitations)
    {
        $job = WorkOrder::with(['collaborators', 'user.department', 'leader.department'])->findOrFail($id);
        $this->authorize('manageTeam', $job);
        abort_if((int) $job->job_status === 4 && Auth::user()?->role !== 'admin', 403);

        $validated = $request->validate([
            'collaborators' => ['required', 'array', 'min:1'],
            'collaborators.*' => ['integer', 'exists:users,id'],
        ]);

        $existingIds = collect([$job->user_id, $job->leader_user_id, Auth::id()])
            ->merge($job->collaborators->pluck('id'))
            ->filter()
            ->unique();

        // บัญชีที่ถูกปิดใช้งาน login ไม่ได้และไม่ได้รับแจ้งเตือน จึงต้องไม่ถูกเพิ่มเข้าทีม
        // ใช้กติกาเดียวกับ TaskCollaboratorOptions ที่สร้างรายชื่อให้ UI
        $eligibleUsers = User::with('department')
            ->where('role', 'user')
            ->where('is_active', true)
            ->whereIn('id', $validated['collaborators'])
            ->get();

        $newUsers = $eligibleUsers
            ->reject(fn (User $candidate) => $existingIds->contains((int) $candidate->id))
            ->unique('id')
            ->values();

        $actor = Auth::user();
        foreach ($newUsers as $candidate) {
            $invitations->invite($job, $candidate, $actor);
        }

        return $this->jsonOrBack($request, true, $newUsers->isEmpty() ? 'พนักงานคนนี้อยู่ในโปรเจกต์นี้แล้ว' : 'เพิ่มผู้เข้าร่วมโปรเจกต์แล้ว');
    }

    public function removeCollaborator(Request $request, $id, User $user)
    {
        $job = WorkOrder::with(['collaborators', 'user', 'leader'])->findOrFail($id);
        $this->authorize('manageTeam', $job);
        abort_if((int) $job->job_status === 4 && Auth::user()?->role !== 'admin', 403);
        abort_if(in_array($user->id, [$job->user_id, $job->created_by, $job->leader_user_id], true), 422, 'ไม่สามารถนำหัวหน้าโปรเจกต์หรือผู้รับผิดชอบงานออกจากโปรเจกต์ได้');

        $job->collaborators()->detach($user->id);

        AuditTrail::log('collaborator_removed', $job, 'นำผู้เข้าร่วมออกจากโปรเจกต์: '.$job->job_topic, [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        app(NotificationService::class)->notifyRemovedParticipant($user, 'collaborator_removed', 'ถูกนำออกจากโปรเจกต์',
            'คุณถูกนำออกจากโปรเจกต์ "'.$job->job_topic.'"', $job, Auth::user());

        return $this->jsonOrBack($request, true, 'นำผู้เข้าร่วมออกจากโปรเจกต์แล้ว');
    }
}

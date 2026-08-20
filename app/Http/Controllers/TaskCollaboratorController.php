<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskCollaboratorController extends Controller
{
    use RespondsWithTaskResult;

    public function addCollaborators(Request $request, $id)
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

        $eligibleUsers = User::with('department')
            ->where('role', 'user')
            ->whereIn('id', $validated['collaborators'])
            ->get();

        $newUsers = $eligibleUsers
            ->reject(fn (User $candidate) => $existingIds->contains((int) $candidate->id))
            ->unique('id')
            ->values();

        $actor = Auth::user();
        $actorLabel = $actor?->role === 'admin' ? 'ผู้ดูแลระบบ' : ($actor?->name ?? 'หัวหน้างาน');
        $jobDepartmentId = $job->department_id ?: $job->user?->department_id;
        $admins = User::where('role', 'admin')->get();

        foreach ($newUsers as $candidate) {
            $sameDepartment = $jobDepartmentId && (int) $candidate->department_id === (int) $jobDepartmentId;
            $pivotStatus = $sameDepartment || $actor?->role === 'admin' ? 'accepted' : 'pending';

            $job->collaborators()->syncWithoutDetaching([
                $candidate->id => [
                    'added_by' => Auth::id(),
                    'status' => $pivotStatus,
                    'responded_at' => $pivotStatus === 'accepted' ? now() : null,
                ],
            ]);

            AuditTrail::log('collaborator_added', $job, 'เพิ่มผู้ร่วมโปรเจกต์ในงาน: '.$job->job_topic, [
                'user_id' => $candidate->id,
                'status' => $pivotStatus,
            ]);

            if ($pivotStatus === 'accepted') {
                app(NotificationService::class)->notify([$candidate], 'collaborator_added', 'ถูกเพิ่มเข้าร่วมงาน',
                    $actorLabel.' เพิ่มคุณเข้าร่วมงาน "'.$job->job_topic.'"', $job, $actor);

                continue;
            }

            foreach ($admins as $admin) {
                app(NotificationService::class)->notify([$admin], 'collaborator_approval_request', 'ขออนุมัติผู้ร่วมงานข้ามแผนก',
                    $actorLabel.' ขอเพิ่ม '.$candidate->name.' ('.($candidate->department?->department_name ?? 'ไม่ระบุแผนก').') เข้าร่วมงาน "'.$job->job_topic.'"',
                    $job, $actor);
            }
        }

        return $this->jsonOrBack($request, true, $newUsers->isEmpty() ? 'พนักงานคนนี้อยู่ในรายการเชิญหรือทีมแล้ว' : 'เพิ่ม/ส่งคำขอผู้ร่วมงานสำเร็จ');
    }

    public function removeCollaborator(Request $request, $id, User $user)
    {
        $job = WorkOrder::with(['collaborators', 'user', 'leader'])->findOrFail($id);
        $this->authorize('manageTeam', $job);
        abort_if((int) $job->job_status === 4 && Auth::user()?->role !== 'admin', 403);
        abort_if(in_array($user->id, [$job->user_id, $job->created_by, $job->leader_user_id], true), 422, 'ไม่สามารถลบผู้รับผิดชอบหลักหรือหัวหน้างานออกจากทีมได้');

        $job->collaborators()->detach($user->id);

        AuditTrail::log('collaborator_removed', $job, 'นำผู้ร่วมโปรเจกต์ออกจากงาน: '.$job->job_topic, [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        app(NotificationService::class)->notifyRemovedParticipant($user, 'collaborator_removed', 'ถูกนำออกจากงาน',
            'คุณถูกนำออกจากทีมงาน "'.$job->job_topic.'"', $job, Auth::user());

        return $this->jsonOrBack($request, true, 'นำผู้ร่วมงานออกจากทีมแล้ว');
    }

    public function respondInvitation(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
        ]);

        $job = WorkOrder::with('collaborators')->findOrFail($id);
        $this->authorize('respondToInvitation', $job);
        $collaborator = $job->collaborators->firstWhere('id', Auth::id());

        $job->collaborators()->updateExistingPivot(Auth::id(), [
            'status' => $validated['status'],
            'responded_at' => now(),
        ]);

        $message = $validated['status'] === 'accepted' ? 'รับเข้าร่วมงานแล้ว' : 'ปฏิเสธคำเชิญแล้ว';

        return back()->with('success', $message);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CollaboratorInvitationService;
use App\Services\NotificationService;
use App\Support\ApprovalPresenter;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        // ตอบคำเชิญได้ครั้งเดียว มิฉะนั้นผู้ที่กดปฏิเสธไปแล้วจะยิงซ้ำเพื่อเข้าร่วมงานเองได้
        $invitation = $job->collaborators->firstWhere('id', Auth::id());
        abort_unless($invitation?->pivot?->status === 'pending', 422, 'คำเชิญนี้ได้รับการตอบกลับไปแล้ว');

        $job->collaborators()->updateExistingPivot(Auth::id(), [
            'status' => $validated['status'],
            'responded_at' => now(),
        ]);

        $message = $validated['status'] === 'accepted' ? 'รับเข้าร่วมงานแล้ว' : 'ปฏิเสธคำเชิญแล้ว';

        return back()->with('success', $message);
    }

    public function decideCollaborator(Request $request, $id, User $user)
    {
        $this->authorize('approveCollaborator', WorkOrder::class);

        $validated = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
        ]);

        $result = DB::transaction(function () use ($id, $user, $validated): array {
            $job = WorkOrder::with(['user.department', 'creator', 'leader', 'collaborators'])
                ->lockForUpdate()
                ->findOrFail($id);
            $this->authorize('approveCollaborator', [$job, $user]);

            $pivot = DB::table('work_order_collaborators')
                ->where('work_order_id', $job->job_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $pivot || $pivot->status !== 'pending' || $job->approval_status !== 'approved') {
                return ['changed' => false, 'job' => $job];
            }

            DB::table('work_order_collaborators')
                ->where('id', $pivot->id)
                ->update([
                    'status' => $validated['status'],
                    'decided_by' => Auth::id(),
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);

            $job->load('collaborators');
            $actor = Auth::user();
            $actor->loadMissing('department');
            $approver = ApprovalPresenter::approverLabel($actor);
            $inviter = $pivot->added_by ? User::find($pivot->added_by) : null;

            if ($validated['status'] === 'accepted') {
                app(NotificationService::class)->notify(
                    [$user],
                    'collaborator_added',
                    'ได้รับอนุมัติให้ร่วมงานแล้ว',
                    $approver.' อนุมัติให้คุณร่วมงาน "'.$job->job_topic.'" แล้ว',
                    $job,
                    $actor,
                    [],
                    'collaborator-decision:'.$job->job_id.':'.$user->id.':accepted'
                );
            }

            if ($inviter) {
                app(NotificationService::class)->notify(
                    [$inviter],
                    $validated['status'] === 'accepted' ? 'collaborator_approved' : 'collaborator_rejected',
                    $validated['status'] === 'accepted' ? 'อนุมัติผู้ร่วมงานแล้ว' : 'ปฏิเสธผู้ร่วมงานแล้ว',
                    $approver.' '.ApprovalPresenter::decisionVerb($validated['status']).'การเพิ่ม '.$user->name.' ในงาน "'.$job->job_topic.'"',
                    $job,
                    $actor,
                    [],
                    'collaborator-decision:'.$job->job_id.':'.$user->id.':inviter'
                );
            }

            AuditTrail::log('collaborator_'.$validated['status'], $job, $approver.' ตัดสินคำขอผู้ร่วมงาน: '.$job->job_topic, [
                'user_id' => $user->id,
                'status' => $validated['status'],
                'decided_by' => Auth::id(),
            ]);

            return ['changed' => true, 'job' => $job];
        });

        if (! $result['changed']) {
            return $this->jsonOrBack($request, false, 'คำขอผู้ร่วมงานนี้ถูกตัดสินไปแล้วหรือไม่พร้อมให้อนุมัติ', 409);
        }

        return $this->jsonOrBack(
            $request,
            true,
            $validated['status'] === 'accepted' ? 'อนุมัติผู้ร่วมงานแล้ว' : 'ปฏิเสธผู้ร่วมงานแล้ว'
        );
    }
}

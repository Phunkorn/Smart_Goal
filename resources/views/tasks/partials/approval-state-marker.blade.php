@php
    use App\Support\ApprovalPresenter;

    /**
     * งานที่ยังไม่ถูกอนุมัติมองเห็นได้เฉพาะผู้ขอเท่านั้น
     * (WorkOrder::scopeInvolving() จำกัดไว้ที่ created_by / assigned_by / leader_user_id)
     * แต่เดิมไม่มีวิวไหนแสดง approval_status เลย ผู้ขอจึงเห็นงานอยู่บนบอร์ดตามปกติ
     * โดยไม่รู้ว่ายังรอหัวหน้าแผนกปลายทางตัดสิน หรือถูกปฏิเสธไปแล้ว
     */
    $approvalState = $task->approval_status ?? 'approved';
@endphp
@if($approvalState !== 'approved')
    <span class="task-approval-marker is-{{ $approvalState }}" data-task-approval-marker="{{ $approvalState }}">
        <i class="bi {{ $approvalState === 'rejected' ? 'bi-x-octagon-fill' : 'bi-hourglass-split' }}" aria-hidden="true"></i>{{ ApprovalPresenter::statusLabel($approvalState) }}{{ $approvalState === 'pending' ? 'จากแผนกปลายทาง' : '' }}
    </span>
@endif

@extends('layouts.app')
@section('title', 'รายละเอียดงาน')
@php
    $u=auth()->user(); $isAdmin=$u?->role==='admin';
    $isOwner=in_array($u?->id,[$job->user_id,$job->created_by,$job->leader_user_id],true);
    $accepted=$job->collaborators->first(fn($p)=>$p->id===$u?->id&&$p->pivot?->status==='accepted');
    $pending=$job->collaborators->first(fn($p)=>$p->id===$u?->id&&$p->pivot?->status==='pending');
    $canUpdate=$isAdmin||$isOwner||(bool)$accepted; $isDone=(int)$job->job_status===4;
    $isLate=$job->job_due_at&&!$isDone&&$job->job_due_at->isPast();
    $statusLabels=[1=>'ยังไม่เริ่ม',2=>'กำลังทำ',3=>'รอตรวจสอบ',4=>'เสร็จแล้ว',5=>'พักงาน'];
    $priorityLabels=[1=>'ต่ำ',2=>'กลาง',3=>'สูง'];
    $status=$isLate?'ล่าช้า':($statusLabels[(int)$job->job_status]??'ยังไม่เริ่ม');
    $team=collect([$job->user,$job->leader])->filter()->merge($job->collaborators)->unique('id')->values();
    $back=$isAdmin||$u?->role==='viewer'?route('board.index'):route('mytasks.index');
@endphp
@push('styles')<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap"><style>{!! file_get_contents(resource_path('css/pages/task-detail-notion.css')) !!}</style>@endpush
@section('content')
<div class="nd-page">
    <div class="nd-topbar"><a href="{{ $back }}"><i class="bi bi-arrow-left"></i> งานของฉัน</a><div class="nd-actions">
        @if($pending)<form method="POST" action="{{ route('tasks.invitation.respond',$job->job_id) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="accepted"><button class="nd-btn success">รับเข้าร่วม</button></form><form method="POST" action="{{ route('tasks.invitation.respond',$job->job_id) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="rejected"><button class="nd-btn danger">ปฏิเสธ</button></form>@endif
        @if(!$isAdmin&&$canUpdate&&!$isDone&&!$job->delete_requested_at)<button class="nd-btn danger" type="button" data-toggle-delete><i class="bi bi-trash3"></i> ขอลบงาน</button>@endif
    </div></div>
    <article class="nd-document">
        <div class="nd-project"><i class="bi bi-folder"></i> {{ $job->taskList?->name ?? 'งานทั่วไป' }}</div>
        <h1>{{ $job->job_topic }}</h1>
        <div class="nd-tags"><span class="nd-status {{ $isLate?'late':'s'.$job->job_status }}">{{ $status }}</span><span class="nd-priority p{{ $job->job_priority }}">ความสำคัญ {{ $priorityLabels[(int)$job->job_priority]??'กลาง' }}</span>@if($job->approval_status==='pending')<span class="nd-status review">รออนุมัติ</span>@endif</div>

        <section class="nd-properties">
            <div><span><i class="bi bi-person"></i> ผู้รับผิดชอบ</span><strong>{{ $job->user?->name ?? '-' }}</strong></div>
            <div><span><i class="bi bi-person-badge"></i> หัวหน้าโปรเจกต์</span><strong>{{ $job->leader?->name ?? $job->user?->name ?? '-' }}</strong></div>
            <div><span><i class="bi bi-calendar3"></i> วันที่เริ่ม</span><strong>{{ $job->job_start_at?->format('d/m/Y H:i') ?? '-' }}</strong></div>
            <div><span><i class="bi bi-calendar-check"></i> กำหนดส่ง</span><strong class="{{ $isLate?'text-danger':'' }}">{{ $job->job_due_at?->format('d/m/Y H:i') ?? '-' }}</strong></div>
            <div><span><i class="bi bi-building"></i> แผนก</span><strong>{{ $job->department?->department_name ?? '-' }}</strong></div>
            <div><span><i class="bi bi-bar-chart"></i> ความคืบหน้า</span><div class="nd-progress"><i><b style="width:{{ (int)$job->job_progress }}%"></b></i><strong>{{ (int)$job->job_progress }}%</strong></div></div>
        </section>

        <section class="nd-block"><h2>รายละเอียดงาน</h2><p class="nd-description">{{ $job->job_details ?: 'ยังไม่มีรายละเอียดงาน' }}</p></section>

        @if($canUpdate&&$job->approval_status==='approved'&&!$isDone)
        <section class="nd-block"><h2>อัปเดตงาน</h2><div class="nd-form-grid">
            <form method="POST" action="{{ route('tasks.progress.store',$job->job_id) }}" class="nd-form">@csrf<label>ความคิดเห็นหรือสิ่งที่อัปเดต<textarea name="note" rows="3" required placeholder="อธิบายสิ่งที่ทำเสร็จหรือปัญหาที่พบ"></textarea></label><button class="nd-primary">เพิ่มการอัปเดต</button></form>
            <form method="POST" action="{{ route('tasks.updateStatus',$job->job_id) }}" enctype="multipart/form-data" class="nd-form">@csrf @method('PATCH')<label>สถานะ<select name="job_status">@foreach($statusLabels as $v=>$label)<option value="{{ $v }}" @selected((int)$job->job_status===$v)>{{ $label }}</option>@endforeach</select></label><label>ไฟล์ส่งงานเมื่อปิดงาน<input type="file" name="completion_attachments[]" multiple></label><button class="nd-primary">บันทึกสถานะ</button></form>
        </div></section>
        @endif

        <section class="nd-block"><div class="nd-section-title"><h2>ไฟล์อ้างอิง</h2><span>{{ $job->images->count() }} ไฟล์</span></div><div class="nd-files">@forelse($job->images as $file)<a href="{{ route('media.show',['path'=>$file->file_path]) }}" target="_blank"><i class="bi bi-paperclip"></i><span>{{ $file->original_name ?? basename($file->file_path) }}</span><i class="bi bi-box-arrow-up-right"></i></a>@empty<p class="nd-empty">ยังไม่มีไฟล์แนบ</p>@endforelse</div>@if($canUpdate)<form method="POST" action="{{ route('tasks.attachments.store',$job->job_id) }}" enctype="multipart/form-data" class="nd-upload">@csrf<input type="file" name="completion_attachments[]" multiple required><button class="nd-btn">เพิ่มไฟล์</button></form>@endif</section>

        <section class="nd-block"><div class="nd-section-title"><h2>ทีมในงานนี้</h2><span>{{ $team->count() }} คน</span></div><div class="nd-team">@foreach($team as $person)<div><i>{{ Str::substr($person->name,0,1) }}</i><span><strong>{{ $person->name }}</strong><small>{{ $person->id===$job->user_id?'ผู้รับผิดชอบหลัก':($person->id===$job->leader_user_id?'หัวหน้าโปรเจกต์':'ผู้ร่วมงาน') }}</small></span>@if($canManageTeam&&$job->collaborators->contains('id',$person->id)&&!in_array($person->id,[$job->user_id,$job->created_by,$job->leader_user_id],true))<form method="POST" action="{{ route('tasks.collaborators.destroy',[$job->job_id,$person->id]) }}">@csrf @method('DELETE')<button title="นำออก"><i class="bi bi-x"></i></button></form>@endif</div>@endforeach</div>@if($canManageTeam&&$availableCollaborators->isNotEmpty())<details class="nd-invite"><summary><i class="bi bi-plus"></i> เชิญผู้ร่วมงาน</summary><form method="POST" action="{{ route('tasks.collaborators.store',$job->job_id) }}">@csrf<div>@foreach($availableCollaborators as $person)<label><input type="checkbox" name="collaborators[]" value="{{ $person->id }}"> {{ $person->name }} <small>{{ $person->department?->department_name }}</small></label>@endforeach</div><button class="nd-primary">ส่งคำเชิญ</button></form></details>@endif</section>

        <section class="nd-block"><div class="nd-section-title"><h2>ประวัติการอัปเดต</h2><span>{{ $job->updates->count() }} รายการ</span></div><div class="nd-timeline">@forelse($job->updates as $update)<div><i>{{ Str::substr($update->user?->name ?? 'ผู้ใช้',0,1) }}</i><p><strong>{{ $update->user?->name ?? 'ผู้ใช้' }}</strong> อัปเดต {{ $update->progress }}%<small>{{ $update->created_at?->format('d/m/Y H:i') }}</small><span>{{ $update->note }}</span></p></div>@empty<p class="nd-empty">ยังไม่มีประวัติการอัปเดต</p>@endforelse</div></section>

        @if(!$isAdmin&&$canUpdate&&!$isDone&&!$job->delete_requested_at)<section class="nd-block nd-delete" data-delete-panel hidden><h2>ส่งคำขอลบงาน</h2><form method="POST" action="{{ route('tasks.deleteRequest.store',$job->job_id) }}">@csrf<textarea name="reason" rows="3" required placeholder="ระบุเหตุผลที่ต้องการลบงาน"></textarea><button class="nd-btn danger">ส่งคำขอลบ</button></form></section>@endif
    </article>
</div>
@endsection
@push('scripts')<script>document.querySelector('[data-toggle-delete]')?.addEventListener('click',()=>{const p=document.querySelector('[data-delete-panel]');p.hidden=!p.hidden;p.scrollIntoView({behavior:'smooth'})});</script>@endpush

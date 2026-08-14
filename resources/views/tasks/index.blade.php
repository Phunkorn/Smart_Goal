@extends('layouts.app')
@section('title', 'งานของฉัน')

@php
    $allTasks = $activeTasks->merge($completedTasks)->unique('job_id')->values();
    $statusLabels = [1 => 'ยังไม่เริ่ม', 2 => 'กำลังทำ', 3 => 'รอตรวจสอบ', 4 => 'เสร็จแล้ว', 5 => 'พักงาน'];
    $priorityLabels = [1 => 'ต่ำ', 2 => 'กลาง', 3 => 'สูง'];
    $doneCount = $allTasks->where('job_status', 4)->count();
    $lateCount = $allTasks->filter(fn ($task) => (int) $task->job_status !== 4 && $task->job_due_at?->isPast())->count();
    $overall = $allTasks->count() ? (int) round($doneCount / $allTasks->count() * 100) : 0;
@endphp

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap">
    <style>{!! file_get_contents(resource_path('css/pages/mytasks-notion.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/pages/mytasks-task-modal.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/pages/mytasks-views.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/pages/mytasks-layout-fix.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/pages/mytasks-project-board.css')) !!}</style>
@endpush

@section('content')
<div class="notion-workspace" data-workspace
    data-details-template="{{ route('tasks.details.update', ['id' => '__ID__']) }}"
    data-status-template="{{ route('tasks.updateStatus', ['id' => '__ID__']) }}"
    data-priority-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
    data-due-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}"
    data-progress-template="{{ route('tasks.progress.store', ['id' => '__ID__']) }}"
    data-quick-url="{{ route('mytasks.store') }}"
    data-create-url="{{ route('mytasks.create') }}">
    <section class="notion-heading">
        <div><span class="notion-kicker">SMART GOAL WORKSPACE</span><h1>งานของฉัน</h1><p>จัดการงานและติดตามความคืบหน้าทั้งหมดในมุมมองเดียว</p></div>
        <button type="button" class="notion-primary" data-open-create><i class="bi bi-plus-lg"></i> เพิ่มโปรเจกต์</button>
    </section>

    <section class="notion-summary" aria-label="สรุปงาน">
        <button data-summary-filter=""><span>งานทั้งหมด</span><strong>{{ $allTasks->count() }}</strong></button>
        <button data-summary-filter="2"><span>กำลังทำ</span><strong>{{ $allTasks->where('job_status', 2)->count() }}</strong></button>
        <button data-summary-filter="late"><span>ล่าช้า</span><strong class="danger">{{ $lateCount }}</strong></button>
        <button data-summary-filter="3"><span>รอตรวจสอบ</span><strong>{{ $allTasks->where('job_status', 3)->count() }}</strong></button>
        <button data-summary-filter="4"><span>เสร็จแล้ว</span><strong class="success">{{ $doneCount }}</strong></button>
        <div class="summary-progress"><span>ความคืบหน้ารวม</span><div><i style="width:{{ $overall }}%"></i></div><strong>{{ $overall }}%</strong></div>
    </section>

    <nav class="notion-viewbar">
        <button class="active" type="button" data-view="table" role="tab" aria-selected="true"><i class="bi bi-table"></i> ตารางทั้งหมด</button>
        <button type="button" data-view="board" role="tab" aria-selected="false"><i class="bi bi-layout-three-columns"></i> บอร์ด</button>
        <button type="button" data-view="list" role="tab" aria-selected="false"><i class="bi bi-list-ul"></i> รายการ</button>
    </nav>

    <section class="notion-database">
        <div class="notion-toolbar">
            <label class="notion-search"><i class="bi bi-search"></i><input type="search" data-search placeholder="ค้นหาชื่องาน โปรเจกต์ หรือผู้รับผิดชอบ..."></label>
            <label class="notion-group">จัดกลุ่มตาม <select data-group><option value="project">โปรเจกต์</option><option value="status">สถานะ</option><option value="assignee">ผู้รับผิดชอบ</option><option value="priority">ความสำคัญ</option></select></label>
            <label class="notion-filter"><i class="bi bi-funnel"></i><select data-filter><option value="">ทุกสถานะ</option><option value="1">ยังไม่เริ่ม</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="5">พักงาน</option><option value="late">ล่าช้า</option><option value="4">เสร็จแล้ว</option></select></label>
            <button type="button" data-sort><i class="bi bi-sort-down"></i> กำหนดส่ง</button>
        </div>

        <div class="notion-table-scroll">
            <div class="project-board" data-project-board>
                @foreach($taskLists as $list)
                    @include('tasks.partials.project-board-card', compact('list', 'allTasks'))
                @endforeach
                <div class="project-board-empty" data-board-empty hidden><i class="bi bi-kanban"></i><p>ไม่พบงานในบอร์ดตามตัวกรองที่เลือก</p></div>
            </div>
            <div class="notion-table" data-table>
                <div class="notion-columns"><span></span><span>ชื่องาน</span><span>โปรเจกต์</span><span>สถานะ</span><span>ผู้รับผิดชอบ</span><span>กำหนดส่ง</span><span>ความคืบหน้า</span><span>ความสำคัญ</span><span></span></div>
                <div data-groups>
                    @foreach($taskLists as $list)
                        @php($listTasks = $allTasks->where('work_order_list_id', $list->id))
                        @if($listTasks->isNotEmpty())
                            <section class="notion-group-section" data-group-section data-group-key="{{ $list->name }}">
                                <header>
                                    <button type="button" data-collapse title="ย่อ/ขยาย"><i class="bi bi-chevron-down"></i></button>
                                    <span class="project-pill">{{ $list->name }}</span><small>{{ $listTasks->count() }} งาน</small>
                                    <div class="project-actions">
                                        <button type="button" class="group-plus" data-add-in-group data-list-id="{{ $list->id }}" title="เพิ่มรายการ"><i class="bi bi-plus-lg"></i></button>
                                        @can('manage', $list)
                                            <button type="button" data-edit-project data-name="{{ $list->name }}" data-url="{{ route('mytasks.lists.update', $list) }}" title="แก้ไขชื่อโปรเจกต์"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="danger" data-delete-project data-name="{{ $list->name }}" data-url="{{ route('mytasks.lists.destroy', $list) }}" title="ลบโปรเจกต์"><i class="bi bi-trash3"></i></button>
                                        @endcan
                                    </div>
                                </header>
                                <div data-group-rows>
                                    @foreach($listTasks as $task)
                                        @include('tasks.partials.notion-task-row', compact('task', 'statusLabels', 'priorityLabels'))
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    @endforeach
                    @php($ungrouped = $allTasks->whereNull('work_order_list_id'))
                    @if($ungrouped->isNotEmpty())
                        <section class="notion-group-section" data-group-section data-group-key="งานทั่วไป"><header><button type="button" data-collapse><i class="bi bi-chevron-down"></i></button><span class="project-pill neutral">งานทั่วไป</span><small>{{ $ungrouped->count() }} งาน</small></header><div data-group-rows>@foreach($ungrouped as $task) @include('tasks.partials.notion-task-row', compact('task', 'statusLabels', 'priorityLabels')) @endforeach</div></section>
                    @endif
                </div>
                <div class="notion-empty" data-empty hidden><i class="bi bi-search"></i><strong>ไม่พบงาน</strong><span>ลองเปลี่ยนคำค้นหาหรือตัวกรอง</span></div>
            </div>
        </div>
        <footer><button type="button" data-open-create><i class="bi bi-plus"></i> เพิ่มโปรเจกต์</button><span>แก้ไขข้อมูลในช่องได้ทันที ระบบจะบันทึกอัตโนมัติ</span></footer>
    </section>
</div>

<div class="notion-modal" data-create-modal hidden>
    <form class="notion-modal-card" data-create-form enctype="multipart/form-data">
        <header><div><strong>เพิ่มโปรเจกต์ใหม่</strong><span>สร้างโปรเจกต์และงานแรก</span></div><button type="button" data-close-create><i class="bi bi-x-lg"></i></button></header>
        <div class="modal-body">
            <label><span>ชื่อโปรเจกต์</span><input name="project_name" maxlength="80" required placeholder="เช่น ปรับปรุงเว็บไซต์บริษัท"></label>
            <label><span>ชื่องานแรก</span><input name="job_topic" maxlength="255" required placeholder="ระบุสิ่งที่ต้องดำเนินการ"></label>
            <label class="full"><span>รายละเอียด</span><textarea name="job_details" rows="3" maxlength="2000" placeholder="อธิบายผลลัพธ์ที่ต้องการ"></textarea></label>
            <label><span>ผู้รับผิดชอบ</span><select name="user_id"><option value="{{ auth()->id() }}">ฉัน — {{ auth()->user()->name }}</option>@foreach($availableCollaborators as $person)<option value="{{ $person->id }}">{{ $person->name }} · {{ $person->department?->department_name }}</option>@endforeach</select></label>
            <label><span>ความสำคัญ</span><select name="job_priority"><option value="1">ต่ำ</option><option value="2" selected>กลาง</option><option value="3">สูง</option></select></label>
            <label><span>วันที่เริ่ม</span><input type="date" name="job_start_at" value="{{ now()->format('Y-m-d') }}" required></label>
            <label><span>กำหนดส่ง</span><input type="date" name="job_due_at" value="{{ now()->addDay()->format('Y-m-d') }}" required></label>
            <label class="full"><span>ไฟล์แนบ (สูงสุด 5 ไฟล์)</span><input type="file" name="attachments[]" multiple></label>
        </div>
        <footer><button type="button" data-close-create>ยกเลิก</button><button class="notion-primary" type="submit">สร้างโปรเจกต์</button></footer>
    </form>
</div>

<div class="task-edit-modal notion-modal" data-task-modal hidden>
    <form class="task-edit-card" data-task-form>
        <header>
            <div><span class="task-edit-kicker">TASK DETAILS</span><strong>รายละเอียดงาน</strong><small>แก้ไขข้อมูลและบันทึกได้จากหน้านี้</small></div>
            <button type="button" class="task-modal-close" data-close-task aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="task-edit-body">
            <label class="task-field full"><span>ชื่องาน</span><input name="job_topic" maxlength="255" required></label>
            <label class="task-field full"><span>รายละเอียดงาน</span><textarea name="job_details" rows="5" maxlength="5000" placeholder="อธิบายเป้าหมาย ผลลัพธ์ หรือข้อมูลที่เกี่ยวข้อง"></textarea></label>
            <label class="task-field"><span>สถานะ</span><select name="job_status"><option value="1">ยังไม่เริ่ม</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="4">เสร็จแล้ว</option><option value="5">พักงาน</option></select></label>
            <label class="task-field"><span>ความสำคัญ</span><select name="job_priority"><option value="1">ต่ำ</option><option value="2">กลาง</option><option value="3">สูง</option></select></label>
            <label class="task-field"><span>กำหนดส่ง</span><input type="date" name="job_due_at" required></label>
            <div class="task-field task-progress-readonly"><span>ความคืบหน้า</span><strong data-modal-progress>0%</strong><small>คำนวณจากงานย่อยโดยอัตโนมัติ</small></div>
            <label class="task-field"><span>โปรเจกต์</span><input name="project" readonly></label>
            <label class="task-field"><span>ผู้รับผิดชอบ</span><input name="assignee" readonly></label>
        </div>
        <footer><button type="button" class="task-secondary" data-close-task>ยกเลิก</button><button type="submit" class="notion-primary">บันทึกการแก้ไข</button></footer>
    </form>
</div>
<div class="notion-toast" data-toast></div>
@endsection

@push('scripts')
    <script>{!! file_get_contents(resource_path('js/mytasks-notion.js')) !!}</script>
    <script>{!! file_get_contents(resource_path('js/mytasks-task-modal.js')) !!}</script>
    <script>{!! file_get_contents(resource_path('js/mytasks-views.js')) !!}</script>
    <script>{!! file_get_contents(resource_path('js/mytasks-management.js')) !!}</script>
    <script>{!! file_get_contents(resource_path('js/mytasks-project-board.js')) !!}</script>
@endpush

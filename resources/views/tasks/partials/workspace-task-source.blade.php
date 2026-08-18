@php
    $showQuickAdd = $showQuickAdd ?? true;
    $workspaceContext = $workspaceContext ?? 'user';
@endphp
<div class="notion-table" data-table>
    <div class="notion-columns"><span>ชื่องาน</span><span>สถานะ</span><span>ความสำคัญ</span><span>ผู้รับผิดชอบ</span><span>ระยะเวลา</span><span>ผู้ร่วมงาน</span><span>ไฟล์</span><span>ความคืบหน้า</span><span>Action</span></div>
    <div data-groups>
        @foreach($taskLists as $list)
            @php($listTasks = $allTasks->where('work_order_list_id', $list->id))
            <section class="notion-group-section" data-group-section data-group-key="{{ $list->name }}">
                <header>
                    <button type="button" data-collapse title="ย่อ/ขยาย"><i class="bi bi-chevron-down"></i></button>
                    @php($tableProjectPriority = [1=>['สำคัญ/ต่ำ','low'],2=>['สำคัญ/กลาง','medium'],3=>['สำคัญ/สูง','high']][(int) ($list->priority ?? 2)] ?? ['สำคัญ/กลาง','medium'])
                    <span class="project-pill">{{ $list->name }}</span>
                    @can('manage', $list)
                        <details class="board-project-priority-menu table-project-priority-menu" data-table-project-priority-menu data-url="{{ route('mytasks.lists.update', $list) }}">
                            <summary class="board-project-priority priority-{{ $tableProjectPriority[1] }}"><i class="bi bi-flag-fill"></i><span data-table-project-priority-label>{{ $tableProjectPriority[0] }}</span><i class="bi bi-chevron-down"></i></summary>
                            <div>@foreach([1=>['สำคัญ/ต่ำ','low'],2=>['สำคัญ/กลาง','medium'],3=>['สำคัญ/สูง','high']] as $value=>$meta)<button type="button" class="priority-{{ $meta[1] }}" data-table-project-priority-value="{{ $value }}"><i class="bi bi-flag-fill"></i>{{ $meta[0] }}@if((int)$list->priority === $value)<span class="bi bi-check2"></span>@endif</button>@endforeach</div>
                        </details>
                    @else
                        <span class="board-project-priority priority-{{ $tableProjectPriority[1] }}"><i class="bi bi-flag-fill"></i>{{ $tableProjectPriority[0] }}</span>
                    @endcan
                    <small>{{ $listTasks->count() }} งาน</small>
                    <div class="project-actions">
                        @if($showQuickAdd && $manageableTaskLists->contains('id', $list->id))
                            <button type="button" class="group-plus" data-add-in-group data-list-id="{{ $list->id }}" title="เพิ่มรายการ"><i class="bi bi-plus-lg"></i></button>
                        @endif
                        @can('manage', $list)
                            <button type="button" data-edit-project data-name="{{ $list->name }}" data-url="{{ route('mytasks.lists.update', $list) }}" title="แก้ไขชื่อโปรเจกต์"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="danger" data-delete-project data-name="{{ $list->name }}" data-total-count="{{ $list->work_orders_count ?? $listTasks->count() }}" data-url="{{ route('mytasks.lists.destroy', $list) }}" title="ลบโปรเจกต์"><i class="bi bi-trash3"></i></button>
                        @endcan
                    </div>
                </header>
                <div data-group-rows>
                    @foreach($listTasks as $task)
                        @include('tasks.partials.notion-task-row', compact('task', 'statusLabels', 'priorityLabels', 'showQuickAdd', 'workspaceContext'))
                    @endforeach
                </div>
            </section>
        @endforeach
        @php($ungrouped = $allTasks->whereNull('work_order_list_id'))
        @if($ungrouped->isNotEmpty())
            <section class="notion-group-section" data-group-section data-group-key="งานทั่วไป"><header><button type="button" data-collapse><i class="bi bi-chevron-down"></i></button><span class="project-pill neutral">งานทั่วไป</span><small>{{ $ungrouped->count() }} งาน</small></header><div data-group-rows>@foreach($ungrouped as $task) @include('tasks.partials.notion-task-row', compact('task', 'statusLabels', 'priorityLabels', 'showQuickAdd', 'workspaceContext')) @endforeach</div></section>
        @endif
    </div>
    <div class="notion-empty" data-empty hidden><i class="bi bi-search"></i><strong>ไม่พบงาน</strong><span>ลองเปลี่ยนคำค้นหาหรือตัวกรอง</span></div>
</div>

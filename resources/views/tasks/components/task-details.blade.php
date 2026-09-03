@php
    $taskDetails = $task->relationLoaded('subtasks')
        ? $task->subtasks->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()
        : collect();
    $canManageTaskDetails = auth()->user()->can('work', $task);
@endphp

<div class="board-task-details" data-task-details data-work-order-id="{{ $task->job_id }}">
    <div class="board-task-details__heading">
        <button type="button" class="board-task-details__toggle" data-task-details-toggle aria-expanded="false" aria-controls="task-details-{{ $task->job_id }}">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span class="board-reference-task__title">{{ $task->job_topic }}</span>
            <small>รายละเอียด <b data-task-details-count>{{ $taskDetails->count() }}</b></small>
        </button>
        <button type="button" class="board-reference-task__open" data-open-task-modal data-task-id="{{ $task->job_id }}" aria-label="เปิดข้อมูลทั้งหมดของงาน {{ $task->job_topic }}" title="เปิดข้อมูลงาน">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
        </button>
    </div>

    <div class="board-task-details__panel" id="task-details-{{ $task->job_id }}" data-task-details-panel hidden>
        <ol class="board-task-details__list" data-task-details-list>
            @foreach($taskDetails as $detail)
                <li class="board-task-detail" data-task-detail data-detail-id="{{ $detail->id }}" data-work-order-id="{{ $task->job_id }}" data-update-url="{{ route('mytasks.details.update', $detail) }}" data-delete-url="{{ route('mytasks.details.destroy', $detail) }}" data-move-url="{{ route('mytasks.details.move', $detail) }}" @if($canManageTaskDetails) draggable="true" @endif>
                    @if($canManageTaskDetails)
                        <button type="button" class="board-task-detail__drag" data-task-detail-drag aria-label="ลากเพื่อย้ายรายละเอียดงาน {{ $detail->title }}" title="ลากไปวางที่งานหรือโปรเจกต์อื่น"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
                    @else
                        <i class="board-task-detail__bullet bi bi-dash" aria-hidden="true"></i>
                    @endif
                    <span data-task-detail-title>{{ $detail->title }}</span>
                    @if($canManageTaskDetails)
                        <span class="board-task-detail__actions">
                            <button type="button" data-task-detail-move aria-label="ย้ายรายละเอียดงาน {{ $detail->title }}" title="ย้ายไปงานอื่น"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></button>
                            <button type="button" data-task-detail-edit aria-label="แก้ไขรายละเอียดงาน {{ $detail->title }}" title="แก้ไข"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                            <button type="button" data-task-detail-delete aria-label="ลบรายละเอียดงาน {{ $detail->title }}" title="ลบ"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>

        <p class="board-task-details__empty" data-task-details-empty @if($taskDetails->isNotEmpty()) hidden @endif>ยังไม่มีรายละเอียดงาน</p>

        @if($canManageTaskDetails)
            <form class="board-task-details__create" data-task-detail-create data-url="{{ route('mytasks.details.store', $task) }}">
                <label class="visually-hidden" for="task-detail-new-{{ $task->job_id }}">รายละเอียดงานใหม่</label>
                <input id="task-detail-new-{{ $task->job_id }}" name="title" maxlength="255" placeholder="เพิ่มรายละเอียดงาน เช่น ซื้ออุปกรณ์">
                <button type="submit" aria-label="เพิ่มรายละเอียดงาน"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>เพิ่ม</span></button>
            </form>
        @endif
    </div>
</div>

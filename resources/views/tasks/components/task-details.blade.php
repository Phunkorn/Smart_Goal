@php
    // ส่วนหัวของงาน อยู่ในคอลัมน์ "ชื่องาน" ของแถวบอร์ด
    // ตัวรายการงานย่อยอยู่ใน tasks.components.task-details-panel ซึ่งถูกวางเป็นลูกโดยตรง
    // ของ .board-reference-row เพื่อให้กินความกว้างทั้งแถวและเรียงคอลัมน์ตรงกับงานแม่
    $taskDetails = $task->relationLoaded('children')
        ? $task->children
        : collect();
    // เคลียร์แล้วคือสถานะ 4 เท่านั้น — พักงาน รอตรวจสอบ และล่าช้า ยังนับว่าค้าง
    // ตัวเลขชุดเดียวกับที่ TaskStatusTransitionService ใช้ตัดสินว่าปิดงานแม่ได้หรือยัง
    $taskDetailsDone = $taskDetails->where('job_status', 4)->count();
    $taskDetailsComplete = $taskDetails->isNotEmpty() && $taskDetailsDone === $taskDetails->count();
@endphp

<div class="board-task-details" data-task-details data-work-order-id="{{ $task->job_id }}">
    <div class="board-task-details__heading">
        <button type="button" class="board-task-details__toggle" data-task-details-toggle aria-expanded="false" aria-controls="task-details-{{ $task->job_id }}">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span class="board-reference-task__title">{{ $task->job_topic }}</span>
            <small class="board-task-details__progress {{ $taskDetailsComplete ? 'is-complete' : 'is-pending' }}" data-task-details-progress>งานย่อย <b data-task-details-count>{{ $taskDetailsDone }}</b>/<span data-task-details-total>{{ $taskDetails->count() }}</span></small>
        </button>
        <button type="button" class="board-reference-task__open" data-open-task-modal data-task-id="{{ $task->job_id }}" aria-label="เปิดข้อมูลทั้งหมดของงาน {{ $task->job_topic }}" title="เปิดข้อมูลงาน">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
        </button>
    </div>
</div>

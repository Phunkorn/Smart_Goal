@php
    /*
     * รายการงานย่อยของงานหนึ่งใบ
     *
     * partial นี้ถูกวางเป็นลูกโดยตรงของ .board-reference-row และกินทั้งแถว (grid-column: 1/-1)
     * แต่ละงานย่อยจึงใช้กริดคอลัมน์ชุดเดียวกับงานแม่ — สถานะอยู่ใต้ "สถานะ" ความสำคัญอยู่ใต้
     * "ความสำคัญ" ไปจนถึงคอมเมนต์ ไม่ใช่ก้อนชิปที่อัดอยู่ในคอลัมน์ชื่องาน
     */
    $taskDetails = $task->relationLoaded('children')
        ? $task->children->sortBy([['parent_sort_order', 'asc'], ['job_id', 'asc']])->values()
        : collect();
    /*
     * เพิ่ม ลาก ย้าย แก้ชื่อ และลบงานย่อย ใช้สิทธิ์ manageSubtasks ตัวเดียวกับ WorkOrderSubtaskController
     *
     * เดิมหน้าจอเช็ค work() ซึ่งกว้างกว่า ผู้ร่วมงาน (รวมถึงผู้ร่วมงานข้ามแผนก) จึงเห็นช่องเพิ่มงานย่อย
     * และเมนูย้าย/ลบ แต่กดแล้วได้ "This action is unauthorized" ทุกครั้ง
     * งานย่อยของงานนั้นเป็นหน้าที่ของเจ้าของงาน ซึ่งเพิ่มคนเข้าร่วมเองได้
     */
    $canManageTaskDetails = auth()->user()->can('manageSubtasks', $task);
@endphp

<div class="board-task-details__panel" id="task-details-{{ $task->job_id }}" data-task-details-panel hidden>
    <ol class="board-task-details__list" data-task-details-list>
        @foreach($taskDetails as $detail)
            @include('tasks.components.task-detail-row', ['task' => $task, 'detail' => $detail, 'canManageTaskDetails' => $canManageTaskDetails, 'projectKey' => $projectKey ?? ''])
        @endforeach
    </ol>

    <p class="board-task-details__empty" data-task-details-empty @if($taskDetails->isNotEmpty()) hidden @endif>ยังไม่มีงานย่อย</p>

    @if($canManageTaskDetails)
        <form class="board-task-details__create" data-task-detail-create data-url="{{ route('mytasks.details.store', $task) }}">
            <label class="visually-hidden" for="task-detail-new-{{ $task->job_id }}">งานย่อยใหม่</label>
            <input id="task-detail-new-{{ $task->job_id }}" name="title" maxlength="255" placeholder="เพิ่มงานย่อย เช่น ซื้ออุปกรณ์">
            <button type="submit" aria-label="เพิ่มงานย่อย"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>เพิ่ม</span></button>
        </form>
    @endif
</div>

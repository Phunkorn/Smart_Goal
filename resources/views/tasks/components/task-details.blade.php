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
    /*
     * จำนวนงานย่อยที่เลยกำหนดส่ง สำหรับป้ายเตือนบนหัวข้องาน
     *
     * ผู้ใช้ที่มีงานย่อยเยอะต้องกางทุกงานออกมาดูถึงจะรู้ว่ามีใบไหนล่าช้า ป้ายนี้ยกข้อมูลนั้น
     * ขึ้นมาไว้ระดับสายตา ตรงหลังชื่องานซึ่งเป็นสิ่งที่ผู้ใช้อ่านก่อนเสมอ
     *
     * ต้องนับด้วย TodayWorkspace::isOverdue() ตัวเดียวกับที่ task-detail-row.blade.php ใช้
     * ทำแถวงานย่อยให้เป็นสีแดง ไม่งั้นผู้ใช้เห็นเลข 3 แล้วกางออกมาเจอแดงแค่ 2 แถว
     *
     * children ถูก eager-load มาแล้วที่ MyTaskController::index() และถูกตัดตามสิทธิ์ด้วย
     * CrossDepartmentWork::restrictChildren() การนับตรงนี้จึงไม่มีคิวรีเพิ่มและเคารพสิทธิ์เอง
     */
    $taskDetailsLate = $taskDetails->filter(
        fn ($detail) => \App\Support\TodayWorkspace::isOverdue($detail)
    )->count();
@endphp

<div class="board-task-details" data-task-details data-work-order-id="{{ $task->job_id }}">
    <div class="board-task-details__heading">
        <button type="button" class="board-task-details__toggle" data-task-details-toggle aria-expanded="false" aria-controls="task-details-{{ $task->job_id }}">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span class="board-reference-task__title">{{ $task->job_topic }}</span>
            {{--
                ป้ายเตือน "มีงานย่อยเลยกำหนด" — ต้องเป็น <span> เท่านั้น ห้ามเป็นปุ่มหรือลิงก์
                เพราะมันอยู่ใน <button> ของตัวกางแผงงานย่อยอยู่แล้ว ปุ่มซ้อนปุ่มเป็น HTML ที่ไม่ถูกต้อง
                และเบราว์เซอร์จะดึงปุ่มในออกมานอกปุ่มนอก ทำให้หัวข้องานเพี้ยนทั้งแถว

                การคลิกตกไปถึง [data-task-details-toggle] เองผ่าน event delegation ใน
                pages/mytasks/task-details.js แล้วกางแผงงานย่อยให้ ซึ่งคือพฤติกรรมที่ต้องการพอดี
                จึงไม่ต้องเขียน JS เพิ่มแม้แต่บรรทัดเดียว และห้ามใส่ pointer-events: none

                ไอคอนกับตัวเลขถูก aria-hidden เพราะป้ายนี้อยู่ในชื่อของปุ่ม ถ้าปล่อยไว้
                โปรแกรมอ่านหน้าจอจะอ่านว่า "2" ลอย ๆ ปนกับตัวนับ "0/3" จนฟังไม่รู้เรื่อง
            --}}
            @if($taskDetailsLate)
                <span class="board-task-details__late" data-task-details-late title="มีงานย่อยเลยกำหนดส่ง {{ $taskDetailsLate }} งาน"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><b aria-hidden="true">{{ $taskDetailsLate }}</b><span class="visually-hidden">มีงานย่อยเลยกำหนดส่ง {{ $taskDetailsLate }} งาน</span></span>
            @endif
            <small class="board-task-details__progress {{ $taskDetailsComplete ? 'is-complete' : 'is-pending' }}" data-task-details-progress>งานย่อย <b data-task-details-count>{{ $taskDetailsDone }}</b>/<span data-task-details-total>{{ $taskDetails->count() }}</span></small>
        </button>
        <button type="button" class="board-reference-task__open" data-open-task-modal data-task-id="{{ $task->job_id }}" aria-label="เปิดข้อมูลทั้งหมดของงาน {{ $task->job_topic }}" title="เปิดข้อมูลงาน">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
        </button>
    </div>
</div>

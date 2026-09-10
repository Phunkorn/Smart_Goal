{{--
    ช่อง "งานย่อย" ของตารางรายงาน ใช้ร่วมกันทั้งหน้าพนักงานและหน้าหัวหน้าแผนก

    แสดงเป็นจำนวนที่กดได้ ไม่ใช่รายชื่อทั้งหมด เพราะงานที่มีงานย่อยเยอะ (เช่น 7 ใบ)
    ดันความสูงของแถวจนตารางอ่านไม่ออก และชื่อยาว ๆ ทำให้กวาดสายตาตามคอลัมน์ไม่ได้
    รายชื่อเต็มย้ายไปอยู่ใน modal ที่เปิดจากปุ่มนี้

    ชื่องานย่อยถูกฝากมากับปุ่มเป็น JSON แทนการ render รายการซ้ำในทุกแถว
    เพื่อไม่ให้หน้ามี DOM ซ่อนไว้เท่าจำนวนแถว

    @param array $job  แถวหนึ่งจาก taskRows / attentionJobs
--}}
@if($job['subtasks'])
    <button type="button" class="report-subtask-btn" data-subtask-open
        data-subtask-task="{{ $job['topic'] }}"
        data-subtask-project="{{ $job['project'] }}"
        data-subtask-names="{{ json_encode($job['subtasks'], JSON_UNESCAPED_UNICODE) }}"
        aria-label="ดูงานย่อย {{ count($job['subtasks']) }} รายการของ {{ $job['topic'] }}">
        <i class="bi bi-diagram-3" aria-hidden="true"></i>
        <span>{{ count($job['subtasks']) }} รายการ</span>
    </button>
@else
    <span class="report-subtask-none">—</span>
@endif

{{--
    ปุ่ม "แชร์งาน" ในเมนูจัดการของแถวงาน

    ใช้ร่วมกันทั้งแถวรายการงานหลัก (project-board-card) และแถวงานย่อย
    (task-detail-row) เพราะทั้งสองแถวเป็น work_orders เหมือนกัน และกติกาการแชร์
    ก็ชุดเดียวกัน ห้ามคัดลอกปุ่มนี้ไปเขียนซ้ำในอีกไฟล์

    ต้องเป็นลูกโดยตรงของ div.board-task-menu เพราะตัวปิดเมนูลอยของบอร์ดใช้
    selector '.board-reference-menu > div button' (mytasks-project-board.js)
    ถ้าห่อ div เพิ่มอีกชั้น เมนูจะไม่ปิดหลังกด

    สิทธิ์ตัดสินที่ WorkOrderPolicy::share() ฝั่ง server การซ่อนปุ่มที่นี่เป็นเรื่อง
    การมองเห็นอย่างเดียว
--}}
@can('share', $shareTask)
    @php($shareOpen = $shareTask->openShare)
    <button type="button"
        data-share-task
        data-task-id="{{ $shareTask->job_id }}"
        data-topic="{{ $shareTask->job_topic }}"
        data-url="{{ route('shares.store', $shareTask->job_id) }}"
        data-close-url="{{ $shareOpen ? route('shares.destroy', $shareOpen) : '' }}"
        data-shared="{{ $shareOpen ? 1 : 0 }}"
        data-scope="{{ $shareOpen?->scope }}">
        <i class="bi {{ $shareOpen ? 'bi-share-fill' : 'bi-share' }}"></i>
        <span>
            <strong>{{ $shareOpen ? 'จัดการการแชร์งาน' : 'แชร์งาน' }}</strong>
            <small>{{ $shareOpen ? 'กำลังแชร์อยู่ — เปิดเพื่อปิดประกาศ' : 'ประกาศให้คนอื่นขอเข้าร่วมงานนี้' }}</small>
        </span>
    </button>
@endcan

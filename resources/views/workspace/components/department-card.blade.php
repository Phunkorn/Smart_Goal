{{--
    การ์ดแผนกหนึ่งใบในหน้ารวมกระดานไอเดีย

    ภาพโฟลเดอร์เป็นของตกแต่งชุดเดียวกันทุกแผนก (CSS background) จึงไม่มีสีหรือ
    ภาพแยกตามแผนก ข้อมูลที่แสดงทั้งหมดมาจากตัวแปรที่ controller ส่งมาแล้ว
    ไม่มี query เพิ่ม

    ตัวแปรที่รับ: $department, $boardCount, $isOwn
--}}

<a class="ws-dept-card {{ $isOwn ? 'ws-dept-card--own' : '' }}"
    href="{{ route('workspace.department', $department) }}">
    <span class="ws-dept-card__visual" aria-hidden="true">
        @if ($isOwn)
            <span class="ws-dept-card__badge">แผนกของฉัน</span>
        @endif
    </span>
    <span class="ws-dept-card__foot">
        <span class="ws-dept-card__body">
            <span class="ws-dept-card__name">{{ $department->department_name }}</span>
            <span class="ws-dept-card__count">{{ $boardCount }} กระดาน</span>
        </span>
        <i class="bi bi-arrow-up-right ws-dept-card__arrow" aria-hidden="true"></i>
    </span>
</a>

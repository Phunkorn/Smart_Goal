{{--
    งานหนึ่งรายการของวันนี้ = หนึ่งบรรทัดในการ์ดของคนคนนั้น

    ลำดับในบรรทัดคงที่เสมอ: ผลการทำ → ชื่องาน → ชนิดงาน → เวลาที่ใช้ ตำแหน่งของ
    ทุกส่วนจึงตรงกันทุกบรรทัดและทุกการ์ด กวาดสายตาลงในแนวตั้งได้ว่าอันไหนยังไม่ติ๊ก
    และใครใช้เวลาไปกับงานไหนมากผิดปกติ โดยไม่ต้องอ่านทุกตัวอักษร

    ไม่มีคำว่า "สำเร็จแล้ว" ซ้ำทุกบรรทัด เพราะไอคอนกับสีบอกเรื่องเดียวกันอยู่แล้ว
    ส่วนสถานะที่ไม่ใช่ "เสร็จ" ยังต้องมีคำกำกับ เพราะ "ข้าม" กับ "ยังไม่เริ่ม"
    ต่างกันมากสำหรับคนที่ต้องไปตาม

    แยกเป็นไฟล์เพราะถูกเรียกสองที่ในการ์ดเดียวกัน — บรรทัดแรก ๆ และส่วนที่กางเพิ่ม
--}}
<li class="report-people-card__item @if($item['is_done']) is-done @endif">
    <i class="bi @if($item['is_done']) bi-check-circle-fill @else bi-circle @endif" aria-hidden="true"></i>
    <span class="report-people-card__item-title">{{ $item['title'] }}</span>
    @unless($item['is_done'])
        <span class="report-people-card__item-status">{{ $item['status_label'] }}</span>
    @endunless
    <span class="report-people-card__item-kind">{{ $item['kind_label'] }}</span>
    <span class="report-people-card__item-time">{{ $item['minutes_label'] }}</span>
</li>

{{--
    ช่วงที่แสดงและปุ่มเปลี่ยนหน้าในกล่องรายละเอียดการ์ดตัวเลข — ใช้ร่วมกันทั้งสองรายงาน

    ปุ่ม (ไม่ใช่ลิงก์) เพราะ kpi-detail-modal.js โหลดหน้าถัดไปมาแทนที่ในกล่องเดิม
    URL มาจาก controller ซึ่งพกเฉพาะ query ที่ตรวจแล้วของหน้ารายงาน

    @param array $detail  ผลจาก kpiDetail() ของ service
    @param \Closure $pageUrl  fn (int $page): string
--}}
@php
    $shown = count($detail['items']);
@endphp
<footer class="report-kpi-detail__foot">
    <p class="report-kpi-detail__range" tabindex="-1" data-kpi-detail-focus>
        แสดง {{ number_format($detail['from'] + 1) }}–{{ number_format($detail['from'] + $shown) }} จาก {{ number_format($detail['total']) }} รายการ
    </p>
    @if($detail['last_page'] > 1)
        <nav class="report-kpi-detail__pager" aria-label="เปลี่ยนหน้ารายการ">
            <button type="button" class="report-kpi-detail__page" data-kpi-page-url="{{ $pageUrl($detail['page'] - 1) }}" @disabled($detail['page'] <= 1)>
                <i class="bi bi-chevron-left" aria-hidden="true"></i><span>ก่อนหน้า</span>
            </button>
            <span class="report-kpi-detail__page-label">หน้า {{ $detail['page'] }} / {{ $detail['last_page'] }}</span>
            <button type="button" class="report-kpi-detail__page" data-kpi-page-url="{{ $pageUrl($detail['page'] + 1) }}" @disabled($detail['page'] >= $detail['last_page'])>
                <span>ถัดไป</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>
    @endif
</footer>

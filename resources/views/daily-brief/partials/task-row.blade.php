{{--
    หนึ่งแถวงานประจำในสรุปประจำวัน — ใช้ทั้งในการ์ดสรุปและในกล่อง "ดูทั้งหมด"

    งานโปรเจกต์ล่าช้าใช้แถวแบบจัดอันดับคนละชุด (partials/overdue-row.blade.php)

    data-daily-brief-link ทำให้การกดแถวนับเป็นการรับทราบก่อนพาไปที่งาน
    (resources/js/components/daily-brief.js)
--}}
<a class="daily-brief__row" href="{{ $row['url'] }}" data-daily-brief-link>
    <span class="daily-brief__row-main">
        <strong>{{ $row['title'] }}</strong>
        <small>{{ $meta }}</small>
    </span>
    @if($row['time'] !== '')
        <span class="daily-brief__time">{{ $row['time'] }}</span>
    @endif
    <i class="bi bi-chevron-right daily-brief__chevron" aria-hidden="true"></i>
</a>

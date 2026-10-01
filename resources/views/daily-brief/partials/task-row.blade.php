{{--
    หนึ่งแถวงานในสรุปประจำวัน — ใช้ทั้งงานโปรเจกต์และงานประจำ

    data-daily-brief-link ทำให้การกดแถวนับเป็นการรับทราบก่อนพาไปที่งาน
    (resources/js/components/daily-brief.js)
--}}
<a class="daily-brief__row" href="{{ $row['url'] }}" data-daily-brief-link>
    <span class="daily-brief__row-main">
        <strong>{{ $row['title'] }}</strong>
        <small>{{ $meta }}</small>
    </span>
    @if(! empty($row['priority']))
        <span class="badge-soft {{ $row['priority']['tone'] }}">{{ $row['priority']['label'] }}</span>
    @endif
    @if($row['time'] !== '')
        <span @class(['daily-brief__time', 'is-late' => ! empty($row['is_late'])])>{{ $row['time'] }}</span>
    @endif
    <i class="bi bi-chevron-right daily-brief__chevron" aria-hidden="true"></i>
</a>

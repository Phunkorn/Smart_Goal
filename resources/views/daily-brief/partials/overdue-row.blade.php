{{--
    หนึ่งอันดับงานโปรเจกต์ล่าช้าในสรุปประจำวัน

    ตัวเลขอันดับเป็นการนับซ้ำของลำดับ <li> จึงซ่อนจากโปรแกรมอ่านหน้าจอ ซึ่งอ่านลำดับ
    จากรายการอยู่แล้ว ข้อมูลที่ต้องได้ยินคือชื่องาน โปรเจกต์ ความล่าช้า และกำหนดส่ง

    data-daily-brief-link ทำให้การกดแถวนับเป็นการรับทราบก่อนพาไปที่งาน
    (resources/js/components/daily-brief.js) เส้นทางและสิทธิ์เป็นชุดเดิมของหน้างานของฉัน
--}}
<li class="daily-brief__rank-item">
    <a class="daily-brief__rank-link" href="{{ $row['url'] }}" data-daily-brief-link>
        <span class="daily-brief__rank-number" aria-hidden="true">{{ str_pad((string) $rank, 2, '0', STR_PAD_LEFT) }}</span>
        <span class="daily-brief__rank-main">
            <span class="daily-brief__rank-title">{{ $row['title'] }}</span>
            <span class="daily-brief__rank-sub">
                {{-- ป้ายความสำคัญอยู่นอกชื่องาน เพราะชื่อที่ยาวถูกตัดเหลือสองบรรทัด ป้ายที่วางไว้ในชื่อจะหายไปด้วย --}}
                @if(! empty($row['priority']))
                    <span class="badge-soft {{ $row['priority']['tone'] }} daily-brief__rank-priority">{{ $row['priority']['label'] }}</span>
                @endif
                <span class="daily-brief__rank-project">{{ $row['project'] }}</span>
            </span>
        </span>
        <span class="daily-brief__rank-meta">
            <span class="daily-brief__rank-overdue">{{ $row['overdue_label'] }}</span>
            <span class="daily-brief__rank-due">ครบกำหนด {{ $row['due_label'] }}</span>
        </span>
    </a>
</li>

{{--
    สรุปเวลาของวัน

    ตัวเลขสำคัญที่สุดของบล็อกนี้คือ "เวลาที่ไม่ได้ลงโปรเจกต์" เพราะมันคือคำตอบ
    ของคำถามที่ทำให้ต้องสร้างฟีเจอร์นี้ขึ้นมา — ทำไมโปรเจกต์ไม่ขยับเลยวันนี้
    ทั้งที่พนักงานทำงานเต็มวัน

    ทุกค่ามาจาก WorkLogSummary ชุดเดียวกับที่ payload ของ AJAX ใช้ หน้าจอจึงไม่
    ต้องคำนวณเลขเองหลังบันทึกสำเร็จ
--}}
<section class="log-summary" aria-labelledby="logSummaryHeading" data-log-summary>
    <h2 class="log-summary__heading" id="logSummaryHeading">สรุปเวลาของวัน</h2>

    <p class="log-summary__total">
        <span class="log-summary__total-value" data-summary-total>
            {{ \App\Support\WorkLogDesign::durationLabel($summary['total_minutes']) }}
        </span>
        <span class="log-summary__total-note">
            จาก <span data-summary-count>{{ $summary['total_count'] }}</span> รายการ
        </span>
    </p>

    @if($summary['total_minutes'] > 0)
        <div class="log-summary__bar" role="img"
            aria-label="สัดส่วนเวลาตามประเภทงาน">
            @foreach($summary['by_kind'] as $key => $bucket)
                @continue($bucket['minutes'] === 0)
                <span class="log-summary__bar-part log-summary__bar-part--{{ $bucket['tone'] }}"
                    style="flex-grow: {{ $bucket['minutes'] }}"
                    title="{{ $bucket['label'] }} {{ \App\Support\WorkLogDesign::durationLabel($bucket['minutes']) }}"></span>
            @endforeach
        </div>
    @endif

    <dl class="log-summary__kinds">
        @foreach($summary['by_kind'] as $key => $bucket)
            <div class="log-summary__kind log-summary__kind--{{ $bucket['tone'] }}">
                <dt>
                    <i class="bi {{ $bucket['icon'] }}" aria-hidden="true"></i>
                    {{ $bucket['label'] }}
                </dt>
                <dd>
                    <strong>{{ \App\Support\WorkLogDesign::durationLabel($bucket['minutes']) }}</strong>
                    <span>{{ $bucket['count'] }} งาน</span>
                </dd>
            </div>
        @endforeach
    </dl>

    @if($summary['total_count'] > 0)
        <div class="log-summary__highlight">
            <span class="log-summary__highlight-label">เวลาที่ไม่ได้ลงโปรเจกต์</span>
            <span class="log-summary__highlight-value" data-summary-unlinked>
                {{ \App\Support\WorkLogDesign::durationLabel($summary['unlinked_minutes']) }}
            </span>
            <span class="log-summary__highlight-note">
                งานปฏิบัติการที่ไม่ได้ผูกกับโปรเจกต์ใด
            </span>
        </div>
    @endif

    @if($summary['open_count'] > 0)
        <p class="log-summary__note">
            <i class="bi bi-circle" aria-hidden="true"></i>
            ยังไม่ปิด {{ $summary['open_count'] }} รายการ
        </p>
    @endif

    @if($summary['untimed_count'] > 0)
        <p class="log-summary__note">
            <i class="bi bi-question-circle" aria-hidden="true"></i>
            ไม่ระบุเวลา {{ $summary['untimed_count'] }} รายการ
        </p>
    @endif

    @if($summary['auto_closed_count'] > 0)
        <p class="log-summary__note log-summary__note--warning">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            ระบบปิดให้อัตโนมัติ {{ $summary['auto_closed_count'] }} รายการ — ควรตรวจสอบเวลา
        </p>
    @endif

    @if($capabilities['canCreate'])
        <a class="log-summary__link" href="{{ route('daily-logs.index') }}">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> กลับไปวันนี้
        </a>
    @endif
</section>

{{--
    รายการงานของวัน แบ่งเป็นสองกลุ่มตามสถานะ

    กลุ่ม "ที่ต้องทำ" กับ "ทำแล้ว" คือสิ่งที่ผู้ใช้ถามจริง ๆ ตอนเปิดหน้านี้ตอนเช้า
    ("เหลืออะไรอีก") ไทม์ไลน์เรียงเวลาล้วน ๆ ตอบคำถามนั้นไม่ได้ เพราะงานที่ยืนยัน
    ไปแล้วกับงานที่ยังไม่ได้เข้าไปทำ ปนกันอยู่ในลำดับเดียว

    ภายในแต่ละกลุ่มยังเรียงตามเวลาเหมือนเดิม (รายการที่ไม่ระบุเวลาอยู่ท้ายสุด)
    ชิปกรองประเภทกรองบนโหนดที่ render แล้วฝั่ง client จึงไม่ต้องยิง request ใหม่
    จำนวนในชิปมาจาก summary ชุดเดียวกับกล่องสรุป เพื่อไม่ให้ตัวเลขสองที่ไม่ตรงกัน
--}}
@php
    $openLogs = [];
    $doneLogs = [];

    foreach ($logs as $index => $log) {
        $entry = ['log' => $log, 'presented' => $presentedLogs[$index]];

        if ($entry['presented']['is_done'] || $entry['presented']['is_skipped']) {
            $doneLogs[] = $entry;
        } else {
            $openLogs[] = $entry;
        }
    }
@endphp

<section class="log-timeline" aria-labelledby="logTimelineHeading">
    <div class="log-timeline__head">
        <h2 class="log-timeline__heading" id="logTimelineHeading">รายการของวันนี้</h2>

        @if($logs->isNotEmpty())
            <div class="log-timeline__filters" role="group" aria-label="กรองตามประเภทงาน">
                <button type="button" class="log-filter is-active" data-kind-filter="all">
                    ทั้งหมด <span class="log-filter__count">{{ $summary['total_count'] }}</span>
                </button>
                @foreach($summary['by_kind'] as $key => $bucket)
                    @continue($bucket['count'] === 0)
                    <button type="button" class="log-filter log-filter--{{ $bucket['tone'] }}" data-kind-filter="{{ $key }}">
                        {{ $bucket['label'] }} <span class="log-filter__count">{{ $bucket['count'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    @if($summary['overlaps'])
        {{-- ระบบยอมให้ช่วงเวลาซ้อนกันโดยตั้งใจ (งานจริงซ้อนกันได้) แต่บอกให้รู้ไว้ --}}
        <p class="log-timeline__hint" role="status">
            <i class="bi bi-layers" aria-hidden="true"></i>
            มีรายการที่ช่วงเวลาซ้อนกัน — เวลารวมอาจมากกว่าเวลาที่ผ่านไปจริง
        </p>
    @endif

    <div class="log-group" data-log-group="open">
        <h3 class="log-group__heading">
            <i class="bi bi-circle" aria-hidden="true"></i>
            ที่ต้องทำ
            <span class="log-group__count" data-group-count>{{ count($openLogs) }}</span>
        </h3>

        <div class="log-timeline__list" data-timeline-list>
            @foreach($openLogs as $entry)
                @include('daily-logs.components.log-card', [
                    'log' => $entry['log'],
                    'presented' => $entry['presented'],
                    'capabilities' => $capabilities,
                ])
            @endforeach
        </div>

        <p class="log-timeline__empty" data-group-empty @if(count($openLogs) > 0) hidden @endif>
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            @if($logs->isEmpty() && ($capabilities['canCreate'] ?? false))
                ยังไม่มีรายการของวันนี้ — ตั้งงานประจำไว้ล่วงหน้า หรือพิมพ์งานที่เพิ่งทำแล้วกดบันทึก
            @elseif($logs->isEmpty())
                ยังไม่มีบันทึกงานของวันนี้
            @else
                ทำครบทุกรายการของวันนี้แล้ว
            @endif
        </p>
    </div>

    <div class="log-group log-group--done" data-log-group="done">
        <h3 class="log-group__heading">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            ทำแล้ว
            <span class="log-group__count" data-group-count>{{ count($doneLogs) }}</span>
        </h3>

        <div class="log-timeline__list" data-timeline-done>
            @foreach($doneLogs as $entry)
                @include('daily-logs.components.log-card', [
                    'log' => $entry['log'],
                    'presented' => $entry['presented'],
                    'capabilities' => $capabilities,
                ])
            @endforeach
        </div>

        <p class="log-timeline__empty" data-group-empty @if(count($doneLogs) > 0) hidden @endif>
            <i class="bi bi-hourglass" aria-hidden="true"></i>
            ยังไม่มีรายการที่ยืนยันว่าทำเสร็จแล้ว
        </p>
    </div>
</section>

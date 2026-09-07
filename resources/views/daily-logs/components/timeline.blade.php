{{--
    รายการงานของวัน เรียงตามเวลาที่เริ่มทำ (รายการที่ไม่ระบุเวลาอยู่ท้ายสุด)

    ชิปกรองประเภทกรองบนโหนดที่ render แล้วฝั่ง client จึงไม่ต้องยิง request ใหม่
    จำนวนในชิปมาจาก summary ชุดเดียวกับกล่องสรุป เพื่อไม่ให้ตัวเลขสองที่ไม่ตรงกัน
--}}
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

    <div class="log-timeline__list" data-timeline-list>
        @forelse($logs as $log)
            @include('daily-logs.components.log-card', [
                'log' => $log,
                'presented' => $presentedLogs[$loop->index],
                'capabilities' => $capabilities,
            ])
        @empty
            <p class="log-timeline__empty" data-timeline-empty>
                <i class="bi bi-clipboard" aria-hidden="true"></i>
                @if($capabilities['canCreate'])
                    ยังไม่มีบันทึกงานของวันนี้ — พิมพ์งานที่ทำแล้วกด บันทึกงาน ได้เลย
                @else
                    ยังไม่มีบันทึกงานของวันนี้
                @endif
            </p>
        @endforelse
    </div>
</section>

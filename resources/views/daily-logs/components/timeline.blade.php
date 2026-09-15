<section class="log-timeline" aria-labelledby="logTimelineHeading">
    <div class="log-timeline__head">
        <h2 class="log-timeline__heading" id="logTimelineHeading">
            <span class="log-timeline__heading-mark" aria-hidden="true"></span>
            รายการงานวันนี้ <small data-timeline-count>({{ $logs->count() }} รายการ)</small>
        </h2>
        {{-- ไม่ห่อด้วย <label> เพราะดร็อปดาวน์แบบสไลด์วางปุ่มไว้ข้างใน
             คลิกตัวเลือกในแผงแล้ว label จะส่งคลิกต่อให้ปุ่มจนแผงเด้งเปิดซ้ำ --}}
        <div class="log-timeline__status-control">
            <label class="visually-hidden" for="logTimelineStatus">กรองตามสถานะ</label>
            <select class="form-select" id="logTimelineStatus" data-status-filter>
                <option value="all">ทุกสถานะ</option>
                <option value="open">รอเริ่ม</option>
                <option value="in_progress">กำลังทำ</option>
                <option value="done">เสร็จแล้ว</option>
                <option value="skipped">ไม่ได้ทำ</option>
            </select>
        </div>
    </div>
    <div class="log-timeline__scroll">
        <div class="log-timeline__columns" aria-hidden="true">
            <span>เวลา</span><span>ชื่องาน</span><span>ประเภท / หมวด</span><span>Project</span>
            <span>ผู้ร่วมงาน</span><span>สถานะ</span><span>Action</span>
        </div>
        <div class="log-timeline__list" data-timeline-list>
            @foreach($logs as $index => $log)
                @include('daily-logs.components.log-card', [
                    'log' => $log,
                    'presented' => $presentedLogs[$index],
                    'capabilities' => $capabilities,
                ])
            @endforeach
        </div>
    </div>
    <p class="log-timeline__empty" data-timeline-empty @unless($logs->isEmpty()) hidden @endunless>
        ยังไม่มีรายการของวันนี้
    </p>
</section>

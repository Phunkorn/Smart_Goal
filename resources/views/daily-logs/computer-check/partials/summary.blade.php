{{--
    สรุปผลการตรวจของรอบที่เปิดอยู่ — ตัวเลข รายการปัญหา และปุ่มปิดรอบ

    ปุ่ม "บันทึกผลการตรวจ" อยู่ที่นี่ที่เดียว ไม่ซ้ำกับแท็บอื่น เพื่อให้การปิดรอบ
    เป็นการกระทำที่ต้องตั้งใจเข้ามาทำ ไม่ใช่ปุ่มที่กดโดนระหว่างเดินตรวจ

    @param array $summary
    @param array $issues
    @param \App\Models\ComputerCheckSession|null $session
    @param array $capabilities
--}}
<div class="cc-summary">
    <div class="cc-summary__grid">
        <div class="cc-summary__card">
            <span class="cc-summary__label">จำนวนเครื่องทั้งหมด</span>
            <strong class="cc-summary__value" data-cc-stat="total">{{ $summary['total'] }}</strong>
        </div>
        <div class="cc-summary__card">
            <span class="cc-summary__label">ตรวจแล้ว</span>
            <strong class="cc-summary__value" data-cc-stat="checked">{{ $summary['checked'] }}</strong>
        </div>
        <div class="cc-summary__card cc-summary__card--normal">
            <span class="cc-summary__label">ปกติ</span>
            <strong class="cc-summary__value" data-cc-stat="normal">{{ $summary['normal'] }}</strong>
        </div>
        <div class="cc-summary__card cc-summary__card--issue">
            <span class="cc-summary__label">มีปัญหา</span>
            <strong class="cc-summary__value" data-cc-stat="issue">{{ $summary['issue'] }}</strong>
        </div>
    </div>

    <section class="cc-summary__issues" aria-labelledby="ccIssueHeading">
        <h3 class="cc-summary__heading" id="ccIssueHeading">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> รายการปัญหา
        </h3>

        <ul class="cc-issue-list" data-cc-issue-list>
            @forelse($issues as $issue)
                <li class="cc-issue">
                    <strong class="cc-issue__code">{{ $issue['code'] }}</strong>
                    <span class="cc-issue__devices">{{ implode(', ', $issue['devices']) }}</span>
                    <span class="cc-issue__note">{{ $issue['note'] }}</span>
                </li>
            @empty
                <li class="cc-issue cc-issue--empty" data-cc-issue-empty>ยังไม่พบปัญหาในรอบนี้</li>
            @endforelse
        </ul>
    </section>

    @if($session !== null)
        <footer class="cc-summary__foot">
            @if($session->isCompleted())
                <p class="cc-summary__done">
                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                    บันทึกผลการตรวจแล้ว — รอบนี้แก้ไขย้อนหลังไม่ได้
                </p>
                <a class="btn btn-outline-primary" href="{{ route('daily-logs.computer-check.report', $session) }}" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i> ดูแบบตรวจ
                </a>
            @elseif($capabilities['canComplete'])
                <p class="cc-summary__hint">ต้องตรวจครบทุกเครื่องก่อนจึงบันทึกผลได้</p>
                <button type="button" class="btn btn-primary" data-cc-complete>
                    <i class="bi bi-save" aria-hidden="true"></i> บันทึกผลการตรวจ
                </button>
            @else
                <p class="cc-summary__hint">รอบนี้เปิดโดยผู้ตรวจคนอื่น คุณดูผลได้แต่แก้ไขไม่ได้</p>
            @endif

            <p class="cc-panel__error" role="alert" data-cc-complete-error hidden></p>
        </footer>
    @endif
</div>

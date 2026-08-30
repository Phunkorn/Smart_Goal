<article class="report-panel report-dashboard-card report-dashboard-card--attention" aria-labelledby="attention-title">
    <div class="report-panel__heading"><div><h2 id="attention-title">งานที่ต้องติดตาม</h2><p>งานล่าช้าหรือครบกำหนดภายใน 3 วัน</p></div><span class="report-panel__period">สูงสุด 10 งาน</span></div>
    <div class="report-attention__list">
        @forelse($attentionJobs as $job)
            <article class="report-attention__item">
                <span class="report-attention__state {{ $job['is_overdue'] ? 'is-overdue' : 'is-due-soon' }}"><i class="bi {{ $job['is_overdue'] ? 'bi-exclamation-circle-fill' : 'bi-clock-fill' }}" aria-hidden="true"></i></span>
                <div class="report-attention__body"><a href="{{ $job['url'] }}">{{ $job['topic'] }}</a><div><span>{{ $job['assignee'] }}</span><span>{{ $job['department'] }}</span></div></div>
                <span class="report-priority report-tone-{{ $job['priority']['tone'] }}"><i class="bi bi-flag-fill" aria-hidden="true"></i>{{ $job['priority']['label'] }}</span>
                <time datetime="{{ $job['due_at']?->toIso8601String() }}" class="{{ $job['is_overdue'] ? 'is-overdue' : '' }}">{{ $job['due_at']?->locale('th')->translatedFormat('j M Y') ?? 'ไม่ระบุกำหนด' }}</time>
            </article>
        @empty
            <div class="report-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>ไม่มีงานที่ต้องติดตาม</strong><span>ไม่พบงานล่าช้าหรือใกล้ครบกำหนดตามตัวกรอง</span></div>
        @endforelse
    </div>
</article>

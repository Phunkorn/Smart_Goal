@php
    use App\Support\AuditSnapshot;

    // ตัวกรองร่วมใช้ได้ทุกแท็บ ส่วนตัวกรองเฉพาะแท็บจะโผล่ต่อท้ายเฉพาะแท็บนั้น
    $activeFilters = collect([
        'q' => request('q') ? 'คำค้น: '.request('q') : null,
        'from' => request('from') ? 'ตั้งแต่ '.request('from') : null,
        'to' => request('to') ? 'ถึง '.request('to') : null,
        'user_id' => request('user_id')
            ? 'ผู้ทำรายการ: '.optional($users->firstWhere('id', (int) request('user_id')))->name
            : null,
        'action' => request('action') ? 'การกระทำ: '.AuditSnapshot::actionLabel(request('action')) : null,
        'subject_type' => request('subject_type') ? 'ประเภทข้อมูล: '.AuditSnapshot::subjectLabel(request('subject_type')) : null,
        'entity_type' => request('entity_type') ? 'ประเภท: '.AuditSnapshot::subjectLabel(request('entity_type')) : null,
        'department' => request('department') ? 'แผนก: '.request('department') : null,
    ])->filter();
@endphp

<form method="GET" action="{{ route('admin.audit.index') }}" class="audit-filters" data-audit-filters>
    <input type="hidden" name="tab" value="{{ $tab }}">

    <div class="audit-filters__grid">
        <label class="audit-field audit-field--wide">
            <span>ค้นหา</span>
            <input type="search" name="q" value="{{ request('q') }}"
                   placeholder="ชื่อผู้ใช้ รายละเอียด หมายเลข IP หรือรหัสรายการ">
        </label>

        <label class="audit-field">
            <span>ตั้งแต่วันที่</span>
            <input type="date" name="from" value="{{ request('from') }}">
        </label>

        <label class="audit-field">
            <span>ถึงวันที่</span>
            <input type="date" name="to" value="{{ request('to') }}">
        </label>

        <label class="audit-field">
            <span>ผู้ทำรายการ</span>
            <select name="user_id">
                <option value="">ทุกคน</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </label>

        @if ($tab === 'activity')
            <label class="audit-field">
                <span>ประเภทการกระทำ</span>
                <select name="action">
                    <option value="">ทั้งหมด</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ AuditSnapshot::actionLabel($action) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="audit-field">
                <span>ประเภทข้อมูล</span>
                <select name="subject_type">
                    <option value="">ทั้งหมด</option>
                    @foreach ($subjectTypes as $subjectType)
                        <option value="{{ $subjectType }}" @selected(request('subject_type') === $subjectType)>{{ AuditSnapshot::subjectLabel($subjectType) }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($tab === 'trash')
            <label class="audit-field">
                <span>ประเภทข้อมูล</span>
                <select name="entity_type">
                    <option value="">ทั้งหมด</option>
                    @foreach ($entityTypes as $entityType)
                        <option value="{{ $entityType }}" @selected(request('entity_type') === $entityType)>{{ AuditSnapshot::subjectLabel($entityType) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="audit-field">
                <span>แผนก</span>
                <select name="department">
                    <option value="">ทุกแผนก</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department }}" @selected(request('department') === $department)>{{ $department }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <div class="audit-field audit-field--actions">
            <button class="audit-btn audit-btn--primary" type="submit">
                <i class="bi bi-funnel" aria-hidden="true"></i> กรอง
            </button>
        </div>
    </div>

    @if ($activeFilters->isNotEmpty())
        <div class="audit-active-filters">
            <span class="audit-active-filters__label">กำลังกรอง</span>
            @foreach ($activeFilters as $label)
                <span class="audit-chip">{{ $label }}</span>
            @endforeach
            <a class="audit-chip audit-chip--reset" href="{{ route('admin.audit.index', ['tab' => $tab]) }}">
                <i class="bi bi-x-lg" aria-hidden="true"></i> ล้างตัวกรอง
            </a>
        </div>
    @endif
</form>

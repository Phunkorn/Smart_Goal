@extends('layouts.app')

@section('title', 'ถังขยะและข้อมูลที่ถูกลบ')

@push('styles')
<style>
    .trash-page { display:grid; gap:18px; }
    .trash-head { display:flex; justify-content:space-between; gap:14px; align-items:flex-end; flex-wrap:wrap; }
    .trash-kicker { display:inline-flex; align-items:center; gap:8px; background:#fee2e2; color:#dc2626; border-radius:999px; padding:6px 12px; font-weight:850; }
    .trash-head h1 { margin:10px 0 4px; font-size:32px; font-weight:900; }
    .trash-head p { margin:0; color:var(--text-muted); max-width:760px; }
    .trash-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .trash-btn { min-height:42px; border:1px solid var(--border); border-radius:12px; background:#fff; color:var(--text); font-weight:850; padding:0 14px; display:inline-flex; align-items:center; gap:8px; text-decoration:none; }
    .trash-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
    .trash-btn.restore { background:#dcfce7; color:#15803d; border-color:#bbf7d0; }
    .trash-stats { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .stat-card, .filter-card, .trash-card { background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow-sm); }
    .stat-card { padding:16px; }
    .stat-card span { color:var(--text-muted); font-weight:800; }
    .stat-card strong { display:block; font-size:28px; margin-top:4px; }
    .filter-card { padding:14px; }
    .filter-grid { display:grid; grid-template-columns:1.2fr 190px 190px 190px auto; gap:10px; align-items:end; }
    .filter-grid label { display:grid; gap:6px; font-weight:800; color:var(--text-muted); font-size:13px; }
    .filter-grid input, .filter-grid select { min-height:42px; border:1px solid var(--border); border-radius:12px; padding:0 12px; font:inherit; background:#fff; }
    .filter-btn { min-height:42px; border:0; border-radius:12px; background:var(--accent); color:#fff; font-weight:850; padding:0 16px; }
    .trash-card { overflow:hidden; }
    .trash-table { width:100%; border-collapse:collapse; }
    .trash-table th, .trash-table td { padding:14px; border-bottom:1px solid var(--border); vertical-align:top; text-align:left; }
    .trash-table th { background:var(--surface-2); color:var(--text-muted); font-size:13px; font-weight:850; white-space:nowrap; }
    .entity-pill, .danger-pill, .ok-pill { display:inline-flex; border-radius:999px; padding:4px 10px; font-weight:850; font-size:12px; }
    .entity-pill { background:#eef2ff; color:#4f46e5; }
    .danger-pill { background:#fee2e2; color:#dc2626; }
    .ok-pill { background:#dcfce7; color:#15803d; }
    .trash-title { font-weight:900; color:var(--text); }
    .trash-muted { color:var(--text-muted); font-size:13px; }
    .json-box { margin-top:8px; max-height:220px; overflow:auto; background:#0f172a; color:#dbeafe; border-radius:10px; padding:10px; font-size:12px; }
    .empty-state { padding:44px 18px; text-align:center; color:var(--text-muted); }
    .row-actions { display:flex; gap:8px; flex-wrap:wrap; }
    @media (max-width:1100px) {
        .trash-stats { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .filter-grid { grid-template-columns:1fr; }
        .trash-card { overflow-x:auto; }
    }
    @media (max-width:640px) {
        .trash-stats { grid-template-columns:1fr; }
        .trash-head h1 { font-size:26px; }
    }
</style>
@endpush

@section('content')
<div class="trash-page">
    <section class="trash-head">
        <div>
            <span class="trash-kicker"><i class="bi bi-trash3"></i> ถังขยะ 30 วัน</span>
            <h1>ข้อมูลที่ถูกลบ</h1>
            <p>ดูว่าใครลบอะไร ลบจากแผนกไหน เหลืออีกกี่วันก่อนลบถาวร และสามารถกู้คืนได้ก่อนครบกำหนด</p>
        </div>
        <div class="trash-actions">
            <a class="trash-btn" href="{{ route('admin.trash.export', request()->query()) }}">
                <i class="bi bi-filetype-csv"></i> Export CSV
            </a>
        </div>
    </section>

    <section class="trash-stats">
        <div class="stat-card"><span>รายการที่พบ</span><strong>{{ $stats['total'] }}</strong></div>
        <div class="stat-card"><span>งานที่ถูกลบ</span><strong>{{ $stats['work_orders'] }}</strong></div>
        <div class="stat-card"><span>พนักงานที่ถูกลบ</span><strong>{{ $stats['users'] }}</strong></div>
        <div class="stat-card"><span>ครบกำหนดลบถาวร</span><strong>{{ $stats['expired'] }}</strong></div>
    </section>

    <form method="GET" action="{{ route('admin.trash.index') }}" class="filter-card">
        <div class="filter-grid">
            <label>
                ค้นหา
                <input type="search" name="q" value="{{ request('q') }}" placeholder="ชื่อพนักงาน ชื่องาน ID หรือข้อความ">
            </label>
            <label>
                ประเภท
                <select name="entity_type">
                    <option value="">ทั้งหมด</option>
                    @foreach ($entityTypes as $entityType)
                        <option value="{{ $entityType }}" @selected(request('entity_type') === $entityType)>{{ class_basename($entityType) }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                แผนก
                <select name="department">
                    <option value="">ทุกแผนก</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department }}" @selected(request('department') === $department)>{{ $department }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                ผู้ลบ
                <select name="deleted_by">
                    <option value="">ทุกคน</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) request('deleted_by') === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="filter-btn" type="submit"><i class="bi bi-search"></i> ค้นหา</button>
        </div>
    </form>

    <section class="trash-card">
        @if ($trashLogs->isEmpty())
            <div class="empty-state">ยังไม่มีข้อมูลในถังขยะ</div>
        @else
            <table class="trash-table">
                <thead>
                    <tr>
                        <th>ข้อมูลที่ถูกลบ</th>
                        <th>แผนก</th>
                        <th>ผู้ลบ</th>
                        <th>วันที่ลบ</th>
                        <th>ลบถาวรใน</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($trashLogs as $trash)
                        @php($summary = $trash->summary)
                        <tr>
                            <td>
                                <span class="entity-pill">{{ $summary['entity_label'] }}</span>
                                <div class="trash-title">{{ $summary['name'] }}</div>
                                <div class="trash-muted">ID #{{ $trash->entity_id }}</div>
                            </td>
                            <td>
                                <strong>{{ $summary['department'] }}</strong>
                            </td>
                            <td>
                                <div class="trash-title">{{ $trash->deletedBy?->name ?? 'ระบบ' }}</div>
                                <div class="trash-muted">{{ $trash->deletedBy?->email }}</div>
                            </td>
                            <td>
                                <strong>{{ optional($trash->deleted_at)->format('d/m/Y H:i') }}</strong>
                            </td>
                            <td>
                                @if ($summary['days_left'] === null)
                                    <span class="entity-pill">ไม่กำหนด</span>
                                @elseif ($summary['days_left'] <= 0)
                                    <span class="danger-pill">ครบกำหนดแล้ว</span>
                                @else
                                    <span class="danger-pill">{{ $summary['days_left'] }} วัน</span>
                                    <div class="trash-muted">ถึง {{ optional($trash->purge_after)->format('d/m/Y') }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="row-actions">
                                    @if ($summary['can_restore'])
                                        <form method="POST" action="{{ route('admin.trash.restore', $trash) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="trash-btn restore" type="submit" onclick="return confirm('ยืนยันกู้คืนข้อมูลนี้?')">
                                                <i class="bi bi-arrow-counterclockwise"></i> กู้คืน
                                            </button>
                                        </form>
                                    @endif
                                    <details>
                                        <summary class="trash-btn">ดู snapshot</summary>
                                        <pre class="json-box">{{ json_encode($trash->payload_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $trashLogs->links() }}</div>
        @endif
    </section>
</div>
@endsection

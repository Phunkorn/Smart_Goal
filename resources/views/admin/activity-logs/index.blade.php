@extends('layouts.app')

@section('title', 'บันทึกระบบ')

@push('styles')
<style>
    .admin-log-page { display:grid; gap:18px; }
    .admin-log-head { display:flex; justify-content:space-between; gap:14px; align-items:flex-end; flex-wrap:wrap; }
    .admin-log-kicker { display:inline-flex; align-items:center; gap:8px; background:var(--accent-dim); color:var(--accent-strong); border-radius:999px; padding:6px 12px; font-weight:800; }
    .admin-log-head h1 { margin:10px 0 4px; font-size:34px; font-weight:900; }
    .admin-log-head p { margin:0; color:var(--text-muted); }
    .filter-card, .log-card { background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow-sm); }
    .filter-card { padding:14px; }
    .filter-grid { display:grid; grid-template-columns:1fr 190px 220px auto; gap:10px; align-items:end; }
    .filter-grid label { display:grid; gap:6px; font-weight:800; color:var(--text-muted); font-size:13px; }
    .filter-grid input, .filter-grid select { min-height:42px; border:1px solid var(--border); border-radius:12px; padding:0 12px; font:inherit; background:#fff; }
    .filter-btn { min-height:42px; border:0; border-radius:12px; background:var(--accent); color:#fff; font-weight:850; padding:0 16px; }
    .log-table { width:100%; border-collapse:collapse; }
    .log-table th, .log-table td { padding:13px 14px; border-bottom:1px solid var(--border); vertical-align:top; }
    .log-table th { background:var(--surface-2); color:var(--text-muted); font-size:13px; font-weight:850; }
    .log-action { display:inline-flex; border-radius:999px; background:var(--blue-dim); color:#1d4ed8; padding:3px 9px; font-weight:850; font-size:12px; }
    .log-desc { font-weight:800; }
    .log-muted { color:var(--text-muted); font-size:13px; }
    .json-box { margin-top:8px; max-height:180px; overflow:auto; background:#0f172a; color:#dbeafe; border-radius:10px; padding:10px; font-size:12px; }
    .empty-state { padding:44px 18px; text-align:center; color:var(--text-muted); }
    @media (max-width:900px) { .filter-grid { grid-template-columns:1fr; } .admin-log-head h1 { font-size:28px; } .log-card { overflow-x:auto; } }
</style>
@endpush

@section('content')
<div class="admin-log-page">
    <section class="admin-log-head">
        <div>
            <span class="admin-log-kicker"><i class="bi bi-clock-history"></i> Audit Log</span>
            <h1>บันทึกระบบ</h1>
            <p>ดูประวัติการทำงานของระบบ เช่น การสร้าง แก้ไข ลบ และเปลี่ยนสถานะ</p>
        </div>
    </section>

    <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="filter-card">
        <div class="filter-grid">
            <label>
                ค้นหา
                <input type="search" name="q" value="{{ request('q') }}" placeholder="ค้นหารายละเอียด, object, IP">
            </label>
            <label>
                Action
                <select name="action">
                    <option value="">ทั้งหมด</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                ผู้ใช้งาน
                <select name="user_id">
                    <option value="">ทั้งหมด</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="filter-btn" type="submit"><i class="bi bi-search"></i> ค้นหา</button>
        </div>
    </form>

    <section class="log-card">
        @if ($logs->isEmpty())
            <div class="empty-state">ยังไม่มีบันทึกระบบ</div>
        @else
            <table class="log-table">
                <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>ผู้ทำรายการ</th>
                        <th>Action</th>
                        <th>รายละเอียด</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td>
                                <strong>{{ optional($log->created_at)->format('d/m/Y H:i') }}</strong>
                            </td>
                            <td>
                                <div class="log-desc">{{ $log->user?->name ?? 'ระบบ' }}</div>
                                <div class="log-muted">{{ $log->user?->email }}</div>
                            </td>
                            <td><span class="log-action">{{ $log->action }}</span></td>
                            <td>
                                <div class="log-desc">{{ $log->description ?? '-' }}</div>
                                <div class="log-muted">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</div>
                                @if ($log->changes)
                                    <details>
                                        <summary class="log-muted">ดูข้อมูล before/after</summary>
                                        <pre class="json-box">{{ json_encode($log->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="log-muted">{{ $log->ip_address ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $logs->links() }}</div>
        @endif
    </section>
</div>
@endsection

@extends('layouts.app')

@section('title', 'ถังขยะและข้อมูลที่ถูกลบ')

@push('styles')
    @vite('resources/css/pages/admin-trash.css')
@endpush

@section('content')
@php
    $entityTypeLabels = [
        \App\Models\User::class => 'พนักงาน',
        \App\Models\WorkOrder::class => 'งาน',
        \App\Models\WorkOrderList::class => 'โปรเจกต์',
    ];
@endphp
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
        <div class="stat-card"><span>งานและโปรเจกต์ที่ถูกลบ</span><strong>{{ $stats['work_items'] }}</strong></div>
        <div class="stat-card"><span>พนักงานที่ถูกลบ</span><strong>{{ $stats['users'] }}</strong></div>
        <div class="stat-card stat-card--warning"><span>ใกล้ถูกลบถาวร</span><strong>{{ $stats['near_expiry'] }}</strong><small>ภายใน 7 วัน</small></div>
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
                        <option value="{{ $entityType }}" @selected(request('entity_type') === $entityType)>{{ $entityTypeLabels[$entityType] ?? class_basename($entityType) }}</option>
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
                                <div class="trash-title">{{ $summary['name'] }}</div>
                                <div class="trash-item-meta">
                                    <span class="entity-pill">{{ $summary['entity_label'] }}</span>
                                    <span class="trash-muted">ID #{{ $trash->entity_id }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="trash-context">{{ $summary['department'] }}</span>
                            </td>
                            <td>
                                <div class="trash-title">{{ $trash->deletedBy?->name ?? 'ระบบ' }}</div>
                                <div class="trash-muted">{{ $trash->deletedBy?->email ?: ($trash->deletedBy?->username ? '@'.$trash->deletedBy->username : '') }}</div>
                            </td>
                            <td>
                                <time class="trash-date" datetime="{{ optional($trash->deleted_at)->toIso8601String() }}">{{ optional($trash->deleted_at)->format('d/m/Y H:i') }}</time>
                            </td>
                            <td>
                                @if ($summary['days_left'] === null)
                                    <span class="retention-pill retention-pill--neutral">ไม่กำหนด</span>
                                @elseif ($summary['days_left'] <= 0)
                                    <span class="retention-pill retention-pill--danger">ครบกำหนดแล้ว</span>
                                @elseif ($summary['days_left'] <= 7)
                                    <span class="retention-pill retention-pill--warning">{{ $summary['days_left'] }} วัน</span>
                                    <div class="trash-muted">ถึง {{ optional($trash->purge_after)->format('d/m/Y') }}</div>
                                @else
                                    <span class="retention-pill retention-pill--neutral">{{ $summary['days_left'] }} วัน</span>
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
                                    <details class="snapshot-details">
                                        <summary class="trash-btn"><i class="bi bi-file-earmark-code"></i> ดู Snapshot</summary>
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

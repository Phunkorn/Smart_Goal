@extends('layouts.app')
@section('content')

@php
    $fieldLabels = [
        'id' => 'รหัส',
        'name' => 'ชื่อ',
        'email' => 'อีเมล',
        'phone' => 'เบอร์โทรศัพท์',
        'role' => 'สิทธิ์การใช้งาน',
        'department_id' => 'แผนก',
        'profile_image' => 'รูปโปรไฟล์',
        'must_change_password' => 'บังคับเปลี่ยนรหัสผ่าน',
        'email_verified_at' => 'ยืนยันอีเมลเมื่อ',
        'created_at' => 'สร้างเมื่อ',
        'updated_at' => 'แก้ไขล่าสุด',
        'deleted_at' => 'ลบเมื่อ',
    ];

    $roleLabels = [
        'admin' => 'ผู้ดูแลระบบ',
        'user' => 'พนักงาน',
        'viewer' => 'ผู้เข้าชม',
    ];

    $actionLabels = [
        'created' => 'สร้าง',
        'create' => 'สร้าง',
        'updated' => 'แก้ไข',
        'update' => 'แก้ไข',
        'deleted' => 'ลบ',
        'delete' => 'ลบ',
        'restored' => 'กู้คืน',
        'restore' => 'กู้คืน',
        'login' => 'เข้าสู่ระบบ',
        'logout' => 'ออกจากระบบ',
    ];

    $subjectLabels = [
        'User' => 'พนักงาน',
        'Employee' => 'พนักงาน',
        'Department' => 'แผนก',
        'Task' => 'งาน',
        'Job' => 'โปรเจกต์',
        'Subtask' => 'งานย่อย',
    ];

    $formatLogValue = function ($field, $value) use ($roleLabels) {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($field === 'role') {
            return $roleLabels[$value] ?? $value;
        }

        if ($field === 'must_change_password') {
            return (bool) $value ? 'ใช่' : 'ไม่ใช่';
        }

        if (in_array($field, [
            'created_at',
            'updated_at',
            'deleted_at',
            'email_verified_at'
        ])) {
            try {
                return \Carbon\Carbon::parse($value)
                    ->timezone(config('app.timezone'))
                    ->format('d/m/Y H:i');
            } catch (\Throwable $e) {
                return $value;
            }
        }

        if (is_bool($value)) {
            return $value ? 'ใช่' : 'ไม่ใช่';
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return $value;
    };
@endphp

<div class="admin-log-page">
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

    .log-change-trigger {
        margin-top: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid var(--border);
        border-radius: 999px;
        background: #f1f5f9;
        color: var(--text-muted);
        font-size: 13px;
        font-weight: 850;
        padding: 6px 12px;
        cursor: pointer;
    }
    .log-change-trigger:hover {
        background: var(--blue-dim);
        color: #1d4ed8;
    }
    .log-modal .modal-content {
        border: 1px solid var(--border);
        border-radius: 16px;
        overflow: hidden;
    }
    .log-modal .modal-header {
        background: var(--surface-2);
        border-bottom: 1px solid var(--border);
    }
    .log-modal .modal-title {
        font-weight: 850;
        font-size: 16px;
    }
    .log-change-content {
        padding: 4px 2px;
        display: grid;
        gap: 9px;
    }
    .log-avatar-group {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .log-avatar {
        width: 56px;
        height: 56px;
        border-radius: 10px;
        object-fit: cover;
        border: 1px solid var(--border);
        background: #f1f5f9;
    }
    .log-avatar-old {
        outline: 2px solid #fecaca;
    }
    .log-avatar-new {
        outline: 2px solid #bbf7d0;
    }
    .log-change-row {
        display: grid;
        grid-template-columns: 150px minmax(0, 1fr);
        gap: 12px;
    }
    .log-change-field {
        color: var(--text-muted);
        font-weight: 800;
    }
    .log-change-value {
        word-break: break-word;
    }
    .log-value-old {
        color: #b91c1c;
        background: #fee2e2;
        padding: 2px 7px;
        border-radius: 6px;
    }
    .log-value-new {
        color: #166534;
        background: #dcfce7;
        padding: 2px 7px;
        border-radius: 6px;
    }
    .log-change-arrow {
        margin: 0 6px;
        color: var(--text-muted);
    }
    .log-action.created,
    .log-action.create {
        background: #dcfce7;
        color: #166534;
    }
    .log-action.updated,
    .log-action.update {
        background: #dbeafe;
        color: #1d4ed8;
    }
    .log-action.deleted,
    .log-action.delete {
        background: #fee2e2;
        color: #b91c1c;
    }
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
                        <!-- <th>IP</th> -->
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
                            <td>
                                @php
                                    $actionKey = strtolower($log->action);
                                @endphp
                                <span class="log-action {{ $actionKey }}">
                                    {{ $actionLabels[$actionKey] ?? $log->action }}
                                </span>
                            </td>
                            <td>
                                @php
                                    $changes = is_array($log->changes)
                                        ? $log->changes
                                        : json_decode($log->changes ?? '[]', true);
                                    $before = $changes['before'] ?? $changes['old'] ?? [];
                                    $after = $changes['after'] ?? $changes['new'] ?? [];
                                    $actionKey = strtolower($log->action);
                                    $subjectClass = class_basename($log->subject_type);
                                    $subjectLabel = $subjectLabels[$subjectClass] ?? $subjectClass;
                                    $actionLabel = $actionLabels[$actionKey] ?? $log->action;
                                    $hiddenFields = [
                                        'password',
                                        'remember_token',
                                        'updated_at',
                                    ];
                                    $displayFields = collect(array_unique(array_merge(
                                        array_keys($before),
                                        array_keys($after)
                                    )))->reject(fn ($field) => in_array($field, $hiddenFields));
                                @endphp
                                <div class="log-desc">
                                    {{ $log->user?->name ?? 'ระบบ' }}
                                    {{ $actionLabel }}{{ $subjectLabel }}
                                </div>
                                @if (!empty($after['name']) || !empty($before['name']))
                                    <div class="log-muted">
                                        ชื่อรายการ: {{ $after['name'] ?? $before['name'] }}
                                    </div>
                                @endif
                                @if ($displayFields->isNotEmpty())
                                    <button type="button" class="log-change-trigger" data-bs-toggle="modal" data-bs-target="#logModal{{ $log->id }}">
                                        <i class="bi bi-eye"></i>
                                        ดูรายละเอียด
                                    </button>

                                    <div class="modal fade log-modal" id="logModal{{ $log->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-scrollable modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">
                                                        {{ $log->user?->name ?? 'ระบบ' }}
                                                        {{ $actionLabel }}{{ $subjectLabel }}
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="log-change-content">
                                                        @foreach ($displayFields as $field)
                                                            @php
                                                                $oldValue = $before[$field] ?? null;
                                                                $newValue = $after[$field] ?? null;
                                                                $formattedOld = $formatLogValue($field, $oldValue);
                                                                $formattedNew = $formatLogValue($field, $newValue);
                                                                $fieldLabel = $fieldLabels[$field]
                                                                    ?? str_replace('_', ' ', ucfirst($field));
                                                                $isImageField = $field === 'profile_image';
                                                            @endphp
                                                            @if (in_array($actionKey, ['deleted', 'delete']))
                                                                @if (array_key_exists($field, $before))
                                                                    <div class="log-change-row">
                                                                        <div class="log-change-field">{{ $fieldLabel }}</div>
                                                                        <div class="log-change-value">
                                                                            @if ($isImageField && $oldValue)
                                                                                <img src="{{ asset('storage/' . $oldValue) }}" alt="profile" class="log-avatar">
                                                                            @else
                                                                                {{ $formattedOld }}
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @elseif (in_array($actionKey, ['created', 'create']))
                                                                @if (array_key_exists($field, $after))
                                                                    <div class="log-change-row">
                                                                        <div class="log-change-field">{{ $fieldLabel }}</div>
                                                                        <div class="log-change-value">
                                                                            @if ($isImageField && $newValue)
                                                                                <img src="{{ asset('storage/' . $newValue) }}" alt="profile" class="log-avatar">
                                                                            @else
                                                                                {{ $formattedNew }}
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @elseif ($formattedOld !== $formattedNew)
                                                                <div class="log-change-row">
                                                                    <div class="log-change-field">{{ $fieldLabel }}</div>
                                                                    <div class="log-change-value">
                                                                        @if ($isImageField)
                                                                            <div class="log-avatar-group">
                                                                                @if ($oldValue)
                                                                                    <img src="{{ asset('storage/' . $oldValue) }}" alt="old profile" class="log-avatar log-avatar-old">
                                                                                @else
                                                                                    <span class="log-value-old">{{ $formattedOld }}</span>
                                                                                @endif
                                                                                <i class="bi bi-arrow-right log-change-arrow"></i>
                                                                                @if ($newValue)
                                                                                    <img src="{{ asset('storage/' . $newValue) }}" alt="new profile" class="log-avatar log-avatar-new">
                                                                                @else
                                                                                    <span class="log-value-new">{{ $formattedNew }}</span>
                                                                                @endif
                                                                            </div>
                                                                        @else
                                                                            <span class="log-value-old">{{ $formattedOld }}</span>
                                                                            <span class="log-change-arrow">
                                                                                <i class="bi bi-arrow-right"></i>
                                                                            </span>
                                                                            <span class="log-value-new">{{ $formattedNew }}</span>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </td>
                            <!-- <td class="log-muted">{{ $log->ip_address ?? '-' }}</td> -->
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $logs->links() }}</div>
        @endif
    </section>
</div>
@endsection
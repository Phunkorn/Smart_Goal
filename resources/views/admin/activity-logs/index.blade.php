@extends('layouts.app')

@php
    $fieldLabels = [
        'id' => 'รหัส',
        'name' => 'ชื่อ',
        'username' => 'ชื่อผู้ใช้',
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
        'title' => 'ชื่องาน',
        'description' => 'รายละเอียด',
        'detail' => 'รายละเอียด',
        'comment' => 'ความคิดเห็น',
        'job_status' => 'สถานะงาน',
        'status' => 'สถานะ',
        'priority_level' => 'ความสำคัญ',
        'due_date' => 'กำหนดส่ง',
        'progress' => 'ความคืบหน้า',
        'project_leader_id' => 'หัวหน้าโปรเจกต์',
        'delete_request_reason' => 'เหตุผลขอลบ',
        'delete_requested_at' => 'วันที่ส่งคำขอลบ',
        'delete_rejected_reason' => 'เหตุผลปฏิเสธ',
        'attachments' => 'ไฟล์อ้างอิงงาน',
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
        'status_changed' => 'เปลี่ยนสถานะงาน',
        'priority_changed' => 'เปลี่ยนความสำคัญ',
        'due_date_changed' => 'เปลี่ยนกำหนดส่ง',
        'progress_updated' => 'เพิ่มความคิดเห็น/อัปเดตงาน',
        'attachments_uploaded' => 'เพิ่มไฟล์อ้างอิงงาน',
        'delete_requested' => 'ส่งคำขอลบงาน',
        'delete_request_rejected' => 'ปฏิเสธคำขอลบงาน',
        'approval_updated' => 'อัปเดตการอนุมัติ',
        'collaborator_added' => 'เพิ่มผู้ร่วมโปรเจกต์',
        'collaborator_removed' => 'นำผู้ร่วมโปรเจกต์ออก',
        'project_leader_assigned' => 'กำหนดหัวหน้าโปรเจกต์',
    ];

    $subjectLabels = [
        'User' => 'พนักงาน',
        'Employee' => 'พนักงาน',
        'Department' => 'แผนก',
        'Task' => 'งาน',
        'Job' => 'โปรเจกต์',
        'Subtask' => 'งานย่อย',
        'WorkOrder' => 'รายการงาน',
        'WorkOrderList' => 'โปรเจกต์',
    ];

    $statusLabels = [
        1 => 'รอดำเนินการ',
        2 => 'กำลังดำเนินการ',
        3 => 'พักงาน',
        4 => 'เสร็จสิ้น',
        5 => 'ล่าช้า',
    ];

    $priorityLabels = [
        'low' => 'ต่ำ',
        'normal' => 'ปกติ',
        'medium' => 'ปกติ',
        'high' => 'สูง',
        'critical' => 'เร่งด่วน',
    ];

    $sensitiveFields = ['password', 'remember_token'];
    $redactSensitiveValues = function ($value) use (&$redactSensitiveValues, $sensitiveFields) {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = in_array((string) $key, $sensitiveFields, true)
                ? '[REDACTED]'
                : $redactSensitiveValues($nestedValue);
        }

        return $value;
    };

    $formatLogValue = function ($field, $value) use ($roleLabels, $statusLabels, $priorityLabels, $redactSensitiveValues) {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($field === 'role') {
            return $roleLabels[$value] ?? $value;
        }

        if ($field === 'must_change_password') {
            return (bool) $value ? 'ใช่' : 'ไม่ใช่';
        }

        if ($field === 'job_status' || $field === 'status') {
            return $statusLabels[$value] ?? $value;
        }

        if ($field === 'priority_level') {
            return $priorityLabels[strtolower((string) $value)] ?? $value;
        }

        if (in_array($field, [
            'created_at',
            'updated_at',
            'deleted_at',
            'email_verified_at',
            'due_date',
            'delete_requested_at',
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
            return json_encode(
                $redactSensitiveValues($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return $value;
    };

    $resolveTargetName = function (array $changes, ?string $description): ?string {
        $snapshots = [
            $changes['after'] ?? [],
            $changes['new'] ?? [],
            $changes['before'] ?? [],
            $changes['old'] ?? [],
            $changes,
        ];

        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            foreach (['name', 'job_topic', 'title'] as $field) {
                $value = $snapshot[$field] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return \Illuminate\Support\Str::limit(trim((string) $value), 110);
                }
            }
        }

        return filled($description)
            ? \Illuminate\Support\Str::limit(trim($description), 110)
            : null;
    };
@endphp

@section('title', 'บันทึกระบบ')

@push('styles')
    @vite('resources/css/pages/admin-activity-logs.css')
@endpush

@section('content')
<div class="admin-log-page">
    <section class="admin-log-head">
        <div>
            <span class="admin-log-kicker"><i class="bi bi-clock-history"></i> บันทึกการใช้งาน</span>
            <h1>บันทึกระบบ</h1>
            <p>ดูประวัติการทำงานของระบบ เช่น การสร้าง แก้ไข ลบ และเปลี่ยนสถานะ</p>
        </div>
    </section>

    <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="filter-card">
        <div class="filter-grid">
            <label>
                ค้นหา
                <input type="search" name="q" value="{{ request('q') }}" placeholder="ค้นหารายละเอียด, ประเภทข้อมูล, IP">
            </label>
            <label>
                ประเภทการทำรายการ
                <select name="action">
                    <option value="">ทั้งหมด</option>
                    @foreach ($actions as $action)
                        @php $actionKey = strtolower($action); @endphp
                        <option value="{{ $action }}" @selected(request('action') === $action)>
                            {{ $actionLabels[$actionKey] ?? $action }}
                        </option>
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
            <label>
                ประเภทข้อมูล
                <select name="subject_type">
                    <option value="">ทั้งหมด</option>
                    @foreach ($subjectTypes as $subjectType)
                        @php $subjectClass = class_basename($subjectType); @endphp
                        <option value="{{ $subjectType }}" @selected(request('subject_type') === $subjectType)>
                            {{ $subjectLabels[$subjectClass] ?? $subjectClass }}
                        </option>
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
                        <th>การกระทำ</th>
                        <th>ประเภท / เป้าหมาย</th>
                        <th>รายละเอียด</th>
                        <th>ดูรายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        @php
                            $changes = is_array($log->changes)
                                ? $log->changes
                                : json_decode($log->changes ?? '[]', true);
                            $changes = is_array($changes) ? $changes : [];
                            $before = $changes['before'] ?? $changes['old'] ?? [];
                            $after = $changes['after'] ?? $changes['new'] ?? [];
                            $before = is_array($before) ? $before : [];
                            $after = is_array($after) ? $after : [];
                            $actionKey = strtolower($log->action);
                            $actionLabel = $actionLabels[$actionKey] ?? $log->action;
                            $subjectClass = $log->subject_type ? class_basename($log->subject_type) : null;
                            $subjectLabel = $subjectClass ? ($subjectLabels[$subjectClass] ?? $subjectClass) : 'ระบบ';
                            $targetName = $resolveTargetName($changes, $log->description);
                            $hiddenFields = [
                                'password',
                                'remember_token',
                                'updated_at',
                            ];
                            $displayFields = collect(array_unique(array_merge(
                                array_keys($before),
                                array_keys($after)
                            )))->reject(fn ($field) => in_array($field, $hiddenFields, true));
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ optional($log->created_at)->format('d/m/Y H:i') }}</strong>
                            </td>
                            <td>
                                <div class="log-desc">{{ $log->user?->name ?? 'ระบบ' }}</div>
                                <div class="log-muted">{{ $log->user?->email ?: ($log->user?->username ? '@'.$log->user->username : '') }}</div>
                            </td>
                            <td>
                                <span class="log-action {{ $actionKey }}">
                                    {{ $actionLabel }}
                                </span>
                            </td>
                            <td>
                                <span class="log-subject">{{ $subjectLabel }}</span>
                                @if ($targetName)
                                    <div class="log-target">{{ $targetName }}</div>
                                @endif
                                <div class="log-muted">{{ $log->subject_id ? 'ID #'.$log->subject_id : 'ไม่มีรหัสรายการ' }}</div>
                            </td>
                            <td>
                                <div class="log-summary">{{ $log->description ?: $actionLabel.' '.$subjectLabel }}</div>
                            </td>
                            <td class="log-detail-cell">
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
                                                        {{ $actionLabel }} {{ $targetName ?: $subjectLabel }}
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
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
                                                                                @include('admin.activity-logs.components.profile-image', ['path' => $oldValue, 'label' => 'รูปโปรไฟล์'])
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
                                                                                @include('admin.activity-logs.components.profile-image', ['path' => $newValue, 'label' => 'รูปโปรไฟล์'])
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
                                                                                    @include('admin.activity-logs.components.profile-image', ['path' => $oldValue, 'label' => 'รูปโปรไฟล์เดิม', 'extraClass' => 'log-avatar-old'])
                                                                                @else
                                                                                    <span class="log-value-old">{{ $formattedOld }}</span>
                                                                                @endif
                                                                                <i class="bi bi-arrow-right log-change-arrow"></i>
                                                                                @if ($newValue)
                                                                                    @include('admin.activity-logs.components.profile-image', ['path' => $newValue, 'label' => 'รูปโปรไฟล์ใหม่', 'extraClass' => 'log-avatar-new'])
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
                                @else
                                    <span class="log-muted">ไม่มีรายละเอียดเพิ่มเติม</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $logs->links() }}</div>
        @endif
    </section>
</div>
@endsection

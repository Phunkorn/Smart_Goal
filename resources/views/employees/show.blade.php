@extends('layouts.app')

@section('title', 'รายละเอียดพนักงาน')

@push('styles')
    @vite('resources/css/pages/employee-show.css')
@endpush

@section('content')

    @php
        $statusMeta = [
            1 => ['label' => 'รอเริ่ม', 'tone' => 'amber'],
            2 => ['label' => 'กำลังทำงาน', 'tone' => 'green'],
            3 => ['label' => 'ตรวจสอบ', 'tone' => 'blue'],
            4 => ['label' => 'เสร็จสิ้น', 'tone' => 'green'],
            5 => ['label' => 'พักงานชั่วคราว', 'tone' => 'gray'],
        ];

        // สถานะจากงานล่าสุด
        $lastJob = $user->jobs->last();
        $status = optional($lastJob)->job_status ?? 1;
        $statusInfo = $statusMeta[$status] ?? $statusMeta[1];

        // Initials จากชื่อจริง
        $initials = strtoupper(substr($user->name, 0, 2));

        // วันที่เข้าทำงาน
        $joined = $user->created_at
            ? \Carbon\Carbon::parse($user->created_at)->locale('th')->translatedFormat('M Y')
            : '-';
    @endphp

    <div class="page-head">
        <div>
            <span class="eyebrow">
                <a href="{{ route('employees.index') }}" class="employee-back-link">
                    <i class="bi bi-arrow-left me-1"></i>พนักงานทั้งหมด
                </a>
            </span>
            <h1>{{ $user->name }}</h1>
            <p>{{ $user->position ?? '-' }} · {{ optional($user->department)->department_name ?? '-' }}</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @if($user->role === 'user' && $user->is_active && is_null($user->deleted_at))
                <a href="{{ route('meetings.index', ['employee' => $user->id]) }}" class="btn-outline-line">
                    <i class="bi bi-calendar-event"></i> ดูการประชุม
                </a>
            @endif
            <span class="badge-soft {{ $statusInfo['tone'] }} employee-status-pill">
                <span class="status-dot"></span> {{ $statusInfo['label'] }}
            </span>
        </div>
    </div>

    <div class="row g-3">
        {{-- Left Panel --}}
        <div class="col-xl-4">
            <div class="panel mb-3 text-center">
<div class="avatar mx-auto mb-3 employee-show-avatar">

    @if($user->profile_image)
        <img
            src="{{ route('media.show', ['path' => $user->profile_image]) }}"
            alt="{{ $user->name }}">
    @else
        {{ $initials }}
    @endif

</div>
                <div class="employee-profile-name">{{ $user->name }}</div>
                <div class="employee-profile-department">
                    {{ $user->department?->department_name ?? '-' }} </div>
                <div class="text-start employee-contact-list">
                    <div class="d-flex align-items-center gap-2 py-2 employee-contact-row">
                        <i class="bi bi-envelope-fill"></i>
                        {{ $user->email }}
                    </div>
                    <div class="d-flex align-items-center gap-2 py-2 employee-contact-row">
                        <i class="bi bi-telephone-fill"></i>
                        {{ $user->phone ?? '-' }}
                    </div>
                    <div class="d-flex align-items-center gap-2 py-2 employee-contact-row">
                        <i class="bi bi-calendar-check-fill"></i>
                        เข้าทำงานเมื่อ {{ $joined }}
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-6">
                    <div class="stat-card">
                        <span class="label">งานที่เสร็จแล้ว</span>
                        <div class="value">{{ $completedCount }}</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="stat-card">
                        <span class="label">งานทั้งหมด</span>
                        <div class="value">{{ $user->jobs->count() }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Panel --}}
        <div class="col-xl-8">

            {{-- งานที่กำลังทำ --}}
            <div class="panel mb-3">
                <div class="panel-head">
                    <h2><i class="bi bi-broadcast-pin me-1 employee-active-icon"></i> งานที่กำลังทำอยู่</h2>
                </div>

                @if ($activeJob)
                    <div class="d-flex align-items-center justify-content-between p-3 rounded employee-active-card">
                        <div>
                            <span class="ticket-id">IT-{{ $activeJob->job_id }}</span>
                            <div class="employee-active-title">{{ $activeJob->job_topic }}</div>
                            <div class="employee-active-meta">
                                เริ่มเมื่อ {{ \Carbon\Carbon::parse($activeJob->job_start_at)->diffForHumans() }}
                            </div>
                        </div>
                        <a href="{{ route('tasks.show', $activeJob->job_id) }}" class="btn-outline-line">ดูงาน</a>
                    </div>
                @else
                    <p class="employee-muted-text">ไม่มีงานที่กำลังดำเนินการอยู่</p>
                @endif
            </div>

            {{-- ประวัติงาน --}}
            <div class="panel">
                <div class="panel-head">
                    <h2><i class="bi bi-clock-history me-1"></i> ประวัติงานย้อนหลัง</h2>
                </div>

                @if ($history->count())
                    <table class="table-clean">
                        <thead>
                            <tr>
                                <th>เลขที่งาน</th>
                                <th>ชื่องาน</th>
                                <th>สถานะ</th>
                                <th>วันที่สร้าง</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $job)
                                @php
                                    $info = $statusMeta[$job->job_status] ?? $statusMeta[1];
                                @endphp
                                <tr>
                                    <td class="ticket-id">IT-{{ $job->job_id }}</td>
                                    <td>{{ $job->job_topic }}</td>
                                    <td><span class="badge-soft {{ $info['tone'] }}">{{ $info['label'] }}</span></td>
                                    <td class="mono">
                                        {{ \Carbon\Carbon::parse($job->created_at)->format('d/m/Y') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="employee-muted-text">ยังไม่มีประวัติงาน</p>
                @endif
            </div>

        </div>
    </div>

@endsection

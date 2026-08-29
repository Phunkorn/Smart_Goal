@extends('layouts.app')

@section('title', 'บอร์ดงาน')

@push('styles')
    @vite(auth()->user()->role === 'admin' ? 'resources/css/pages/board-admin.css' : 'resources/css/pages/board.css')
    @if($canManageTasks)
        @vite('resources/js/pages/board/admin-assignment.js')
    @endif
@endpush

@section('content')
@php
    $statusLabels = [
        1 => ['label' => 'รอดำเนินการ', 'tone' => 'gray', 'icon' => 'bi-clock'],
        2 => ['label' => 'กำลังทำ', 'tone' => 'blue', 'icon' => 'bi-lightning-charge-fill'],
        3 => ['label' => 'ตรวจสอบ', 'tone' => 'amber', 'icon' => 'bi-eye'],
        4 => ['label' => 'เสร็จสิ้น', 'tone' => 'green', 'icon' => 'bi-check-circle-fill'],
        5 => ['label' => 'พักงานชั่วคราว', 'tone' => 'gray', 'icon' => 'bi-pause-circle'],
    ];

    $priorityLabels = [
        1 => ['label' => 'ต่ำ', 'tone' => 'gray'],
        2 => ['label' => 'กลาง', 'tone' => 'amber'],
        3 => ['label' => 'สูง', 'tone' => 'red'],
    ];

    $allJobs = isset($jobs) ? $jobs : collect();
    if ($allJobs->isEmpty() && isset($columns)) {
        foreach ($columns as $column) {
            $allJobs = $allJobs->concat($column['tasks']);
        }
    }

    $employeesByDept = $employees->groupBy('department_id');
    $totalJobs = $allJobs->count();
    $activeJobs = $allJobs->where('job_status', '!=', 4)->count();
    $doneJobs = $stats['done'] ?? $allJobs->where('job_status', 4)->count();
    $completionRate = $totalJobs > 0 ? round(($doneJobs / $totalJobs) * 100) : 0;

    $visibleJobs = $allJobs->sortByDesc('job_id')->values();
    $attention = ($attentionJobs ?? collect())->take(6);
    $deptSummary = $workloadByDepartment ?? collect();
    $teamWorkload = ($workloadByUser ?? collect())->sortByDesc('active_count')->values();
@endphp

@include('board.components.admin-overview')

@if($canManageTasks)
    @include('board.components.admin-assignment-modal', [
        'openOnLoad' => request()->boolean('open_assignment'),
        'preselectAssigneeId' => request('assign_to'),
    ])
@endif
@endsection

@push('scripts')
@include('board.components.admin-assignment-flash')
@endpush

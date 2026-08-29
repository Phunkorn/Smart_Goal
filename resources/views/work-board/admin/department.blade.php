@extends('layouts.app')

@section('title', 'ทีม '.$department->department_name)

@push('styles')
    @vite(['resources/css/pages/work-board-admin.css', 'resources/js/pages/work-board/department.js'])
@endpush

@section('content')
    @include('work-board.components.department-directory', [
        'isAdmin' => true,
        'breadcrumbLabel' => 'บอร์ดผู้ดูแลระบบ',
        'breadcrumbUrl' => route('board.index'),
        'resetUrl' => route('admin.work-board.department', $department),
        'previewRouteName' => 'admin.work-board.member.preview',
    ])
@endsection

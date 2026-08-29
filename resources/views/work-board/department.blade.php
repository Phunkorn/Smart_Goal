@extends('layouts.app')

@section('title', 'ทีม '.$department->department_name)

@push('styles')
    @vite(['resources/css/pages/work-board.css', 'resources/js/pages/work-board/department.js'])
@endpush

@section('content')
    @include('work-board.components.department-directory', [
        'isAdmin' => false,
        'breadcrumbLabel' => 'บอร์ดทุกแผนก',
        'breadcrumbUrl' => route('work-board.index'),
        'resetUrl' => route('work-board.department', $department),
        'previewRouteName' => 'work-board.member',
    ])
@endsection

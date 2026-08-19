@extends('layouts.app')

@section('title', 'การแจ้งเตือน')

@push('styles')
    @vite('resources/css/pages/notifications.css')
@endpush

@section('content')
@php
    $categories = ['task'=>'งาน','review'=>'ตรวจสอบ','comment'=>'ความคิดเห็น','deadline'=>'กำหนดเวลา','system'=>'ระบบ'];
    $icons = ['task'=>'bi-briefcase-fill','review'=>'bi-check2-circle','comment'=>'bi-chat-dots-fill','deadline'=>'bi-alarm-fill','system'=>'bi-gear-fill'];
@endphp
<div class="notification-center">
    <header class="notification-center__header">
        <div><p>Smart Goal</p><h1>การแจ้งเตือน</h1></div>
        <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-outline-primary" type="submit"><i class="bi bi-check2-all"></i> อ่านทั้งหมด</button></form>
    </header>

    <form method="GET" class="notification-center__filters">
        <div class="notification-center__tabs">
            <a href="{{ route('notifications.index', array_filter(['status' => $filters['status'] ?? null, 'project' => $filters['project'] ?? null])) }}" @class(['active'=>($filters['category'] ?? 'all')==='all'])>ทั้งหมด</a>
            <a href="{{ route('notifications.index', array_filter(['status' => ($filters['status'] ?? 'all') === 'unread' ? null : 'unread', 'category' => $filters['category'] ?? null, 'project' => $filters['project'] ?? null])) }}" @class(['active'=>($filters['status'] ?? 'all')==='unread'])>ยังไม่อ่าน</a>
            @foreach($categories as $value=>$label)<a href="{{ route('notifications.index', array_filter(['status' => $filters['status'] ?? null, 'category' => $value, 'project' => $filters['project'] ?? null])) }}" @class(['active'=>($filters['category'] ?? 'all')===$value])>{{ $label }}</a>@endforeach
        </div>
        <input type="hidden" name="status" value="{{ $filters['status'] ?? 'all' }}">
        <input type="hidden" name="category" value="{{ $filters['category'] ?? 'all' }}">
        @if($projects->isNotEmpty())
            <label>โปรเจกต์ <select name="project" onchange="this.form.submit()"><option value="">ทั้งหมด</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string)($filters['project'] ?? '')===(string)$project->id)>{{ $project->name }}</option>@endforeach</select></label>
        @endif
    </form>

    @forelse($groups as $group=>$notifications)
        <section class="notification-center__group"><h2>{{ $group }}</h2>
            @foreach($notifications as $notice)
                <article data-notification-center-item data-notification-id="{{ $notice->id }}" @class(['notification-center__item','is-unread'=>!$notice->read_at])>
                    <span class="notification-center__icon category-{{ $notice->category }}"><i class="bi {{ $icons[$notice->category] ?? $icons['system'] }}"></i></span>
                    <a href="{{ route('notifications.open', $notice->id) }}" class="notification-center__body"><strong>{{ $notice->title }}</strong><p>{{ $notice->message }}</p><small>@if($notice->project){{ $notice->project->name }} · @endif{{ $notice->created_at->timezone('Asia/Bangkok')->diffForHumans() }}</small></a>
                    <form method="POST" action="{{ $notice->read_at ? route('notifications.unread', $notice->id) : route('notifications.read', $notice->id) }}">@csrf @method('PATCH')<button type="submit" title="{{ $notice->read_at ? 'ทำเครื่องหมายว่ายังไม่อ่าน' : 'ทำเครื่องหมายว่าอ่านแล้ว' }}"><i class="bi {{ $notice->read_at ? 'bi-envelope' : 'bi-envelope-open' }}"></i></button></form>
                    @if(!$notice->read_at)<i class="notification-center__dot" aria-label="ยังไม่อ่าน"></i>@endif
                </article>
            @endforeach
        </section>
    @empty
        <div class="notification-center__empty"><i class="bi bi-bell-slash"></i><strong>ไม่มีการแจ้งเตือน</strong><span>ยังไม่มีรายการที่ตรงกับตัวกรองนี้</span></div>
    @endforelse
    {{ $items->links() }}
</div>
@endsection

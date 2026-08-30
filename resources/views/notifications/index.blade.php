@extends('layouts.app')

@section('title', 'การแจ้งเตือน')

@push('styles')
    @vite('resources/css/pages/notifications.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/notifications.js')
@endpush

@section('content')
@php
    $categories = ['task' => 'งาน', 'review' => 'ตรวจสอบ', 'comment' => 'ความคิดเห็น', 'deadline' => 'กำหนดเวลา', 'system' => 'ระบบ'];
    $icons = ['task' => 'bi-briefcase', 'review' => 'bi-check2-circle', 'comment' => 'bi-chat-dots', 'deadline' => 'bi-alarm', 'system' => 'bi-gear'];
@endphp
<div class="notification-center"
    data-notification-center
    data-page-items="{{ $items->count() }}"
    data-total-items="{{ $items->total() }}"
    data-read-count="{{ $readCount }}"
    @if(session('warning')) data-flash-warning="{{ session('warning') }}" @endif>
    <header class="notification-center__header">
        <div class="notification-center__heading">
            <p>Smart Goal</p>
            <h1>การแจ้งเตือน</h1>
            <span>ติดตามความเคลื่อนไหวของงานและรายการที่ต้องดำเนินการ</span>
        </div>
        <div class="notification-center__header-actions">
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-check2-all" aria-hidden="true"></i> อ่านทั้งหมด
                </button>
            </form>
            <form method="POST" action="{{ route('notifications.destroy-read') }}" data-delete-read-form data-delete-count="{{ $readCount }}">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-secondary" type="submit" @disabled($readCount === 0)>
                    <i class="bi bi-trash3" aria-hidden="true"></i> ลบที่อ่านแล้วทั้งหมด
                </button>
            </form>
        </div>
    </header>

    <form method="GET" class="notification-center__filters">
        <div class="notification-center__tabs-wrap">
            <nav class="notification-center__tabs" aria-label="กรองหมวดหมู่การแจ้งเตือน">
                <a href="{{ route('notifications.index', array_filter(['status' => $filters['status'] ?? null, 'project' => $filters['project'] ?? null])) }}" @class(['active' => ($filters['category'] ?? 'all') === 'all'])>ทั้งหมด</a>
                <a href="{{ route('notifications.index', array_filter(['status' => ($filters['status'] ?? 'all') === 'unread' ? null : 'unread', 'category' => $filters['category'] ?? null, 'project' => $filters['project'] ?? null])) }}" @class(['active' => ($filters['status'] ?? 'all') === 'unread'])>ยังไม่อ่าน</a>
                @foreach($categories as $value => $label)
                    <a href="{{ route('notifications.index', array_filter(['status' => $filters['status'] ?? null, 'category' => $value, 'project' => $filters['project'] ?? null])) }}" @class(['active' => ($filters['category'] ?? 'all') === $value])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <input type="hidden" name="status" value="{{ $filters['status'] ?? 'all' }}">
        <input type="hidden" name="category" value="{{ $filters['category'] ?? 'all' }}">
        @if($projects->isNotEmpty())
            <label class="notification-center__project-filter">
                <span>โปรเจกต์</span>
                <select class="form-select form-select-sm" name="project" onchange="this.form.submit()">
                    <option value="">ทั้งหมด</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) ($filters['project'] ?? '') === (string) $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </form>

    <div data-notification-list>
        @forelse($groups as $group => $notifications)
            <section class="notification-center__group" data-notification-group>
                <h2>{{ $group }}</h2>
                <div class="notification-center__group-list">
                    @foreach($notifications as $notice)
                        <article data-notification-center-item data-notification-id="{{ $notice->id }}" @class(['notification-center__item', 'is-unread' => ! $notice->read_at])>
                            <span class="notification-center__icon category-{{ $notice->category }}" aria-hidden="true"><i class="bi {{ $icons[$notice->category] ?? $icons['system'] }}"></i></span>
                            <div class="notification-center__content">
                                <div class="notification-center__title-line">
                                    <a href="{{ route('notifications.open', $notice->id) }}" class="notification-center__title">{{ $notice->title }}</a>
                                    @if(! $notice->read_at)<span class="notification-center__unread">ยังไม่อ่าน</span>@endif
                                </div>
                                @if($notice->message)<p>{{ $notice->message }}</p>@endif
                                <div class="notification-center__meta">
                                    @if($notice->project)<span class="notification-center__project">{{ $notice->project->name }}</span><span aria-hidden="true">·</span>@endif
                                    <time datetime="{{ $notice->created_at->toIso8601String() }}">{{ $notificationService->relativeTime($notice->created_at) }}</time>
                                </div>
                            </div>
                            <div class="dropdown notification-center__actions">
                                <button class="notification-center__menu-button" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="เมนูการแจ้งเตือน: {{ $notice->title }}">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="{{ route('notifications.open', $notice->id) }}"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> เปิดรายการ</a></li>
                                    <li>
                                        <form method="POST" action="{{ $notice->read_at ? route('notifications.unread', $notice->id) : route('notifications.read', $notice->id) }}">
                                            @csrf @method('PATCH')
                                            <button class="dropdown-item" type="submit"><i class="bi {{ $notice->read_at ? 'bi-envelope' : 'bi-envelope-open' }}" aria-hidden="true"></i> {{ $notice->read_at ? 'ทำเครื่องหมายว่ายังไม่อ่าน' : 'ทำเครื่องหมายว่าอ่านแล้ว' }}</button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('notifications.destroy', $notice->id) }}" data-delete-notification-form>
                                            @csrf @method('DELETE')
                                            <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> ลบ</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            @include('notifications.components.empty-state')
        @endforelse
    </div>

    <div class="notification-center__pagination">{{ $items->links() }}</div>
</div>
@endsection

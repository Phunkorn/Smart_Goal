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
    $categories = ['task' => 'เกี่ยวกับงาน', 'review' => 'รอตรวจหรืออนุมัติ', 'comment' => 'ความคิดเห็น', 'deadline' => 'กำหนดเวลา', 'system' => 'จากระบบ'];
    $icons = ['task' => 'bi-briefcase', 'review' => 'bi-check2-circle', 'comment' => 'bi-chat-dots', 'deadline' => 'bi-alarm', 'system' => 'bi-gear'];
    $status = $filters['status'] ?? 'all';
    $category = $filters['category'] ?? 'all';
    $projectId = $filters['project'] ?? null;
    $hasAdvancedFilters = $category !== 'all' || filled($projectId);
    $advancedFilterCount = ($category !== 'all' ? 1 : 0) + (filled($projectId) ? 1 : 0);
@endphp
<div class="notification-center"
    data-notification-center
    data-page-items="{{ $items->count() }}"
    data-total-items="{{ $items->total() }}"
    data-read-count="{{ $readCount }}"
    @if(session('warning')) data-flash-warning="{{ session('warning') }}" @endif>
    <header class="notification-center__header">
        <div class="notification-center__heading">
            <p><i class="bi bi-bell" aria-hidden="true"></i> ศูนย์การแจ้งเตือน</p>
            <h1>การแจ้งเตือน</h1>
            <span>เปิดรายการเพื่อดูรายละเอียด ระบบจะทำเครื่องหมายว่าอ่านแล้วให้อัตโนมัติ</span>
        </div>
        <div class="notification-center__manage" aria-label="จัดการการแจ้งเตือน">
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="btn btn-primary" type="submit" @disabled($unreadCount === 0)>
                    <i class="bi bi-check2-all" aria-hidden="true"></i>
                    <span>อ่านทั้งหมด</span>
                </button>
            </form>
            <div class="dropdown">
                <button class="btn btn-outline-secondary notification-center__manage-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="ตัวเลือกจัดการเพิ่มเติม">
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end notification-center__manage-dropdown">
                    <div class="notification-center__manage-label">จัดการรายการ</div>
                    <form method="POST" action="{{ route('notifications.destroy-read') }}" data-delete-read-form data-delete-count="{{ $readCount }}">
                        @csrf
                        @method('DELETE')
                        <button class="dropdown-item text-danger" type="submit" @disabled($readCount === 0)>
                            <i class="bi bi-trash3" aria-hidden="true"></i>
                            <span>ล้างรายการที่อ่านแล้ว <small>{{ number_format($readCount) }} รายการ</small></span>
                        </button>
                    </form>
                    <p>รายการที่ยังไม่อ่านจะไม่ถูกลบ</p>
                </div>
            </div>
        </div>
    </header>

    <form method="GET" class="notification-center__filters">
        <div class="notification-center__filter-bar">
            <nav class="notification-center__tabs" aria-label="สถานะการอ่าน">
                <a href="{{ route('notifications.index', array_filter(['category' => $category === 'all' ? null : $category, 'project' => $projectId])) }}" @class(['active' => $status === 'all'])>
                    ทั้งหมด
                </a>
                <a href="{{ route('notifications.index', array_filter(['status' => 'unread', 'category' => $category === 'all' ? null : $category, 'project' => $projectId])) }}" @class(['active' => $status === 'unread'])>
                    ยังไม่อ่าน <span>{{ number_format($unreadCount) }}</span>
                </a>
            </nav>
            <button class="btn notification-center__filter-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#notificationFilters" aria-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}" aria-controls="notificationFilters">
                <i class="bi bi-sliders" aria-hidden="true"></i>
                ตัวกรอง
                @if($advancedFilterCount > 0)<span>{{ $advancedFilterCount }}</span>@endif
            </button>
        </div>
        <div id="notificationFilters" @class(['collapse', 'show' => $hasAdvancedFilters])>
            <div class="notification-center__advanced-filters">
                <input type="hidden" name="status" value="{{ $status }}">
                <label>
                    <span>ประเภทการแจ้งเตือน</span>
                    <select class="form-select" name="category">
                        <option value="all">ทุกประเภท</option>
                        @foreach($categories as $value => $label)
                            <option value="{{ $value }}" @selected($category === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if($projects->isNotEmpty())
                    <label>
                        <span>โปรเจกต์</span>
                        <select class="form-select" name="project">
                            <option value="">ทุกโปรเจกต์</option>
                            @foreach($projects as $project)
                                <option value="{{ $project->id }}" @selected((string) $projectId === (string) $project->id)>{{ $project->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <div class="notification-center__filter-actions">
                    @if($hasAdvancedFilters)
                        <a class="btn btn-link" href="{{ route('notifications.index', $status === 'unread' ? ['status' => 'unread'] : []) }}">ล้างตัวกรอง</a>
                    @endif
                    <button class="btn btn-outline-primary" type="submit">แสดงผล</button>
                </div>
            </div>
        </div>
    </form>

    <div data-notification-list>
        @forelse($groups as $group => $notifications)
            <section class="notification-center__group" data-notification-group>
                <h2>{{ $group }}</h2>
                <div class="notification-center__group-list">
                    @foreach($notifications as $notice)
                        <article data-notification-center-item data-notification-id="{{ $notice->id }}" @class(['notification-center__item', 'is-unread' => ! $notice->read_at])>
                            <a href="{{ route('notifications.open', $notice->id) }}" class="notification-center__item-link" aria-label="เปิดการแจ้งเตือน: {{ $notice->title }}">
                                <span class="notification-center__icon category-{{ $notice->category }}" aria-hidden="true"><i class="bi {{ $icons[$notice->category] ?? $icons['system'] }}"></i></span>
                                <div class="notification-center__content">
                                    <div class="notification-center__title-line">
                                        <span class="notification-center__title">{{ $notice->title }}</span>
                                        @if(! $notice->read_at)<span class="notification-center__unread">ใหม่</span>@endif
                                    </div>
                                    @if($notice->message)<p>{{ $notice->message }}</p>@endif
                                    <div class="notification-center__meta">
                                        <span class="notification-center__category">{{ $categories[$notice->category] ?? $categories['system'] }}</span>
                                        @if($notice->project)<span class="notification-center__project">{{ $notice->project->name }}</span>@endif
                                        <time datetime="{{ $notice->created_at->toIso8601String() }}">{{ $notificationService->relativeTime($notice->created_at) }}</time>
                                    </div>
                                </div>
                                <i class="bi bi-chevron-right notification-center__open-icon" aria-hidden="true"></i>
                            </a>
                            <div class="dropdown notification-center__actions">
                                <button class="notification-center__menu-button" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="เมนูการแจ้งเตือน: {{ $notice->title }}">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
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
            @include('notifications.components.empty-state', compact('status', 'hasAdvancedFilters'))
        @endforelse
    </div>

    <div class="notification-center__pagination">{{ $items->links() }}</div>
</div>
@endsection

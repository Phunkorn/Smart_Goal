@extends('layouts.app')

@section('title', 'ประกาศ')

@push('styles')
    @vite('resources/css/pages/announcements.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/announcements/index.js')
@endpush

@section('content')
@php
    $tabs = [
        'all' => 'ทั้งหมด',
        'department' => 'เฉพาะแผนก',
        'everyone' => 'ทุกแผนก',
        'active' => 'กำลังแสดง',
        'pending' => 'รอแสดง',
        'expired' => 'หมดอายุ',
    ];
    $tabUrl = fn (string $key) => route('announcements.index', array_filter([
        'tab' => $key === 'all' ? null : $key,
        'q' => $search !== '' ? $search : null,
    ]));
    $feedback = array_filter([
        'success' => session('success'),
        'error' => $errors->has('status') ? $errors->first('status') : null,
    ]);
@endphp
<div class="announcements-page" data-announcements-page>
    <header class="announcements-page__header">
        <div class="announcements-page__heading">
            <span class="announcements-page__icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
            <div>
                <h1>ศูนย์ประกาศประจำวัน</h1>
                <p>ประกาศถึงแผนกของคุณหรือทุกแผนก ผู้รับจะเห็นในสรุปประจำวันตามช่วงวันที่ที่กำหนด</p>
            </div>
        </div>
        <button type="button" class="btn-accent announcements-page__create" data-announcement-create>
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>สร้างประกาศ</span>
        </button>
    </header>

    <section class="announcements-page__panel">
        <nav class="announcements-page__tabs" aria-label="กรองประกาศ">
            @foreach($tabs as $key => $label)
                <a href="{{ $tabUrl($key) }}" @class(['announcements-page__tab', 'is-active' => $tab === $key])
                    @if($tab === $key) aria-current="page" @endif>
                    {{ $label }} <span>({{ number_format($tabCounts[$key] ?? 0) }})</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('announcements.index') }}" class="announcements-page__search" role="search">
            @if($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif
            <label class="announcements-page__search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="visually-hidden">ค้นหาหัวข้อประกาศ</span>
                <input type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="ค้นหาหัวข้อประกาศ...">
            </label>
            @if($search !== '')
                <a class="announcements-page__clear" href="{{ route('announcements.index', array_filter(['tab' => $tab === 'all' ? null : $tab])) }}">ล้างคำค้น</a>
            @endif
        </form>

        @include('announcements.components.table', ['announcements' => $announcements, 'today' => $today])
    </section>

    @include('announcements.components.form-modal', ['today' => $today, 'departmentName' => $departmentName])

    <script type="application/json" data-announcement-feedback>@json($feedback)</script>
</div>
@endsection

@extends('layouts.app')

@section('title', 'งานประจำ')

@push('styles')
    @vite('resources/css/pages/daily-logs.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/daily-logs/routines.js')
@endpush

@section('content')
{{--
    แม่แบบงานประจำ — ตั้งครั้งเดียวแล้วระบบสร้างรายการให้ทุกวันที่กำหนด
    ผู้ใช้จึงไม่ต้องพิมพ์งานเดิมซ้ำทุกเช้า

    แม่แบบเป็นการตั้งค่าส่วนตัว หัวหน้าและ admin ไม่เห็นหน้านี้ของคนอื่น
--}}
<div class="routine-page" data-routine-page>
    <script type="application/json" id="work-log-design">@json($design)</script>

    <header class="routine-page__header">
        <div>
            <nav class="routine-page__breadcrumb" aria-label="breadcrumb">
                <a href="{{ route('daily-logs.index') }}">บันทึกงานประจำวัน</a>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span>งานประจำ</span>
            </nav>
            <h1 class="routine-page__title">งานประจำของฉัน</h1>
            <p class="routine-page__subtitle">
                ตั้งไว้ครั้งเดียว ระบบจะเพิ่มรายการให้ทุกเช้าตามวันที่กำหนด
            </p>
        </div>
    </header>

    <div class="routine-page__body">
        <section class="routine-list" aria-labelledby="routineListHeading">
            <h2 class="visually-hidden" id="routineListHeading">แม่แบบที่มีอยู่</h2>

            @forelse($templates as $template)
                @php($kind = \App\Support\WorkLogDesign::kind($template->kind))
                <article class="routine-card @unless($template->is_active) routine-card--inactive @endunless"
                    data-routine-card data-routine-id="{{ $template->id }}">
                    <div class="routine-card__main">
                        <div class="routine-card__chips">
                            <span class="log-chip log-chip--{{ $kind['tone'] }}">
                                <i class="bi {{ $kind['icon'] }}" aria-hidden="true"></i> {{ $kind['label'] }}
                            </span>
                            @if($template->category)
                                <span class="log-chip log-chip--{{ $template->category->tone }}">
                                    {{ $template->category->name }}
                                </span>
                            @endif
                            @unless($template->is_active)
                                <span class="log-chip log-chip--gray">ปิดใช้งาน</span>
                            @endunless
                        </div>

                        <h3 class="routine-card__title">{{ $template->title }}</h3>

                        <p class="routine-card__meta">
                            <span><i class="bi bi-calendar-week" aria-hidden="true"></i>
                                {{ \App\Support\WorkLogWeekdays::label((int) $template->weekday_mask) }}</span>
                            @if($template->default_start_time)
                                <span><i class="bi bi-clock" aria-hidden="true"></i>
                                    เริ่ม {{ \Illuminate\Support\Str::substr($template->default_start_time, 0, 5) }}</span>
                            @endif
                            @if($template->default_duration_minutes)
                                <span><i class="bi bi-hourglass" aria-hidden="true"></i>
                                    ประมาณ {{ \App\Support\WorkLogDesign::durationLabel($template->default_duration_minutes) }}</span>
                            @endif
                        </p>
                    </div>

                    <form method="POST" action="{{ route('daily-logs.routines.destroy', $template) }}"
                        class="routine-card__actions" data-routine-delete>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="routine-card__delete" aria-label="ลบแม่แบบ {{ $template->title }}">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </article>
            @empty
                <p class="routine-list__empty">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    ยังไม่มีงานประจำ — เพิ่มงานที่ต้องทำซ้ำทุกวันได้ที่ฟอร์มด้านขวา
                </p>
            @endforelse
        </section>

        <aside class="routine-form-panel">
            @include('daily-logs.components.routine-form')
        </aside>
    </div>
</div>
@endsection

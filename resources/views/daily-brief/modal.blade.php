{{--
    สรุปประจำวัน (Daily Brief) — modal จริง (backdrop, aria-modal, จับโฟกัส, ล็อก body)

    เปิดอัตโนมัติจาก resources/js/components/daily-brief.js ผ่าน modal-stack ซึ่งเป็น
    เจ้าของชั้น overlay เพียงรายเดียวของระบบ หน้า "งานของฉัน" ที่มี ?open_task= เปิด
    Task Workspace ผ่าน modal-stack ตัวเดียวกัน สองกล่องจึงซ้อนกันได้ถูกลำดับ

    ถูก include จาก layouts.app เฉพาะตอนที่ DailyBriefService::isPending() เป็นจริง
    CSS/JS ของกล่องนี้โหลดที่ layout โดยตรง ไม่ใช้ @push เพราะ @stack('styles') ใน <head>
    ถูกวาดไปก่อนที่ layout จะ include ไฟล์นี้ ของที่ push มาทีหลังจะหายเงียบ ๆ

    การจัดวาง: หัวกล่องสีขาวเต็มความกว้าง เนื้อหาสองคอลัมน์บนเดสก์ท็อป (คอลัมน์หลักคือ
    อันดับงานล่าช้า คอลัมน์รองคืองานประจำและประกาศ) และคอลัมน์เดียวเรียงตามลำดับ DOM
    บนจอเล็ก ท้ายกล่องมีปุ่มรับทราบเพียงปุ่มเดียว

    เนื้อหาประกาศแสดงแบบ escape + nl2br เท่านั้น ห้ามใช้ {!! !!} กับข้อความของผู้ใช้
--}}
@php
    $overdueShown = count($brief['overdue_tasks']);
    $overdueTotal = $brief['overdue_total'];
@endphp
<div class="daily-brief" data-daily-brief hidden
    role="dialog" aria-modal="true" aria-labelledby="dailyBriefTitle"
    data-acknowledge-url="{{ route('daily-brief.acknowledge') }}"
    data-brief-date="{{ $brief['date'] }}">
    <div class="daily-brief__panel">
        <header class="daily-brief__head">
            <h2 id="dailyBriefTitle" class="daily-brief__title">สรุปประจำวัน</h2>
            <p class="daily-brief__date">{{ $brief['date_label'] }}</p>
            <p class="daily-brief__lead">สิ่งที่ต้องจัดการก่อนเริ่มวันทำงาน</p>
        </header>

        <div class="daily-brief__body">
            <div class="daily-brief__column daily-brief__column--main">
                <section class="daily-brief__card daily-brief__card--project" aria-labelledby="dailyBriefProjectTitle">
                    <header class="daily-brief__card-head">
                        <h3 id="dailyBriefProjectTitle" class="daily-brief__card-title">
                            @if($overdueTotal > $overdueShown)
                                {{ $overdueShown }} งานโปรเจกต์ล่าช้าที่สุด
                            @else
                                งานโปรเจกต์ล่าช้า
                            @endif
                        </h3>
                        @if($overdueTotal > 0)
                            <span class="daily-brief__card-count">{{ $overdueTotal }} งาน</span>
                        @endif
                    </header>
                    @if($overdueShown > 0)
                        <ol class="daily-brief__ranked">
                            @foreach($brief['overdue_tasks'] as $index => $task)
                                @include('daily-brief.partials.overdue-row', ['row' => $task, 'rank' => $index + 1])
                            @endforeach
                        </ol>
                    @else
                        <p class="daily-brief__empty">ไม่มีงานโปรเจกต์ล่าช้า</p>
                    @endif
                    @if($overdueTotal > $overdueShown)
                        <button type="button" class="daily-brief__more" data-daily-brief-expand="project" aria-haspopup="dialog">
                            ดูงานล่าช้าทั้งหมด {{ $overdueTotal }} งาน <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </button>
                    @endif
                </section>
            </div>

            <div class="daily-brief__column daily-brief__column--side">
                <section class="daily-brief__card daily-brief__card--routine" aria-labelledby="dailyBriefRoutineTitle">
                    <header class="daily-brief__card-head">
                        <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi bi-journal-check"></i></span>
                        <h3 id="dailyBriefRoutineTitle" class="daily-brief__card-title">งานประจำวันนี้</h3>
                        <span class="daily-brief__card-count">{{ $brief['routine_total'] }} งาน</span>
                    </header>
                    <div class="daily-brief__list">
                        @forelse($brief['routine_tasks'] as $routine)
                            @include('daily-brief.partials.task-row', [
                                'row' => $routine,
                                'meta' => $routine['status_label'],
                            ])
                        @empty
                            <p class="daily-brief__empty">วันนี้ไม่มีงานประจำที่ต้องทำ</p>
                        @endforelse
                    </div>
                    @if($brief['routine_total'] > count($brief['routine_tasks']))
                        <button type="button" class="daily-brief__more" data-daily-brief-expand="routine" aria-haspopup="dialog">
                            ดูงานประจำทั้งหมด {{ $brief['routine_total'] }} งาน <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </button>
                    @endif
                </section>

                <section class="daily-brief__card daily-brief__card--department" aria-labelledby="dailyBriefDepartmentTitle">
                    <header class="daily-brief__card-head">
                        <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
                        <h3 id="dailyBriefDepartmentTitle" class="daily-brief__card-title">ประกาศจาก{{ $brief['department_name'] ? 'แผนก '.$brief['department_name'] : 'แผนกของคุณ' }}</h3>
                    </header>
                    <div class="daily-brief__list">
                        @forelse($brief['department_announcements'] as $announcement)
                            @include('daily-brief.partials.announcement-card', ['announcement' => $announcement])
                        @empty
                            <p class="daily-brief__empty">วันนี้ไม่มีประกาศจากแผนก</p>
                        @endforelse
                    </div>
                </section>

                <section class="daily-brief__card daily-brief__card--all" aria-labelledby="dailyBriefAllTitle">
                    <header class="daily-brief__card-head">
                        <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi bi-globe2"></i></span>
                        <h3 id="dailyBriefAllTitle" class="daily-brief__card-title">ประกาศทุกแผนก</h3>
                    </header>
                    <div class="daily-brief__list">
                        @forelse($brief['all_announcements'] as $announcement)
                            @include('daily-brief.partials.announcement-card', ['announcement' => $announcement])
                        @empty
                            <p class="daily-brief__empty">วันนี้ไม่มีประกาศถึงทุกแผนก</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        <footer class="daily-brief__footer">
            <button type="button" class="btn-accent daily-brief__acknowledge" data-daily-brief-acknowledge autofocus>
                <span>รับทราบ</span>
            </button>
        </footer>
    </div>
</div>

{{--
    กล่อง "ดูทั้งหมด" ซ้อนบนสรุปผ่าน modal-stack ต้องเป็นพี่น้องของ .daily-brief ไม่ใช่ลูก
    เพราะ modal-stack ใส่ inert ให้ชั้นล่าง ถ้าวางไว้ข้างในจะกดอะไรในกล่องนี้ไม่ได้เลย
--}}
@if($overdueTotal > $overdueShown)
    @include('daily-brief.partials.task-list-dialog', [
        'key' => 'project',
        'title' => 'งานโปรเจกต์ล่าช้าทั้งหมด',
        'icon' => 'bi-exclamation-triangle',
        'rows' => $brief['overdue_all_tasks'],
        'ranked' => true,
    ])
@endif
@if($brief['routine_total'] > count($brief['routine_tasks']))
    @include('daily-brief.partials.task-list-dialog', [
        'key' => 'routine',
        'title' => 'งานประจำวันนี้ทั้งหมด',
        'icon' => 'bi-journal-check',
        'rows' => $brief['routine_all_tasks'],
    ])
@endif

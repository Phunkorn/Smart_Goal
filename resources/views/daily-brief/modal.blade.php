{{--
    สรุปประจำวัน (Daily Brief) — modal จริง (backdrop, aria-modal, จับโฟกัส, ล็อก body)

    เปิดอัตโนมัติจาก resources/js/components/daily-brief.js ผ่าน modal-stack ซึ่งเป็น
    เจ้าของชั้น overlay เพียงรายเดียวของระบบ หน้า "งานของฉัน" ที่มี ?open_task= เปิด
    Task Workspace ผ่าน modal-stack ตัวเดียวกัน สองกล่องจึงซ้อนกันได้ถูกลำดับ

    ถูก include จาก layouts.app เฉพาะตอนที่ DailyBriefService::isPending() เป็นจริง
    CSS/JS ของกล่องนี้โหลดที่ layout โดยตรง ไม่ใช้ @push เพราะ @stack('styles') ใน <head>
    ถูกวาดไปก่อนที่ layout จะ include ไฟล์นี้ ของที่ push มาทีหลังจะหายเงียบ ๆ

    เนื้อหาประกาศแสดงแบบ escape + nl2br เท่านั้น ห้ามใช้ {!! !!} กับข้อความของผู้ใช้
--}}
<div class="daily-brief" data-daily-brief hidden
    role="dialog" aria-modal="true" aria-labelledby="dailyBriefTitle"
    data-acknowledge-url="{{ route('daily-brief.acknowledge') }}"
    data-brief-date="{{ $brief['date'] }}">
    <div class="daily-brief__panel">
        <header class="daily-brief__hero">
            <span class="daily-brief__sun" aria-hidden="true"><i class="bi bi-brightness-high-fill"></i></span>
            <div class="daily-brief__heading">
                <h2 id="dailyBriefTitle">สรุปประจำวัน</h2>
                <p class="daily-brief__date">{{ $brief['date_label'] }}</p>
                <p class="daily-brief__lead">เริ่มต้นวันทำงานด้วยเป้าหมายที่ชัดเจน</p>
            </div>
        </header>

        <div class="daily-brief__body">
            <div class="daily-brief__column">
                <section class="daily-brief__card daily-brief__card--project" aria-labelledby="dailyBriefProjectTitle">
                    <header class="daily-brief__card-head">
                        <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi bi-kanban"></i></span>
                        <h3 id="dailyBriefProjectTitle">งานโปรเจกต์วันนี้</h3>
                        <span class="badge-soft accent">{{ $brief['project_total'] }} งาน</span>
                    </header>
                    <div class="daily-brief__list">
                        @forelse($brief['project_tasks'] as $task)
                            @include('daily-brief.partials.task-row', [
                                'row' => $task,
                                'meta' => $task['project'].' · '.$task['status_label'],
                            ])
                        @empty
                            <p class="daily-brief__empty">วันนี้ไม่มีงานโปรเจกต์ที่ต้องทำ</p>
                        @endforelse
                    </div>
                    @if($brief['project_total'] > count($brief['project_tasks']))
                        <button type="button" class="daily-brief__more" data-daily-brief-expand="project" aria-haspopup="dialog">
                            ดูงานวันนี้ทั้งหมด {{ $brief['project_total'] }} งาน <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </button>
                    @endif
                </section>

                <section class="daily-brief__card daily-brief__card--routine" aria-labelledby="dailyBriefRoutineTitle">
                    <header class="daily-brief__card-head">
                        <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi bi-journal-check"></i></span>
                        <h3 id="dailyBriefRoutineTitle">งานประจำวันนี้</h3>
                        <span class="badge-soft accent">{{ $brief['routine_total'] }} งาน</span>
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
            </div>

            <div class="daily-brief__column">
                <section class="daily-brief__card daily-brief__card--department" aria-labelledby="dailyBriefDepartmentTitle">
                    <header class="daily-brief__card-head">
                        <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
                        <h3 id="dailyBriefDepartmentTitle">ประกาศจาก{{ $brief['department_name'] ? 'แผนก '.$brief['department_name'] : 'แผนกของคุณ' }}</h3>
                        @if(collect($brief['department_announcements'])->contains('is_new', true))
                            <span class="badge-soft red">ใหม่</span>
                        @endif
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
                        <h3 id="dailyBriefAllTitle">ประกาศทุกแผนก</h3>
                        @if(collect($brief['all_announcements'])->contains('is_new', true))
                            <span class="badge-soft blue">ใหม่</span>
                        @endif
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
@if($brief['project_total'] > count($brief['project_tasks']))
    @include('daily-brief.partials.task-list-dialog', [
        'key' => 'project',
        'title' => 'งานโปรเจกต์วันนี้ทั้งหมด',
        'icon' => 'bi-kanban',
        'rows' => $brief['project_all_tasks'],
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

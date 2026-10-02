@php
    $isEdit = $formMeeting instanceof \App\Models\Meeting;
    $modalId = $isEdit ? 'editMeetingModal' : 'createMeetingModal';
    $selectedAttendees = collect(old('attendees', $isEdit ? $formMeeting->attendees->pluck('id')->all() : []))
        ->map(fn($id) => (int) $id)
        ->unique()
        ->values();
    $startValue = old(
        'starts_at',
        $isEdit ? $formMeeting->starts_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i') : '',
    );
    $endValue = old('ends_at', $isEdit ? $formMeeting->ends_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i') : '');
    $projectOptions = collect($projectOptions ?? [])->values();
    $selectedProjectId = old('work_order_list_id', $isEdit ? $formMeeting->work_order_list_id : '');
    $selectedTaskId = old('work_order_id', $isEdit ? $formMeeting->work_order_id : '');
    $selectedProjectTasks = $projectOptions->firstWhere('id', (int) $selectedProjectId)['tasks'] ?? [];
@endphp

<div class="modal fade meeting-form-modal" id="{{ $modalId }}" tabindex="-1"
    aria-labelledby="{{ $modalId }}Title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">MEETING</span>
                    <h2 class="modal-title" id="{{ $modalId }}Title">{{ $isEdit ? 'แก้ไขการประชุม' : 'นัดประชุม' }}
                    </h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="POST" id="{{ $modalId }}Form"
                action="{{ $isEdit ? route('meetings.update', $formMeeting) : route('meetings.store') }}"
                data-meeting-form>
                @foreach ($meetingContextQuery ?? [] as $contextKey => $contextValue)
                    <input type="hidden" name="{{ $contextKey }}" value="{{ $contextValue }}">
                @endforeach
                @csrf
                @if ($isEdit)
                    @method('PATCH')
                @endif
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label" for="{{ $modalId }}TitleInput">ชื่อการประชุม
                                <span aria-hidden="true">*</span></label><input class="form-control"
                                id="{{ $modalId }}TitleInput" name="title" maxlength="255" required
                                value="{{ old('title', $formMeeting?->title ?? '') }}"></div>
                        <div class="col-12"><label class="form-label"
                                for="{{ $modalId }}Description">รายละเอียด</label>
                            <textarea class="form-control" id="{{ $modalId }}Description" name="description" rows="2" maxlength="5000">{{ old('description', $formMeeting?->description ?? '') }}</textarea>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="{{ $modalId }}Start">เริ่มประชุม
                                <span aria-hidden="true">*</span></label><input class="form-control"
                                type="datetime-local" data-date-picker id="{{ $modalId }}Start" name="starts_at"
                                required value="{{ $startValue }}"></div>
                        <div class="col-md-6"><label class="form-label" for="{{ $modalId }}End">สิ้นสุด <span
                                    aria-hidden="true">*</span></label><input class="form-control" type="datetime-local"
                                data-date-picker id="{{ $modalId }}End" name="ends_at" required
                                value="{{ $endValue }}"></div>
                        <div class="col-12"><label class="form-label"
                                for="{{ $modalId }}Location">สถานที่</label><input class="form-control"
                                id="{{ $modalId }}Location" name="location" maxlength="255"
                                placeholder="เช่น ห้องประชุมชั้น 2 หรือ ออนไลน์"
                                value="{{ old('location', $formMeeting?->location ?? '') }}"></div>
                        <div class="col-12" data-meeting-project-link>
                            <div class="meeting-form-modal__section-heading">
                                <span><i class="bi bi-folder2-open" aria-hidden="true"></i></span>
                                <div><strong>โปรเจกต์ที่เกี่ยวข้อง</strong><small>ไม่บังคับ —
                                        เลือกเมื่อการประชุมนี้เกี่ยวกับโปรเจกต์หรืองานใดงานหนึ่งโดยเฉพาะ</small></div>
                            </div>
                            <div class="meeting-form-modal__project-grid">
                                <div data-sg-select>
                                    <label for="{{ $modalId }}Project">โปรเจกต์</label>
                                    <select id="{{ $modalId }}Project" name="work_order_list_id"
                                        data-meeting-project-select>
                                        <option value="">ไม่ระบุ</option>
                                        @foreach ($projectOptions as $project)
                                            {{-- รายการงานของโปรเจกต์ฝังมากับ option เหมือน data-parent-tasks ของ project-board-card --}}
                                            <option value="{{ $project['id'] }}"
                                                data-tasks='@json($project['tasks'])' @selected((string) $selectedProjectId === (string) $project['id'])>
                                                {{ $project['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div data-sg-select>
                                    <label for="{{ $modalId }}Task">งาน</label>
                                    <select id="{{ $modalId }}Task" name="work_order_id" data-meeting-task-select>
                                        <option value="">ไม่ระบุ</option>
                                        @foreach ($selectedProjectTasks as $task)
                                            <option value="{{ $task['id'] }}" @selected((string) $selectedTaskId === (string) $task['id'])>
                                                {{ $task['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="meeting-form-modal__attendees" data-meeting-attendees-field="{{ $modalId }}Attendees">
                                <div class="meeting-form-modal__attendees-head">
                                    <strong>ผู้เข้าร่วม</strong>
                                    <span class="meeting-form-modal__attendees-count">{{ $selectedAttendees->isNotEmpty() ? 'เลือกแล้ว '.$selectedAttendees->count().' คน' : 'ยังไม่ได้เลือกผู้เข้าร่วม' }}</span>
                                </div>
                                <button type="button" class="meetings-page__button meeting-form-modal__attendees-trigger"><i
                                        class="bi bi-person-plus" aria-hidden="true"></i> เพิ่มผู้ร่วมประชุม</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="meetings-page__button"
                        data-bs-dismiss="modal">ยกเลิก</button><button type="submit"
                        class="meetings-page__button meetings-page__button--primary"><i class="bi bi-check2"
                            aria-hidden="true"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : 'นัดประชุม' }}</button></div>
            </form>
        </div>
    </div>
</div>

{{--
    ผู้เข้าร่วมถูกย้ายมาเป็น modal แยก เปิดจากปุ่มในฟอร์มด้านบน เพื่อให้ฟอร์มหลักอ่านง่ายขึ้น

    ต้องอยู่นอก modal หลักจริง ๆ ไม่ใช่แค่ซ่อนไว้ข้างใน — ถ้าอยู่ข้างใน พอ modal หลักถูกซ่อนด้วย
    display:none ลูกของมันก็มองไม่เห็นไปด้วยไม่ว่าตัวมันเองจะมีคลาส show หรือไม่ (backdrop ของมันซึ่ง
    Bootstrap แปะไว้ที่ body ยังขึ้นอยู่ปกติ จึงเห็นเป็นจอมืดไม่มีอะไรเลย) checkbox ผูกกลับไปฟอร์มหลักด้วย
    attribute form="{{ $modalId }}Form" แทนการเป็นลูกของ <form> เพื่อให้ attendees[] ยังส่งไปพร้อมฟอร์มหลัก

    ต้อง "สลับ" กับ modal หลัก ไม่ใช่ "ซ้อน" กัน — ดู initializeMeetingAttendeeModals()
    ใน meetings/index.js สำหรับเหตุผลที่ modal ของ Bootstrap สองใบเปิดพร้อมกันไม่ได้
--}}
<div class="modal fade meeting-form-modal meeting-attendees-modal" id="{{ $modalId }}Attendees" tabindex="-1"
    aria-labelledby="{{ $modalId }}AttendeesTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">MEETING</span>
                    <h2 class="modal-title" id="{{ $modalId }}AttendeesTitle">เพิ่มผู้เข้าร่วมประชุม</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                @include('components.people-selector', [
                    'instanceId' => $modalId,
                    'inputName' => 'attendees[]',
                    'formId' => $modalId.'Form',
                    'people' => $attendeeOptions,
                    'departments' => $attendeeDepartments,
                    'selectedIds' => $selectedAttendees,
                    'showAvatar' => true,
                    'labels' => [
                        'title' => 'ผู้เข้าร่วม',
                        'search' => 'ค้นหาชื่อ แผนก หรือสิทธิ์',
                        'emptyOptions' => 'ไม่พบผู้เข้าร่วมที่ตรงกับตัวกรอง',
                        'emptySelected' => 'ยังไม่ได้เลือกผู้เข้าร่วม',
                    ],
                ])
            </div>
            <div class="modal-footer"><button type="button"
                    class="meetings-page__button meetings-page__button--primary" data-bs-dismiss="modal"><i
                        class="bi bi-check2" aria-hidden="true"></i> เสร็จสิ้น</button></div>
        </div>
    </div>
</div>

{{--
    กล่องเดียวของหน้าบันทึกงานประจำวัน

    มีสองเนื้อใน สลับตามปุ่มที่กด (ไม่ใช่ overlay ชั้นใหม่):
    1. data-entry-panel="once"    — ฟอร์มบันทึกงานหรือแก้ไขรายการ
    2. data-entry-panel="routine" — งานประจำของฉัน: รายการ และฟอร์มตั้ง/แก้งานประจำ

    การเปิด/ปิด backdrop โฟกัส Escape และการซ้อนชั้น เป็นหน้าที่ของ modal-stack ทั้งหมด
    เริ่มต้นด้วย hidden เพราะ modal-stack ใช้ property hidden เป็นตัวคุมการแสดงผล
--}}
<div class="log-modal" id="logEntryModal" role="dialog" aria-modal="true" aria-labelledby="logEntryModalTitle"
    data-log-entry-modal hidden>
    <div class="log-modal__panel">
        <header class="log-modal__header">
            <h2 class="log-modal__title" id="logEntryModalTitle" data-entry-modal-title>บันทึกงาน</h2>
            <button type="button" class="log-modal__close" aria-label="ปิด" data-entry-modal-close>
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="log-modal__choice" data-entry-panel="choice">
            <p class="log-modal__step-heading"><span>1</span> เลือกประเภทงาน</p>
            <div class="log-modal__type-grid">
                <button type="button" data-entry-select-kind="routine">
                    <i class="bi bi-display" aria-hidden="true"></i>
                    <strong>งานประจำ</strong><small>งานที่บันทึกเป็นแผนงาน</small>
                </button>
                <button type="button" data-entry-select-kind="field">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    <strong>งานนอกสถานที่</strong><small>งานที่ทำนอกสถานที่</small>
                </button>
            </div>
            <footer class="log-modal__footer"><button type="button" class="btn btn-outline-secondary" data-entry-modal-close>ยกเลิก</button></footer>
        </div>

        <form class="log-modal__form" method="POST" action="{{ route('daily-logs.store') }}"
            data-log-entry-form data-entry-panel="once" hidden>
            @csrf
            <input type="hidden" name="_method" value="POST" data-entry-method>
            {{-- ประเภทงานมาจากปุ่มที่กด หรือจากรายการเดิมตอนแก้ไข --}}
            <input type="hidden" name="kind" value="{{ array_key_first($design['kinds']) }}" data-entry-kind>

            <div class="log-modal__body">
                <div class="log-field-row">
                    <div class="log-field">
                        <label class="form-label" for="logEntryCategory">หมวดงาน <span aria-hidden="true">*</span></label>
                        <select class="form-select" id="logEntryCategory" name="work_log_category_id" data-entry-category>
                            <option value="">เลือกหมวดงาน</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="log-field log-field--multiple-date" data-date-picker-field>
                        <label class="form-label" for="logEntryDate">วันที่ <small>เลือกได้หลายวัน</small></label>
                        <input type="date" class="form-control" id="logEntryDate" name="work_date"
                            value="{{ $dateValue }}"
                            max="{{ \App\Support\TodayWorkspace::businessNow()->format('Y-m-d') }}"
                            data-date-picker
                            data-date-picker-multiple
                            data-date-picker-multiple-name="work_dates[]"
                            data-entry-date>
                        <small class="log-field__date-summary" data-date-picker-summary aria-live="polite">เลือก 1 วัน</small>
                        <small class="log-field__date-list" data-date-picker-dates hidden></small>
                    </div>
                </div>

                <div class="log-field">
                    <label class="form-label" for="logEntryTitle">งานที่ทำ <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="logEntryTitle" name="title"
                        maxlength="200" required autocomplete="off" placeholder="เช่น เช็คคอมพิวเตอร์ Call Center" data-entry-title>
                </div>

                {{-- งานนอกสถานที่เท่านั้น — สถานที่เป็นช่องบังคับของประเภทนี้ --}}
                <div class="log-field" data-entry-only-kind="field" hidden>
                    <label class="form-label" for="logEntryLocation">สถานที่ <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="logEntryLocation" name="location"
                        maxlength="120" placeholder="เช่น สาขาบางนา" data-entry-location>
                </div>

                <div class="log-field-row">
                    <div class="log-field">
                        <label class="form-label" for="logEntryStart">เริ่ม</label>
                        <input type="time" class="form-control" id="logEntryStart" name="start_time" data-entry-start>
                    </div>

                    <div class="log-field">
                        <label class="form-label" for="logEntryEnd">สิ้นสุด</label>
                        <input type="time" class="form-control" id="logEntryEnd" name="end_time" data-entry-end>
                    </div>

                    <div class="log-field">
                        <label class="form-label" for="logEntryDuration">หรือระบุเป็นนาที</label>
                        <input type="number" class="form-control" id="logEntryDuration" name="duration_minutes"
                            min="1"
                            max="{{ $design['maxDurationMinutes'] }}"
                            placeholder="เช่น 30" data-entry-duration>
                    </div>
                </div>
                <p class="log-field__hint">
                    ระบุช่วงเวลา หรือระบุเป็นนาทีอย่างใดอย่างหนึ่ง — ถ้าจำเวลาไม่ได้จะเว้นว่างไว้ก่อนก็ได้
                </p>

                <details class="routine-form__more" data-entry-more>
                    <summary><i class="bi bi-sliders" aria-hidden="true"></i> เพิ่มเติม: โปรเจกต์ ผู้ร่วมงาน รายละเอียด</summary>
                    <div class="routine-form__more-body">
                        <div class="log-field-row">
                            <div class="log-field">
                                <label class="form-label" for="logEntryProject">เกี่ยวข้องกับโปรเจกต์</label>
                                <select class="form-select" id="logEntryProject" name="work_order_list_id" data-entry-project>
                                    <option value="">ไม่เกี่ยวข้อง</option>
                                    @foreach($projects as $project)
                                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="log-field">
                                <label class="form-label" for="logEntryTask">เกี่ยวข้องกับงาน</label>
                                <select class="form-select" id="logEntryTask" name="job_id" data-entry-task>
                                    <option value="">ไม่เกี่ยวข้อง</option>
                                    @foreach($tasks as $task)
                                        <option value="{{ $task->job_id }}">{{ $task->job_topic }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="log-field">
                            <span class="form-label">ผู้ร่วมงาน</span>
                            @include('daily-logs.components.participant-picker', [
                                'instanceId' => 'entry-participant',
                                'options' => $participantOptions,
                                'selectedIds' => [],
                                'summaryLabel' => 'เลือกเพื่อนร่วมแผนก',
                            ])
                        </div>

                        <div class="log-field">
                            <label class="form-label" for="logEntryDetails">รายละเอียด</label>
                            <textarea class="form-control" id="logEntryDetails" name="details" rows="3"
                                maxlength="2000" placeholder="เช่น พบเครื่อง 1 เครื่อง HDD มีปัญหา" data-entry-details></textarea>
                        </div>
                    </div>
                </details>

                <p class="log-modal__error" role="alert" data-entry-error hidden></p>
            </div>

            <footer class="log-modal__footer">
                <button type="button" class="btn btn-outline-secondary" data-entry-modal-close>ยกเลิก</button>
                <button type="button" class="btn btn-outline-primary" data-entry-step-back hidden>ย้อนกลับ</button>
                <button type="button" class="btn btn-primary" data-entry-step-next>ถัดไป <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                <button type="submit" class="btn btn-primary" data-entry-submit hidden>บันทึก</button>
            </footer>
        </form>

        {{--
            ไฟล์แนบอยู่นอกฟอร์มหลักเพราะ HTML ซ้อนฟอร์มกันไม่ได้ และการแนบไฟล์
            ต้องมีบันทึกอยู่ก่อนแล้ว (ต้องรู้ id ปลายทาง) จึงแสดงเฉพาะตอนแก้ไข
        --}}
        <section class="log-attachments" data-entry-attachments hidden>
            <h3 class="log-attachments__heading">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
                หลักฐาน / ไฟล์แนบ
            </h3>

            <ul class="log-attachments__list" data-attachment-list></ul>

            <form method="POST" enctype="multipart/form-data"
                class="log-attachments__form" data-attachment-form>
                @csrf
                <label class="log-attachments__picker">
                    <input type="file" name="attachments[]" multiple
                        accept="{{ \App\Support\AttachmentPolicy::acceptAttribute() }}"
                        data-attachment-input>
                    <span><i class="bi bi-upload" aria-hidden="true"></i> เลือกไฟล์</span>
                </label>

                <button type="submit" class="btn btn-outline-primary btn-sm" data-attachment-submit>
                    แนบไฟล์
                </button>
            </form>

            <p class="log-attachments__hint">
                แนบได้ไม่เกิน {{ $design['maxAttachments'] }} ไฟล์ต่อหนึ่งบันทึก
            </p>

            <p class="log-modal__error" role="alert" data-attachment-error hidden></p>
        </section>

        @if($capabilities['canCreate'])
            {{--
                งานประจำของฉัน

                แม่แบบกำหนดวันในสัปดาห์และสร้างรายการจริงให้เจ้าของกับผู้ร่วมงานเมื่อถึงกำหนด
            --}}
            <div class="log-modal__routine" data-entry-panel="routine" hidden>

                {{-- ฟอร์มตั้งค่า ส่งแบบฟอร์มปกติแล้วโหลดหน้าใหม่ เพื่อพาไปเห็นรายการของวันนี้ที่ระบบเพิ่งวางให้ --}}
                <form method="POST" action="{{ route('daily-logs.routines.store') }}"
                    class="routine-add" data-routine-form data-routine-store-url="{{ route('daily-logs.routines.store') }}"
                    data-routine-create-label="เพิ่มงานประจำ">
                    @csrf
                    <input type="hidden" name="_method" value="POST" data-routine-method>

                    <div class="log-modal__body">
                        <div class="log-field-row">
                            <div class="log-field">
                                <label class="form-label" for="routineCategory">หมวดงาน</label>
                                <select class="form-select" id="routineCategory" name="work_log_category_id">
                                    <option value="">ไม่ระบุ</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="log-field">
                                <label class="form-label" for="routineTitle">ทำอะไร <span aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="routineTitle" name="title"
                                    maxlength="200" autocomplete="off"
                                    placeholder="เช่น เช็คคอม Call Center">
                            </div>
                        </div>

                        {{-- เลือกได้หลายวัน: แต่ละวันที่เลือกกลายเป็นแม่แบบวันเดียวหนึ่งรายการ (plan_dates[]) --}}
                        <div class="log-field log-field--multiple-date" data-routine-plan-date data-date-picker-field>
                            <label class="form-label" for="routinePlanDate">วันที่จากปฏิทิน <span aria-hidden="true">*</span> <small>เลือกได้หลายวัน</small></label>
                            <input type="date" class="form-control" id="routinePlanDate" name="plan_date"
                                value="{{ $dateValue }}" min="{{ $maxDate }}" required
                                data-date-picker
                                data-date-picker-multiple
                                data-date-picker-multiple-name="plan_dates[]">
                            <small class="log-field__date-summary" data-date-picker-summary aria-live="polite">เลือก 1 วัน</small>
                            <small class="log-field__date-list" data-date-picker-dates hidden></small>
                        </div>

                        {{-- วันทำงานเก็บเป็น bitmask (0 = จันทร์) --}}
                        <fieldset class="log-field routine-form__weekdays" data-routine-weekdays>
                            <legend class="form-label">วันไหนบ้าง</legend>
                            <div class="routine-form__days">
                                @foreach($weekdays as $index => $day)
                                    <label class="routine-day">
                                        <input type="checkbox" name="weekdays[]" value="{{ $index }}"
                                            @checked(($defaultMask & (1 << $index)) !== 0)>
                                        <span>{{ $day['short'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <fieldset class="log-field routine-form__window">
                            <legend class="form-label">ช่วงเวลาที่ต้องเข้าไปทำ</legend>
                            <div class="routine-form__times">
                                <label class="visually-hidden" for="routineStart">เวลาเริ่ม</label>
                                <input type="time" class="form-control" id="routineStart" name="default_start_time">
                                <span class="routine-form__times-sep" aria-hidden="true">ถึง</span>
                                <label class="visually-hidden" for="routineEnd">เวลาสิ้นสุด</label>
                                <input type="time" class="form-control" id="routineEnd" name="default_end_time">
                            </div>
                        </fieldset>

                        {{-- คนที่ถูกเลือกจะได้รายการของงานนี้ในหน้าบันทึกงานของตัวเองเมื่อถึงกำหนด --}}
                        @include('daily-logs.components.participant-picker', [
                            'instanceId' => 'routine-participant',
                            'options' => $participantOptions,
                            'selectedIds' => [],
                            'summaryLabel' => 'คนในงานนี้',
                        ])

                        <details class="routine-form__more">
                            <summary><i class="bi bi-sliders" aria-hidden="true"></i> เชื่อมโปรเจกต์และรายละเอียดเพิ่มเติม</summary>
                            <div class="routine-form__more-body">
                                <div class="log-field-row">
                                    <div class="log-field">
                                        <label class="form-label" for="routineProject">โปรเจกต์</label>
                                        <select class="form-select" id="routineProject" name="work_order_list_id">
                                            <option value="">ไม่เกี่ยวข้อง</option>
                                            @foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div class="log-field">
                                        <label class="form-label" for="routineTask">รายการงาน</label>
                                        <select class="form-select" id="routineTask" name="job_id">
                                            <option value="">ไม่เกี่ยวข้อง</option>
                                            @foreach($tasks as $task)<option value="{{ $task->job_id }}">{{ $task->job_topic }}</option>@endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="log-field">
                                    <label class="form-label" for="routineDetails">รายละเอียด</label>
                                    <textarea class="form-control" id="routineDetails" name="details" rows="2" maxlength="2000"></textarea>
                                </div>
                            </div>
                        </details>
                    </div>

                    <footer class="log-modal__footer">
                        <button type="button" class="btn btn-outline-secondary" data-entry-modal-close>ยกเลิก</button>
                        <button type="button" class="btn btn-outline-primary" data-entry-step-back hidden>ย้อนกลับ</button>
                        <button type="button" class="btn btn-primary" data-entry-step-next>ถัดไป <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                        <button type="submit" class="btn btn-primary" hidden>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> <span data-routine-submit-label>เพิ่มงานประจำ</span>
                        </button>
                        <button type="button" class="btn btn-link" data-routine-edit-cancel hidden>ยกเลิกแก้ไข</button>
                    </footer>
                </form>
            </div>
        @endif
    </div>
</div>

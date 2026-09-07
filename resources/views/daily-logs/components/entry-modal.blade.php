{{--
    ฟอร์มเต็มของบันทึกงาน — ใช้ทั้งสร้างใหม่และแก้ไข

    ที่นี่เป็น modal จริง (ต่างจากช่องบันทึกเร็วด้านบน) เพราะมีหลายช่องและกินโฟกัส
    การเปิด/ปิด backdrop โฟกัส Escape และการซ้อนชั้น เป็นหน้าที่ของ modal-stack
    ที่มีอยู่แล้วทั้งหมด ห้ามสลับ hidden เองในหน้านี้

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

        <form class="log-modal__form" method="POST" action="{{ route('daily-logs.store') }}" data-log-entry-form>
            @csrf
            <input type="hidden" name="_method" value="POST" data-entry-method>

            <div class="log-modal__body">
                <div class="log-field">
                    <label class="form-label" for="logEntryTitle">งานที่ทำ <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="logEntryTitle" name="title"
                        maxlength="200" required autocomplete="off" data-entry-title>
                </div>

                <div class="log-field">
                    <span class="form-label">ประเภทงาน</span>
                    <div class="log-modal__kinds">
                        @foreach($design['kinds'] as $key => $meta)
                            <label class="log-kind-option log-kind-option--{{ $meta['tone'] }}">
                                <input type="radio" name="kind" value="{{ $key }}" @checked($loop->first) data-entry-kind>
                                <span><i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i> {{ $meta['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="log-field-row">
                    <div class="log-field">
                        <label class="form-label" for="logEntryCategory">หมวดงาน</label>
                        <select class="form-select" id="logEntryCategory" name="work_log_category_id" data-entry-category>
                            <option value="">ไม่ระบุ</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="log-field">
                        <label class="form-label" for="logEntryDate">วันที่</label>
                        <input type="date" class="form-control" id="logEntryDate" name="work_date"
                            value="{{ $dateValue }}"
                            max="{{ \App\Support\TodayWorkspace::businessNow()->format('Y-m-d') }}"
                            data-entry-date>
                    </div>
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

                {{-- ช่องเฉพาะประเภท แสดงเมื่อเลือกประเภทที่เกี่ยวข้องเท่านั้น --}}
                <div class="log-field" data-entry-only-kind="field" hidden>
                    <label class="form-label" for="logEntryLocation">สถานที่</label>
                    <input type="text" class="form-control" id="logEntryLocation" name="location"
                        maxlength="120" placeholder="เช่น ศูนย์บริการรถยนต์" data-entry-location>
                </div>

                <div class="log-field" data-entry-only-kind="interrupt" hidden>
                    <label class="form-label" for="logEntryRequester">ผู้แจ้ง</label>
                    <input type="text" class="form-control" id="logEntryRequester" name="requester_name"
                        maxlength="120" placeholder="เช่น ฝ่ายบัญชี" data-entry-requester>
                </div>

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

                <p class="log-modal__error" role="alert" data-entry-error hidden></p>
            </div>

            <footer class="log-modal__footer">
                <button type="button" class="btn btn-outline-secondary" data-entry-modal-close>ยกเลิก</button>
                <button type="submit" class="btn btn-primary" data-entry-submit>บันทึก</button>
            </footer>
        </form>

        {{--
            ไฟล์แนบอยู่นอกฟอร์มหลักเพราะ HTML ซ้อนฟอร์มกันไม่ได้ และการแนบไฟล์
            ต้องมีบันทึกอยู่ก่อนแล้ว (ต้องรู้ id ปลายทาง) จึงแสดงเฉพาะตอนแก้ไข

            บล็อกนี้ถูกซ่อนไว้ตอนสร้างรายการใหม่ ผู้ใช้บันทึกก่อนแล้วเปิดแก้ไข
            เพื่อแนบไฟล์ ซึ่งเป็นลำดับที่ตรงกับการใช้งานจริง (ถ่ายรูปหน้างาน
            แล้วค่อยกลับมาแนบทีหลัง)
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
    </div>
</div>

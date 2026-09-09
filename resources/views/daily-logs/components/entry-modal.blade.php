{{--
    กล่องเพิ่มงาน — ทางเข้าเดียวของหน้าบันทึกงานประจำวัน

    รวมสองอย่างที่เคยแยกกันคนละปุ่มไว้ในกล่องเดียว:
    1. "ทำครั้งเดียว" — บันทึกงานที่เพิ่งทำ (เดิมคือแถบพิมพ์เร็วเหนือรายการ)
    2. "ทำซ้ำทุกวัน" — ตั้งงานประจำ (เดิมคือปุ่มแยกบนหัวหน้าจอ แล้วเปิดอีกกล่อง)

    เหตุผลที่รวม: ตอนกดปุ่มเพิ่มงาน ผู้ใช้ยังไม่ได้ตัดสินใจว่าสิ่งนี้จะทำครั้งเดียว
    หรือทำทุกวัน การบังคับให้เลือกตั้งแต่ตอนเลือกปุ่ม ทำให้ต้องรู้คำตอบก่อนเห็นฟอร์ม
    และถ้อยคำบนปุ่มทั้งสองฝั่งก็ซ้ำกันจนแยกไม่ออกว่าอันไหนทำอะไร

    การเปิด/ปิด backdrop โฟกัส Escape และการซ้อนชั้น เป็นหน้าที่ของ modal-stack
    ที่มีอยู่แล้วทั้งหมด ห้ามสลับ hidden เองในหน้านี้ (ยกเว้นการสลับ "โหมด"
    ข้างในกล่อง ซึ่งเป็นเนื้อในของฟอร์ม ไม่ใช่การเปิด/ปิด overlay)

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

        {{--
            แถบเลือกโหมด — ซ่อนตอนเปิดมาเพื่อ "แก้ไข" รายการเดิม เพราะรายการที่มีอยู่
            แล้วเปลี่ยนเป็นแม่แบบงานประจำไม่ได้ การให้เลือกจึงเป็นทางที่กดแล้วไม่มีผล
        --}}
        @if($capabilities['canCreate'])
            <div class="log-modal__modes" role="tablist" aria-label="ประเภทของสิ่งที่จะเพิ่ม" data-entry-modes>
                <button type="button" class="log-mode is-active" role="tab" aria-selected="true"
                    data-entry-mode="once">
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    <span>ทำครั้งเดียว</span>
                </button>
                <button type="button" class="log-mode" role="tab" aria-selected="false"
                    data-entry-mode="routine">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span>ทำซ้ำทุกวัน</span>
                </button>
            </div>
        @endif

        <form class="log-modal__form" method="POST" action="{{ route('daily-logs.store') }}"
            data-log-entry-form data-entry-panel="once">
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

        @if($capabilities['canCreate'])
            {{--
                โหมด "ทำซ้ำทุกวัน" — งานประจำ

                ตั้งครั้งเดียวแล้วระบบวางรายการให้ใหม่ทุกวันที่ถึงกำหนด สถานะของ
                เมื่อวานไม่ติดมาด้วย เพราะเป็นคนละแถวกันของคนละวัน

                ประเภทงานถูกตั้งเป็นค่าเริ่มต้นที่ฝั่งเซิร์ฟเวอร์อยู่แล้ว
                (WorkLogDesign::DEFAULT_KIND) การให้เลือกซ้ำในโหมดที่ชื่อว่า
                "ทำซ้ำทุกวัน" ไม่ได้ช่วยอะไร
            --}}
            <div class="log-modal__routine" data-entry-panel="routine" hidden>
                <div class="log-modal__body">
                    <p class="routine-modal__lead">
                        ตั้งครั้งเดียว ระบบจะวางรายการให้ใหม่ทุกวันที่เลือก พร้อมช่วงเวลาที่ต้องเข้าไปทำ
                        — เช้าวันถัดไปรายการจะกลับมาเป็น "ที่ต้องทำ" ให้เองโดยไม่ต้องพิมพ์ซ้ำ
                    </p>

                    {{-- รายการที่มีอยู่ — แถวเตี้ย ๆ ไม่ใช่การ์ด เพราะเป็นรายการอ้างอิง
                         ที่ผู้ใช้แค่กวาดตาดูว่ามีอะไรอยู่แล้วบ้าง ไม่ได้ลงมือกับมันบ่อย --}}
                    @if($routineTemplates->isNotEmpty())
                        <ul class="routine-rows">
                            @foreach($routineTemplates as $template)
                                <li class="routine-row @unless($template->is_active) routine-row--inactive @endunless"
                                    data-routine-row
                                    data-routine-edit-url="{{ route('daily-logs.routines.update', $template) }}"
                                    data-routine-values="{{ json_encode([
                                        "title" => $template->title,
                                        "kind" => $template->kind,
                                        "weekdays" => collect(range(0, 6))->filter(fn ($day) => ((int) $template->weekday_mask & (1 << $day)) !== 0)->values(),
                                        "start" => $template->default_start_time ? substr($template->default_start_time, 0, 5) : null,
                                        "end" => $template->default_start_time && $template->default_duration_minutes ? \Carbon\Carbon::createFromFormat("H:i:s", $template->normalizedStartTime())->addMinutes($template->default_duration_minutes)->format("H:i") : null,
                                        "category" => $template->work_log_category_id,
                                        "project" => $template->work_order_list_id,
                                        "task" => $template->job_id,
                                        "details" => $template->details,
                                        "participants" => $template->participants->pluck("id"),
                                    ]) }}">
                                    <span class="routine-row__body">
                                        <span class="routine-row__title">{{ $template->title }}</span>
                                        <span class="routine-row__meta">
                                            {{ \App\Support\WorkLogWeekdays::label((int) $template->weekday_mask) }}
                                            @if($template->plannedWindowLabel())
                                                · {{ $template->plannedWindowLabel() }}
                                            @endif
                                            @if($template->participants->isNotEmpty())
                                                · ทำด้วยกัน {{ $template->participants->count() }} คน
                                            @endif
                                        </span>
                                    </span>

                                    <div class="routine-row__actions">
                                        <button type="button" class="routine-row__edit" data-routine-edit aria-label="แก้ไขงานประจำ {{ $template->title }}"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                    <form method="POST" action="{{ route('daily-logs.routines.destroy', $template) }}"
                                        data-routine-delete>
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="routine-row__delete"
                                            aria-label="ลบงานประจำ {{ $template->title }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- งานประจำที่เพื่อนร่วมแผนกตั้งไว้แล้วใส่ชื่อเราเป็นผู้ร่วมงาน
                         อ่านอย่างเดียว เจ้าของแม่แบบเป็นคนเดียวที่แก้ไขหรือลบได้
                         (บังคับจริงที่ WorkLogTemplatePolicy ไม่ใช่การซ่อนปุ่มตรงนี้) --}}
                    @if($sharedRoutines->isNotEmpty())
                        <h3 class="routine-modal__section">ได้รับมอบหมายจากเพื่อนร่วมแผนก</h3>

                        <ul class="routine-rows">
                            @foreach($sharedRoutines as $template)
                                <li class="routine-row routine-row--shared">
                                    <span class="routine-row__body">
                                        <span class="routine-row__title">{{ $template->title }}</span>
                                        <span class="routine-row__meta">
                                            {{ \App\Support\WorkLogWeekdays::label((int) $template->weekday_mask) }}
                                            @if($template->plannedWindowLabel())
                                                · {{ $template->plannedWindowLabel() }}
                                            @endif
                                            · จาก {{ $template->user?->name }}
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if($routineTemplates->isEmpty() && $sharedRoutines->isEmpty())
                        <p class="routine-modal__empty">ยังไม่มีงานประจำ — เพิ่มงานที่ต้องทำซ้ำทุกวันได้ข้างล่างนี้</p>
                    @endif
                </div>

                {{--
                    ฟอร์มเพิ่ม

                    ส่งแบบฟอร์มปกติแล้วโหลดหน้าใหม่ ไม่ทำ AJAX เพราะผลลัพธ์ที่ผู้ใช้
                    ต้องเห็นคือ "รายการของวันนี้" ที่ระบบเพิ่งวางให้ ซึ่งอยู่หลังกล่องนี้
                    อยู่แล้ว การโหลดหน้าใหม่จึงเป็นการพาไปดูผลลัพธ์พอดี
                --}}
                <form method="POST" action="{{ route('daily-logs.routines.store') }}"
                    class="routine-add" data-routine-form data-routine-store-url="{{ route('daily-logs.routines.store') }}">
                    @csrf
                    <input type="hidden" name="_method" value="POST" data-routine-method>

                    <div class="log-modal__body">
                        <div class="log-field">
                            <label class="form-label" for="routineTitle">ทำอะไร <span aria-hidden="true">*</span></label>
                            <input type="text" class="form-control" id="routineTitle" name="title"
                                maxlength="200" autocomplete="off"
                                placeholder="เช่น เช็คคอมพิวเตอร์ห้องบัญชี">
                        </div>

                        <fieldset class="log-field">
                            <legend class="form-label">ประเภทงาน</legend>
                            <div class="log-modal__kinds">
                                @foreach($design['kinds'] as $key => $meta)
                                    <label class="log-kind-option log-kind-option--{{ $meta['tone'] }}">
                                        <input type="radio" name="kind" value="{{ $key }}" @checked($loop->first)>
                                        <span><i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i> {{ $meta['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        {{-- วันทำงานเก็บเป็น bitmask ฝั่งเซิร์ฟเวอร์ ฟอร์มจึงส่งเป็นดัชนีวัน
                             (0 = จันทร์) แล้วให้ WorkLogWeekdays::mask() เป็นผู้ประกอบ --}}
                        <fieldset class="log-field routine-form__weekdays">
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

                        {{-- คนที่ถูกเลือกจะได้รายการของงานนี้ในหน้าบันทึกงานของ "ตัวเอง"
                             ทุกวันที่ถึงกำหนด และได้รับการแจ้งเตือนตอนถูกเพิ่ม --}}
                        @include('daily-logs.components.participant-picker', [
                            'instanceId' => 'routine-participant',
                            'options' => $participantOptions,
                            'selectedIds' => [],
                            'summaryLabel' => 'ให้ใครทำด้วย',
                        ])

                        <details class="routine-form__more">
                            <summary><i class="bi bi-sliders" aria-hidden="true"></i> เชื่อมโปรเจกต์และรายละเอียดเพิ่มเติม</summary>
                            <div class="routine-form__more-body">
                                <div class="log-field">
                                    <label class="form-label" for="routineCategory">หมวดงาน</label>
                                    <select class="form-select" id="routineCategory" name="work_log_category_id">
                                        <option value="">ไม่ระบุ</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
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
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> <span data-routine-submit-label>เพิ่มงานประจำ</span>
                        </button>
                        <button type="button" class="btn btn-link" data-routine-edit-cancel hidden>ยกเลิกแก้ไข</button>
                    </footer>
                </form>
            </div>
        @endif
    </div>
</div>

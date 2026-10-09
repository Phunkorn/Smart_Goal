{{--
    แผงตรวจรายเครื่อง — ใช้ชุดเดียวทั้งเดสก์ท็อปและมือถือ

    มาร์กอัปนี้มีชุดเดียวในหน้า ไม่ใช่แผงข้างหนึ่งใบกับ bottom sheet อีกใบ
    ความต่างอยู่ที่ CSS เท่านั้น:
      - เดสก์ท็อป  — คอลัมน์ขวาของผัง
      - มือถือ     — bottom sheet ที่เลื่อนขึ้นจากขอบล่าง ไม่ใช่กล่องเต็มจอ

    ทำแบบนี้เพราะถ้าแยกเป็นสองชุด การแก้รายการอุปกรณ์หรือกติกาการบันทึกจะต้องทำ
    สองรอบ แล้วรอบที่สองจะถูกลืมเสมอ (กติกา "แหล่งความจริงเดียว" ใน CLAUDE.md)

    @param array $design  ComputerCheckDesign::forClient()
    @param bool $canEdit
--}}
@php
    use App\Support\ComputerCheckDesign;
@endphp

<aside class="cc-panel" data-cc-panel aria-labelledby="ccPanelTitle" hidden>
    {{-- ฉากหลังของ bottom sheet บนมือถือ เดสก์ท็อปซ่อนไว้ด้วย CSS
         แผงนี้ไม่ใช่ modal จึงไม่ล็อกการเลื่อนของทั้งหน้า และปิดได้ด้วยการแตะนอกแผง --}}
    <div class="cc-panel__scrim" data-cc-panel-scrim aria-hidden="true"></div>

    <div class="cc-panel__surface" role="dialog" aria-modal="false" aria-labelledby="ccPanelTitle">
        <span class="cc-panel__grip" aria-hidden="true"></span>

        <header class="cc-panel__head">
            <div class="cc-panel__identity">
                <h2 class="cc-panel__title" id="ccPanelTitle" data-cc-panel-title>รายละเอียดการตรวจเช็ก</h2>
                <p class="cc-panel__subtitle" data-cc-panel-subtitle>เลือกเครื่องจากผังห้อง</p>
            </div>
            <button type="button" class="cc-panel__close" data-cc-panel-close aria-label="ปิดแผงตรวจ">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        {{-- โหมดเลือกเครื่องเดียว — ติ๊กผลอุปกรณ์ทีละชิ้น --}}
        <form class="cc-panel__body" data-cc-result-form data-cc-panel-mode="single" hidden>
            @csrf
            <input type="hidden" name="_method" value="PATCH">

            <ul class="cc-checklist">
                @foreach(ComputerCheckDesign::DEVICES as $deviceKey => $device)
                    <li class="cc-checklist__item">
                        <span class="cc-checklist__label">
                            <i class="bi {{ $device['icon'] }}" aria-hidden="true"></i>
                            {{ $device['label'] }}
                        </span>

                        {{--
                            สามสถานะเป็น radio ไม่ใช่ checkbox ตัวเดียว
                            เพราะ "ยังไม่ได้ตรวจ" กับ "ตรวจแล้วและมีปัญหา" ต้องแยกออกจากกัน
                            ถ้าใช้ checkbox ช่องที่ยังไม่ติ๊กจะอ่านเป็นค่าเดียวกับช่องที่เสีย
                        --}}
                        <fieldset class="cc-tristate" data-cc-tristate="{{ $deviceKey }}">
                            <legend class="visually-hidden">ผลตรวจ {{ $device['label'] }}</legend>
                            @foreach(ComputerCheckDesign::DEVICE_STATUSES as $statusKey => $status)
                                <label class="cc-tristate__option cc-tristate__option--{{ $statusKey }}">
                                    <input type="radio"
                                        name="{{ $deviceKey }}"
                                        value="{{ $statusKey }}"
                                        @checked($statusKey === 'pending')
                                        @disabled(! $canEdit)>
                                    <span>
                                        <i class="bi {{ $status['icon'] }}" aria-hidden="true"></i>
                                        {{ $status['label'] }}
                                    </span>
                                </label>
                            @endforeach
                        </fieldset>
                    </li>
                @endforeach
            </ul>

            @if($canEdit)
                <button type="button" class="cc-panel__bulk" data-cc-mark-all-normal>
                    <i class="bi bi-check2-all" aria-hidden="true"></i> ปกติทั้งหมด
                </button>
            @endif

            {{-- ช่องรายละเอียดปัญหาโผล่เฉพาะเมื่อมีอุปกรณ์ใดถูกเลือกว่า "มีปัญหา"
                 เซิร์ฟเวอร์บังคับกติกาเดียวกันอีกชั้นที่ ComputerCheckService::saveResult() --}}
            <div class="cc-panel__note" data-cc-note-field hidden>
                <label class="form-label" for="ccResultNote">รายละเอียดปัญหา <span aria-hidden="true">*</span></label>
                <textarea class="form-control" id="ccResultNote" name="note" rows="3"
                    maxlength="{{ ComputerCheckDesign::MAX_NOTE_LENGTH }}"
                    placeholder="เช่น Microphone — ไม่มีเสียง"
                    @disabled(! $canEdit)
                    data-cc-note></textarea>
            </div>

            <p class="cc-panel__meta" data-cc-panel-meta hidden></p>
            <p class="cc-panel__error" role="alert" data-cc-panel-error hidden></p>

            @if($canEdit)
                <footer class="cc-panel__foot">
                    <button type="button" class="btn btn-outline-secondary" data-cc-panel-close>ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" data-cc-panel-save>บันทึก</button>
                </footer>
            @endif
        </form>

        {{--
            โหมดเลือกหลายเครื่อง — ตั้งเป็นปกติพร้อมกันได้อย่างเดียว

            ไม่มีปุ่ม "มีปัญหา" ในโหมดนี้โดยตั้งใจ รายละเอียดปัญหาของแต่ละเครื่อง
            ไม่เหมือนกัน การกดทีเดียวให้หลายเครื่องมีปัญหาจะได้ข้อความซ้ำกันทุกเครื่อง
            ซึ่งไม่มีประโยชน์กับคนที่ต้องมาตามแก้ (เซิร์ฟเวอร์ก็รับเฉพาะ "ปกติ")
        --}}
        <div class="cc-panel__body cc-panel__body--bulk" data-cc-panel-mode="multi" hidden>
            <p class="cc-panel__bulk-count" data-cc-bulk-count>เลือกไว้ 0 เครื่อง</p>
            <ul class="cc-panel__bulk-list" data-cc-bulk-list></ul>

            @if($canEdit)
                <button type="button" class="btn btn-primary cc-panel__bulk-submit" data-cc-bulk-normal>
                    <i class="bi bi-check2-all" aria-hidden="true"></i> ตั้งทุกเครื่องที่เลือกเป็นปกติ
                </button>
                <button type="button" class="btn btn-outline-secondary" data-cc-bulk-clear>ล้างการเลือก</button>
            @endif

            <p class="cc-panel__hint">
                บันทึก "มีปัญหา" ต้องทำทีละเครื่อง เพราะรายละเอียดปัญหาของแต่ละเครื่องไม่เหมือนกัน
            </p>
            <p class="cc-panel__error" role="alert" data-cc-bulk-error hidden></p>
        </div>

        {{-- สถานะเริ่มต้นก่อนเลือกเครื่อง --}}
        <div class="cc-panel__body cc-panel__empty" data-cc-panel-mode="empty">
            <i class="bi bi-hand-index-thumb" aria-hidden="true"></i>
            <p>แตะเครื่องบนผังห้องเพื่อเริ่มตรวจ</p>
        </div>
    </div>
</aside>

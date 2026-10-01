{{--
    แถบเครื่องมือของกระดาน — แถวที่สอง (รูปแบบของสิ่งที่กำลังทำอยู่)

    ทุกกลุ่มในนี้ย้ายมาจากแถวเดียวเดิมทั้งชุด ไม่ได้คัดลอกมา markup และ data
    attribute ทุกตัวจึงยังเป็นชุดเดิมที่ toolbar.js กับ toolbar-popover.js รู้จักอยู่แล้ว

    ความต่างคือแถวนี้โผล่เฉพาะเมื่อมีอะไรให้ปรับจริง และแต่ละกลุ่มโผล่แยกกัน
    ผู้ตัดสินคือ contextGroupsFor() ใน resources/js/pages/workspace/toolbar-context.js
    โดย toolbar.js เป็นผู้ตั้ง attribute hidden ให้ทั้งแถวและแต่ละกลุ่มเพียงผู้เดียว

    ป้ายของกลุ่มมาจาก WorkspaceDesign::CONTEXT_GROUPS ซึ่งคีย์ต้องตรงกับ
    CONTEXT_GROUPS ฝั่ง JavaScript
--}}
@php
    $design = \App\Support\WorkspaceDesign::class;
    $contextGroups = $design::CONTEXT_GROUPS;
@endphp

<div class="wsb-toolbar wsb-toolbar--context" data-workspace-context-toolbar id="wsbContextToolbar"
    role="toolbar" aria-label="รูปแบบ" hidden>
    {{--
        สีเส้นและตัวอักษร: วางสีที่ใช้บ่อยไว้ไม่กี่สี ที่เหลือเก็บในแผงที่กดเปิด

        ปุ่มเปิดแผงแสดงสีที่ใช้อยู่ตอนนี้ ผู้ใช้จึงรู้เสมอว่ากำลังวาดด้วยสีอะไร
        แม้สีนั้นจะไม่อยู่ในแถวลัด (เช่นสีที่เลือกเองจากหลอดดูดสี)

        แผงเป็น popover ไม่ใช่ modal ควบคุมโดย toolbar-popover.js ปุ่มสีทุกปุ่ม
        ทั้งในแถวลัดและในแผงใช้ data-color ตัวเดียวกัน toolbar.js จึงจัดการด้วย
        ตัวจัดการเดียว ไม่มีสองชุดที่ต้องคอยให้ตรงกัน
    --}}
    <div class="wsb-toolbar__group" data-context-group="stroke"
        role="group" aria-label="{{ $contextGroups['stroke'] }}">
        @foreach ($design::QUICK_PALETTE as $color)
            <button type="button"
                class="wsb-swatch"
                data-color="{{ $color }}"
                data-requires-edit
                aria-pressed="false"
                style="--wsb-swatch: {{ $color }}"
                aria-label="สี {{ $color }}"
                title="สี {{ $color }}">
                <span class="wsb-swatch__dot" aria-hidden="true"></span>
            </button>
        @endforeach

        <div class="wsb-picker" data-picker>
            <button type="button" class="wsb-picker__toggle"
                data-picker-toggle
                data-requires-edit
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="wsbStrokePalette"
                aria-label="สีทั้งหมด"
                data-tooltip="สีทั้งหมด">
                <span class="wsb-swatch__dot" data-picker-current="stroke"
                    style="--wsb-swatch: {{ $design::DEFAULT_STROKE }}" aria-hidden="true"></span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>

            <div class="wsb-popover" id="wsbStrokePalette" data-picker-panel hidden
                role="group" aria-label="สีเส้นและตัวอักษรทั้งหมด">
                <div class="wsb-palette" style="--wsb-palette-cols: {{ $design::PALETTE_COLUMNS }}">
                    @foreach ($design::PALETTE as $color)
                        <button type="button"
                            class="wsb-swatch"
                            data-color="{{ $color }}"
                            data-requires-edit
                            aria-pressed="false"
                            style="--wsb-swatch: {{ $color }}"
                            aria-label="สี {{ $color }}"
                            title="สี {{ $color }}">
                            <span class="wsb-swatch__dot" aria-hidden="true"></span>
                        </button>
                    @endforeach
                </div>

                {{--
                    ช่องเลือกสีของเบราว์เซอร์ ให้สีได้ไม่จำกัด ค่าที่คืนมาเป็น #rrggbb
                    ซึ่งผ่าน WorkspaceDocumentValidator อยู่แล้ว
                --}}
                <label class="wsb-custom-color">
                    <input type="color" data-custom-color data-requires-edit
                        value="{{ $design::DEFAULT_STROKE }}"
                        aria-label="{{ $design::CUSTOM_COLOR_HINT }}">
                    <i class="bi bi-eyedropper" aria-hidden="true"></i>
                    <span>{{ $design::CUSTOM_COLOR_HINT }}</span>
                </label>
            </div>
        </div>
    </div>

    <div class="wsb-toolbar__group" data-context-group="sticky"
        role="group" aria-label="{{ $contextGroups['sticky'] }}">
        @foreach ($design::QUICK_STICKY_COLORS as $color)
            <button type="button"
                class="wsb-swatch wsb-swatch--sticky"
                data-sticky-color="{{ $color }}"
                data-requires-edit
                aria-pressed="false"
                style="--wsb-swatch: {{ $color }}"
                aria-label="สีกระดาษโน้ต {{ $color }}"
                title="สีกระดาษโน้ต {{ $color }}">
                <span class="wsb-swatch__dot" aria-hidden="true"></span>
            </button>
        @endforeach

        <div class="wsb-picker" data-picker>
            <button type="button" class="wsb-picker__toggle wsb-picker__toggle--sticky"
                data-picker-toggle
                data-requires-edit
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="wsbStickyPalette"
                aria-label="สีกระดาษโน้ตทั้งหมด"
                data-tooltip="สีกระดาษโน้ตทั้งหมด">
                <span class="wsb-swatch__dot" data-picker-current="sticky"
                    style="--wsb-swatch: {{ $design::DEFAULT_STICKY_COLOR }}" aria-hidden="true"></span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>

            <div class="wsb-popover" id="wsbStickyPalette" data-picker-panel hidden
                role="group" aria-label="สีกระดาษโน้ตทั้งหมด">
                <div class="wsb-palette" style="--wsb-palette-cols: {{ $design::PALETTE_COLUMNS }}">
                    @foreach ($design::STICKY_COLORS as $color)
                        <button type="button"
                            class="wsb-swatch wsb-swatch--sticky"
                            data-sticky-color="{{ $color }}"
                            data-requires-edit
                            aria-pressed="false"
                            style="--wsb-swatch: {{ $color }}"
                            aria-label="สีกระดาษโน้ต {{ $color }}"
                            title="สีกระดาษโน้ต {{ $color }}">
                            <span class="wsb-swatch__dot" aria-hidden="true"></span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{--
        ความหนาเส้น: ปุ่มเดียวที่แสดงความหนาปัจจุบัน กดแล้วเลือกจากแผง
        แบบเดียวกับสี เปลี่ยนความหนาไม่บ่อยเท่าเปลี่ยนเครื่องมือ จึงไม่ต้องวาง
        ครบทุกขนาดบนแถบให้กินที่จนแถบตกบรรทัด
    --}}
    <div class="wsb-toolbar__group" data-context-group="width"
        role="group" aria-label="{{ $contextGroups['width'] }}">
        <div class="wsb-picker" data-picker>
            <button type="button" class="wsb-picker__toggle"
                data-picker-toggle
                data-requires-edit
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="wsbStrokeWidths"
                aria-label="ความหนาเส้น"
                data-tooltip="ความหนาเส้น">
                <span class="wsb-width__bar" data-picker-current="width"
                    style="--wsb-width: {{ min($design::DEFAULT_STROKE_WIDTH, 12) }}px" aria-hidden="true"></span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>

            <div class="wsb-popover wsb-popover--row" id="wsbStrokeWidths" data-picker-panel hidden
                role="group" aria-label="ความหนาเส้น">
                @foreach ($design::STROKE_WIDTHS as $width)
                    <button type="button"
                        class="wsb-width"
                        data-stroke-width="{{ $width }}"
                        data-requires-edit
                        aria-pressed="false"
                        aria-label="ความหนา {{ $width }}"
                        title="ความหนา {{ $width }}">
                        <span class="wsb-width__bar" style="--wsb-width: {{ min($width, 12) }}px" aria-hidden="true"></span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{--
        ขนาดตัวอักษรของกระดาษโน้ตและกล่องข้อความ

        เป็นช่องกรอกตัวเลข ไม่ใช่ปุ่มสำเร็จรูป เพราะผู้ใช้ต้องการระบุขนาดเองได้
        เช่น 16 พอดี ๆ ปุ่มลัดข้าง ๆ ไว้กดเพิ่มลดทีละขั้นโดยไม่ต้องพิมพ์

        min/max ต้องตรงกับช่วงที่ WorkspaceDocumentValidator ยอมรับ ไม่งั้นค่าที่
        กรอกจะถูกเซิร์ฟเวอร์ปัดทิ้งเงียบ ๆ แล้วเด้งกลับหลังรีเฟรช
    --}}
    <div class="wsb-toolbar__group" data-context-group="font"
        role="group" aria-label="{{ $contextGroups['font'] }}">
        <button type="button" class="wsb-tool" data-font-step="-2" data-requires-edit
            aria-label="ลดขนาดตัวอักษร" data-tooltip="ลดขนาดตัวอักษร">
            <i class="bi bi-dash-lg" aria-hidden="true"></i>
        </button>

        <label class="wsb-fontfield" title="ขนาดตัวอักษร (พิกเซล)">
            <input type="number" class="wsb-fontfield__input"
                data-font-size-input data-requires-edit
                value="{{ $design::DEFAULT_FONT_SIZE }}"
                min="{{ $design::MIN_FONT_SIZE }}"
                max="{{ $design::MAX_FONT_SIZE }}"
                step="1"
                inputmode="numeric"
                aria-label="ขนาดตัวอักษรเป็นพิกเซล">
            <span class="wsb-fontfield__unit" aria-hidden="true">px</span>
        </label>

        <button type="button" class="wsb-tool" data-font-step="2" data-requires-edit
            aria-label="เพิ่มขนาดตัวอักษร" data-tooltip="เพิ่มขนาดตัวอักษร">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
    </div>

    {{--
        รูปแบบตัวอักษร: ตัวหนา ตัวเอียง และระยะห่างตัวอักษร ของกระดาษโน้ตและ
        กล่องข้อความ

        ปุ่มหนา/เอียงเป็นปุ่มค้างสถานะแบบเดียวกับปุ่มเต็มจอ (.wsb-tool + is-active
        + aria-pressed) ไม่ใช่คอมโพเนนต์ใหม่ ส่วนช่องระยะห่างใช้ลวดลายเดียวกับ
        ช่องขนาดตัวอักษรข้างบน (ปุ่มขั้นคู่ + ช่องกรอกตัวเลข) เพราะเป็นค่าที่ผู้ใช้
        ต้องการกรอกเองได้เหมือนกัน

        min/max ต้องตรงกับ WorkspaceDocumentValidator เหตุผลเดียวกับหมายเหตุของ
        ขนาดตัวอักษรข้างบน
    --}}
    <div class="wsb-toolbar__group" data-context-group="textstyle"
        role="group" aria-label="{{ $contextGroups['textstyle'] }}">
        <button type="button" class="wsb-tool" data-bold-toggle data-requires-edit
            aria-pressed="false" aria-label="ตัวหนา" data-tooltip="ตัวหนา">
            <i class="bi bi-type-bold" aria-hidden="true"></i>
        </button>

        <button type="button" class="wsb-tool" data-italic-toggle data-requires-edit
            aria-pressed="false" aria-label="ตัวเอียง" data-tooltip="ตัวเอียง">
            <i class="bi bi-type-italic" aria-hidden="true"></i>
        </button>

        <button type="button" class="wsb-tool" data-letter-spacing-step="-1" data-requires-edit
            aria-label="ลดระยะห่างตัวอักษร" data-tooltip="ลดระยะห่างตัวอักษร">
            <i class="bi bi-dash-lg" aria-hidden="true"></i>
        </button>

        <label class="wsb-fontfield" title="ระยะห่างตัวอักษร (พิกเซล)">
            <input type="number" class="wsb-fontfield__input"
                data-letter-spacing-input data-requires-edit
                value="{{ $design::DEFAULT_LETTER_SPACING }}"
                min="{{ $design::MIN_LETTER_SPACING }}"
                max="{{ $design::MAX_LETTER_SPACING }}"
                step="1"
                inputmode="numeric"
                aria-label="ระยะห่างตัวอักษรเป็นพิกเซล">
            <span class="wsb-fontfield__unit" aria-hidden="true">px</span>
        </label>

        <button type="button" class="wsb-tool" data-letter-spacing-step="1" data-requires-edit
            aria-label="เพิ่มระยะห่างตัวอักษร" data-tooltip="เพิ่มระยะห่างตัวอักษร">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
    </div>

    {{--
        การจัดบรรทัดของกระดาษโน้ตและกล่องข้อความ: ชิดซ้าย กึ่งกลาง ชิดขวา

        ปุ่มค้างสถานะแบบเดียวกับปุ่มหนา/เอียงข้างบน แต่เลือกได้ทีละปุ่มเดียว
        (เหมือนความหนาเส้น) ไม่ใช่สลับเปิด/ปิดอิสระแบบตัวหนา/ตัวเอียง

        ขณะเปิดแก้ไขกล่องข้อความอยู่ ปุ่มพวกนี้มีผลเฉพาะย่อหน้าที่เคอร์เซอร์/
        ตัวเลือกแตะอยู่เท่านั้น (แต่ละบรรทัดในกล่องเดียวกันจัดคนละแบบได้ ดู
        overlay-text.js) ส่วนตอนแค่เลือกกล่องทั้งใบด้วยเครื่องมือเลือก จะมีผล
        กับทุกบรรทัดในกล่องนั้นเหมือนกันหมด ดู index.js ที่ onSelectAlign

        ปุ่มลัด Ctrl+Shift+L/E/R ใช้ชุดเดียวกับ Word และ Google Docs และเป็น
        ปุ่มลัดกลุ่มเดียวของหน้านี้ที่ทำงานได้ "ระหว่างพิมพ์" (ดู keyboard.js)
        เพราะถ้าเงียบตามกติกาของปุ่มลัดอื่นก็ไม่เหลือประโยชน์อะไร

        ปุ่มกลุ่มนี้ต้องไม่แย่งเคอร์เซอร์ไปจากกล่องที่กำลังพิมพ์อยู่ ตัวที่กันไว้
        คือตัวจัดการ mousedown ใน toolbar.js ซึ่งครอบทั้งแถวนี้

        ชุดค่ามาจาก WorkspaceDesign::TEXT_ALIGNS และต้องตรงกับ
        WorkspaceDocumentValidator::lineAligns()
    --}}
    <div class="wsb-toolbar__group" data-context-group="align"
        role="group" aria-label="{{ $contextGroups['align'] }}">
        <button type="button" class="wsb-tool" data-align="left" data-requires-edit
            aria-pressed="true" aria-label="ชิดซ้าย" data-tooltip="ชิดซ้าย"
            data-shortcut="Ctrl+Shift+L" aria-keyshortcuts="Control+Shift+L">
            <i class="bi bi-text-left" aria-hidden="true"></i>
        </button>

        <button type="button" class="wsb-tool" data-align="center" data-requires-edit
            aria-pressed="false" aria-label="กึ่งกลาง" data-tooltip="กึ่งกลาง"
            data-shortcut="Ctrl+Shift+E" aria-keyshortcuts="Control+Shift+E">
            <i class="bi bi-text-center" aria-hidden="true"></i>
        </button>

        <button type="button" class="wsb-tool" data-align="right" data-requires-edit
            aria-pressed="false" aria-label="ชิดขวา" data-tooltip="ชิดขวา"
            data-shortcut="Ctrl+Shift+R" aria-keyshortcuts="Control+Shift+R">
            <i class="bi bi-text-right" aria-hidden="true"></i>
        </button>
    </div>
</div>

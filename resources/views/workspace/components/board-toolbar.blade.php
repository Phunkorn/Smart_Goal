{{--
    แถบเครื่องมือของกระดาน

    ทุกป้ายชื่อ สี และความหนามาจาก App\Support\WorkspaceDesign แหล่งเดียว
    ห้ามพิมพ์ข้อความไทยซ้ำในไฟล์ .js

    ปุ่มที่แก้เนื้อหาถูกทำเครื่องหมาย data-requires-edit ไว้ toolbar.js จะปิด
    ทั้งชุดเมื่อ $capabilities['canEdit'] เป็นเท็จ การซ่อนปุ่มเป็นเรื่องของหน้าจอ
    เท่านั้น การบังคับใช้จริงอยู่ที่ WorkspaceBoardPolicy ฝั่งเซิร์ฟเวอร์
--}}
@php
    $design = \App\Support\WorkspaceDesign::class;
    $readOnlyTools = ['hand', 'select'];
@endphp

<div class="wsb-toolbar" data-workspace-toolbar role="toolbar" aria-label="เครื่องมือวาด">
    <div class="wsb-toolbar__group" role="group" aria-label="เครื่องมือ">
        @foreach ($design::TOOLS as $key => $tool)
            {{--
                รูปภาพไม่ใช่ "เครื่องมือ" ที่ค้างสถานะไว้เหมือนดินสอ แต่เป็นคำสั่ง
                ที่เปิดหน้าต่างเลือกไฟล์แล้วจบ จึงใช้ data-command ไม่ใช่ data-tool
            --}}
            @if ($key === 'image')
                <button type="button"
                    class="wsb-tool"
                    data-command="attach-image"
                    data-requires-edit
                    title="{{ $tool['label'] }}"
                    aria-label="{{ $tool['label'] }}">
                    <i class="bi {{ $tool['icon'] }}" aria-hidden="true"></i>
                </button>
                @continue
            @endif

            <button type="button"
                class="wsb-tool"
                data-tool="{{ $key }}"
                @unless (in_array($key, $readOnlyTools, true)) data-requires-edit @endunless
                aria-pressed="false"
                title="{{ $tool['label'] }}{{ $tool['shortcut'] ? ' ('.$tool['shortcut'].')' : '' }}"
                aria-label="{{ $tool['label'] }}">
                <i class="bi {{ $tool['icon'] }}" aria-hidden="true"></i>
            </button>
        @endforeach
    </div>

    {{--
        จานสีจัดเป็นกริดสองแถว แถวบนสีเข้มสำหรับเส้นและตัวอักษร แถวล่างเฉดอ่อน
        สำหรับไฮไลต์ วางเป็นกริดแทนแถวเดียวยาว ๆ เพื่อไม่ให้แถบเครื่องมือ
        กว้างจนดันกลุ่มอื่นตกขอบ

        ชุดสีนี้เป็นทางลัด ช่องเลือกสีเองอยู่ท้ายกลุ่มสำหรับสีที่ไม่มีในชุด
    --}}
    <div class="wsb-toolbar__group" role="group" aria-label="สีเส้นและตัวอักษร">
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
        <label class="wsb-custom-color" title="{{ $design::CUSTOM_COLOR_HINT }}">
            <input type="color" data-custom-color data-requires-edit
                value="{{ $design::DEFAULT_STROKE }}"
                aria-label="{{ $design::CUSTOM_COLOR_HINT }}">
            <i class="bi bi-eyedropper" aria-hidden="true"></i>
        </label>
    </div>

    <div class="wsb-toolbar__group" role="group" aria-label="สีกระดาษโน้ต">
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

    {{--
        ขนาดตัวอักษรของกระดาษโน้ตและกล่องข้อความ

        เป็นช่องกรอกตัวเลข ไม่ใช่ปุ่มสำเร็จรูป เพราะผู้ใช้ต้องการระบุขนาดเองได้
        เช่น 16 พอดี ๆ ปุ่มลัดข้าง ๆ ไว้กดเพิ่มลดทีละขั้นโดยไม่ต้องพิมพ์

        min/max ต้องตรงกับช่วงที่ WorkspaceDocumentValidator ยอมรับ ไม่งั้นค่าที่
        กรอกจะถูกเซิร์ฟเวอร์ปัดทิ้งเงียบ ๆ แล้วเด้งกลับหลังรีเฟรช
    --}}
    <div class="wsb-toolbar__group" role="group" aria-label="ขนาดตัวอักษร">
        <button type="button" class="wsb-tool" data-font-step="-2" data-requires-edit
            aria-label="ลดขนาดตัวอักษร" title="ลดขนาดตัวอักษร">
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
            aria-label="เพิ่มขนาดตัวอักษร" title="เพิ่มขนาดตัวอักษร">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="wsb-toolbar__group" role="group" aria-label="ความหนาเส้น">
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

    <div class="wsb-toolbar__group" role="group" aria-label="แก้ไข">
        <button type="button" class="wsb-tool" data-command="undo" data-requires-edit
            aria-label="ย้อนกลับ" title="ย้อนกลับ (Ctrl+Z)" disabled>
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-tool" data-command="redo" data-requires-edit
            aria-label="ทำซ้ำ" title="ทำซ้ำ (Ctrl+Shift+Z)" disabled>
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-tool" data-command="delete" data-requires-edit
            aria-label="ลบที่เลือก" title="ลบที่เลือก (Delete)" disabled>
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
    </div>

    {{-- ปุ่มมุมมองใช้ได้กับทุกคน รวมผู้ที่ดูอย่างเดียว จึงไม่มี data-requires-edit --}}
    <div class="wsb-toolbar__group" role="group" aria-label="มุมมอง">
        <button type="button" class="wsb-tool" data-command="zoom-out" aria-label="ซูมออก" title="ซูมออก">
            <i class="bi bi-zoom-out" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-zoom" data-command="zoom-reset"
            aria-label="กลับไปขนาดจริง" title="กลับไปขนาดจริง">
            <span data-zoom-label>100%</span>
        </button>
        <button type="button" class="wsb-tool" data-command="zoom-in" aria-label="ซูมเข้า" title="ซูมเข้า">
            <i class="bi bi-zoom-in" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-tool" data-command="fit" aria-label="จัดให้พอดีจอ" title="จัดให้พอดีจอ">
            <i class="bi bi-bounding-box" aria-hidden="true"></i>
        </button>

        {{--
            "เต็มจอ" ต่างจาก "จัดให้พอดีจอ" คนละเรื่อง อันบนปรับกล้องให้เห็นงาน
            ทั้งหมด ส่วนอันนี้ขยายตัวหน้าต่างกระดานให้เต็มหน้าจอจริงผ่าน
            Fullscreen API ไอคอนจึงต้องต่างกันชัด ไม่งั้นผู้ใช้กดผิดตัว
        --}}
        <button type="button" class="wsb-tool" data-command="fullscreen"
            data-workspace-fullscreen aria-pressed="false"
            aria-label="เต็มจอ" title="เต็มจอ (F)">
            <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>
        </button>
    </div>
</div>

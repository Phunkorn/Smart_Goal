{{--
    แถบเครื่องมือของกระดาน — แถวหลัก

    เครื่องมือทุกตัววางบนแถบตรง ๆ เห็นไอคอนจริงและเห็นว่าตัวไหนถูกเลือกอยู่
    เหลือดรอปดาวน์เดียวคือรูปทรง ซึ่งปุ่มของมันเปลี่ยนไอคอนตามรูปทรงที่เลือก

    เคยยุบเครื่องมือลงเมนู "วาด" กับ "สร้าง" มาก่อน แล้วพบจากการใช้งานจริงว่ายากกว่าเดิม
    ปุ่มเมนูแสดงไอคอนของกลุ่ม (ดินสอ กับ +) ไม่ใช่ของเครื่องมือที่ถืออยู่ ผู้ใช้จึงไม่รู้
    ว่ากำลังถือยางลบอยู่หรือเปล่า ไม่รู้ว่าเครื่องมือที่ต้องการซ่อนในเมนูไหน และรูปทรง
    ที่ซ้อนอยู่ในเมนูย่อยอีกชั้นต้องกดสามครั้งกว่าจะได้ใช้

    ส่วนรูปแบบ (สี ขนาดตัวอักษร การจัดบรรทัด) อยู่ในแถวที่สอง
    board-toolbar-context.blade.php ซึ่งโผล่เฉพาะเมื่อมีอะไรให้ปรับ

    ทุกป้ายชื่อ ไอคอน และปุ่มลัดมาจาก App\Support\WorkspaceDesign แหล่งเดียว
    ห้ามพิมพ์ข้อความไทยซ้ำในไฟล์ .js

    ปุ่มที่แก้เนื้อหาถูกทำเครื่องหมาย data-requires-edit ไว้ toolbar.js จะปิด
    ทั้งชุดเมื่อ $capabilities['canEdit'] เป็นเท็จ การซ่อนปุ่มเป็นเรื่องของหน้าจอ
    เท่านั้น การบังคับใช้จริงอยู่ที่ WorkspaceBoardPolicy ฝั่งเซิร์ฟเวอร์

    ปุ่มหลักใช้ tooltip ของ CSS (data-tooltip + data-shortcut) แทน title เพราะ
    title ของเบราว์เซอร์ขึ้นช้าราวหนึ่งวินาทีและไม่ขึ้นเลยเมื่อโฟกัสด้วยคีย์บอร์ด
    ชื่อยังอยู่ใน aria-label และปุ่มลัดอยู่ใน aria-keyshortcuts สำหรับโปรแกรมอ่านจอ
--}}
@php
    $design = \App\Support\WorkspaceDesign::class;
    $readOnlyTools = ['select', 'hand'];
@endphp

{{--
    ห่อแถบเครื่องมือไว้ในเชลล์เพื่อวางมือจับพับ/กางไว้นอกแถว
    ถ้ามือจับอยู่ในแถวแล้วพับ มือจับจะหายไปพร้อมแถวจนกางกลับไม่ได้อีก
--}}
<div class="wsb-toolbar-shell" data-workspace-toolbar-shell>
<div class="wsb-toolbar wsb-toolbar--primary" data-workspace-toolbar id="wsbToolbar"
    role="toolbar" aria-label="เครื่องมือวาด">
    {{-- เลือกกับเลื่อนกระดานใช้ได้กับผู้ที่ดูอย่างเดียว จึงไม่มี data-requires-edit --}}
    <div class="wsb-toolbar__group" role="group" aria-label="เครื่องมือ">
        @foreach ($design::PRIMARY_TOOLS as $key)
            @php $tool = $design::TOOLS[$key]; @endphp
            <button type="button"
                class="wsb-tool"
                data-tool="{{ $key }}"
                @unless (in_array($key, $readOnlyTools, true)) data-requires-edit @endunless
                aria-pressed="false"
                data-tooltip="{{ $tool['label'] }}"
                @if ($tool['shortcut'])
                    data-shortcut="{{ $tool['shortcut'] }}"
                    aria-keyshortcuts="{{ $tool['shortcut'] }}"
                @endif
                aria-label="{{ $tool['label'] }}">
                <i class="bi {{ $tool['icon'] }}" aria-hidden="true"></i>
            </button>
        @endforeach

        {{--
            รูปทรง: ปุ่มเดียวที่แสดงรูปทรงที่เลือกอยู่ กดแล้วเลือกจากแผงแถวเดียว
            แบบเดียวกับความหนาเส้น ไม่ใช่เมนูซ้อนเมนู

            ไอคอนของแต่ละตัวเลือกถูกส่งมาใน data-tool-icon ด้วย เพื่อให้ toolbar.js
            สลับไอคอนบนปุ่มได้โดยไม่ต้องรู้จักชื่อไอคอนเอง (ไอคอนมาจาก WorkspaceDesign
            ที่เดียว) ผู้ใช้จึงเห็นจากปุ่มได้ทันทีว่ากำลังถือสี่เหลี่ยมหรือลูกศรอยู่
        --}}
        @foreach ($design::TOOL_MENUS as $menuKey => $menu)
            @php $panelId = 'wsbMenu'.ucfirst($menuKey); @endphp
            <div class="wsb-menu-anchor" data-menu>
                <button type="button"
                    class="wsb-menu__trigger"
                    data-menu-toggle
                    data-requires-edit
                    data-menu-tools="{{ implode(' ', $menu['tools']) }}"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="{{ $panelId }}"
                    aria-label="{{ $menu['label'] }}"
                    data-tooltip="{{ $menu['label'] }}">
                    <i class="bi {{ $menu['icon'] }} wsb-menu__icon" data-menu-icon
                        data-default-icon="bi {{ $menu['icon'] }}" aria-hidden="true"></i>
                    <i class="bi bi-chevron-down wsb-menu__caret" aria-hidden="true"></i>
                </button>

                <div class="wsb-menu wsb-menu--row" id="{{ $panelId }}" data-menu-panel hidden
                    role="menu" aria-label="{{ $menu['label'] }}">
                    @foreach ($menu['tools'] as $key)
                        @php $tool = $design::TOOLS[$key]; @endphp
                        <button type="button"
                            class="wsb-tool"
                            role="menuitem"
                            data-tool="{{ $key }}"
                            data-tool-icon="bi {{ $tool['icon'] }}"
                            data-requires-edit
                            aria-pressed="false"
                            @if ($tool['shortcut'])
                                data-shortcut="{{ $tool['shortcut'] }}"
                                aria-keyshortcuts="{{ $tool['shortcut'] }}"
                            @endif
                            aria-label="{{ $tool['label'] }}"
                            title="{{ $tool['label'] }}">
                            <i class="bi {{ $tool['icon'] }}" aria-hidden="true"></i>
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- คำสั่งที่อยู่ข้างเครื่องมือสร้าง เพราะผู้ใช้มองหามันที่นั่น --}}
        @foreach ($design::PRIMARY_COMMANDS as $command => $key)
            @php $tool = $design::TOOLS[$key]; @endphp
            <button type="button"
                class="wsb-tool"
                data-command="{{ $command }}"
                data-requires-edit
                data-tooltip="{{ $tool['label'] }}"
                aria-label="{{ $tool['label'] }}">
                <i class="bi {{ $tool['icon'] }}" aria-hidden="true"></i>
            </button>
        @endforeach
    </div>

    <div class="wsb-toolbar__group" role="group" aria-label="แก้ไข">
        <button type="button" class="wsb-tool" data-command="undo" data-requires-edit
            aria-label="ย้อนกลับ" data-tooltip="ย้อนกลับ" data-shortcut="Ctrl+Z"
            aria-keyshortcuts="Control+Z" disabled>
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-tool" data-command="redo" data-requires-edit
            aria-label="ทำซ้ำ" data-tooltip="ทำซ้ำ" data-shortcut="Ctrl+Shift+Z"
            aria-keyshortcuts="Control+Shift+Z Control+Y" disabled>
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-tool" data-command="delete" data-requires-edit
            aria-label="ลบที่เลือก" data-tooltip="ลบที่เลือก" data-shortcut="Delete"
            aria-keyshortcuts="Delete Backspace" disabled>
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
    </div>

    {{--
        ปุ่มมุมมองใช้ได้กับทุกคน รวมผู้ที่ดูอย่างเดียว จึงไม่มี data-requires-edit
        และต้องเป็นกลุ่มสุดท้ายของไฟล์นี้ (เทสต์สัญญาตรวจว่าไม่มี data-requires-edit
        หลังกลุ่มนี้)
    --}}
    <div class="wsb-toolbar__group" role="group" aria-label="มุมมอง">
        <button type="button" class="wsb-tool" data-command="zoom-out" aria-label="ซูมออก" data-tooltip="ซูมออก">
            <i class="bi bi-zoom-out" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-zoom" data-command="zoom-reset"
            aria-label="กลับไปขนาดจริง" data-tooltip="กลับไปขนาดจริง">
            <span data-zoom-label>100%</span>
        </button>
        <button type="button" class="wsb-tool" data-command="zoom-in" aria-label="ซูมเข้า" data-tooltip="ซูมเข้า">
            <i class="bi bi-zoom-in" aria-hidden="true"></i>
        </button>
        <button type="button" class="wsb-tool" data-command="fit" aria-label="จัดให้พอดีจอ" data-tooltip="จัดให้พอดีจอ">
            <i class="bi bi-bounding-box" aria-hidden="true"></i>
        </button>

        {{--
            "เต็มจอ" ต่างจาก "จัดให้พอดีจอ" คนละเรื่อง อันบนปรับกล้องให้เห็นงาน
            ทั้งหมด ส่วนอันนี้ขยายตัวหน้าต่างกระดานให้เต็มหน้าจอจริงผ่าน
            Fullscreen API ไอคอนจึงต้องต่างกันชัด ไม่งั้นผู้ใช้กดผิดตัว
        --}}
        <button type="button" class="wsb-tool" data-command="fullscreen"
            data-workspace-fullscreen aria-pressed="false"
            aria-label="เต็มจอ" data-tooltip="เต็มจอ" data-shortcut="F" aria-keyshortcuts="F">
            <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>
        </button>
    </div>
</div>

@include('workspace.components.board-toolbar-context')

{{--
    มือจับพับ/กางแถบเครื่องมือ เพื่อให้เห็นกระดานโล่ง ๆ เวลาไม่ได้ใช้เครื่องมือ
    เป็นปุ่มมุมมองเหมือนปุ่มซูม ใช้ได้แม้เป็นผู้ดูอย่างเดียว ไม่ตัดสิทธิ์แก้ไขใด ๆ
    และไม่ผูกกับ capabilities ใด ๆ สถานะไม่ถูกจำข้ามการโหลดหน้าใหม่โดยตั้งใจ
    เปิดกระดานใหม่ทุกครั้งจึงเห็นแถบเครื่องมือเต็มเสมอ

    พับทีเดียวซ่อนทั้งสองแถว จึงประกาศ aria-controls ทั้งสอง id
--}}
<div class="wsb-toolbar-handle-row">
    <button type="button" class="wsb-toolbar-handle" data-workspace-toolbar-toggle
        aria-expanded="true" aria-controls="wsbToolbar wsbContextToolbar"
        data-label-expanded="ซ่อนแถบเครื่องมือ" data-label-collapsed="แสดงแถบเครื่องมือ"
        aria-label="ซ่อนแถบเครื่องมือ">
        <i class="bi bi-chevron-up" aria-hidden="true"></i>
    </button>
</div>
</div>

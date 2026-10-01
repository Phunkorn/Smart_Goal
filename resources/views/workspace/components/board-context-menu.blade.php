{{--
    เมนูคลิกขวาบนผืนผ้าใบ

    ทุกรายการมาจาก WorkspaceDesign::CANVAS_MENU และชี้ไปที่คำสั่งเดียวกับที่แถบ
    เครื่องมือและปุ่มลัดใช้ (runCommand ใน index.js) เมนูนี้จึงไม่มีตรรกะของตัวเอง
    เลย มีเทสต์สัญญาตรวจว่าทุก command ที่นี่มี case รองรับอยู่จริง

    แบนราบทั้งหมด ไม่มีเมนูย่อย คำสั่งจัดลำดับชั้นเคยซ่อนอยู่ในเมนูย่อยแล้วพบว่า
    กดไม่ติดในการใช้งานจริง เพราะต้องเล็งเมาส์จากรายการแม่เข้าแผงย่อยโดยไม่ให้
    หลุดออกนอกทาง

    data-needs บอกเงื่อนไขที่ทำให้รายการกดได้ (selection / clipboard / always)
    ส่วน data-requires-edit ใช้ชุดเดียวกับแถบเครื่องมือเพื่อปิดรายการที่แก้เนื้อหา
    สำหรับผู้ที่ดูอย่างเดียว การบังคับสิทธิ์จริงอยู่ฝั่งเซิร์ฟเวอร์ ที่นี่เป็นเรื่อง
    ของหน้าจอเท่านั้น

    แผงถูกซ่อนไว้ตั้งแต่แรกและเปิดโดย menu.js ซึ่งเป็นเจ้าของสถานะเปิดปิดของทุกเมนู
    บนหน้านี้ (context-menu.js เป็นคนตัดสินว่ารายการไหนกดได้ แล้วสั่งให้ menu.js เปิด)
--}}
@php
    $design = \App\Support\WorkspaceDesign::class;
@endphp

<div class="wsb-menu" data-workspace-context-menu data-menu data-menu-panel hidden
    role="menu" aria-label="คำสั่งบนกระดาน">
    @foreach ($design::CANVAS_MENU as $item)
        @if (isset($item['separator']))
            <div class="wsb-menu__separator" role="separator"></div>

            @continue
        @endif

        <button type="button"
            class="wsb-menu__item"
            role="menuitem"
            data-command="{{ $item['command'] }}"
            data-needs="{{ $item['needs'] }}"
            @if ($item['edit']) data-requires-edit @endif
            @if ($item['shortcut']) aria-keyshortcuts="{{ $item['shortcut'] }}" @endif
            aria-label="{{ $item['label'] }}">
            <i class="bi {{ $item['icon'] }} wsb-menu__item-icon" aria-hidden="true"></i>
            <span class="wsb-menu__item-label">{{ $item['label'] }}</span>
            @if ($item['shortcut'])
                <span class="wsb-menu__item-shortcut" aria-hidden="true">{{ $item['shortcut'] }}</span>
            @endif
        </button>
    @endforeach
</div>

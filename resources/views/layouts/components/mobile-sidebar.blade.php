{{--
    แถบเมนูด้านข้างสำหรับจอต่ำกว่า 992px — off-canvas เปิดจากปุ่ม ☰ บน Topbar
    จอ 992px ขึ้นไปใช้เมนูแนวนอน (top-navigation) แทน ไม่มีช่วงจอที่แสดงทั้งคู่หรือไม่แสดงเลย

    ข้อมูลเมนูชุดเดียวกับเมนูแนวนอน ($navigation จาก App\Support\TopNavigation ที่ layout คำนวณครั้งเดียว)
    ไฟล์นี้วาดอย่างเดียว ไม่ตัดสินสิทธิ์ — ทุก route ยังตรวจ middleware/policy ของตัวเอง

    เป็น modal: มีฉากหลัง ล็อกการเลื่อนหน้า และย้ายโฟกัส สถานะเปิด/ปิด โฟกัส Escape และ inert
    อยู่ที่ resources/js/components/mobile-sidebar.js ที่เดียว ตอนปิดถูกทำเป็น inert จึง Tab เข้าไม่ได้
    กลุ่มเมนูเป็น accordion — กลุ่มที่มีหน้าปัจจุบันกางไว้ตั้งแต่ server วาดหน้า
--}}
<div class="mobile-sidebar" id="mobileSidebar" role="dialog" aria-modal="true" aria-labelledby="mobileSidebarTitle"
    data-mobile-sidebar inert>
    <div class="mobile-sidebar__head">
        <span class="topbar-brand__mark mobile-sidebar__mark" aria-hidden="true"><i class="bi bi-bullseye"></i></span>
        <span class="mobile-sidebar__brand">
            <span class="mobile-sidebar__title" id="mobileSidebarTitle">Smart Goals</span>
            <span class="mobile-sidebar__subtitle">ระบบบริหารงานองค์กร</span>
        </span>
        <button type="button" class="mobile-sidebar__close" data-mobile-sidebar-close aria-label="ปิดเมนู">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="mobile-nav" aria-label="เมนูหลัก">
        <ul class="mobile-nav__list">
            @foreach ($navigation as $item)
                @if ($item['type'] === 'link')
                    <li>
                        <a href="{{ $item['url'] }}" class="mobile-nav__link{{ $item['active'] ? ' is-active' : '' }}"
                            @if($item['current']) aria-current="{{ $item['current'] }}" @endif>
                            <i class="bi {{ $item['icon'] }} mobile-nav__icon" aria-hidden="true"></i>
                            <span class="mobile-nav__label">{{ $item['label'] }}</span>
                            @includeWhen($item['badge'], 'layouts.components.top-navigation-badge', ['badge' => $item['badge']])
                        </a>
                    </li>
                @else
                    <li class="mobile-nav__group{{ $item['active'] ? ' is-open' : '' }}" data-mobile-nav-group>
                        <button type="button" class="mobile-nav__link mobile-nav__toggle{{ $item['active'] ? ' is-active' : '' }}"
                            data-mobile-nav-toggle aria-expanded="{{ $item['active'] ? 'true' : 'false' }}"
                            aria-controls="mobile-nav-{{ $item['key'] }}">
                            <i class="bi {{ $item['icon'] }} mobile-nav__icon" aria-hidden="true"></i>
                            <span class="mobile-nav__label">{{ $item['label'] }}</span>
                            @if ($item['attention'])
                                <span class="top-nav__dot" aria-hidden="true"></span>
                                <span class="visually-hidden">มีรายการรอดำเนินการ</span>
                            @endif
                            <i class="bi bi-chevron-down mobile-nav__chevron" aria-hidden="true"></i>
                        </button>
                        <div class="mobile-nav__panel" id="mobile-nav-{{ $item['key'] }}" data-mobile-nav-panel
                            @unless($item['active']) inert @endunless>
                            <div class="mobile-nav__panel-inner">
                                @foreach ($item['sections'] as $section)
                                    <div class="mobile-nav__section" role="group" @if($section['label']) aria-label="{{ $section['label'] }}" @endif>
                                        @if ($section['label'])
                                            <p class="mobile-nav__heading" aria-hidden="true">{{ $section['label'] }}</p>
                                        @endif
                                        @foreach ($section['items'] as $link)
                                            <a href="{{ $link['url'] }}" class="mobile-nav__sublink{{ $link['active'] ? ' is-active' : '' }}"
                                                @if($link['current']) aria-current="{{ $link['current'] }}" @endif>
                                                <i class="bi {{ $link['icon'] }} mobile-nav__icon" aria-hidden="true"></i>
                                                <span class="mobile-nav__label">{{ $link['label'] }}</span>
                                                @includeWhen($link['badge'], 'layouts.components.top-navigation-badge', ['badge' => $link['badge']])
                                            </a>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </li>
                @endif
            @endforeach
        </ul>
    </nav>
</div>
<div class="mobile-sidebar-backdrop" data-mobile-sidebar-close aria-hidden="true"></div>

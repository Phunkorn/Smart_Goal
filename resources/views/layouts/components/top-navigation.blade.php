{{--
    เมนูหลักแนวนอนบน Topbar (จอ ≥ 992px) — วาดอย่างเดียว ไม่ตัดสินสิทธิ์
    $navigation มาจาก layouts/app.blade.php ชุดเดียวกับ layouts/components/mobile-sidebar
    ใครเห็นเมนูอะไร ป้ายตัวเลข และเมนูไหนเป็นหน้าปัจจุบัน มาจาก App\Support\TopNavigation ที่เดียว

    กลุ่มเมนูเป็น Bootstrap dropdown ชุดเดียวกับกระดิ่ง/งานประจำ/บัญชี (topbar-slide-menu):
    data-bs-display="static" ให้ CSS กำหนดตำแหน่งเอง เมนูจึงเฟดและเลื่อนลงได้ทั้งตอนเปิดและตอนปิด
    คีย์บอร์ด: ลูกศรขึ้น/ลงในเมนู และ Escape มาจาก Bootstrap ลูกศรซ้าย/ขวาระหว่างเมนูหลักมาจาก top-navigation.js
--}}
<nav class="top-nav" aria-label="เมนูหลัก" data-top-nav>
    <ul class="top-nav__list">
        @foreach ($navigation as $item)
            @if ($item['type'] === 'link')
                <li class="top-nav__item">
                    <a href="{{ $item['url'] }}" class="top-nav__link{{ $item['active'] ? ' is-active' : '' }}" data-top-nav-entry
                        @if($item['current']) aria-current="{{ $item['current'] }}" @endif>
                        <i class="bi {{ $item['icon'] }} top-nav__icon" aria-hidden="true"></i>
                        <span class="top-nav__label">{{ $item['label'] }}</span>
                        @includeWhen($item['badge'], 'layouts.components.top-navigation-badge', ['badge' => $item['badge']])
                    </a>
                </li>
            @else
                <li class="top-nav__item dropdown">
                    <button type="button" class="top-nav__link top-nav__toggle{{ $item['active'] ? ' is-active' : '' }}" data-top-nav-entry
                        id="top-nav-{{ $item['key'] }}" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                        aria-controls="top-nav-{{ $item['key'] }}-menu">
                        <i class="bi {{ $item['icon'] }} top-nav__icon" aria-hidden="true"></i>
                        <span class="top-nav__label">{{ $item['label'] }}</span>
                        @if ($item['attention'])
                            <span class="top-nav__dot" aria-hidden="true"></span>
                            <span class="visually-hidden">มีรายการรอดำเนินการ</span>
                        @endif
                        <i class="bi bi-chevron-down top-nav__chevron" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu topbar-slide-menu nav-dropdown{{ $item['align'] === 'end' ? ' dropdown-menu-end' : '' }}"
                        id="top-nav-{{ $item['key'] }}-menu" aria-labelledby="top-nav-{{ $item['key'] }}">
                        @foreach ($item['sections'] as $section)
                            <div class="nav-dropdown__section" role="group" @if($section['label']) aria-label="{{ $section['label'] }}" @endif>
                                @if ($section['label'])
                                    <p class="nav-dropdown__heading" aria-hidden="true">{{ $section['label'] }}</p>
                                @endif
                                @foreach ($section['items'] as $link)
                                    <a href="{{ $link['url'] }}" class="dropdown-item nav-dropdown__item{{ $link['active'] ? ' is-active' : '' }}"
                                        @if($link['current']) aria-current="{{ $link['current'] }}" @endif>
                                        <i class="bi {{ $link['icon'] }} nav-dropdown__icon" aria-hidden="true"></i>
                                        <span class="nav-dropdown__label">{{ $link['label'] }}</span>
                                        @includeWhen($link['badge'], 'layouts.components.top-navigation-badge', ['badge' => $link['badge']])
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </li>
            @endif
        @endforeach
    </ul>
</nav>

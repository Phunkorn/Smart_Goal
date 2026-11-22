{{--
    ไอคอนของเมนูหลัก — ใช้ร่วมกันทั้งเมนูแนวนอนและแถบข้าง (จอแคบ) เพื่อให้ป้ายซ้อนไอคอนวาดจากที่เดียว

    ป้ายแบบ placement = icon (ป้ายโพสต์ใหม่ของเมนูชุมชน) ถูกวางใน wrapper ที่ position: relative
    ครอบเฉพาะไอคอน และซ้อนที่มุมขวาบนเหมือนป้ายกระดิ่ง wrapper มีขนาดเท่าไอคอนเสมอ ป้ายไม่กินพื้นที่ในแถว
    เมนูจึงไม่ขยับ เมนูที่ไม่มีป้าย หรือป้ายแบบต่อท้ายข้อความ วาดไอคอนเปล่าเหมือนเดิม

    ตัวแปร: $icon (คลาส bi-*) · $iconClass · $wrapClass · $badge (null ได้)
--}}
@if (($badge['placement'] ?? null) === 'icon')
    <span class="{{ $wrapClass }}"><i class="bi {{ $icon }} {{ $iconClass }}" aria-hidden="true"></i>@include('layouts.components.top-navigation-badge', ['badge' => $badge])</span>
@else
    <i class="bi {{ $icon }} {{ $iconClass }}" aria-hidden="true"></i>
@endif

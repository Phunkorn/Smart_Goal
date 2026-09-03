{{--
    แถบแบรนด์ฝั่งซ้าย ใช้ร่วมทั้ง 3 หน้า
    พาดหัวและคำอธิบายเปลี่ยนตามหน้าโดยส่งผ่านตัวแปร ไม่ใช่สลับด้วย JavaScript
--}}
@php
    $heading = $heading ?? 'Smart Goals';
    $tagline = $tagline ?? 'ระบบจัดการองค์กรและติดตามงาน';
@endphp
{{-- โลโก้จริงของ PremiumCare ห้ามแทนด้วยตัวอักษร --}}
<div class="brand-mark auth-rise" data-auth-rise>
    <img src="{{ asset('images/premiuum-care-logo.png') }}" alt="PremiumCare" class="brand-mark__logo">
</div>

<section class="brand-panel" aria-label="Smart Goal By PremiumCare">
    <h1 class="auth-rise" data-auth-rise>{!! nl2br(e($heading)) !!}</h1>
    <p class="brand-tagline auth-rise" data-auth-rise>{{ $tagline }}</p>
    <p class="brand-description auth-rise" data-auth-rise>
        พื้นที่จัดการงานสำหรับทีม : เช็กงานที่ได้รับมอบหมาย<br>อัปเดตสถานะ และส่งงานตรงเวลา
    </p>
</section>

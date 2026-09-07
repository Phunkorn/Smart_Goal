@php($avatarSize = $size ?? 'md')
{{--
    ตัวย่อชื่อถูกเรนเดอร์ไว้เสมอในฐานะเนื้อหาสำรอง แล้วให้ <img> ทับอยู่ด้านบน

    คอลัมน์ profile_image เป็นเพียง path ในฐานข้อมูล ไม่ได้การันตีว่าไฟล์ยังอยู่จริง
    เมื่อรูปโหลดไม่ขึ้น components/avatar-fallback.js จะถอด <img> ออก ตัวย่อชื่อ
    ที่อยู่ข้างล่างจึงโผล่มาแทนทันที แทนที่จะเห็นไอคอนรูปเสียพร้อมข้อความ alt
--}}
<span class="wb-avatar wb-avatar--{{ $avatarSize }}" title="{{ $user->name }}">
    <span aria-hidden="true">{{ \App\Support\WorkBoardDesign::initials($user->name) }}</span>
    @if($user->profile_image)
        <img src="{{ route('media.profile', $user) }}" alt="{{ $user->name }}" data-avatar-image>
    @endif
</span>

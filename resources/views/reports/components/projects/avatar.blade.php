{{--
    รูปโปรไฟล์ขนาดเล็กของตารางรายงานโปรเจกต์ — รูปจริงเมื่อมี ไม่งั้นเป็นอักษรย่อ

    @param array $person  ['id', 'name', 'initials', 'avatar']
    @param bool  $decorative  true เมื่อชื่อแสดงเป็นข้อความข้างรูปอยู่แล้ว
--}}
@php($decorative = $decorative ?? false)
<span class="project-report__avatar" @if($decorative) aria-hidden="true" @else role="img" aria-label="{{ $person['name'] }}" title="{{ $person['name'] }}" @endif>
    @if($person['avatar'])
        <img src="{{ $person['avatar'] }}" alt="" loading="lazy">
    @else
        {{ $person['initials'] }}
    @endif
</span>

{{--
    แสดงรูปโปรไฟล์ที่บันทึกไว้ในบันทึกตรวจสอบ จาก path ในบันทึกเท่านั้น

    ถ้า path นั้นเปิดดูไม่ได้แล้ว (ไฟล์ถูกลบตอนเปลี่ยนรูป หรือไม่ใช่รูปปัจจุบันของผู้ใช้คนใด)
    จะแสดง placeholder แทน ห้ามใช้รูปโปรไฟล์ปัจจุบันมาแสดงแทนรูปเดิมเด็ดขาด
    เพราะจะทำให้บันทึกก่อน/หลังแสดงข้อมูลผิด

    @param string $path        path ของรูปที่บันทึกไว้
    @param string $label       คำอธิบายสำหรับ screen reader
    @param string $extraClass  class เสริม เช่น audit-avatar-old / audit-avatar-new
--}}
@php($extraClass = $extraClass ?? '')
@if (($resolvableProfileImages ?? collect())->has($path))
    <img src="{{ route('media.show', ['path' => $path]) }}" alt="{{ $label }}" class="audit-avatar {{ $extraClass }}">
@else
    <span class="audit-avatar audit-avatar--missing {{ $extraClass }}" role="img" aria-label="{{ $label }}: ไม่พบรูปเดิม">
        <i class="bi bi-person-slash" aria-hidden="true"></i>
        <small>ไม่พบรูปเดิม</small>
    </span>
@endif

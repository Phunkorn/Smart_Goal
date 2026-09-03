@php
    /**
     * เนื้อในของวงกลมโปรไฟล์: ใช้รูปจริงที่ผู้ใช้อัปโหลดเสมอ ถ้ายังไม่มีค่อยใช้ตัวย่อชื่อ
     *
     * เดิมแต่ละหน้าตัดสินใจเรื่องนี้เอง หลายจุดจึงลืมเช็ค profile_image แล้วแสดงตัวอักษร
     * ตลอดไป ทั้งที่ผู้ใช้อัปโหลดรูปไว้แล้ว (เช่น ผู้ร่วมงานบนการ์ดบอร์ด และรายชื่อ
     * ผู้เข้าร่วมประชุม) รวมการตัดสินใจไว้ที่เดียวเพื่อไม่ให้เกิดจุดที่ลืมอีก
     *
     * ตัว wrapper ยังเป็นของแต่ละหน้า เพราะรูปทรงและขนาดต่างกัน
     * partial นี้คุมแค่ "แสดงรูปหรือแสดงตัวอักษร" อย่างเดียว
     *
     * @param  \App\Models\User|null  $user
     * @param  int  $avatarLength  จำนวนตัวอักษรของตัวย่อเมื่อไม่มีรูป (ค่าเริ่มต้น 1)
     * @param  bool $avatarUpper  แปลงตัวย่อเป็นตัวพิมพ์ใหญ่หรือไม่ (ค่าเริ่มต้น ไม่แปลง)
     * @param  string $avatarAlt ข้อความแทนรูป เว้นว่างไว้เมื่อ wrapper บอกชื่อไว้แล้ว
     *
     * ชื่อพารามิเตอร์ขึ้นต้นด้วย avatar เพราะการ include รับตัวแปรของ view แม่มาด้วย
     * ชื่อสั้นอย่าง $length หรือ $upper จึงเสี่ยงชนกับตัวแปรที่หน้าอื่นตั้งไว้อยู่แล้ว
     */
    $avatarLength = $avatarLength ?? 1;
    $avatarInitial = Str::substr($user?->name ?: '?', 0, $avatarLength);
@endphp
@if($user?->profile_image)
    <img src="{{ route('media.profile', $user) }}" alt="{{ $avatarAlt ?? '' }}" loading="lazy">
@else
    {{ ($avatarUpper ?? false) ? Str::upper($avatarInitial) : $avatarInitial }}
@endif

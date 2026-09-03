<?php

/*
|--------------------------------------------------------------------------
| ข้อความผลการตรวจสอบข้อมูล (Validation)
|--------------------------------------------------------------------------
|
| ก่อนหน้านี้โปรเจกต์ไม่มีโฟลเดอร์ lang/ เลย และ APP_LOCALE ถูกตั้งเป็น en
| ผู้ใช้จึงเห็นข้อความอังกฤษของ Laravel ปนกับ UI ไทยทั้งระบบ เช่น
| "The username has already been taken." ในหน้าเพิ่มพนักงาน ซึ่งอ่านแล้ว
| ไม่รู้ว่าติดที่กฎข้อไหน จนเข้าใจผิดว่าระบบบังคับรูปแบบชื่อบัญชี
|
| คีย์ที่ไม่ได้แปลไว้ที่นี่จะตกไปใช้ภาษาอังกฤษของ framework ตาม
| fallback_locale โดยอัตโนมัติ (FileLoader ค้นทั้ง vendor lang และ lang/ ของแอป)
| จึงไม่มีทางที่ข้อความจะหายไปเป็นคีย์ดิบ
|
*/

return [
    'accepted' => 'ต้องยอมรับ :attribute',
    'active_url' => ':attribute ไม่ใช่ URL ที่ใช้งานได้',
    'after' => ':attribute ต้องเป็นวันที่หลัง :date',
    'after_or_equal' => ':attribute ต้องเป็นวันที่ตั้งแต่ :date เป็นต้นไป',
    'alpha' => ':attribute ต้องเป็นตัวอักษรเท่านั้น',
    'alpha_dash' => ':attribute ต้องเป็นตัวอักษร ตัวเลข ขีดกลาง หรือขีดล่างเท่านั้น',
    'alpha_num' => ':attribute ต้องเป็นตัวอักษรและตัวเลขเท่านั้น',
    'array' => ':attribute ต้องเป็นชุดข้อมูล',
    'before' => ':attribute ต้องเป็นวันที่ก่อน :date',
    'before_or_equal' => ':attribute ต้องเป็นวันที่ไม่เกิน :date',
    'boolean' => ':attribute ต้องเป็นค่าจริงหรือเท็จเท่านั้น',
    'confirmed' => ':attribute ทั้งสองช่องไม่ตรงกัน',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'date' => ':attribute ไม่ใช่รูปแบบวันที่ที่ถูกต้อง',
    'date_equals' => ':attribute ต้องเป็นวันที่ตรงกับ :date',
    'date_format' => ':attribute ไม่ตรงกับรูปแบบ :format',
    'different' => ':attribute กับ :other ต้องไม่ซ้ำกัน',
    'digits' => ':attribute ต้องเป็นตัวเลข :digits หลัก',
    'digits_between' => ':attribute ต้องเป็นตัวเลขระหว่าง :min ถึง :max หลัก',
    'email' => ':attribute ต้องเป็นรูปแบบอีเมลที่ถูกต้อง',
    'ends_with' => ':attribute ต้องลงท้ายด้วย: :values',
    'exists' => 'ไม่พบ :attribute ที่เลือกในระบบ',
    'file' => ':attribute ต้องเป็นไฟล์',
    'filled' => 'ต้องระบุ :attribute',
    'image' => ':attribute ต้องเป็นไฟล์รูปภาพ',
    'in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
    'json' => ':attribute ต้องเป็นข้อความรูปแบบ JSON',
    'lowercase' => ':attribute ต้องเป็นตัวพิมพ์เล็กทั้งหมด',
    'max' => [
        'array' => ':attribute ต้องมีไม่เกิน :max รายการ',
        'file' => ':attribute ต้องมีขนาดไม่เกิน :max กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าไม่เกิน :max',
        'string' => ':attribute ต้องยาวไม่เกิน :max ตัวอักษร',
    ],
    'mimes' => ':attribute ต้องเป็นไฟล์ชนิด: :values',
    'mimetypes' => ':attribute ต้องเป็นไฟล์ชนิด: :values',
    'min' => [
        'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'file' => ':attribute ต้องมีขนาดอย่างน้อย :min กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าอย่างน้อย :min',
        'string' => ':attribute ต้องยาวอย่างน้อย :min ตัวอักษร',
    ],
    'not_in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'numeric' => ':attribute ต้องเป็นตัวเลข',
    'present' => 'ต้องส่ง :attribute มาด้วย',
    'prohibited' => 'ไม่อนุญาตให้ระบุ :attribute',
    'regex' => 'รูปแบบของ :attribute ไม่ถูกต้อง',
    'required' => 'กรุณากรอก :attribute',
    'required_if' => 'กรุณากรอก :attribute เมื่อ :other คือ :value',
    'required_unless' => 'กรุณากรอก :attribute เว้นแต่ :other จะเป็น :values',
    'required_with' => 'กรุณากรอก :attribute เมื่อมี :values',
    'required_without' => 'กรุณากรอก :attribute เมื่อไม่มี :values',
    'same' => ':attribute กับ :other ต้องตรงกัน',
    'size' => [
        'array' => ':attribute ต้องมี :size รายการ',
        'file' => ':attribute ต้องมีขนาด :size กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าเท่ากับ :size',
        'string' => ':attribute ต้องยาว :size ตัวอักษร',
    ],
    'starts_with' => ':attribute ต้องขึ้นต้นด้วย: :values',
    'string' => ':attribute ต้องเป็นข้อความ',
    'unique' => ':attribute นี้มีอยู่ในระบบแล้ว',
    'uploaded' => 'อัปโหลด :attribute ไม่สำเร็จ',
    'uppercase' => ':attribute ต้องเป็นตัวพิมพ์ใหญ่ทั้งหมด',
    'url' => ':attribute ต้องเป็น URL ที่ถูกต้อง',

    /*
    | ข้อความเฉพาะฟิลด์ ใช้เมื่อข้อความกลางยังบอกไม่ชัดว่าผู้ใช้ต้องแก้อย่างไร
    */
    'custom' => [
        'username' => [
            'unique' => 'บัญชีผู้ใช้งานนี้มีคนใช้แล้ว กรุณาเปลี่ยนเป็นชื่ออื่น',
            'regex' => 'บัญชีผู้ใช้งานใช้ได้เฉพาะตัวอักษรอังกฤษและตัวเลข จะใส่ . - _ เพิ่มด้วยก็ได้',
        ],
        'email' => [
            'unique' => 'อีเมลนี้ถูกใช้กับบัญชีอื่นแล้ว',
        ],
        'profile_image' => [
            'image' => 'รูปโปรไฟล์ต้องเป็นไฟล์รูปภาพ',
            'mimes' => 'รูปโปรไฟล์รองรับเฉพาะไฟล์ JPG, PNG และ WebP',
            'max' => 'รูปโปรไฟล์ต้องมีขนาดไม่เกิน 4 MB',
        ],
        'department_id' => [
            'required_if' => 'กรุณาเลือกแผนกให้พนักงาน',
        ],
    ],

    /*
    | ชื่อฟิลด์ภาษาไทย ใช้แทน :attribute เพื่อให้ข้อความอ่านเป็นประโยคไทยจริง
    */
    'attributes' => [
        'name' => 'ชื่อ',
        'username' => 'บัญชีผู้ใช้งาน',
        'email' => 'อีเมล',
        'phone' => 'เบอร์โทรศัพท์',
        'password' => 'รหัสผ่าน',
        'password_confirmation' => 'ยืนยันรหัสผ่าน',
        'current_password' => 'รหัสผ่านปัจจุบัน',
        'role' => 'สิทธิ์การใช้งาน',
        'department_id' => 'แผนก',
        'is_active' => 'สถานะการใช้งาน',
        'is_department_head' => 'หัวหน้าแผนก',
        'profile_image' => 'รูปโปรไฟล์',
        'job_topic' => 'ชื่องาน',
        'job_details' => 'รายละเอียดงาน',
        'job_start_at' => 'วันที่เริ่มงาน',
        'job_due_at' => 'กำหนดส่งงาน',
        'job_priority' => 'ระดับความสำคัญ',
        'job_status' => 'สถานะงาน',
        'department_name' => 'ชื่อแผนก',
        'attachments' => 'ไฟล์แนบ',
        'approval_status' => 'สถานะการอนุมัติ',
        'decision_reason' => 'เหตุผล',
    ],
];

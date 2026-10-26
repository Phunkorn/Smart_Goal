{{--
    ข้อมูลผู้แจ้งจากบัญชีที่เข้าสู่ระบบ — แสดงให้เห็นเพื่อความโปร่งใส ไม่ใช่ช่องกรอก
    ผู้แจ้งและแผนกของ Ticket ถูกตั้งฝั่ง server จากบัญชีเสมอ (TicketSubmissionService::submitInternal)
    เบอร์โทรเป็นข้อมูลโปรไฟล์ แสดงเมื่อมี เพื่อให้ผู้แจ้งรู้ว่าทีม IT ติดต่อกลับทางใด (ไม่ถูกบันทึกลง Ticket)

    แผงสีกลาง (ไม่ใช่กล่องสีฟ้า) แถวละหนึ่งข้อมูล: ป้ายซ้าย ค่าขวา — tickets/form.css

    พารามิเตอร์: $requester — ผู้ใช้ที่โหลด department ไว้แล้ว
--}}
<section class="ticket-form__requester" aria-label="ข้อมูลผู้แจ้ง">
    <dl>
        <div>
            <dt>ผู้แจ้ง</dt>
            <dd>{{ $requester->name }}</dd>
        </div>
        <div>
            <dt>แผนก</dt>
            <dd>{{ $requester->department?->department_name ?? 'ไม่ได้ระบุแผนก' }}</dd>
        </div>
        @if (filled($requester->phone))
            <div>
                <dt>เบอร์ติดต่อ</dt>
                <dd>{{ $requester->phone }}</dd>
            </div>
        @endif
    </dl>
</section>

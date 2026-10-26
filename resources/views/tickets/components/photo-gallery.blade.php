{{--
    รูปประกอบที่บันทึกแล้ว — เปิดผ่าน MediaController::ticketAttachment ที่ตรวจ TicketPolicy::view
    ไม่สร้าง URL จาก file_path โดยตรง และไม่ใส่ path ของไฟล์ลงใน HTML
--}}
<ul class="ticket-gallery">
    @foreach ($photos as $photo)
        @php($url = route('media.ticket-attachments.show', $photo))
        <li>
            <a class="ticket-gallery__link" href="{{ $url }}" target="_blank" rel="noopener">
                <img src="{{ $url }}" alt="รูปประกอบ {{ $photo->original_name }}" loading="lazy">
                <span class="visually-hidden">เปิดรูปขนาดเต็มในแท็บใหม่</span>
            </a>
        </li>
    @endforeach
</ul>

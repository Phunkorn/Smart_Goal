{{--
    งานประจำของวันย้อนหลังที่ยังไม่ได้บันทึก

    แสดงเป็นรายการจาง ๆ เท่านั้น ระบบไม่สร้างข้อมูลให้เอง เพราะการเติมรายการค้าง
    ย้อนหลังให้คนที่เพิ่งกลับจากลา จะกลายเป็นสัญญาณ "ไม่ได้ทำงาน" หลายวันติดกัน
    ที่ไปเพี้ยนในรายงานภาระงาน ทั้งที่วันนั้นเขาลาอย่างถูกต้อง

    ปุ่มสร้างจึงเป็นการยืนยันจากเจ้าของว่า "วันนั้นฉันทำงานประจำเหล่านี้จริง"
--}}
<section class="pending-routines" aria-labelledby="pendingRoutinesHeading">
    <div class="pending-routines__head">
        <h2 class="pending-routines__heading" id="pendingRoutinesHeading">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            งานประจำที่ยังไม่ได้บันทึกในวันนี้
        </h2>

        <form method="POST" action="{{ route('daily-logs.routines.materialize') }}" data-materialize-routines>
            @csrf
            <input type="hidden" name="date" value="{{ $dateValue }}">
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> สร้างย้อนหลัง
            </button>
        </form>
    </div>

    <ul class="pending-routines__list">
        @foreach($pendingRoutines as $routine)
            <li class="pending-routines__item">
                <i class="bi bi-circle" aria-hidden="true"></i>
                <span>{{ $routine->title }}</span>
                @if($routine->category)
                    <small>{{ $routine->category->name }}</small>
                @endif
            </li>
        @endforeach
    </ul>

    <p class="pending-routines__note">
        ระบบไม่สร้างรายการย้อนหลังให้อัตโนมัติ เพื่อไม่ให้วันที่ลาหยุดกลายเป็นงานค้างในรายงาน
    </p>
</section>

{{--
    งานประจำของวันที่ผ่านมาที่ไม่มีรายการอยู่เลย

    ระบบไม่สร้างรายการย้อนหลังให้เอง และไม่มีปุ่ม "สร้างย้อนหลัง" อีกต่อไป เพราะ
    การสร้างรายการของวันที่ผ่านไปแล้วเท่ากับเปิดให้กดเริ่มงานย้อนหลัง ซึ่งจะบันทึก
    เวลาของวันนี้ลงในรายการของเมื่อวาน

    สิ่งเดียวที่ทำได้คือบอกว่าวันนั้นไม่ได้ทำเพราะอะไร (ลืม ลา ขาด วันหยุด)
    ซึ่งลงเป็นรายการสถานะ "ไม่ได้ทำ" ให้ทันทีในคลิกเดียว
--}}
<section class="pending-routines" aria-labelledby="pendingRoutinesHeading" data-pending-routines>
    <div class="pending-routines__head">
        <h2 class="pending-routines__heading" id="pendingRoutinesHeading">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            งานประจำของวันนั้นที่ยังไม่มีบันทึก
        </h2>
    </div>

    <ul class="pending-routines__list">
        @foreach($pendingRoutines as $routine)
            <li class="pending-routines__item">
                <i class="bi bi-circle" aria-hidden="true"></i>
                <span class="pending-routines__title">{{ $routine->title }}</span>
                @if($routine->category)
                    <small>{{ $routine->category->name }}</small>
                @endif

                {{-- ปุ่มนี้บันทึกว่า "ไม่ได้ทำ" พร้อมเหตุผล ไม่ได้สร้างงานให้ทำต่อ
                     เส้นทางจริงอยู่ที่ WorkLogController::missRoutine() --}}
                <button type="button"
                    class="pending-routines__reason"
                    data-routine-missed
                    data-template-id="{{ $routine->id }}"
                    data-routine-title="{{ $routine->title }}">
                    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                    ระบุเหตุผลที่ไม่ได้ทำ
                </button>
            </li>
        @endforeach
    </ul>

    <p class="pending-routines__note">
        งานประจำของวันที่ผ่านไปแล้วเริ่มย้อนหลังไม่ได้ ระบุได้เฉพาะเหตุผลที่ไม่ได้ทำ
        เพื่อให้เวลาทำงานของแต่ละวันตรงกับความจริง
    </p>
</section>

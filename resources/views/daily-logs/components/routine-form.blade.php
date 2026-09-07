{{--
    ฟอร์มสร้างแม่แบบงานประจำ

    เป็นฟอร์มธรรมดาในหน้า ไม่ใช่ modal เพราะเป็นเนื้อหาหลักของหน้านี้
    และไม่มีอะไรอยู่ข้างหลังให้ต้องบัง
--}}
<form method="POST" action="{{ route('daily-logs.routines.store') }}" class="routine-form" data-routine-form>
    @csrf

    <h2 class="routine-form__heading">เพิ่มงานประจำ</h2>

    <div class="log-field">
        <label class="form-label" for="routineTitle">งานที่ทำ <span aria-hidden="true">*</span></label>
        <input type="text" class="form-control" id="routineTitle" name="title"
            maxlength="200" required autocomplete="off"
            placeholder="เช่น ตรวจสอบคอมพิวเตอร์ประจำวัน">
    </div>

    <div class="log-field">
        <span class="form-label">ประเภทงาน</span>
        <div class="log-modal__kinds">
            @foreach($design['kinds'] as $key => $meta)
                <label class="log-kind-option log-kind-option--{{ $meta['tone'] }}">
                    <input type="radio" name="kind" value="{{ $key }}" @checked($loop->first)>
                    <span><i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i> {{ $meta['label'] }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="log-field">
        <label class="form-label" for="routineCategory">หมวดงาน</label>
        <select class="form-select" id="routineCategory" name="work_log_category_id">
            <option value="">ไม่ระบุ</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- วันทำงานเก็บเป็น bitmask ฝั่งเซิร์ฟเวอร์ ฟอร์มจึงส่งเป็นดัชนีวัน
         (0 = จันทร์) แล้วให้ WorkLogWeekdays::mask() เป็นผู้ประกอบ --}}
    <fieldset class="log-field routine-form__weekdays">
        <legend class="form-label">ทำวันไหนบ้าง</legend>
        <div class="routine-form__days">
            @foreach($weekdays as $index => $day)
                <label class="routine-day">
                    <input type="checkbox" name="weekdays[]" value="{{ $index }}"
                        @checked(($defaultMask & (1 << $index)) !== 0)>
                    <span>{{ $day['short'] }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <div class="log-field-row">
        <div class="log-field">
            <label class="form-label" for="routineStart">เวลาเริ่มโดยประมาณ</label>
            <input type="time" class="form-control" id="routineStart" name="default_start_time">
        </div>

        <div class="log-field">
            <label class="form-label" for="routineDuration">ใช้เวลาประมาณ (นาที)</label>
            <input type="number" class="form-control" id="routineDuration" name="default_duration_minutes"
                min="1" max="{{ $design['maxDurationMinutes'] }}" placeholder="เช่น 40">
        </div>
    </div>

    <div class="log-field">
        <label class="form-label" for="routineDetails">รายละเอียด</label>
        <textarea class="form-control" id="routineDetails" name="details" rows="2" maxlength="2000"></textarea>
    </div>

    <p class="routine-form__hint">
        ระบบจะเพิ่มรายการให้ในวันที่เลือก โดยยังไม่ระบุเวลา — กด "เริ่มงาน" ในหน้าไทม์ไลน์เพื่อจับเวลาจริง
    </p>

    <p class="log-modal__error" role="alert" data-routine-error hidden></p>

    <button type="submit" class="btn btn-primary routine-form__submit">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มงานประจำ
    </button>
</form>

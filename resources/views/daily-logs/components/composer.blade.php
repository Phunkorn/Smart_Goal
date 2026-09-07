{{--
    ช่องบันทึกงานแบบเร็ว

    ตั้งใจให้เป็นแถบ inline ที่อยู่ถาวรเหนือไทม์ไลน์ ไม่ใช่ modal เพราะทางที่ผู้ใช้
    เดินบ่อยที่สุดคือ "พิมพ์ชื่องานแล้วกดบันทึก" การเปิด overlay ทุกครั้งจะทำให้
    ต้องล็อกการเลื่อนหน้า จัดการโฟกัส และบังวันที่กำลังบันทึกอยู่โดยไม่จำเป็น
    ส่วนฟอร์มเต็ม (เวลาเป๊ะ เชื่อมโปรเจกต์ ไฟล์แนบ) จึงค่อยเปิดเป็น modal
--}}
<section class="log-composer" aria-labelledby="logComposerHeading">
    <h2 class="visually-hidden" id="logComposerHeading">บันทึกงานใหม่</h2>

    <form class="log-composer__form"
        method="POST"
        action="{{ route('daily-logs.store') }}"
        data-log-composer>
        @csrf
        <input type="hidden" name="work_date" value="{{ $dateValue }}">

        <div class="log-composer__row">
            <label class="visually-hidden" for="logComposerTitle">งานที่ทำ</label>
            <input type="text"
                class="form-control log-composer__title"
                id="logComposerTitle"
                name="title"
                maxlength="200"
                required
                autocomplete="off"
                placeholder="ทำอะไรอยู่? เช่น ตรวจสอบคอมพิวเตอร์ฝ่ายบัญชี"
                data-composer-title>
        </div>

        <div class="log-composer__row log-composer__row--options">
            <fieldset class="log-composer__kinds">
                <legend class="visually-hidden">ประเภทงาน</legend>
                @foreach($design['kinds'] as $key => $meta)
                    <label class="log-kind-option log-kind-option--{{ $meta['tone'] }}">
                        <input type="radio" name="kind" value="{{ $key }}" @checked($loop->first)>
                        <span><i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i> {{ $meta['label'] }}</span>
                    </label>
                @endforeach
            </fieldset>

            <label class="visually-hidden" for="logComposerCategory">หมวดงาน</label>
            <select class="form-select log-composer__category" id="logComposerCategory" name="work_log_category_id">
                <option value="">หมวดงาน</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>

            @include('daily-logs.components.participant-picker', [
                'instanceId' => 'composer-participant',
                'options' => $participantOptions,
                'selectedIds' => [],
                'summaryLabel' => 'ทำด้วยกันกับ',
            ])

            <div class="log-composer__actions">
                <button type="button" class="btn btn-outline-secondary log-composer__more" data-open-entry-modal>
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <span>ระบุเวลา / รายละเอียด</span>
                </button>

                @if($capabilities['canUseTimer'])
                    {{-- จับเวลาได้เฉพาะวันนี้ การกดจับเวลาย้อนหลังไม่มีความหมาย
                         เพราะเวลามาจากนาฬิกาของเซิร์ฟเวอร์ ณ ตอนที่กด --}}
                    <button type="button" class="btn btn-outline-primary log-composer__start" data-composer-start>
                        <i class="bi bi-play-fill" aria-hidden="true"></i>
                        <span>เริ่มงาน</span>
                    </button>
                @endif

                <button type="submit" class="btn btn-primary log-composer__submit" data-composer-submit>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>บันทึกงาน</span>
                </button>
            </div>
        </div>

        <p class="log-composer__error" role="alert" data-composer-error hidden></p>
    </form>
</section>

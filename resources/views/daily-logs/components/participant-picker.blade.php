{{--
    ตัวเลือกผู้ร่วมงาน

    งานปฏิบัติการหลายอย่างทำกันหลายคน เช่น ตรวจสอบคอมพิวเตอร์ตอนเช้าที่ขึ้นไป
    กันสองคน การให้ทั้งคู่พิมพ์บันทึกของตัวเองแยกกันทำให้เสียเวลาซ้ำและทำให้
    รายงานนับงานชิ้นเดียวเป็นสองรายการ

    ใช้ <details> ของเบราว์เซอร์แทน popover ที่เขียนเอง เพราะได้พฤติกรรมเปิด/ปิด
    และการเข้าถึงด้วยคีย์บอร์ดมาให้ครบโดยไม่ต้องจัดการโฟกัสเอง และไม่ใช่ overlay
    จึงไม่ต้องล็อกการเลื่อนหน้า

    ไม่ใช้ components/people-selector เพราะตัวนั้นออกแบบมาสำหรับการจัดทีมที่มี
    ขั้นตอนอนุมัติ ส่วนที่นี่ต้องการแค่ติ๊กเพื่อนร่วมแผนกให้จบในไม่กี่วินาที

    @param string $instanceId  คำนำหน้า id ต้องไม่ซ้ำกันในหน้าเดียว
    @param Collection $options เพื่อนร่วมแผนกที่เลือกได้
    @param array $selectedIds  id ที่ติ๊กไว้ล่วงหน้า
    @param string $summaryLabel ข้อความบนปุ่มเปิด
--}}
@php
    $instanceId = $instanceId ?? 'participants';
    $options = $options ?? collect();
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id);
    $summaryLabel = $summaryLabel ?? 'ผู้ร่วมงาน';
@endphp

@if($options->isNotEmpty())
    <details class="participant-picker" data-participant-picker>
        <summary class="participant-picker__summary">
            <i class="bi bi-people" aria-hidden="true"></i>
            <span>{{ $summaryLabel }}</span>
            <span class="participant-picker__count" data-participant-count
                @if($selectedIds->isEmpty()) hidden @endif>{{ $selectedIds->count() }}</span>
        </summary>

        <div class="participant-picker__panel">
            <p class="participant-picker__hint">
                เลือกเพื่อนร่วมแผนกที่ทำงานนี้ด้วยกัน — บันทึกจะนับเป็นงานเดียว ไม่ต้องบันทึกซ้ำ
            </p>

            <div class="participant-picker__options">
                @foreach($options as $person)
                    <label class="participant-option">
                        <input type="checkbox"
                            id="{{ $instanceId }}-{{ $person->id }}"
                            name="participants[]"
                            value="{{ $person->id }}"
                            data-participant-checkbox
                            @checked($selectedIds->contains($person->id))>
                        <span class="participant-option__avatar" aria-hidden="true">
                            {{ \App\Support\WorkBoardDesign::initials($person->name) }}
                        </span>
                        <span class="participant-option__name">{{ $person->name }}</span>
                        <i class="bi bi-check2 participant-option__tick" aria-hidden="true"></i>
                    </label>
                @endforeach
            </div>
        </div>
    </details>
@endif

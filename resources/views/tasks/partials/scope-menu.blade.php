{{--
    ตัวกรองขอบเขตงาน — popover เดียวที่ทุก role ใช้ร่วมกัน

    ใช้ <details> ตาม pattern เดิมของโปรเจกต์ (เมนูสถานะ/ความสำคัญในตาราง)
    เหตุที่ไม่ใช้ <select> เพราะ native select จัดสไตล์รายการข้างในไม่ได้
    จึงใส่หัวกลุ่มและคำอธิบายกำกับแต่ละตัวเลือกไม่ได้ ซึ่งเป็นสาเหตุหลักที่ผู้ใช้สับสน

    ถ้อยคำและสิทธิ์มาจาก App\Support\TaskScopeOptions ที่เดียว
    ตัวกรองจริงบังคับที่ MyTaskController::applyTaskScope() ฝั่งเซิร์ฟเวอร์เสมอ
--}}
@php
    $groups = collect($taskScopeOptions)->groupBy(fn ($option) => $option['group'] ?? '');
@endphp

<div class="mytasks-scope" data-task-scope-control>
    <details class="mytasks-scope__menu" data-task-scope-menu>
        <summary aria-label="เลือกขอบเขตงานที่จะแสดง">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <span class="mytasks-scope__current">{{ $taskScopeActive['label'] }}</span>
            <i class="bi bi-chevron-down mytasks-scope__caret" aria-hidden="true"></i>
        </summary>

        <div class="mytasks-scope__panel" role="menu">
            @foreach($groups as $groupLabel => $options)
                @if($groupLabel !== '')
                    <p class="mytasks-scope__group">{{ $groupLabel }}</p>
                @endif

                @foreach($options as $option)
                    <button
                        type="button"
                        class="mytasks-scope__option {{ $taskScope === $option['value'] ? 'is-selected' : '' }}"
                        role="menuitemradio"
                        aria-checked="{{ $taskScope === $option['value'] ? 'true' : 'false' }}"
                        data-task-scope-option="{{ $option['value'] }}"
                    >
                        <i class="bi {{ $option['icon'] }}" aria-hidden="true"></i>
                        <span>
                            <strong>{{ $option['label'] }}</strong>
                            <small>{{ $option['description'] }}</small>
                        </span>
                        <i class="bi bi-check2 mytasks-scope__tick" aria-hidden="true"></i>
                    </button>
                @endforeach
            @endforeach
        </div>
    </details>

    {{-- บอกตรง ๆ ว่ากำลังแสดงอะไรอยู่และกี่งาน ผู้ใช้จะได้ไม่ต้องเดาว่าทำไมตารางว่าง --}}
    <p class="mytasks-scope__summary" data-task-scope-summary role="status" aria-live="polite">
        <i class="bi bi-eye" aria-hidden="true"></i>
        กำลังแสดง: {{ $taskScopeActive['description'] }}
        <b>{{ number_format($taskScopeCount) }} งาน</b>
    </p>
</div>

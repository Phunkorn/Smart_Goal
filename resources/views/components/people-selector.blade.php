@php
    /**
     * ตัวเลือกบุคคลแบบใช้ร่วมกัน — ใช้ทั้งผู้เข้าร่วมประชุมและผู้ร่วมงานของงาน
     *
     * ห้ามคัดลอกไฟล์นี้ไปทำอีกชุด ถ้าต้องการพฤติกรรมต่างออกไปให้เพิ่ม parameter แทน
     *
     * $instanceId   คำนำหน้าของ id ทุกตัวในอินสแตนซ์นี้ ต้องไม่ซ้ำกันในหน้าเดียว
     * $inputName    ชื่อ input ที่จะถูกส่งไป backend เช่น attendees[] หรือ collaborators[]
     * $people       รายชื่อที่เลือกได้ (ต้องมี id, name, department)
     * $departments  ตัวเลือกปุ่มกรองแผนก
     * $selectedIds  id ที่ถูกเลือกไว้ล่วงหน้า
     * $excludeIds   id ที่ต้องไม่ปรากฏในรายการเลย เช่น คนที่อยู่ในทีมแล้ว
     *                (ห้ามแสดงเป็น disabled สีจาง เพราะจะกลายเป็นข้อมูลซ้ำกับรายการทีม)
     * $sidePanel    view ที่จะแทรกไว้เหนือรายการ "ที่เลือกไว้" ในคอลัมน์ขวา
     * $readOnly     ปิดการแก้ไขทั้งชุด (ผู้ที่ไม่มีสิทธิ์จัดการทีม)
     * $notice       ข้อความอธิบายเมื่อแก้ไขไม่ได้
     * $labels       ข้อความทั้งหมดที่แสดงผล
     */
    $instanceId = $instanceId ?? 'people';
    $inputName = $inputName ?? 'people[]';
    $people = $people ?? collect();
    $departments = $departments ?? collect();
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id)->unique();
    $excludeIds = collect($excludeIds ?? [])->map(fn ($id) => (int) $id)->unique();
    $sidePanel = $sidePanel ?? null;
    $readOnly = (bool) ($readOnly ?? false);
    $notice = $notice ?? null;
    $labels = array_merge([
        'title' => 'เลือกบุคคล',
        'hint' => 'คลิกเลือกได้หลายคน',
        'search' => 'ค้นหาชื่อหรือแผนก',
        'all' => 'ทั้งหมด',
        'emptyOptions' => 'ไม่พบรายชื่อที่ตรงกับตัวกรอง',
        'emptySelected' => 'ยังไม่ได้เลือก',
        'selectedTitle' => 'ที่เลือกไว้',
        'countTemplate' => 'เลือกแล้ว :count คน',
        'removeHint' => 'กด × เพื่อนำออก',
        'help' => 'แสดงเฉพาะบัญชีที่เปิดใช้งาน',
        'help2' => 'การเปลี่ยนแผนกไม่ยกเลิกคนที่เลือกไว้',
    ], $labels ?? []);

    $selectedPeople = $people->whereIn('id', $selectedIds->all());
@endphp

<div class="people-selector-field" data-people-selector data-instance="{{ $instanceId }}" @if($readOnly) data-readonly="true" @endif>
    <div class="people-selector-field__head">
        <span class="people-selector-field__label" id="{{ $instanceId }}-label">{{ $labels['title'] }}</span>
        <span>{{ $labels['hint'] }}</span>
    </div>

    <div class="people-selector">
        <div class="people-selector__browser">
            <label class="people-selector__search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="visually-hidden">{{ $labels['search'] }}</span>
                <input type="search" placeholder="{{ $labels['search'] }}" aria-label="{{ $labels['search'] }}" data-people-search @disabled($readOnly)>
            </label>

            <div class="people-selector__departments" role="group" aria-label="กรองตามแผนก">
                <button class="people-selector__department is-active" type="button" data-people-department data-department-id="" aria-pressed="true" @disabled($readOnly)>{{ $labels['all'] }}</button>
                @foreach($departments as $department)
                    <button class="people-selector__department" type="button" data-people-department data-department-id="{{ $department->id }}" aria-pressed="false" @disabled($readOnly)>{{ $department->department_name }}</button>
                @endforeach
            </div>

            <div class="people-selector__options" role="group" aria-labelledby="{{ $instanceId }}-label" data-people-options>
                @foreach($people as $person)
                    @php
                        $isSelected = $selectedIds->contains($person->id);
                        $isExcluded = $excludeIds->contains($person->id);
                        $departmentName = $person->department?->department_name ?? 'ไม่ระบุแผนก';
                    @endphp
                    <label @class(['people-selector__option', 'is-selected' => $isSelected])
                        data-people-option
                        @if($isExcluded) data-people-excluded hidden @endif
                        data-person-id="{{ $person->id }}"
                        data-department-id="{{ $person->department_id ?? '' }}"
                        data-search="{{ Str::lower($person->name.' '.$departmentName.' '.($person->role ?? '')) }}">
                        <input class="form-check-input" type="checkbox"
                            id="{{ $instanceId }}-person-{{ $person->id }}"
                            name="{{ $inputName }}"
                            value="{{ $person->id }}"
                            data-people-checkbox
                            data-person-name="{{ $person->name }}"
                            @checked($isSelected) @disabled($readOnly || $isExcluded)>
                        <span><strong>{{ $person->name }}</strong><small>{{ $departmentName }}</small></span>
                    </label>
                @endforeach
                <p class="people-selector__empty" data-people-empty @if($people->isNotEmpty()) hidden @endif>{{ $labels['emptyOptions'] }}</p>
            </div>
        </div>

        <div class="people-selector__selected">
            {{-- ผู้เรียกแทรกบล็อกของตัวเองได้ เช่น รายชื่อทีมปัจจุบันของงาน --}}
            @if($sidePanel)
                @include($sidePanel)
            @endif

            <div class="people-selector__selected-head">
                <strong data-people-count data-count-template="{{ $labels['countTemplate'] }}" aria-live="polite">{{ str_replace(':count', $selectedIds->count(), $labels['countTemplate']) }}</strong>
                <span>{{ $labels['removeHint'] }}</span>
            </div>
            <div class="people-selector__chips" data-people-chips aria-live="polite">
                @foreach($selectedPeople as $person)
                    <span class="people-selector__chip" data-people-chip data-person-id="{{ $person->id }}">
                        <span>{{ $person->name }}</span>
                        <button type="button" data-people-remove data-person-id="{{ $person->id }}" aria-label="นำ {{ $person->name }} ออก" @disabled($readOnly)><i class="bi bi-x" aria-hidden="true"></i></button>
                    </span>
                @endforeach
                <p class="people-selector__empty" data-people-chips-empty @if($selectedIds->isNotEmpty()) hidden @endif>{{ $labels['emptySelected'] }}</p>
            </div>
        </div>
    </div>

    @if($notice)
        <p class="people-selector-field__notice" data-people-notice>{{ $notice }}</p>
    @endif

    <div class="people-selector-field__help"><span>{{ $labels['help'] }}</span><span>{{ $labels['help2'] }}</span></div>
</div>

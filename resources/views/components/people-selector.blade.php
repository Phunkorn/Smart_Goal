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
    $variant = $variant ?? null;
    $isTeamManager = $variant === 'team-manager';
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

<div @class(['people-selector-field', 'people-selector-field--team-manager' => $isTeamManager])
    data-people-selector
    data-instance="{{ $instanceId }}"
    @if($variant) data-people-variant="{{ $variant }}" @endif
    @if($readOnly) data-readonly="true" @endif>
    @unless($isTeamManager)
        <div class="people-selector-field__head">
            <span class="people-selector-field__label" id="{{ $instanceId }}-label">{{ $labels['title'] }}</span>
            <span>{{ $labels['hint'] }}</span>
        </div>
    @endunless

    <div class="people-selector">
        <div class="people-selector__browser">
            @if($isTeamManager)
                <div class="people-selector-field__head">
                    <span>
                        <strong class="people-selector-field__label" id="{{ $instanceId }}-label">{{ $labels['title'] }}</strong>
                        <small>{{ $labels['hint'] }}</small>
                    </span>
                </div>
            @endif

            <div class="people-selector__search-tools">
                <label class="people-selector__search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span class="visually-hidden">{{ $labels['search'] }}</span>
                    <input type="search" placeholder="{{ $labels['search'] }}" aria-label="{{ $labels['search'] }}" data-people-search @disabled($readOnly)>
                </label>

                @if($isTeamManager)
                    <label class="people-selector__department-select">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        <span class="visually-hidden">กรองตามแผนก</span>
                        <select data-people-department-select aria-label="กรองตามแผนก" @disabled($readOnly)>
                            <option value="">แผนกทั้งหมด</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                @endif
            </div>

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
                        $email = $person->email ?? null;
                        $avatarUrl = $person->profile_image ? route('media.profile', $person) : null;
                        $initial = Str::substr($person->name ?: '?', 0, 1);
                    @endphp
                    <label @class(['people-selector__option', 'is-selected' => $isSelected])
                        data-people-option
                        @if($isExcluded) data-people-excluded hidden @endif
                        data-person-id="{{ $person->id }}"
                        data-department-id="{{ $person->department_id ?? '' }}"
                        data-search="{{ Str::lower($person->name.' '.($email ?? '').' '.$departmentName.' '.($person->role ?? '')) }}">
                        <input class="form-check-input" type="checkbox"
                            id="{{ $instanceId }}-person-{{ $person->id }}"
                            name="{{ $inputName }}"
                            value="{{ $person->id }}"
                            data-people-checkbox
                            data-person-name="{{ $person->name }}"
                            data-person-email="{{ $email }}"
                            data-person-department="{{ $departmentName }}"
                            data-person-avatar-url="{{ $avatarUrl }}"
                            @checked($isSelected) @disabled($readOnly || $isExcluded)>
                        @if($isTeamManager)
                            <span class="people-selector__avatar" aria-hidden="true">
                                @if($avatarUrl)
                                    <img src="{{ $avatarUrl }}" alt="">
                                @else
                                    <span>{{ $initial }}</span>
                                @endif
                            </span>
                        @endif
                        <span class="people-selector__person">
                            <strong>{{ $person->name }}</strong>
                            <small>{{ $email ?: $departmentName }}</small>
                        </span>
                        @if($isTeamManager)
                            <span class="people-selector__department-badge">{{ $departmentName }}</span>
                        @endif
                    </label>
                @endforeach
                <p class="people-selector__empty" data-people-empty @if($people->isNotEmpty()) hidden @endif>{{ $labels['emptyOptions'] }}</p>
            </div>

            @if($isTeamManager)
                <div class="people-selector__selection-summary">
                    <span><i class="bi bi-info-circle" aria-hidden="true"></i> <strong data-people-summary-count>เลือกแล้ว {{ $selectedIds->count() }} คน</strong></span>
                    <button type="button" data-people-clear @disabled($readOnly || $selectedIds->isEmpty())>ล้างการเลือก</button>
                </div>
            @endif
        </div>

        <div class="people-selector__selected">
            {{-- ผู้เรียกแทรกบล็อกของตัวเองได้ เช่น รายชื่อทีมปัจจุบันของงาน --}}
            @if($sidePanel)
                @include($sidePanel)
            @endif

            @if($isTeamManager)
                <section class="people-selector__selected-stage" data-people-stage @if($selectedIds->isEmpty()) hidden @endif>
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
            @if($isTeamManager)
                </section>
            @endif
        </div>
    </div>

    @if($notice)
        <p class="people-selector-field__notice" data-people-notice>{{ $notice }}</p>
    @endif

    <div class="people-selector-field__help"><span>{{ $labels['help'] }}</span><span>{{ $labels['help2'] }}</span></div>
</div>

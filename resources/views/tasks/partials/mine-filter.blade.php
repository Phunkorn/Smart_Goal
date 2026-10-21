{{--
    ตัวกรองตามผู้รับผิดชอบ — ตัวกรองการแสดงผลฝั่ง client ล้วน ๆ

    การเลือก "งานของใคร" (งานที่รับผิดชอบ / ร่วมทำ / มอบหมาย ฯลฯ) เป็นหน้าที่ของตัวกรองขอบเขตงาน
    (tasks/partials/scope-menu.blade.php) ที่อยู่ข้าง ๆ แล้ว ที่นี่จึงไม่มีปุ่มชุดนั้นซ้ำอีก

    ผู้ใช้เห็นงานของคนอื่นได้จากสิทธิ์ระดับโปรเจกต์ หัวหน้าแผนก หรือสิทธิ์ดูงานของแผนก
    (own_department/custom) ที่ server ส่งมาแล้วทั้งหมด ตัวกรองนี้ไม่เปลี่ยนสิทธิ์หรือชุดงาน
    ที่ server ส่งมาเลย มันเพียงซ่อนแถวตาม data-assignee (work_orders.user_id) ที่ server ใส่มา

    ค่าเริ่มต้นคือ "ผู้รับผิดชอบทุกคน" เสมอ หน้าเพิ่งโหลดจึงแสดงเท่ากับที่ server ส่งมา

    มุมมองที่ตัวกรองนี้มีผล — server เป็นผู้ประกาศผ่าน data-owner-filter-views:
      board  (แท็บ "ตาราง") เห็นงานทั้งโปรเจกต์อยู่แล้ว จึงมีเสมอ
      table  (แท็บ "บอร์ด") ปกติวาดเฉพาะงานของตัวเอง ตัวกรองจึงไม่มีผล ยกเว้นผู้ที่ได้รับสิทธิ์
             ดูงานของแผนก ซึ่ง table-kanban.blade.php วาดงานของแผนกให้ด้วย
    ค่า hidden ตั้งต้นที่นี่เป็นเพียงการกันหน้ากระพริบ ตัวตัดสินจริงคือ applyView()
    ใน mytasks-views.js ซึ่งคำนวณใหม่ทุกครั้งที่สลับมุมมอง
--}}
@php
    $ownerFilterViews = \App\Support\DepartmentTaskVisibility::isGranted(auth()->user())
        ? ['board', 'table']
        : ['board'];
    // รายชื่อผู้รับผิดชอบมาจากงานที่ถูกวาดอยู่ในหน้านี้เท่านั้น จึงไม่มีชื่อที่ผู้ใช้ไม่มีสิทธิ์เห็นหลุดมา
    $ownerFilterAssignees = $activeTasks->merge($completedTasks)
        ->pluck('user')
        ->filter()
        ->unique('id')
        ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
        ->values();
@endphp
<div class="mytasks-owner-filter" data-board-mine-filter
    data-owner-filter-views="{{ implode(' ', $ownerFilterViews) }}"
    {{ in_array($workspaceView, $ownerFilterViews, true) ? '' : 'hidden' }}>
    {{--
        รูปโปรไฟล์ของแต่ละคนวาดโดย components/select-dropdown.js จาก data-avatar-*
        ทั้งในรายการและบนปุ่ม (data-sg-trigger-media) ตัวย่อชื่อใช้กติกาเดียวกับวงกลมผู้รับผิดชอบ
        บนการ์ดบอร์ด (components/user-avatar-content.blade.php) คือตัวอักษรแรกของชื่อ
    --}}
    <div class="notion-filter mytasks-owner-filter__assignee" data-sg-select>
        <select data-board-assignee-filter data-sg-trigger-media aria-label="กรองตามผู้รับผิดชอบ">
            <option value="" data-icon="bi-people" data-description="แสดงงานของผู้รับผิดชอบทุกคน">ผู้รับผิดชอบทุกคน</option>
            @foreach($ownerFilterAssignees as $person)
                <option value="{{ $person->id }}" data-avatar-initial="{{ Str::substr($person->name ?: '?', 0, 1) }}"@if($person->profile_image) data-avatar-url="{{ route('media.profile', $person) }}"@endif>{{ $person->name }}{{ (int) $person->id === (int) auth()->id() ? ' (ฉัน)' : '' }}</option>
            @endforeach
        </select>
    </div>
</div>

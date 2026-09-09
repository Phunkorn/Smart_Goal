{{--
    ตัวกรองเพิ่มเติมของรายงานภาระงานปฏิบัติการ

    เสียบเข้าไปในตัวกรองร่วม (reports.components.filters) ผ่านพารามิเตอร์
    $extraFilters แทนการคัดลอกฟอร์มทั้งชุดมาแก้ ซึ่งจะกลายเป็นแหล่งความจริงที่สอง
--}}
<div>
    <label class="form-label" for="reportKind">ประเภทงาน</label>
    <select class="form-select" id="reportKind" name="kind">
        <option value="">ทุกประเภท</option>
        @foreach($filterOptions['kinds'] as $key => $meta)
            <option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ $meta['label'] }}</option>
        @endforeach
    </select>
</div>

@if(($filterOptions['owners'] ?? collect())->count() > 1)
    <div>
        <label class="form-label" for="reportOwner">ผู้ปฏิบัติงาน</label>
        <select class="form-select" id="reportOwner" name="owner">
            <option value="">ทุกคนในขอบเขตที่ดูแล</option>
            @foreach($filterOptions['owners'] as $owner)
                <option value="{{ $owner->id }}" @selected($filters['owner_id'] === $owner->id)>{{ $owner->name }}</option>
            @endforeach
        </select>
    </div>
@endif

<div>
    <label class="form-label" for="reportRoutineStatus">สถานะงานประจำ</label>
    <select class="form-select" id="reportRoutineStatus" name="status">
        <option value="">ทุกสถานะ</option>
        @foreach($filterOptions['statuses'] as $key => $meta)
            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $meta['label'] }}</option>
        @endforeach
    </select>
</div>

<div>
    <label class="form-label" for="reportCategory">หมวดงาน</label>
    <select class="form-select" id="reportCategory" name="category">
        <option value="">ทุกหมวด</option>
        @foreach($filterOptions['categories'] as $category)
            <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
</div>

{{--
    ตัวกรองฝั่งงานประจำ — แยกจาก "สถานะ" เพราะตอบคนละคำถาม
    สถานะบอกว่ารายการอยู่ขั้นไหน ส่วนมุมมองงานประจำบอกว่ามันเป็นไปตามแผนหรือไม่
--}}
<div>
    <label class="form-label" for="reportRoutineFocus">มุมมองงานประจำ</label>
    <select class="form-select" id="reportRoutineFocus" name="routine_focus">
        <option value="">งานประจำทั้งหมด</option>
        @foreach($filterOptions['routineFocuses'] as $key => $label)
            <option value="{{ $key }}" @selected($filters['routine_focus'] === $key)>{{ $label }}</option>
        @endforeach
    </select>
</div>

@if(($filterOptions['routines'] ?? collect())->isNotEmpty())
    <div>
        <label class="form-label" for="reportRoutine">งานประจำ</label>
        <select class="form-select" id="reportRoutine" name="routine">
            <option value="">ทุกงานประจำ</option>
            @foreach($filterOptions['routines'] as $routine)
                <option value="{{ $routine->id }}" @selected($filters['routine_id'] === $routine->id)>{{ $routine->title }}</option>
            @endforeach
        </select>
    </div>
@endif

@if(($filterOptions['projects'] ?? collect())->isNotEmpty())
    <div>
        <label class="form-label" for="reportProject">โปรเจกต์</label>
        <select class="form-select" id="reportProject" name="project">
            <option value="">ทุกโปรเจกต์</option>
            @foreach($filterOptions['projects'] as $project)
                <option value="{{ $project->id }}" @selected($filters['project_id'] === $project->id)>{{ $project->name }}</option>
            @endforeach
        </select>
    </div>
@endif

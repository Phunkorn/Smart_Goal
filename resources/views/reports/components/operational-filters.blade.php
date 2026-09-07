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

<div>
    <label class="form-label" for="reportCategory">หมวดงาน</label>
    <select class="form-select" id="reportCategory" name="category">
        <option value="">ทุกหมวด</option>
        @foreach($filterOptions['categories'] as $category)
            <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
</div>

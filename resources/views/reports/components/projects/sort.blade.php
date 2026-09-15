{{--
    ช่องเรียงลำดับของตารางรายงานโปรเจกต์ — ส่งฟอร์มทันทีเมื่อเปลี่ยน พกตัวกรองปัจจุบันไปด้วย

    @param string $routeName
--}}
<form method="GET" action="{{ route($routeName) }}" class="project-report__sort" data-auto-submit-form>
    @foreach(array_diff_key($query, ['sort' => true]) as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <div class="project-report__control project-report__control--sort" data-sg-select>
        <label for="projectReportSort">เรียงตาม :</label>
        <select id="projectReportSort" name="sort" data-auto-submit>
            @foreach($filterOptions['sorts'] as $value => $label)
                <option value="{{ $value }}" @selected($value === $filters['sort'])>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <noscript><button type="submit" class="project-report__button">เรียง</button></noscript>
</form>

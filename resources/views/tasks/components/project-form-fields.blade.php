@php
    $projectName = $projectName ?? '';
    $projectPriority = (int) ($projectPriority ?? 2);
@endphp

<label class="project-create-name">
    <span>ชื่อโปรเจกต์ <b aria-hidden="true">*</b></span>
    <div class="project-input-shell">
        <i class="bi bi-folder" aria-hidden="true"></i>
        <input name="project_name" maxlength="80" required value="{{ $projectName }}" placeholder="เช่น ปรับปรุงเว็บไซต์บริษัท">
    </div>
</label>
<label>
    <span>ความสำคัญของโปรเจกต์</span>
    <div class="project-input-shell">
        <i class="bi bi-flag" aria-hidden="true"></i>
        <select name="project_priority">
            @foreach(\App\Support\WorkBoardDesign::PROJECT_PRIORITIES as $value => $meta)
                <option value="{{ $value }}" @selected($projectPriority === $value)>{{ $meta['label'] }}</option>
            @endforeach
        </select>
    </div>
</label>
<label class="project-create-files">
    <span>ไฟล์แนบของโปรเจกต์ <em>ไม่บังคับ · สูงสุด 5 ไฟล์</em></span>
    <div class="project-file-drop">
        <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
        <div><strong>เลือกไฟล์ที่เกี่ยวข้อง</strong><small>JPG, PNG, Word, Excel, PowerPoint · สูงสุด 10 MB ต่อไฟล์</small></div>
        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
    </div>
</label>

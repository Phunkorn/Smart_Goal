{{--
    แถบความคืบหน้าเหนือผังห้อง

    ตัวเลขทั้งหมดมาจาก ComputerCheckService::summarize() ที่เดียว ฝั่ง JavaScript
    แทนที่ค่าในนี้ด้วยค่าที่เซิร์ฟเวอร์ส่งกลับมาหลังบันทึก ไม่ได้นับเอง

    "ตรวจบางส่วน" แสดงเฉพาะเมื่อมีจริง เพื่อไม่ให้แถบรกในกรณีปกติ แต่ต้องไม่ถูก
    ซ่อนทิ้ง เพราะถ้าไม่บอก ผลรวมของสี่ตัวจะไม่เท่ากับจำนวนเครื่องทั้งหมด

    @param array $summary
--}}
<div class="cc-progress" data-cc-progress role="status" aria-live="polite">
    <p class="cc-progress__headline">
        ตรวจแล้ว <strong data-cc-stat="checked">{{ $summary['checked'] }}</strong>
        / <span data-cc-stat="total">{{ $summary['total'] }}</span> เครื่อง
    </p>

    <ul class="cc-progress__stats">
        <li class="cc-progress__stat cc-progress__stat--normal">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            ปกติ <strong data-cc-stat="normal">{{ $summary['normal'] }}</strong>
        </li>
        <li class="cc-progress__stat cc-progress__stat--issue">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            มีปัญหา <strong data-cc-stat="issue">{{ $summary['issue'] }}</strong>
        </li>
        <li class="cc-progress__stat cc-progress__stat--partial" data-cc-stat-row="partial"
            @if($summary['partial'] === 0) hidden @endif>
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            ตรวจบางส่วน <strong data-cc-stat="partial">{{ $summary['partial'] }}</strong>
        </li>
        <li class="cc-progress__stat cc-progress__stat--pending">
            <i class="bi bi-circle" aria-hidden="true"></i>
            ยังไม่ได้ตรวจ <strong data-cc-stat="pending">{{ $summary['pending'] }}</strong>
        </li>
    </ul>
</div>

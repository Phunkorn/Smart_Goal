{{--
    ผืนผ้าใบ

    โครงสามชั้นที่ใช้กล้องตัวเดียวกัน:
      wsb-vector    ชั้นเนื้อหาจริง ถูก transform ตามกล้อง
      wsb-preview   ชั้นของที่กำลังลาก ถูก transform ตามกล้องเช่นกัน
      wsb-selection ชั้นกรอบและมือจับ ไม่ถูก transform เพราะมือจับต้องมีขนาด
                    คงที่บนหน้าจอไม่ว่าจะซูมเท่าไร

    ชั้น overlay ของกระดาษโน้ตและกล่องข้อความจะถูกเพิ่มในเฟสถัดไป โดยวางซ้อน
    บน <svg> นี้และใช้ค่ากล้องเดียวกันผ่าน CSS transform

    touch-action: none อยู่ใน workspace.css ถ้าไม่มี เบราว์เซอร์บนมือถือจะขโมย
    ทุก gesture ไปเลื่อนหน้าแทนที่จะให้เราวาด
--}}
<div class="wsb-stage" data-workspace-stage>
    <svg class="wsb-canvas" data-workspace-canvas aria-label="ผืนผ้าใบของกระดาน" role="img">
        <g data-workspace-vector></g>
        <g data-workspace-preview class="wsb-preview"></g>
        <g data-workspace-selection class="wsb-selection"></g>
    </svg>

    {{--
        ชั้นข้อความเป็น HTML ไม่ใช่ SVG เพราะภาษาไทยไม่มีเว้นวรรคระหว่างคำ
        การตัดบรรทัดจึงต้องอาศัยตัวตัดคำของเบราว์เซอร์ ซึ่ง <text> ของ SVG
        และ <canvas> ไม่มีให้ ใช้ค่ากล้องเดียวกับชั้น SVG ผ่าน CSS transform
    --}}
    <div class="wsb-overlay" data-workspace-overlay></div>

    {{--
        ช่องเลือกไฟล์ที่ซ่อนไว้ ใช้แทน dropzone เต็มหน้า เพราะผืนผ้าใบต้องรับ
        pointer event ทั้งหมดไว้เอง overlay รับไฟล์จะไปขวางการวาด
    --}}
    <input type="file" class="visually-hidden" data-workspace-image-input
        accept="{{ collect(\App\Support\WorkspaceDesign::IMAGE_EXTENSIONS)->map(fn ($ext) => '.'.$ext)->implode(',') }}"
        multiple aria-hidden="true" tabindex="-1">

    <p class="wsb-hint" data-workspace-hint>
        เลื่อนกระดานด้วยล้อเมาส์ ซูมด้วย Ctrl + ล้อ หรือใช้สองนิ้วบนจอสัมผัส
    </p>
</div>

{{--
    ผืนผ้าใบ

    โครงสองส่วน:

      wsb-layers    ชั้นเนื้อหาจริง สร้างโดย layers.js ไม่ใช่เขียนตายตัวไว้ที่นี่
                    เพราะจำนวนชั้นขึ้นกับว่าเอกสารสลับระหว่างชิ้นงาน SVG กับ
                    กระดาษโน้ต/กล่องข้อความ (HTML) กี่ครั้ง ลำดับใน DOM ของชั้น
                    เหล่านี้คือลำดับชั้นที่ผู้ใช้เห็น คำสั่ง "ขึ้นบนสุด/ลงล่างสุด"
                    จึงพาเส้นข้ามกระดาษโน้ตได้จริง ดูเหตุผลเต็มที่ layers.js

      wsb-canvas--tools  ชั้นเครื่องมือที่ลอยอยู่บนสุดเสมอ ไม่ใช่เนื้อหาของกระดาน
                    จึงไม่เข้าไปปนกับลำดับชั้นข้างบน
                      wsb-preview   ของที่กำลังลาก ถูก transform ตามกล้อง
                      wsb-selection กรอบและมือจับ ไม่ถูก transform เพราะมือจับ
                                    ต้องมีขนาดคงที่บนหน้าจอไม่ว่าจะซูมเท่าไร

    touch-action: none อยู่ใน workspace/stage.css ถ้าไม่มี เบราว์เซอร์บนมือถือจะ
    ขโมยทุก gesture ไปเลื่อนหน้าแทนที่จะให้เราวาด และรูปร่างเคอร์เซอร์มาจาก
    data-tool ที่ index.js เขียนไว้บนกล่องนี้ ผู้ใช้จึงรู้จากปลายเมาส์ว่าถือ
    เครื่องมืออะไรอยู่
--}}
<div class="wsb-stage" data-workspace-stage role="group" aria-label="ผืนผ้าใบของกระดาน">
    <div class="wsb-layers" data-workspace-layers></div>

    <svg class="wsb-canvas wsb-canvas--tools" data-workspace-canvas aria-hidden="true">
        <g data-workspace-preview class="wsb-preview"></g>
        <g data-workspace-selection class="wsb-selection"></g>
    </svg>

    {{--
        ชิปไอคอนที่ลอยตามเมาส์ บอกว่ากำลังถือดินสอหรือยางลบอยู่

        อยู่ท้ายสุดของผืนผ้าใบเพื่อให้ลอยอยู่บนทุกชั้นโดยไม่ต้องใช้ z-index
        คลาสของไอคอนถูกเติมโดย tool-cursor.js จาก WorkspaceDesign::TOOLS
        ผ่าน JSON island ไฟล์ .js จึงไม่ต้องรู้จักชื่อไอคอนเอง

        เคยทำเป็นรูปฝังใน cursor ของ CSS มาก่อน แล้วออกมาเพี้ยนทุกครั้งบนจอจริง
        ดูเหตุผลเต็มที่ resources/js/pages/workspace/tool-cursor.js
    --}}
    <div class="wsb-tool-cursor" data-workspace-tool-cursor hidden aria-hidden="true">
        <i class="bi" data-workspace-tool-cursor-icon></i>
    </div>

    {{--
        ช่องเลือกไฟล์ที่ซ่อนไว้ ใช้แทน dropzone เต็มหน้า เพราะผืนผ้าใบต้องรับ
        pointer event ทั้งหมดไว้เอง overlay รับไฟล์จะไปขวางการวาด
    --}}
    <input type="file" class="visually-hidden" data-workspace-image-input
        accept="{{ collect(\App\Support\WorkspaceDesign::IMAGE_EXTENSIONS)->map(fn ($ext) => '.'.$ext)->implode(',') }}"
        multiple aria-hidden="true" tabindex="-1">

</div>

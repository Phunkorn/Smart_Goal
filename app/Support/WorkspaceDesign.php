<?php

namespace App\Support;

/**
 * แหล่งเดียวของป้ายชื่อ สี ไอคอน และค่าคงที่ทั้งหมดของ "กระดานไอเดีย"
 * ทำหน้าที่เดียวกับที่ WorkBoardDesign ทำให้บอร์ดงาน และ WorkLogDesign ทำให้
 * บันทึกงานประจำวัน
 *
 * Blade อ่านคลาสนี้ตรง ๆ ส่วน JavaScript อ่านผ่าน JSON island ที่ได้จาก
 * forClient() เพื่อไม่ให้ข้อความไทยถูกคัดลอกไปอยู่ในไฟล์ .js อีกชุดหนึ่ง
 */
final class WorkspaceDesign
{
    /**
     * ใครเห็นกระดานนี้ได้บ้าง
     *
     * ธงนี้มีผลกับ "การอ่าน" เท่านั้น ไม่เคยเพิ่มหรือลดสิทธิ์แก้ไข สิทธิ์แก้ไข
     * ตัดสินจากการเป็นสมาชิกแผนกเสมอ (ดู WorkspaceBoardPolicy::update())
     */
    public const VISIBILITIES = [
        'organization' => [
            'label' => 'ทั้งองค์กร',
            'hint' => 'แผนกอื่นเปิดดูได้ แต่แก้ไขไม่ได้',
            'tone' => 'blue',
            'icon' => 'bi-globe2',
        ],
        'department' => [
            'label' => 'เฉพาะแผนก',
            'hint' => 'เห็นเฉพาะคนในแผนกนี้และผู้ดูแลระบบ',
            'tone' => 'amber',
            'icon' => 'bi-lock',
        ],
    ];

    public const DEFAULT_VISIBILITY = 'organization';

    public const MAX_TITLE_LENGTH = 160;

    /**
     * เพดานเนื้อหาของหนึ่งกระดาน
     *
     * MAX_DOCUMENT_BYTES ตั้งไว้ 2 MB ไม่ใช่เพราะ MySQL รับไม่ไหว (คอลัมน์ json
     * รับได้ถึง 1 GB) แต่เพราะ max_allowed_packet ของ MySQL โดยปริยายอยู่ที่
     * 16-64 MB และ packet ที่ใหญ่เกินจะกลายเป็น connection error ซึ่งผู้ใช้
     * อ่านไม่รู้เรื่อง ไม่ใช่ข้อความ validation ภาษาไทยที่บอกได้ว่าต้องทำอะไรต่อ
     *
     * MAX_ELEMENTS เป็นเพดานด้านประสิทธิภาพของการเรนเดอร์ SVG ฝั่งเบราว์เซอร์
     */
    public const MAX_ELEMENTS = 1500;

    public const MAX_DOCUMENT_BYTES = 2097152;

    public const MAX_TEXT_LENGTH = 2000;

    /**
     * จำนวนจุดสูงสุดต่อหนึ่งเส้นดินสอ หลังผ่านการลดจุดแบบ RDP ฝั่งเบราว์เซอร์แล้ว
     * ค่านี้เป็นการ์ดฝั่งเซิร์ฟเวอร์ เผื่อกรณีที่มีคนยิง payload เข้ามาตรง ๆ
     */
    public const MAX_POINTS_PER_STROKE = 2000;

    /**
     * รูปภาพบนกระดาน - แคบกว่า AttachmentPolicy ของงานโครงการโดยตั้งใจ
     *
     * AttachmentPolicy อนุญาต docx/xlsx/zip และไฟล์ขนาดถึง 1 GB ซึ่งเหมาะกับ
     * เอกสารแนบของใบงาน แต่ไม่เหมาะกับรูปที่ต้องเรนเดอร์บนผืนผ้าใบ ห้ามไปลด
     * ค่าใน AttachmentPolicy เพราะใช้ร่วมกับงานโครงการ ให้กรองให้แคบลงที่นี่แทน
     */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public const IMAGE_MAX_KILOBYTES = 10240;

    public const MAX_IMAGES = 30;

    /**
     * เครื่องมือบนแถบเครื่องมือ เรียงตามลำดับที่แสดงผล
     *
     * ยางลบเป็นแบบ "ลบทั้งชิ้น" (object eraser) ไม่ใช่ลบทีละพิกเซล เพราะเนื้อหา
     * เก็บเป็นเวกเตอร์ การลบบางส่วนของเส้นต้องตัดเส้นออกเป็นหลายชิ้นซึ่งทำให้
     * ประวัติ undo และการบันทึกซับซ้อนขึ้นมากโดยได้ประโยชน์น้อย
     */
    public const TOOLS = [
        'hand' => ['label' => 'เลื่อนกระดาน', 'icon' => 'bi-arrows-move', 'shortcut' => 'H'],
        'select' => ['label' => 'เลือก', 'icon' => 'bi-cursor', 'shortcut' => 'V'],
        'pen' => ['label' => 'ดินสอ', 'icon' => 'bi-pencil', 'shortcut' => 'P'],
        'eraser' => ['label' => 'ยางลบ', 'icon' => 'bi-eraser', 'shortcut' => 'E'],
        'sticky' => ['label' => 'กระดาษโน้ต', 'icon' => 'bi-sticky', 'shortcut' => 'N'],
        'text' => ['label' => 'ข้อความ', 'icon' => 'bi-type', 'shortcut' => 'T'],
        'rect' => ['label' => 'สี่เหลี่ยม', 'icon' => 'bi-square', 'shortcut' => 'R'],
        'ellipse' => ['label' => 'วงกลม', 'icon' => 'bi-circle', 'shortcut' => 'O'],
        'line' => ['label' => 'เส้นตรง', 'icon' => 'bi-slash-lg', 'shortcut' => 'L'],
        'arrow' => ['label' => 'ลูกศร', 'icon' => 'bi-arrow-up-right', 'shortcut' => 'A'],
        'image' => ['label' => 'แนบรูปภาพ', 'icon' => 'bi-image', 'shortcut' => null],
    ];

    public const DEFAULT_TOOL = 'select';

    /**
     * เครื่องมือที่วางบนแถบตรง ๆ เรียงตามลำดับที่แสดง
     *
     * ทุกตัวเห็นไอคอนจริงของตัวเองและค้างสถานะให้เห็นว่ากำลังถืออะไรอยู่
     *
     * เคยยุบเครื่องมือพวกนี้ลงเมนู "วาด" กับ "สร้าง" มาก่อน แล้วพบว่าใช้งานยากกว่าเดิม
     * เพราะปุ่มเมนูแสดงไอคอนของกลุ่ม (ดินสอ กับ +) ไม่ใช่ของเครื่องมือที่ถืออยู่
     * ผู้ใช้จึงไม่รู้ว่าตอนนี้ถือยางลบอยู่หรือเปล่า และไม่รู้ว่าเครื่องมือที่ต้องการ
     * ซ่อนอยู่ในเมนูไหน การกดหนึ่งครั้งจึงกลายเป็นสองถึงสามครั้ง
     */
    public const PRIMARY_TOOLS = ['select', 'hand', 'pen', 'eraser', 'sticky', 'text'];

    /**
     * คำสั่งที่วางบนแถบข้างเครื่องมือ (ไม่ใช่เครื่องมือที่ค้างสถานะ)
     *
     * แนบรูปภาพเปิดหน้าต่างเลือกไฟล์แล้วจบ จึงไม่ควรค้างสถานะเหมือนดินสอ
     * แต่ยังต้องอยู่ข้างเครื่องมือสร้างอื่นเพราะผู้ใช้มองหามันที่นั่น
     *
     * คีย์คือชื่อคำสั่ง ค่าคือคีย์ใน TOOLS ที่ยืมป้ายกับไอคอนมาใช้
     */
    public const PRIMARY_COMMANDS = ['attach-image' => 'image'];

    /**
     * เครื่องมือที่แสดงไอคอนลอยตามเมาส์บนผืนผ้าใบ
     *
     * ดินสอกับยางลบเป็นสองตัวที่ระบบปฏิบัติการไม่มีเคอร์เซอร์ตรงความหมายให้
     * (ตัวชี้ มือ และตัวพิมพ์มีอยู่แล้ว) ผู้ใช้จึงแยกไม่ออกว่ากำลังถือตัวไหน
     * ถ้าปล่อยให้ทั้งคู่เป็นกากบาทเหมือนกัน
     *
     * เคยแก้ด้วยการวาดรูปฝังเป็นเคอร์เซอร์ แล้วออกมาเพี้ยนทุกครั้งบนจอจริง
     * (ดู resources/css/pages/workspace/stage.css) วิธีที่ใช้อยู่ตอนนี้คือแสดง
     * ไอคอนตัวเดียวกับที่อยู่บนแถบเครื่องมือเป็นชิปเล็ก ๆ ลอยข้างเมาส์ ซึ่งเรนเดอร์
     * ด้วยฟอนต์ไอคอนปกติ จึงออกมาเหมือนบนแถบเป๊ะ ไม่มีทางเพี้ยน
     *
     * ไอคอนของแต่ละตัวมาจาก TOOLS ผ่าน JSON island ไฟล์ .js จึงไม่ต้องรู้จัก
     * ชื่อไอคอนใด ๆ เอง
     */
    public const POINTER_TOOLS = ['pen', 'eraser'];

    /**
     * เมนูเครื่องมือบนแถบ — เหลือกลุ่มเดียวคือรูปทรง
     *
     * รูปทรงสี่ตัวใช้สลับกันไม่บ่อยเท่าดินสอหรือโน้ต และสี่ปุ่มเรียงกันกินที่บนแถบ
     * มากโดยที่ผู้ใช้เลือกได้ทีละตัวอยู่ดี จึงยุบเป็นปุ่มเดียว
     *
     * ปุ่มนี้ต้องแสดงไอคอนของรูปทรงที่เลือกอยู่ ไม่ใช่ไอคอนกลาง ๆ ของกลุ่ม
     * (toolbar.js สลับไอคอนให้จาก data-tool-icon) และไม่มีเมนูซ้อนเมนูอีกชั้น
     * เพราะการต้องเล็งเมาส์เข้าเมนูย่อยทำให้เมนูหลุดบ่อยจนกดไม่ติด
     *
     * ไอคอนปริยายคือ bi-intersect ซึ่งเป็นรูปสองรูปทรงซ้อนกันแบบเส้นขอบโปร่ง
     * สื่อว่าปุ่มนี้คือ "กลุ่มรูปทรง" ไม่ใช่รูปทรงใดรูปทรงหนึ่ง
     *
     * ต้องเป็นไอคอนเส้นขอบ ไม่ใช่ไอคอนทึบตัน เพราะเครื่องมือทุกตัวที่อยู่ข้าง ๆ
     * บนแถบเดียวกันเป็นเส้นขอบทั้งหมด (ดินสอ ยางลบ สี่เหลี่ยม วงกลม) ไอคอนทึบ
     * ตัวเดียวจะกลายเป็นก้อนดำที่เด่นผิดพวกจนดูเหมือนปุ่มที่ถูกเลือกค้างอยู่
     * เคยใช้รูปสองรูปทรงซ้อนกันรุ่นทึบตันมาก่อน แล้วพบปัญหานี้จากการใช้จริง
     * มีเทสต์สัญญากันไม่ให้ไอคอนทึบกลับเข้ามาอีก
     *
     * ก่อนหน้านั้นตั้งเป็นชื่อไอคอนที่ไม่มีอยู่จริงใน Bootstrap Icons ปุ่มจึง
     * ว่างเปล่าโดยไม่มี error ให้เห็น มีเทสต์สัญญาตรวจแล้วว่าทุกไอคอนในไฟล์นี้
     * มีอยู่จริงในชุดไอคอน
     */
    public const TOOL_MENUS = [
        'shapes' => [
            'label' => 'รูปทรง',
            'icon' => 'bi-intersect',
            'tools' => ['rect', 'ellipse', 'line', 'arrow'],
        ],
    ];

    /**
     * ป้ายของกลุ่มบนแถบรูปแบบ (แถวที่สองของแถบเครื่องมือ)
     *
     * คีย์ต้องตรงกับ CONTEXT_GROUPS ใน resources/js/pages/workspace/toolbar-context.js
     * ซึ่งเป็นผู้ตัดสินว่ากลุ่มไหนควรโผล่ในสถานะใด
     */
    public const CONTEXT_GROUPS = [
        'stroke' => 'สีเส้นและตัวอักษร',
        'sticky' => 'สีกระดาษโน้ต',
        'width' => 'ความหนาเส้น',
        'font' => 'ขนาดตัวอักษร',
        'textstyle' => 'รูปแบบตัวอักษร',
        'align' => 'การจัดบรรทัด',
    ];

    /**
     * เมนูคลิกขวาบนผืนผ้าใบ
     *
     * ทุกรายการชี้ไปที่คำสั่งเดียวกับที่แถบเครื่องมือและปุ่มลัดใช้ (runCommand
     * ใน index.js) ไม่มีรายการไหนมีตรรกะของตัวเอง มีเทสต์สัญญาตรวจว่าทุก
     * command ที่นี่มี case รองรับอยู่จริง
     *
     * needs  - เงื่อนไขที่ทำให้รายการกดได้: selection (ต้องเลือกชิ้นงานไว้),
     *          clipboard (ต้องมีของที่คัดลอกไว้), always (กดได้ตลอด)
     * edit   - true เมื่อรายการนั้นแก้เนื้อหากระดาน จึงต้องถูกปิดสำหรับผู้ที่ดู
     *          อย่างเดียว (การบังคับสิทธิ์จริงอยู่ฝั่งเซิร์ฟเวอร์)
     *
     * รายการแบนราบทั้งหมด ไม่มีเมนูย่อย คำสั่งจัดลำดับชั้นเคยอยู่ในเมนูย่อย
     * "จัดลำดับชั้น" แล้วพบว่ากดไม่ติดในการใช้งานจริง เพราะต้องเล็งเมาส์จากรายการแม่
     * เข้าไปในแผงย่อยโดยไม่ให้หลุดออกนอกทาง คำสั่งที่ใช้บ่อยไม่ควรอยู่ลึกขนาดนั้น
     */
    public const CANVAS_MENU = [
        ['command' => 'copy', 'label' => 'คัดลอก', 'icon' => 'bi-clipboard', 'shortcut' => 'Ctrl+C', 'needs' => 'selection', 'edit' => false],
        ['command' => 'duplicate', 'label' => 'ทำสำเนา', 'icon' => 'bi-copy', 'shortcut' => 'Ctrl+D', 'needs' => 'selection', 'edit' => true],
        ['command' => 'paste', 'label' => 'วาง', 'icon' => 'bi-clipboard-plus', 'shortcut' => 'Ctrl+V', 'needs' => 'clipboard', 'edit' => true],

        ['separator' => true],

        ['command' => 'bring-to-front', 'label' => 'ขึ้นบนสุด', 'icon' => 'bi-front', 'shortcut' => null, 'needs' => 'selection', 'edit' => true],
        ['command' => 'bring-forward', 'label' => 'ขึ้นหนึ่งชั้น', 'icon' => 'bi-arrow-up-short', 'shortcut' => null, 'needs' => 'selection', 'edit' => true],
        ['command' => 'send-backward', 'label' => 'ลงหนึ่งชั้น', 'icon' => 'bi-arrow-down-short', 'shortcut' => null, 'needs' => 'selection', 'edit' => true],
        ['command' => 'send-to-back', 'label' => 'ลงล่างสุด', 'icon' => 'bi-back', 'shortcut' => null, 'needs' => 'selection', 'edit' => true],

        ['separator' => true],

        ['command' => 'center', 'label' => 'เลื่อนไปหา', 'icon' => 'bi-bullseye', 'shortcut' => null, 'needs' => 'selection', 'edit' => false],
        ['command' => 'delete', 'label' => 'ลบ', 'icon' => 'bi-trash', 'shortcut' => 'Delete', 'needs' => 'selection', 'edit' => true],
    ];

    /**
     * ประเภทของชิ้นงานที่บันทึกลง document ได้ - เป็น allow-list ที่
     * WorkspaceDocumentValidator ใช้ปฏิเสธ payload แปลกปลอม
     *
     * ต่างจาก TOOLS ตรงที่ hand/select/eraser เป็นเครื่องมือที่ไม่สร้างชิ้นงาน
     */
    public const ELEMENT_TYPES = ['pen', 'rect', 'ellipse', 'line', 'arrow', 'sticky', 'text', 'image'];

    /**
     * ชนิดที่เก็บมุมหมุน (rotation เป็นองศา หมุนรอบจุดกึ่งกลางกรอบ)
     *
     * ดินสอ เส้นตรง และลูกศรไม่อยู่ในรายการโดยตั้งใจ ชิ้นกลุ่มนั้นหมุนที่ตัว
     * พิกัดของจุดไปเลย จึงไม่มีมุมให้เก็บ ต้องตรงกับ ROTATABLE_BOX_TYPES ใน
     * resources/js/pages/workspace/rotation.js (มีเทสต์สัญญาตรวจอยู่)
     */
    public const ROTATABLE_ELEMENT_TYPES = ['rect', 'ellipse', 'sticky', 'text', 'image'];

    /**
     * ชนิดที่ w และ h ติดลบได้ เพราะคือระยะจากจุดต้นถึงจุดปลาย ไม่ใช่ขนาดกรอบ
     * เส้นที่ลากไปทางซ้ายหรือขึ้นบนจึงมี w หรือ h ติดลบเป็นปกติ
     */
    public const DIRECTIONAL_ELEMENT_TYPES = ['line', 'arrow'];

    /**
     * สีปากกาและเส้นขอบ
     *
     * จัดเป็นสองแถว แถวบนเป็นสีเข้มสำหรับเส้นและตัวอักษร แถวล่างเป็นเฉดอ่อนกว่า
     * สำหรับไฮไลต์และเส้นประกอบ เรียงตามวงล้อสีเพื่อให้หาสีที่ต้องการได้เร็ว
     *
     * ชุดนี้เป็นเพียงทางลัด ผู้ใช้เลือกสีอื่นได้ไม่จำกัดผ่านช่องเลือกสีของ
     * เบราว์เซอร์ (ดู CUSTOM_COLOR_HINT) ค่าที่ได้เป็น #rrggbb ซึ่งผ่าน
     * WorkspaceDocumentValidator อยู่แล้ว
     */
    public const PALETTE = [
        // แถวเข้ม
        '#1f2937',
        '#64748b',
        '#dc2626',
        '#ea580c',
        '#d97706',
        '#ca8a04',
        '#65a30d',
        '#059669',
        '#0d9488',
        '#0891b2',
        '#2563eb',
        '#4f46e5',
        '#7c3aed',
        '#c026d3',
        '#db2777',
        '#e11d48',
        // แถวอ่อน
        '#94a3b8',
        '#cbd5e1',
        '#fca5a5',
        '#fdba74',
        '#fcd34d',
        '#fde047',
        '#bef264',
        '#6ee7b7',
        '#5eead4',
        '#67e8f9',
        '#93c5fd',
        '#a5b4fc',
        '#c4b5fd',
        '#f0abfc',
        '#f9a8d4',
        '#fda4af',
    ];

    /** จำนวนสีต่อแถวในแผงสีทั้งหมด ใช้จัดกริดให้ตรงกันทั้งสองแผง */
    public const PALETTE_COLUMNS = 8;

    /**
     * สีที่วางไว้บนแถบเครื่องมือตลอดเวลา ส่วนที่เหลือเก็บไว้ในแผงที่กดเปิด
     *
     * การวางครบ 32 สีบนแถบทำให้แถบกว้างจนตกบรรทัดและดูรก ทั้งที่คนส่วนใหญ่ใช้
     * แค่ดำ แดง ส้ม เขียว น้ำเงิน ทุกค่าต้องมีอยู่ใน PALETTE ด้วย (มีเทสต์ตรวจ)
     */
    public const QUICK_PALETTE = ['#1f2937', '#dc2626', '#ea580c', '#059669', '#2563eb'];

    /** สีโน้ตที่วางไว้บนแถบตลอดเวลา ทุกค่าต้องมีอยู่ใน STICKY_COLORS ด้วย */
    public const QUICK_STICKY_COLORS = ['#fde68a', '#fecaca', '#bfdbfe'];

    public const CUSTOM_COLOR_HINT = 'เลือกสีเอง';

    public const DEFAULT_STROKE = '#1f2937';

    public const STROKE_WIDTHS = [2, 4, 8, 16];

    public const DEFAULT_STROKE_WIDTH = 4;

    /**
     * สีกระดาษโน้ต
     *
     * ต้องเป็นเฉดอ่อนทั้งหมด เพราะข้อความบนโน้ตใช้สีเข้มคงที่ (#1f2937)
     * ถ้าใส่สีเข้มเข้ามา ตัวอักษรจะอ่านไม่ออก
     */
    public const STICKY_COLORS = [
        '#fde68a',
        '#fed7aa',
        '#fecaca',
        '#fbcfe8',
        '#e9d5ff',
        '#ddd6fe',
        '#c7d2fe',
        '#bfdbfe',
        '#a5f3fc',
        '#99f6e4',
        '#a7f3d0',
        '#d9f99d',
        '#fef08a',
        '#e2e8f0',
        '#f5f5f4',
        '#ffffff',
    ];

    public const DEFAULT_STICKY_COLOR = '#fde68a';

    /**
     * ขนาดตัวอักษรของกระดาษโน้ตและกล่องข้อความ
     *
     * เป็นชุดปิดตายแทนช่องกรอกตัวเลข เพราะบนกระดานที่ซูมเข้าออกได้ ตัวเลขดิบ
     * ไม่สื่ออะไรกับผู้ใช้ (18 พอยต์ที่ซูม 400% ตาเห็นเป็น 72) การให้เลือกจาก
     * ชุดที่เตรียมไว้จึงคาดเดาผลได้มากกว่า และทำให้ข้อความบนกระดานเดียวกัน
     * มีขนาดที่เข้าชุดกันเอง
     *
     * ค่าต้องอยู่ในช่วงที่ WorkspaceDocumentValidator ยอมรับ (8-96)
     */
    public const FONT_SIZES = [
        ['value' => 14, 'label' => 'เล็ก'],
        ['value' => 20, 'label' => 'กลาง'],
        ['value' => 28, 'label' => 'ใหญ่'],
        ['value' => 40, 'label' => 'ใหญ่มาก'],
        ['value' => 64, 'label' => 'หัวเรื่อง'],
    ];

    public const DEFAULT_FONT_SIZE = 20;

    /**
     * ช่วงขนาดตัวอักษรที่กรอกเองได้
     *
     * ต้องตรงกับช่วงที่ WorkspaceDocumentValidator ยอมรับ ถ้าที่นี่กว้างกว่า
     * ผู้ใช้จะกรอกค่าที่หน้าจอรับแต่เซิร์ฟเวอร์ปัดทิ้งเงียบ ๆ แล้วขนาดจะเด้ง
     * กลับหลังรีเฟรชโดยไม่มีอะไรอธิบาย
     */
    public const MIN_FONT_SIZE = 8;

    public const MAX_FONT_SIZE = 96;

    /**
     * ระยะห่างตัวอักษรของกระดาษโน้ตและกล่องข้อความ
     *
     * เป็นช่องกรอกเสรีเหมือนขนาดตัวอักษร (ไม่ใช่ชุดปิดตาย) เพราะช่วงค่าที่มี
     * ประโยชน์นั้นแคบพออยู่แล้ว (-2 ถึง 8px) ผู้ใช้เห็นผลทันทีตอนพิมพ์เหมือนช่อง
     * ขนาดตัวอักษร ไม่ต้องมีชุดสำเร็จรูปมาเดา
     *
     * ค่า 0 (ปกติ) ไม่ถูกเก็บเลย เอกสารที่ไม่มีอะไรตั้งระยะจึงมีหน้าตาเหมือนก่อน
     * มีฟีเจอร์นี้ เหมือนกับที่ rotation ทำกับมุม 0 (ดู
     * WorkspaceDocumentValidator::letterSpacing())
     *
     * ตัวอักษรไทยมีสระและวรรณยุกต์ลอยเหนือ/ใต้พยัญชนะ (่ ้ ั ิ ึ ำ) แต่
     * letter-spacing ของ CSS ตาม spec (CSS Text Module Level 3) นับฐานตัวอักษร
     * รวมกับเครื่องหมายกำกับของมันเป็นหนึ่งหน่วยเสมอ ระยะที่เพิ่มจึงแทรกหลัง
     * พยางค์ทั้งชุด ไม่ใช่แทรกเข้าไปดันสระ/วรรณยุกต์ให้หลุดจากพยัญชนะฐาน
     * เบราว์เซอร์สมัยใหม่ทุกตัวที่ใช้งานจริงเคารพกฎนี้
     */
    public const MIN_LETTER_SPACING = -2;

    public const MAX_LETTER_SPACING = 8;

    public const DEFAULT_LETTER_SPACING = 0;

    /**
     * การจัดบรรทัดของแต่ละบรรทัดในกระดาษโน้ตและกล่องข้อความ
     *
     * เป็นชุดปิดตายสามค่าเหมือนปุ่มจัดบรรทัดทั่วไป ไม่ใช่ช่องกรอกเสรี ทั้ง
     * Blade และ WorkspaceDocumentValidator::lineAligns() ต้องอ่านชุดเดียวกันนี้
     * ไม่งั้นปุ่มที่ผู้ใช้กดได้กับค่าที่เซิร์ฟเวอร์ยอมรับจะไม่ตรงกัน
     *
     * จัดเป็นรายบรรทัด ไม่ใช่ค่าเดียวของทั้งกล่อง เพราะผู้ใช้ต้องจัดแต่ละบรรทัด
     * ในกล่องเดียวกันต่างกันได้ (บรรทัดแรกกึ่งกลาง บรรทัดถัดมาชิดขวา) เหมือน
     * โปรแกรมประมวลผลคำทั่วไป ดู WorkspaceDocumentValidator::lineAligns()
     */
    public const TEXT_ALIGNS = ['left', 'center', 'right'];

    /**
     * ค่าปริยายของแต่ละบรรทัดไม่ถูกเก็บลงเอกสารเลย ด้วยเหตุผลเดียวกับ
     * DEFAULT_LETTER_SPACING เอกสารเก่าก่อนมีฟีเจอร์นี้จึงยังอ่านได้เป็น
     * "ชิดซ้ายทุกบรรทัด" โดยไม่ต้องย้ายข้อมูล
     */
    public const DEFAULT_TEXT_ALIGN = 'left';

    /**
     * สถานะการบันทึกที่แสดงบนหัวกระดาน
     *
     * ข้อความไทยอยู่ที่นี่ที่เดียว ฝั่ง JavaScript อ่านผ่าน JSON island
     * ไม่เขียนซ้ำในไฟล์ .js ตามแนวทางเดียวกับ WorkLogDesign
     */
    /*
     * dirty กับ saving แสดงหน้าตาเดียวกันโดยตั้งใจ dirty คือช่วงหน่วงไม่ถึงสองวินาที
     * ก่อนบันทึกจริง ถ้าแยกป้ายและสี ป้ายจะกระพริบสามสีทุกครั้งที่ขีดเส้น
     * (เทา "ยังไม่ได้บันทึก" -> ฟ้า -> เขียว) ซึ่งผู้ใช้อ่านว่าระบบบันทึกชนกันมั่ว
     */
    public const SAVE_STATES = [
        'saved' => ['label' => 'บันทึกแล้ว', 'icon' => 'bi-cloud-check', 'tone' => 'teal'],
        'dirty' => ['label' => 'กำลังบันทึก...', 'icon' => 'bi-arrow-repeat', 'tone' => 'gray'],
        'saving' => ['label' => 'กำลังบันทึก...', 'icon' => 'bi-arrow-repeat', 'tone' => 'gray'],
        'error' => ['label' => 'บันทึกไม่สำเร็จ จะลองใหม่อัตโนมัติ', 'icon' => 'bi-exclamation-triangle', 'tone' => 'amber'],
        'conflict' => ['label' => 'มีฉบับใหม่กว่า กดรีเฟรชเพื่อโหลด', 'icon' => 'bi-exclamation-octagon', 'tone' => 'amber'],
    ];

    /**
     * ข้อความในกล่องเตือนเมื่อสองคนบันทึกชนกัน
     *
     * เป็นจุดที่ผู้ใช้ต้องตัดสินใจว่าจะทิ้งงานที่เพิ่งวาดหรือไม่ ถ้อยคำจึงต้อง
     * บอกผลลัพธ์ให้ชัด ไม่ใช่แค่แจ้งว่าเกิดอะไรขึ้น
     */
    public const CONFLICT_DIALOG = [
        'title' => 'มีคนอื่นบันทึกกระดานนี้แล้ว',
        'confirm' => 'โหลดฉบับล่าสุด',
        'cancel' => 'ยังไม่โหลด',
        'warning' => 'การโหลดฉบับล่าสุดจะทิ้งสิ่งที่คุณวาดหลังจากนั้น',
        'suspended' => 'หยุดบันทึกอัตโนมัติไว้แล้ว กดรีเฟรชเมื่อพร้อมโหลดฉบับล่าสุด',
        'refreshDirty' => 'กระดานนี้มีสิ่งที่ยังไม่ได้บันทึก การโหลดใหม่จะทิ้งไป',
    ];

    /**
     * ขอบเขตพิกัดของผืนผ้าใบ ใช้ clamp ค่าที่ส่งเข้ามาไม่ให้มีชิ้นงานหลุดไป
     * อยู่ไกลจนผู้ใช้หาไม่เจอ และกดปุ่ม "จัดให้พอดีจอ" แล้วซูมออกจนมองไม่เห็นอะไร
     */
    public const WORLD_BOUND = 100000;

    public const MIN_SCALE = 0.1;

    public const MAX_SCALE = 4.0;

    public static function visibilityKeys(): array
    {
        return array_keys(self::VISIBILITIES);
    }

    public static function visibility(?string $key): array
    {
        return self::VISIBILITIES[$key] ?? [
            'label' => 'ไม่ระบุ',
            'hint' => '',
            'tone' => 'gray',
            'icon' => 'bi-question-circle',
        ];
    }

    /**
     * เนื้อหาเริ่มต้นของกระดานที่เพิ่งสร้าง
     *
     * อยู่ที่นี่เพราะเป็น "รูปร่างของข้อมูล" ไม่ใช่ตรรกะการบันทึก และเพราะ
     * migration ตั้ง DEFAULT ให้คอลัมน์ json บน MySQL ไม่ได้
     */
    public static function emptyDocument(): array
    {
        return ['schema' => 1, 'elements' => []];
    }

    /**
     * ข้อมูลชุดเดียวกันในรูปแบบที่ JavaScript ใช้ได้ ส่งผ่าน JSON island
     * ไม่ใช่ให้ฝั่ง client เขียนป้ายชื่อของตัวเองซ้ำ
     */
    public static function forClient(): array
    {
        return [
            'tools' => self::TOOLS,
            'pointerTools' => self::POINTER_TOOLS,
            'saveStates' => self::SAVE_STATES,
            'conflictDialog' => self::CONFLICT_DIALOG,
            'defaultTool' => self::DEFAULT_TOOL,
            'elementTypes' => self::ELEMENT_TYPES,
            'visibilities' => self::VISIBILITIES,
            'palette' => self::PALETTE,
            'defaultStroke' => self::DEFAULT_STROKE,
            'strokeWidths' => self::STROKE_WIDTHS,
            'defaultStrokeWidth' => self::DEFAULT_STROKE_WIDTH,
            'stickyColors' => self::STICKY_COLORS,
            'fontSizes' => self::FONT_SIZES,
            'defaultFontSize' => self::DEFAULT_FONT_SIZE,
            'minFontSize' => self::MIN_FONT_SIZE,
            'maxFontSize' => self::MAX_FONT_SIZE,
            'minLetterSpacing' => self::MIN_LETTER_SPACING,
            'maxLetterSpacing' => self::MAX_LETTER_SPACING,
            'defaultLetterSpacing' => self::DEFAULT_LETTER_SPACING,
            'paletteColumns' => self::PALETTE_COLUMNS,
            'defaultStickyColor' => self::DEFAULT_STICKY_COLOR,
            'maxElements' => self::MAX_ELEMENTS,
            'maxTextLength' => self::MAX_TEXT_LENGTH,
            'maxImages' => self::MAX_IMAGES,
            'imageMaxKilobytes' => self::IMAGE_MAX_KILOBYTES,
            'imageExtensions' => self::IMAGE_EXTENSIONS,
            'worldBound' => self::WORLD_BOUND,
            'minScale' => self::MIN_SCALE,
            'maxScale' => self::MAX_SCALE,
        ];
    }
}

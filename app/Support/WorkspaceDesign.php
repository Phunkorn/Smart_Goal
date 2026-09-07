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
     * ประเภทของชิ้นงานที่บันทึกลง document ได้ - เป็น allow-list ที่
     * WorkspaceDocumentValidator ใช้ปฏิเสธ payload แปลกปลอม
     *
     * ต่างจาก TOOLS ตรงที่ hand/select/eraser เป็นเครื่องมือที่ไม่สร้างชิ้นงาน
     */
    public const ELEMENT_TYPES = ['pen', 'rect', 'ellipse', 'line', 'arrow', 'sticky', 'text', 'image'];

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

    /** จำนวนสีต่อแถวบนแถบเครื่องมือ ใช้จัดกริดให้ตรงกันทั้งสองแถว */
    public const PALETTE_COLUMNS = 8;

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
     * สถานะการบันทึกที่แสดงบนหัวกระดาน
     *
     * ข้อความไทยอยู่ที่นี่ที่เดียว ฝั่ง JavaScript อ่านผ่าน JSON island
     * ไม่เขียนซ้ำในไฟล์ .js ตามแนวทางเดียวกับ WorkLogDesign
     */
    public const SAVE_STATES = [
        'saved' => ['label' => 'บันทึกแล้ว', 'icon' => 'bi-cloud-check', 'tone' => 'teal'],
        'dirty' => ['label' => 'ยังไม่ได้บันทึก', 'icon' => 'bi-cloud', 'tone' => 'gray'],
        'saving' => ['label' => 'กำลังบันทึก...', 'icon' => 'bi-arrow-repeat', 'tone' => 'blue'],
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

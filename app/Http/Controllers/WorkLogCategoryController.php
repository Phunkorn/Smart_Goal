<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkLogCategory;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * จัดการหมวดงานของบันทึกงานประจำวัน (admin เท่านั้น)
 *
 * หมวดงานเป็นตาราง lookup ไม่ใช่ค่าคงที่ในโค้ด เพราะแต่ละองค์กรมีหมวดต่างกัน
 * และจะเพิ่มเรื่อย ๆ ถ้าเป็น enum จะต้องเขียน migration แล้ว deploy ใหม่ทุกครั้ง
 * ที่อยากเพิ่มหมวดหนึ่งหมวด ซึ่งไม่คุ้มบน shared hosting
 *
 * สิทธิ์ถูกบังคับด้วย middleware('admin') ที่ระดับ route
 */
class WorkLogCategoryController extends Controller
{
    use RespondsWithTaskResult;

    /** โทนสีที่ใช้ได้ ต้องอยู่ในชุดเดียวกับที่ CSS ของระบบรองรับ */
    private const TONES = ['blue', 'green', 'purple', 'amber', 'teal', 'cyan', 'gray'];

    public function index()
    {
        return view('admin.work-log-categories.index', [
            'categories' => WorkLogCategory::query()
                ->withCount('logs')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'tones' => self::TONES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $category = WorkLogCategory::create([
            'name' => $data['name'],
            'tone' => $data['tone'] ?? 'gray',
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        AuditTrail::log(
            'work_log_category_created',
            $category,
            sprintf('เพิ่มหมวดงาน "%s"', $category->name),
            null
        );

        return $this->jsonOrBack($request, true, 'เพิ่มหมวดงานเรียบร้อย');
    }

    public function update(Request $request, WorkLogCategory $category)
    {
        $data = $request->validate($this->rules($category));

        $category->update([
            'name' => $data['name'],
            'tone' => $data['tone'] ?? $category->tone,
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditTrail::log(
            'work_log_category_updated',
            $category,
            sprintf('แก้ไขหมวดงาน "%s"', $category->name),
            null
        );

        return $this->jsonOrBack($request, true, 'แก้ไขหมวดงานเรียบร้อย');
    }

    /**
     * ลบหมวดงาน
     *
     * ปฏิเสธเมื่อยังมีบันทึกงานอ้างอิงอยู่ แม้ FK จะตั้งเป็น nullOnDelete ก็ตาม
     * เพราะการลบจะทำให้บันทึกย้อนหลังกลายเป็น "ไม่ระบุหมวด" ทั้งหมดเงียบ ๆ
     * และรายงานที่เคยดูย้อนหลังไว้จะเปลี่ยนไปโดยไม่มีร่องรอย
     * ทางที่ถูกคือปิดใช้งาน (is_active = false) ซึ่งกันไม่ให้เลือกใหม่แต่เก็บประวัติไว้
     */
    public function destroy(Request $request, WorkLogCategory $category)
    {
        $inUse = $category->logs()->count();

        if ($inUse > 0) {
            return $this->jsonOrBack(
                $request,
                false,
                sprintf(
                    'ลบไม่ได้ เพราะมีบันทึกงานใช้หมวดนี้อยู่ %d รายการ — ให้ปิดใช้งานแทนเพื่อเก็บประวัติไว้',
                    $inUse
                ),
                422
            );
        }

        $name = $category->name;
        $before = $category->attributesToArray();

        // หมวดงานไม่มี SoftDeletes แถวหายจากฐานข้อมูลจริง สำเนาใน payload_json
        // จึงเป็นสิ่งเดียวที่ใช้กู้คืนได้
        AuditTrail::trash($category, $request->user(), ['category' => $before]);

        $category->delete();

        AuditTrail::log('work_log_category_deleted', null, sprintf('ลบหมวดงาน "%s"', $name), [
            'before' => $before,
        ]);

        return $this->jsonOrBack($request, true, 'ลบหมวดงานเรียบร้อย');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?WorkLogCategory $category = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('work_log_categories', 'name')->ignore($category?->id),
            ],
            'tone' => ['nullable', 'string', Rule::in(self::TONES)],
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^bi-[a-z0-9-]+$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}

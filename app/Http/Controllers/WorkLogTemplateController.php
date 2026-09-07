<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Services\WorkLogQueryService;
use App\Services\WorkLogRoutineMaterializer;
use App\Support\AuditTrail;
use App\Support\WorkLogDesign;
use App\Support\WorkLogWeekdays;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * แม่แบบงานประจำ — สิ่งที่ผู้ใช้ตั้งไว้ครั้งเดียวแล้วระบบสร้างรายการให้ทุกวัน
 *
 * แม่แบบเป็นการตั้งค่าส่วนตัว ไม่ใช่ข้อมูลผลงาน หัวหน้าและ admin จึงไม่เห็น
 * (ต่างจากตัวบันทึกงานที่เห็นได้) ดูเหตุผลใน WorkLogTemplatePolicy
 */
class WorkLogTemplateController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly WorkLogRoutineMaterializer $routines,
        private readonly WorkLogQueryService $query,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', WorkLogTemplate::class);

        $owner = Auth::user();

        return view('daily-logs.routines', [
            'templates' => WorkLogTemplate::query()
                ->with('category')
                ->where('user_id', $owner->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'categories' => WorkLogCategory::query()->selectable()->get(),
            'design' => WorkLogDesign::forClient(),
            'weekdays' => WorkLogWeekdays::WEEKDAYS,
            'defaultMask' => WorkLogWeekdays::WORKWEEK,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', WorkLogTemplate::class);

        $owner = Auth::user();
        $data = $request->validate($this->rules());

        $template = WorkLogTemplate::create([
            'user_id' => $owner->id,
            'work_log_category_id' => $data['work_log_category_id'] ?? null,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'weekday_mask' => WorkLogWeekdays::mask($data['weekdays'] ?? []),
            'default_start_time' => $data['default_start_time'] ?? null,
            'default_duration_minutes' => $data['default_duration_minutes'] ?? null,
            'is_active' => true,
        ]);

        AuditTrail::log(
            'work_log_template_created',
            $template,
            sprintf('สร้างแม่แบบงานประจำ "%s" (%s)', $template->title, WorkLogWeekdays::label((int) $template->weekday_mask)),
            null
        );

        // สร้างรายการของวันนี้ทันที ผู้ใช้จึงเห็นผลตั้งแต่ครั้งแรกที่กลับไปหน้าไทม์ไลน์
        // แทนที่จะต้องรอถึงพรุ่งนี้ ซึ่งทำให้รู้สึกเหมือนระบบไม่ทำงาน
        $created = $this->routines->materializeToday($owner);

        return $this->jsonOrBack(
            $request,
            true,
            $created > 0
                ? 'สร้างแม่แบบและเพิ่มรายการของวันนี้แล้ว'
                : 'สร้างแม่แบบเรียบร้อย',
            200,
            ['template_id' => $template->id, 'created_today' => $created]
        );
    }

    public function update(Request $request, WorkLogTemplate $template)
    {
        Gate::authorize('update', $template);

        $data = $request->validate($this->rules());

        $template->update([
            'work_log_category_id' => $data['work_log_category_id'] ?? null,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'weekday_mask' => WorkLogWeekdays::mask($data['weekdays'] ?? []),
            'default_start_time' => $data['default_start_time'] ?? null,
            'default_duration_minutes' => $data['default_duration_minutes'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditTrail::log(
            'work_log_template_updated',
            $template,
            sprintf('แก้ไขแม่แบบงานประจำ "%s"', $template->title),
            null
        );

        return $this->jsonOrBack($request, true, 'แก้ไขแม่แบบเรียบร้อย');
    }

    /**
     * ลบแม่แบบ — รายการที่สร้างไปแล้วยังอยู่
     *
     * รายการที่ผ่านมาเป็นประวัติการทำงานจริงของเจ้าของ การลบแม่แบบเป็นการบอกว่า
     * "ไม่ต้องสร้างให้อีกต่อไป" ไม่ใช่ "งานที่ผ่านมาไม่เคยเกิดขึ้น"
     */
    public function destroy(Request $request, WorkLogTemplate $template)
    {
        Gate::authorize('delete', $template);

        $title = $template->title;
        $before = $template->attributesToArray();

        // แม่แบบไม่มี SoftDeletes แถวหายจากฐานข้อมูลจริง สำเนาใน payload_json
        // จึงเป็นสิ่งเดียวที่ใช้กู้คืนได้
        AuditTrail::trash($template, $request->user(), ['template' => $before]);

        $template->delete();

        AuditTrail::log(
            'work_log_template_deleted',
            null,
            sprintf('ลบแม่แบบงานประจำ "%s" (รายการที่บันทึกไว้แล้วยังอยู่)', $title),
            ['before' => $before]
        );

        return $this->jsonOrBack($request, true, 'ลบแม่แบบเรียบร้อย');
    }

    /**
     * สร้างรายการงานประจำของวันย้อนหลังตามคำสั่งของเจ้าของ
     *
     * ระบบไม่สร้างย้อนหลังให้เองโดยอัตโนมัติ เพราะการเติมรายการค้างให้คนที่เพิ่ง
     * กลับจากลา จะกลายเป็นสัญญาณ "ไม่ได้ทำงาน" ปลอม ๆ ในรายงาน
     */
    public function materialize(Request $request)
    {
        Gate::authorize('create', WorkLogTemplate::class);

        $owner = Auth::user();
        $businessDay = $this->query->resolveBusinessDay($request->input('date'));

        $created = $this->routines->materializeDay($owner, $businessDay);

        return $this->jsonOrBack(
            $request,
            true,
            $created > 0
                ? sprintf('เพิ่มงานประจำของวันนั้น %d รายการ', $created)
                : 'วันนั้นมีงานประจำครบแล้ว',
            200,
            ['created' => $created]
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'string', 'in:'.implode(',', WorkLogDesign::kindKeys())],
            'work_log_category_id' => ['nullable', 'integer', 'exists:work_log_categories,id'],
            'details' => ['nullable', 'string', 'max:2000'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'min:0', 'max:6'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            'default_duration_minutes' => [
                'nullable',
                'integer',
                'min:'.WorkLogDesign::MIN_DURATION_MINUTES,
                'max:'.WorkLogDesign::MAX_DURATION_MINUTES,
            ],
        ];
    }
}

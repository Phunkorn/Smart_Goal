<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\User;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\WorkLogParticipantService;
use App\Services\WorkLogRoutineMaterializer;
use App\Support\AuditTrail;
use App\Support\WorkLogDesign;
use App\Support\WorkLogWeekdays;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
        private readonly WorkLogParticipantService $participants,
    ) {}

    /**
     * เส้นทางเดิมของหน้าแม่แบบแยก — ตอนนี้จัดการใน modal ของหน้าบันทึกงานแล้ว
     *
     * เก็บ route ไว้เพื่อไม่ให้ลิงก์ที่ถูกบุ๊กมาร์กหรือส่งต่อกันไว้กลายเป็น 404
     * แต่ไม่มีหน้าเป็นของตัวเองอีกต่อไป เพราะการมีฟอร์มงานประจำสองชุดจะเพี้ยน
     * ออกจากกันทันทีที่ดีไซน์เปลี่ยน (กติกา "หนึ่งพฤติกรรม หนึ่งแหล่งความจริง")
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', WorkLogTemplate::class);

        return redirect()->route('daily-logs.index');
    }

    public function store(Request $request)
    {
        Gate::authorize('create', WorkLogTemplate::class);

        $owner = Auth::user();
        $data = $request->validate($this->rules());
        $this->assertOwnedLinks($owner, $data);

        /*
         * เลือกหลายวันจากปฏิทินได้ในการกรอกครั้งเดียว
         *
         * แม่แบบแบบวันเดียวเก็บ starts_on = ends_on อยู่แล้ว จึงสร้างหนึ่งแม่แบบต่อหนึ่งวัน
         * ไม่ต้องเพิ่มโครงข้อมูลใหม่ และแต่ละวันยังแก้หรือลบแยกกันได้ในรายการแผนงาน
         * ถ้าไม่ได้ส่ง plan_dates มา (ฟอร์มเก่า หรือเลือกเป็นวันในสัปดาห์) ใช้ plan_date เดิม
         */
        $planDates = collect($data['plan_dates'] ?? [])->filter()->unique()->sort()->values();
        if ($planDates->isEmpty()) {
            $planDates->push($data['plan_date'] ?? null);
        }

        $durationMinutes = $this->durationFrom($data);
        $templates = DB::transaction(fn (): Collection => $planDates->map(
            function (?string $planDate) use ($owner, $data, $durationMinutes): WorkLogTemplate {
                $template = WorkLogTemplate::create([
                    'user_id' => $owner->id,
                    'work_log_category_id' => $data['work_log_category_id'] ?? null,
                    'work_order_list_id' => $data['work_order_list_id'] ?? null,
                    'job_id' => $data['job_id'] ?? null,
                    'kind' => $data['kind'] ?? WorkLogDesign::DEFAULT_KIND,
                    'title' => $data['title'],
                    'details' => $data['details'] ?? null,
                    'weekday_mask' => $this->maskFrom($data, $planDate),
                    'starts_on' => $planDate,
                    'ends_on' => $planDate,
                    'default_start_time' => $data['default_start_time'] ?? null,
                    'default_duration_minutes' => $durationMinutes,
                    'is_active' => true,
                ]);

                $this->participants->syncTemplate($template, $owner, $data['participants'] ?? []);

                AuditTrail::log(
                    'work_log_template_created',
                    $template,
                    sprintf('สร้างแม่แบบงานประจำ "%s" (%s)', $template->title, WorkLogWeekdays::label((int) $template->weekday_mask)),
                    null
                );

                return $template;
            }
        ));

        /** @var WorkLogTemplate $template */
        $template = $templates->last();

        // สร้างรายการของวันนี้ทันที ผู้ใช้จึงเห็นผลตั้งแต่ครั้งแรกที่กลับไปหน้าไทม์ไลน์
        // แทนที่จะต้องรอถึงพรุ่งนี้ ซึ่งทำให้รู้สึกเหมือนระบบไม่ทำงาน
        //
        // ทำให้ผู้ร่วมงานด้วย เพื่อให้คนที่ถูกเลือกไว้เห็นรายการในวันนี้ทันที
        // ไม่ต้องรอให้ตัวเองเปิดหน้าในวันถัดไปถึงจะมีอะไรโผล่ขึ้นมา
        $created = $this->materializeForEveryone($template, $owner);

        return $this->jsonOrBack(
            $request,
            true,
            $created > 0
                ? 'สร้างแม่แบบและเพิ่มรายการของวันนี้แล้ว'
                : 'สร้างแม่แบบเรียบร้อย',
            200,
            ['template_id' => $template->id, 'created_today' => $created, 'created_count' => $templates->count()]
        );
    }

    public function update(Request $request, WorkLogTemplate $template)
    {
        Gate::authorize('update', $template);

        $owner = Auth::user();
        $data = $request->validate($this->rules());
        $this->assertOwnedLinks($owner, $data);

        $template->update([
            'work_log_category_id' => $data['work_log_category_id'] ?? null,
            'work_order_list_id' => $data['work_order_list_id'] ?? null,
            'job_id' => $data['job_id'] ?? null,
            'kind' => $data['kind'] ?? WorkLogDesign::DEFAULT_KIND,
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'weekday_mask' => $this->maskFrom($data, $data['plan_date'] ?? null),
            ...(isset($data['plan_date']) ? ['starts_on' => $data['plan_date'], 'ends_on' => $data['plan_date']] : []),
            'default_start_time' => $data['default_start_time'] ?? null,
            'default_duration_minutes' => $this->durationFrom($data),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $template->is_active,
        ]);

        $this->participants->syncTemplate($template, $owner, $data['participants'] ?? []);
        $this->materializeForEveryone($template, $owner);

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
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            // ฟอร์มในหน้าไม่ถามประเภทงานอีกแล้ว — แม่แบบในเมนู "งานประจำ" เป็น
            // ประเภทงานประจำอยู่แล้วโดยนิยาม ยังรับค่าที่ส่งมาได้เผื่อทางเข้าอื่น
            'kind' => ['nullable', 'string', 'in:'.implode(',', WorkLogDesign::kindKeys())],
            'work_log_category_id' => ['required_with:plan_date,plan_dates', 'nullable', 'integer', 'exists:work_log_categories,id'],
            'work_order_list_id' => ['nullable', 'integer', 'exists:work_order_lists,id'],
            'job_id' => ['nullable', 'integer', 'exists:work_orders,job_id'],
            'details' => ['nullable', 'string', 'max:2000'],
            'plan_date' => ['nullable', 'date_format:Y-m-d', 'required_without_all:weekdays,plan_dates'],
            // หลายวันจากปฏิทิน — จำกัด 31 วันเท่ากับบันทึกงานนอกสถานที่
            'plan_dates' => ['nullable', 'array', 'max:31'],
            'plan_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'weekdays' => ['required_without_all:plan_date,plan_dates', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'min:0', 'max:6'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            // ผู้ใช้คิดเป็น "ช่วงเวลาที่ต้องเข้าไปทำ" เช่น 08:30 ถึง 08:50 ไม่ใช่
            // จำนวนนาที ฟอร์มจึงถามเวลาสองค่า แล้วให้เซิร์ฟเวอร์คำนวณนาทีเอง
            'default_end_time' => ['nullable', 'date_format:H:i', 'required_with:default_start_time'],
            'participants' => ['nullable', 'array', 'max:20'],
            'participants.*' => ['integer', 'exists:users,id'],
        ];
    }

    private function maskFrom(array $data, ?string $planDate): int
    {
        if (! empty($planDate)) {
            $weekday = Carbon::createFromFormat('Y-m-d', $planDate)->dayOfWeekIso - 1;

            return WorkLogWeekdays::mask([$weekday]);
        }

        return WorkLogWeekdays::mask($data['weekdays'] ?? []);
    }

    /**
     * จำนวนนาทีของงานประจำ คำนวณจากช่วงเวลาที่ตั้งไว้
     *
     * เก็บเป็นนาทีคอลัมน์เดียวเหมือนเดิม เพื่อไม่ให้เวลาสิ้นสุดกลายเป็นแหล่ง
     * ความจริงที่สองที่เพี้ยนออกจากกันได้ ป้ายช่วงเวลาที่แสดงผลถูกประกอบกลับใน
     * WorkLogTemplate::plannedWindowLabel()
     *
     * @param  array<string, mixed>  $data
     */
    private function durationFrom(array $data): ?int
    {
        $start = $data['default_start_time'] ?? null;
        $end = $data['default_end_time'] ?? null;

        if ($start === null || $end === null) {
            return null;
        }

        $minutes = (int) Carbon::createFromFormat('H:i', $start)
            ->diffInMinutes(Carbon::createFromFormat('H:i', $end), false);

        if ($minutes < WorkLogDesign::MIN_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'default_end_time' => 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม',
            ]);
        }

        return min($minutes, WorkLogDesign::MAX_DURATION_MINUTES);
    }

    private function assertOwnedLinks(User $owner, array $data): void
    {
        $projectId = $data['work_order_list_id'] ?? null;
        $taskId = $data['job_id'] ?? null;

        if ($projectId !== null && ! WorkOrderList::query()->whereKey($projectId)->where('user_id', $owner->id)->exists()) {
            throw ValidationException::withMessages(['work_order_list_id' => 'เลือกโปรเจกต์ที่คุณเข้าถึงไม่ได้']);
        }

        if ($taskId !== null) {
            $task = WorkOrder::query()->whereKey($taskId)->where('user_id', $owner->id)->first();
            if (! $task || ($projectId !== null && (int) $task->work_order_list_id !== (int) $projectId)) {
                throw ValidationException::withMessages(['job_id' => 'รายการงานไม่อยู่ในโปรเจกต์ที่เลือก']);
            }
        }
    }

    /**
     * สร้างรายการของวันนี้ให้ทั้งเจ้าของแม่แบบและผู้ร่วมงานทุกคน
     *
     * @return int จำนวนรายการที่ถูกสร้างให้เจ้าของ (ใช้ประกอบข้อความตอบกลับ)
     */
    private function materializeForEveryone(WorkLogTemplate $template, User $owner): int
    {
        $created = $this->routines->materializeToday($owner);

        $template->load('participants');

        foreach ($template->participants as $person) {
            $this->routines->materializeToday($person);
        }

        return $created;
    }
}

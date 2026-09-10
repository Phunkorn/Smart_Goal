<?php

namespace App\Support;

use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * ยุบงานย่อยเข้าไปอยู่ใต้งานแม่ก่อนนำไปแสดงในรายงาน
 *
 * งานย่อยถูกเก็บเป็น work_orders อีกใบที่ผูกด้วย parent_job_id ถ้ารายงานไล่แถวตรง ๆ
 * งานย่อยจะโผล่เป็นงานเดี่ยวเคียงข้างงานแม่ ทำให้ทั้งจำนวนแถวและตัวเลขในการ์ดพองเกินจริง
 * (งานแม่ 1 ใบที่มีงานย่อย 2 ใบถูกนับเป็น 3 งาน) ซึ่งเป็นกับดักเดียวกับที่ WorkOrder::scopeTopLevel()
 * เตือนไว้ แต่ชั้นรายงานไม่เคยใช้ scope นั้น
 *
 * ไม่ใช้ scopeTopLevel() ตรง ๆ เพราะจะทำให้ผลงานหายไปทั้งใบเมื่อผู้ใช้ถูกเชิญมาร่วม
 * เฉพาะงานย่อย แต่ไม่ได้เกี่ยวข้องกับงานแม่ งานย่อยแบบนั้นจึงถูกเลื่อนขึ้นมาเป็นแถวของตัวเอง
 * เพื่อไม่ให้ผลงานของใครหายไปจากรายงาน
 */
final class ReportTaskTree
{
    /**
     * @param  Collection<int, WorkOrder>  $jobs  งานที่ผู้ใช้มีส่วนร่วม ทั้งงานแม่และงานย่อย
     * @return Collection<int, WorkOrder> แถวที่จะนำไปแสดงและนับ
     */
    public static function collapse(Collection $jobs): Collection
    {
        $presentIds = $jobs->pluck('job_id')->map(fn ($id) => (int) $id)->flip();

        return $jobs
            ->reject(fn (WorkOrder $job) => $job->parent_job_id !== null
                && $presentIds->has((int) $job->parent_job_id))
            ->values();
    }

    /**
     * ชื่องานย่อยทั้งหมดของงานใบหนึ่ง สำหรับแสดงใต้ชื่องานแม่
     *
     * คืนชื่อของงานย่อยทุกใบที่ยังไม่ถูกลบ ไม่ใช่เฉพาะใบที่ผู้ใช้เกี่ยวข้อง
     * เพราะคำถามคือ "งานใบนี้ประกอบด้วยอะไรบ้าง" ไม่ใช่ "เราทำส่วนไหน"
     *
     * @return list<string>
     */
    public static function subtaskNames(WorkOrder $job): array
    {
        return $job->relationLoaded('children')
            ? $job->children->pluck('job_topic')->all()
            : [];
    }
}

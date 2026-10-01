<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Support\AnnouncementDesign;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ใครเห็นประกาศไหน — แหล่งเดียวของกติกาการมองเห็นระดับ SQL
 *
 * ประกาศ "ทุกแผนก" เห็นทุกคนที่เป็นผู้รับได้ ประกาศ "เฉพาะแผนก" เห็นเฉพาะคนใน
 * แผนกที่ประกาศนั้นสังกัด (announcements.department_id ไม่ใช่แผนกปัจจุบันของผู้สร้าง)
 *
 * การเทียบวันใช้ whereDate เพราะคอลัมน์ date ที่เขียนผ่าน cast ของ Eloquent ถูกเก็บ
 * เป็น "Y-m-d 00:00:00" บน SQLite (ทดสอบ) แต่เป็น DATE บน MySQL การเทียบสตริง
 * ตรง ๆ จึงให้ผลต่างกันระหว่างสองฐานข้อมูล
 */
class AnnouncementQueryService
{
    public const TABS = ['all', 'department', 'everyone', 'active', 'pending', 'expired'];

    public const PER_PAGE = 15;

    /**
     * ประกาศที่อยู่ในช่วงแสดงของวันนี้สำหรับผู้รับคนหนึ่ง เรียงใหม่ไปเก่า
     *
     * @return Collection<int, Announcement>
     */
    public function activeFor(User $viewer, ?string $today = null): Collection
    {
        $today ??= AnnouncementDesign::today();

        return $this->visibleTo(Announcement::query(), $viewer)
            ->tap(fn (Builder $query) => $this->applyStatus($query, 'active', $today))
            ->with('author.department')
            ->latest('starts_on')
            ->latest('id')
            ->get();
    }

    /**
     * รายการในศูนย์ประกาศของหัวหน้า — ประกาศทุกใบที่หัวหน้าคนนี้มองเห็นได้
     * ปุ่มแก้ไข/ลบของแต่ละแถวตัดสินด้วย AnnouncementPolicy ไม่ใช่ที่นี่
     */
    public function manageList(User $head, string $tab, string $search, ?string $today = null): LengthAwarePaginator
    {
        $today ??= AnnouncementDesign::today();

        $query = $this->visibleTo(Announcement::query(), $head)
            ->with('author.department');

        $this->applyTab($query, $tab, $today);

        if ($search !== '') {
            $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return $query->latest('starts_on')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * จำนวนประกาศของแต่ละแท็บ — ตามคำค้นเดียวกับที่ผู้ใช้กรองอยู่
     *
     * @return array<string, int>
     */
    public function tabCounts(User $head, string $search, ?string $today = null): array
    {
        $today ??= AnnouncementDesign::today();

        return collect(self::TABS)->mapWithKeys(function (string $tab) use ($head, $search, $today): array {
            $query = $this->visibleTo(Announcement::query(), $head);
            $this->applyTab($query, $tab, $today);

            if ($search !== '') {
                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
            }

            return [$tab => $query->count()];
        })->all();
    }

    public static function normalizeTab(mixed $tab): string
    {
        return in_array($tab, self::TABS, true) ? $tab : 'all';
    }

    private function visibleTo(Builder $query, User $viewer): Builder
    {
        return $query->where(function (Builder $visible) use ($viewer): void {
            $visible->where('audience', Announcement::AUDIENCE_ALL);

            if ($viewer->department_id !== null) {
                $visible->orWhere(fn (Builder $own) => $own
                    ->where('audience', Announcement::AUDIENCE_DEPARTMENT)
                    ->where('department_id', $viewer->department_id));
            }
        });
    }

    private function applyTab(Builder $query, string $tab, string $today): void
    {
        match ($tab) {
            'department' => $query->where('audience', Announcement::AUDIENCE_DEPARTMENT),
            'everyone' => $query->where('audience', Announcement::AUDIENCE_ALL),
            'active', 'pending', 'expired' => $this->applyStatus($query, $tab, $today),
            default => null,
        };
    }

    /**
     * เงื่อนไขเดียวกับ AnnouncementDesign::statusFor() ในรูป SQL
     */
    private function applyStatus(Builder $query, string $status, string $today): void
    {
        match ($status) {
            'pending' => $query->whereDate('starts_on', '>', $today),
            'expired' => $query->whereNotNull('ends_on')->whereDate('ends_on', '<', $today),
            default => $query->whereDate('starts_on', '<=', $today)
                ->where(fn (Builder $window) => $window
                    ->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $today)),
        };
    }
}

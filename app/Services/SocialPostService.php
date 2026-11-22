<?php

namespace App\Services;

use App\Models\Department;
use App\Models\SocialPost;
use App\Models\SocialPostLike;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * โพสต์ในชุมชน — ฟีด การสร้าง แก้ไข ลบ และการถูกใจ
 *
 * ฟีดเป็นชุดเดียวสำหรับทุกบัญชี ไม่มีเงื่อนไขแผนกหรือบทบาท ผู้ชมต่างกันแค่สถานะ "ฉันถูกใจแล้ว"
 * การแบ่งหน้าใช้ id เป็นเคอร์เซอร์ (id < before) เรียงจากใหม่ไปเก่า เมื่อมีคนโพสต์ใหม่ระหว่าง
 * ที่ผู้ใช้กดโหลดเพิ่ม หน้าถัดไปจึงไม่ซ้ำและไม่ข้ามโพสต์ ต่างจาก offset pagination
 */
class SocialPostService
{
    public function __construct(private readonly SocialPostMediaStore $media) {}

    /**
     * @return array{posts: Collection<int, SocialPost>, next_cursor: int|null}
     */
    public function feed(User $viewer, ?int $before = null, ?int $limit = null): array
    {
        $limit ??= (int) config('social.feed_page_size', 10);

        $posts = $this->displayQuery($viewer)
            ->when($before !== null, fn (Builder $query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $posts->count() > $limit;
        $posts = $posts->take($limit)->values();

        return [
            'posts' => $posts,
            'next_cursor' => $hasMore ? (int) $posts->last()->id : null,
        ];
    }

    /** โหลดทุกอย่างที่การ์ดโพสต์ต้องใช้ในคิวรีชุดเดียว (ใช้หลังสร้าง/แก้ไข) */
    public function forDisplay(SocialPost $post, User $viewer): SocialPost
    {
        return $this->displayQuery($viewer)->whereKey($post->getKey())->firstOrFail();
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function create(User $author, string $body, array $images = [], ?string $visibility = null): SocialPost
    {
        $images = $this->media->validated($images);
        $this->assertNotEmpty($body, count($images));
        $audience = $this->audienceFor($author, $visibility ?? SocialPost::VISIBILITY_EVERYONE);

        $paths = [];

        try {
            return DB::transaction(function () use ($author, $body, $images, $audience, &$paths): SocialPost {
                $post = new SocialPost(['body' => $body !== '' ? $body : null]);
                $post->user_id = $author->id;
                $post->forceFill($audience);
                $post->save();

                $paths = $this->media->store($post, $images);

                return $post;
            });
        } catch (Throwable $exception) {
            $this->media->discard($paths);

            throw $exception;
        }
    }

    /**
     * @param  array<int, UploadedFile>  $images  รูปที่เพิ่มใหม่
     * @param  array<int, int|string>  $removeMediaIds  รูปเดิมที่ผู้เขียนเอาออก (ต้องเป็นของโพสต์นี้)
     * @param  string|null  $visibility  null = คงระดับการมองเห็นเดิม
     */
    public function update(SocialPost $post, string $body, array $images = [], array $removeMediaIds = [], ?string $visibility = null): SocialPost
    {
        $audience = $this->audienceOnUpdate($post, $visibility);

        $existing = $post->media()->get();
        $removeIds = collect($removeMediaIds)->map(fn ($id) => (int) $id)->unique();

        // id ที่ไม่ใช่รูปของโพสต์นี้ถูกปฏิเสธทั้งคำขอ — ไม่ข้ามเงียบ ๆ และไม่แตะรูปของโพสต์อื่น
        if ($removeIds->diff($existing->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['remove_media' => 'รูปที่ต้องการลบไม่ได้อยู่ในโพสต์นี้']);
        }

        $kept = $existing->reject(fn ($media) => $removeIds->contains($media->id))->values();
        $images = $this->media->validated($images, $kept->count());
        $this->assertNotEmpty($body, $kept->count() + count($images));

        $paths = [];

        try {
            return DB::transaction(function () use ($post, $body, $images, $existing, $kept, $removeIds, $audience, &$paths): SocialPost {
                // ลบทีละแถวผ่าน Eloquent — event ของ SocialPostMedia ลบไฟล์หลัง commit
                $existing->filter(fn ($media) => $removeIds->contains($media->id))->each->delete();

                foreach ($kept as $order => $media) {
                    if ($media->sort_order !== $order) {
                        $media->forceFill(['sort_order' => $order])->save();
                    }
                }

                $paths = $this->media->store($post, $images, $kept->count());

                $newBody = $body !== '' ? $body : null;
                if ($newBody !== $post->body || $removeIds->isNotEmpty() || $images !== []) {
                    $post->forceFill(['body' => $newBody, 'edited_at' => now()]);
                }

                $before = ['visibility' => $post->visibility, 'target_department_id' => $post->target_department_id];
                $post->forceFill($audience);
                if ($post->isDirty(['visibility', 'target_department_id'])) {
                    AuditTrail::log('updated', $post, 'เปลี่ยนการมองเห็นโพสต์ชุมชน: '.$before['visibility'].' → '.$post->visibility, [
                        'before' => $before,
                        'after' => ['visibility' => $post->visibility, 'target_department_id' => $post->target_department_id],
                    ]);
                }

                $post->save();

                return $post;
            });
        } catch (Throwable $exception) {
            $this->media->discard($paths);

            throw $exception;
        }
    }

    /**
     * ลบลงถังขยะ — แถว ความคิดเห็น การถูกใจ และไฟล์ยังอยู่ กู้คืนได้ภายใน 30 วัน
     * การลบถาวร (TrashRetention) ไล่ลบรูปผ่าน TrashRestorers::FILE_RELATIONS ก่อนลบโพสต์
     */
    public function delete(SocialPost $post, User $actor): void
    {
        DB::transaction(function () use ($post, $actor): void {
            $post->loadMissing('author.department');
            $author = $post->author?->name ?? 'ไม่ระบุ';
            $excerpt = $post->hasBody() ? Str::limit((string) $post->body, 60) : 'โพสต์รูปภาพ';

            // title / department_name คือคีย์ที่หน้าถังขยะ (TrashRetention::summary) ใช้ตั้งชื่อรายการ
            AuditTrail::trash($post, $actor, ['post' => [
                'id' => $post->id,
                'user_id' => $post->user_id,
                'title' => 'โพสต์ของ '.$author.': '.$excerpt,
                'department_name' => $post->author?->department?->department_name,
                'body' => $post->body,
                'created_at' => $post->created_at?->toIso8601String(),
            ]]);

            if ((int) $post->user_id !== (int) $actor->id) {
                AuditTrail::log('deleted', $post, 'ผู้ดูแลระบบลบโพสต์ชุมชนของ '.($post->author?->name ?? 'ไม่ระบุ'));
            }

            $post->delete();
        });
    }

    /** ถูกใจ — กดซ้ำหรือกดพร้อมกันหลายแท็บก็ได้แถวเดียว (unique + insertOrIgnore) */
    public function like(SocialPost $post, User $user): int
    {
        SocialPostLike::query()->insertOrIgnore([
            'social_post_id' => $post->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        return $post->likes()->count();
    }

    public function unlike(SocialPost $post, User $user): int
    {
        $post->likes()->where('user_id', $user->id)->delete();

        return $post->likes()->count();
    }

    /**
     * ข้อความจากผู้ใช้ — คงการขึ้นบรรทัดและ emoji ไว้ทั้งหมด
     * ตัดเฉพาะอักขระควบคุมที่มองไม่เห็น (ไม่แตะ ZWJ / variation selector ของ emoji)
     */
    public static function normalizeText(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim($text);
    }

    /**
     * ผู้ชมของโพสต์ใหม่ — แผนกเป้าหมายมาจากแผนกจริงของผู้โพสต์ฝั่ง server เสมอ
     * ไม่มีแผนก (หรือแผนกถูกลบไปแล้ว) แล้วเลือก "เฉพาะแผนก" → ปฏิเสธ ไม่เปลี่ยนเป็น "ทุกคน" ให้เอง
     *
     * @return array{visibility: string, target_department_id: int|null}
     */
    private function audienceFor(User $author, string $visibility): array
    {
        if ($visibility !== SocialPost::VISIBILITY_DEPARTMENT) {
            return ['visibility' => SocialPost::VISIBILITY_EVERYONE, 'target_department_id' => null];
        }

        if ($author->department_id === null || ! Department::query()->whereKey($author->department_id)->exists()) {
            throw ValidationException::withMessages([
                'visibility' => 'บัญชีของคุณยังไม่มีแผนก จึงเลือก "เฉพาะแผนกของฉัน" ไม่ได้ กรุณาติดต่อผู้ดูแลระบบ',
            ]);
        }

        return ['visibility' => SocialPost::VISIBILITY_DEPARTMENT, 'target_department_id' => (int) $author->department_id];
    }

    /**
     * ผู้ชมเมื่อแก้ไข — ไม่ส่งมา หรือยังเป็น "เฉพาะแผนก" เหมือนเดิม: คงแผนกที่ตรึงไว้ตอนเลือก
     * (ผู้โพสต์ที่ย้ายแผนกแล้วแก้ข้อความ ต้องไม่ทำให้โพสต์ย้ายไปอยู่กับแผนกใหม่เอง)
     * เปลี่ยนจาก "ทุกคน" เป็น "เฉพาะแผนก": ใช้แผนกปัจจุบันของเจ้าของโพสต์
     *
     * @return array{visibility: string, target_department_id: int|null}
     */
    private function audienceOnUpdate(SocialPost $post, ?string $visibility): array
    {
        if ($visibility === null || ($visibility === SocialPost::VISIBILITY_DEPARTMENT && $post->isDepartmentOnly())) {
            return ['visibility' => $post->visibility, 'target_department_id' => $post->target_department_id];
        }

        return $this->audienceFor($post->author ?? User::withTrashed()->findOrFail($post->user_id), $visibility);
    }

    private function assertNotEmpty(string $body, int $imageCount): void
    {
        if ($body === '' && $imageCount === 0) {
            throw ValidationException::withMessages(['body' => 'กรุณาพิมพ์ข้อความหรือเลือกรูปภาพอย่างน้อย 1 รูป']);
        }
    }

    private function displayQuery(User $viewer): Builder
    {
        // visibleTo() ก่อนทุกอย่าง — โพสต์ที่ผู้ชมไม่มีสิทธิ์เห็นไม่ถูกดึงออกจากฐานข้อมูลเลย
        return SocialPost::query()
            ->visibleTo($viewer)
            ->with(['author.department', 'targetDepartment', 'media'])
            ->withCount(['likes', 'comments'])
            ->withExists(['likes as liked_by_viewer' => fn (Builder $query) => $query->where('user_id', $viewer->id)]);
    }
}

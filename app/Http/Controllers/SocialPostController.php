<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Http\Requests\Social\StoreSocialPostRequest;
use App\Http\Requests\Social\UpdateSocialPostRequest;
use App\Models\SocialPost;
use App\Services\SocialPostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * สร้าง แก้ไข และลบโพสต์ในชุมชน
 *
 * สิทธิ์อยู่ที่ SocialPostPolicy (ผ่าน Form Request และ Gate) งานจริงอยู่ที่ SocialPostService
 * ผู้เรียกแบบ AJAX ได้ HTML ของการ์ดที่เรนเดอร์จาก partial เดียวกับหน้าฟีดกลับไปแทนที่ในหน้าเอง
 */
class SocialPostController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(private readonly SocialPostService $posts) {}

    public function store(StoreSocialPostRequest $request)
    {
        $post = $this->posts->create($request->user(), $request->body(), $request->images(), $request->visibility());

        return $this->jsonOrBack($request, true, 'โพสต์เรียบร้อยแล้ว', 201, $this->cardPayload($request, $post));
    }

    public function update(UpdateSocialPostRequest $request, SocialPost $post)
    {
        $post = $this->posts->update($post, $request->body(), $request->images(), $request->removeMediaIds(), $request->visibility());

        return $this->jsonOrBack($request, true, 'บันทึกการแก้ไขโพสต์แล้ว', 200, $this->cardPayload($request, $post));
    }

    public function destroy(Request $request, SocialPost $post)
    {
        Gate::authorize('delete', $post);

        $this->posts->delete($post, $request->user());

        return $this->jsonOrBack($request, true, 'ลบโพสต์แล้ว', 200, ['post_id' => $post->id]);
    }

    /** @return array{post_id: int, html: string} */
    private function cardPayload(Request $request, SocialPost $post): array
    {
        return [
            'post_id' => $post->id,
            'html' => view('social.partials.post', ['post' => $this->posts->forDisplay($post, $request->user())])->render(),
        ];
    }
}

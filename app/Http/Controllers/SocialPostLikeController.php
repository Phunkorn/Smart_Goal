<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\SocialPost;
use App\Services\SocialPostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ถูกใจ / ยกเลิกถูกใจโพสต์
 *
 * แยกเป็น PUT (ถูกใจ) และ DELETE (ยกเลิก) แทนการ toggle — สองแท็บที่กดพร้อมกัน
 * หรือคำขอที่ถูกส่งซ้ำจึงได้ผลลัพธ์ตามที่ผู้ใช้ตั้งใจเสมอ ไม่สลับกลับไปมา
 */
class SocialPostLikeController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(private readonly SocialPostService $posts) {}

    public function store(Request $request, SocialPost $post)
    {
        Gate::authorize('interact', $post);

        $count = $this->posts->like($post, $request->user());

        return $this->jsonOrBack($request, true, 'ถูกใจโพสต์แล้ว', 200, ['liked' => true, 'likes_count' => $count]);
    }

    public function destroy(Request $request, SocialPost $post)
    {
        Gate::authorize('interact', $post);

        $count = $this->posts->unlike($post, $request->user());

        return $this->jsonOrBack($request, true, 'ยกเลิกถูกใจแล้ว', 200, ['liked' => false, 'likes_count' => $count]);
    }
}

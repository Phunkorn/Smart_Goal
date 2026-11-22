<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ช่องค้นหาบน Topbar (resources/js/components/global-search.js) — ตอบเป็น JSON เท่านั้น
 *
 * ขอบเขตข้อมูลตามสิทธิ์ของผู้ใช้อยู่ใน GlobalSearchService ทั้งหมด controller ทำแค่รับคำค้น
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:'.GlobalSearchService::MAX_LENGTH],
        ]);

        return response()->json($search->search($request->user(), (string) ($validated['q'] ?? '')));
    }
}

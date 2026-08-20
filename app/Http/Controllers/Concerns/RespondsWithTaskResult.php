<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait RespondsWithTaskResult
{
    private function jsonOrBack(Request $request, bool $ok, string $message, int $status = 200)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => $ok, 'message' => $message], $status);
        }

        return $ok
            ? back()->with('success', $message)
            : back()->withErrors(['status' => $message]);
    }
}

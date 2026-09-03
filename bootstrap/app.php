<?php

use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\ByteSize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Cloudflare Tunnel / reverse proxy
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->alias([
            'admin' => AdminOnly::class,
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordHasBeenChanged::class,
            'role' => EnsureRole::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        /*
         * Laravel มี ValidatePostSize อยู่ในกลุ่ม web อยู่แล้ว และโยน PostTooLargeException
         * เมื่อ Content-Length เกิน post_max_size จึงไม่ต้องเขียน middleware ซ้ำ
         * แต่ข้อความเริ่มต้นคือ "The POST data is too large." ซึ่งเป็นภาษาอังกฤษ
         * และไม่บอกว่าเพดานเท่าไรหรือควรทำอย่างไรต่อ
         *
         * เรื่องนี้สำคัญขึ้นมากเมื่อเพดานไฟล์แนบเป็นระดับกิกะไบต์ เพราะ post_max_size
         * คุมขนาด "ทั้ง request" การแนบหลายไฟล์ใหญ่พร้อมกันจึงชนเพดานนี้ได้จริง
         */
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $limit = ByteSize::humanize(ByteSize::fromIni('post_max_size'));
            $message = 'ไฟล์ที่ส่งมารวมกันใหญ่เกินที่เซิร์ฟเวอร์รับได้ (สูงสุด '.$limit.' ต่อการอัปโหลดหนึ่งครั้ง) กรุณาแบ่งอัปโหลดเป็นหลายครั้ง';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], 413);
            }

            return back()->withErrors(['attachments' => $message]);
        });

    })->create();

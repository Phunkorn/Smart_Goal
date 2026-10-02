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
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        /*
         * Telegram ยิง webhook เข้ามาจากเซิร์ฟเวอร์ของตัวเอง ไม่มี session และไม่มี CSRF token
         * เส้นทางนี้ยืนยันตัวตนด้วย secret token ในเฮดเดอร์แทน (ดู TelegramWebhookController)
         */
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
        ]);

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

        /*
         * เซสชันหมดอายุระหว่างที่เปิดหน้าค้างไว้กับตอนกดส่ง
         *
         * คุกกี้เซสชันมีอายุ SESSION_LIFETIME นาที หน้าเข้าสู่ระบบที่เปิดค้างนานกว่านั้นจึงถือ
         * token ของเซสชันที่ตายไปแล้ว พอกดเข้าสู่ระบบจึงเด้งหน้า 419 ดำ ๆ ที่ไม่มีปุ่มให้กดต่อ
         * และไม่บอกว่าเกิดอะไรขึ้น ผู้ใช้อ่านว่าระบบเสีย ทั้งที่แค่รีเฟรชหน้าก็จบ
         *
         * พากลับมาที่ฟอร์มเดิมพร้อม token ใหม่และข้อความที่บอกว่าต้องทำอะไรต่อ แทนการปล่อยให้เจอ
         * หน้าตาย — การป้องกัน CSRF ยังทำงานครบเหมือนเดิม เพราะคำขอเดิมถูกปฏิเสธไปแล้ว
         */
        $exceptions->render(function (HttpException $exception, Request $request) {
            /*
             * ต้องดักที่ HttpException รหัส 419 ไม่ใช่ที่ TokenMismatchException ตรง ๆ
             *
             * Handler::render() ของ Laravel เรียก prepareException() ก่อน renderViaCallbacks()
             * เสมอ (Handler.php:616 มาก่อน 618) และ prepareException() แปลง
             * TokenMismatchException เป็น HttpException(419) ไปแล้ว (Handler.php:673)
             * callback ที่ผูกกับ TokenMismatchException จึงไม่มีทางถูกเรียกเลย
             * (ต่างจาก PostTooLargeException ข้างบน ซึ่งไม่อยู่ในรายการแปลงของ prepareException)
             *
             * คืน null เมื่อไม่ใช่ 419 เพื่อให้ 404/403/500 ไหลไปตามกลไกเดิม
             */
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            $message = 'หน้านี้เปิดค้างไว้นานเกิน '.config('session.lifetime').' นาที ระบบจึงตัดการเชื่อมต่อเพื่อความปลอดภัย กรุณาส่งใหม่อีกครั้ง';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], 419);
            }

            // หน้าเข้าสู่ระบบต้องพากลับมาที่ฟอร์ม ไม่ใช่ back() เพราะปลายทางคือ POST เส้นเดิม
            // ดูจาก path ไม่ใช่ routeIs() เพราะ routeIs() ต้องมี route ที่ถูก resolve แล้ว
            // ซึ่งไม่เป็นจริงเสมอเมื่อข้อผิดพลาดเกิดก่อนหรือนอกขั้นตอน routing
            if ($request->is('login')) {
                return redirect()->route('login')->withErrors(['username' => $message]);
            }

            return redirect()->to($request->headers->get('referer') ?: '/')->withErrors(['token' => $message]);
        });

    })->create();

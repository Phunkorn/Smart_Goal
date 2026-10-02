<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * เซสชันหมดอายุระหว่างที่หน้าค้างอยู่
 *
 * บั๊กจริงที่ผู้ใช้รายงาน: เปิดหน้าเข้าสู่ระบบค้างไว้เกิน SESSION_LIFETIME แล้วกดเข้าสู่ระบบ
 * เจอหน้าดำ "419 PAGE EXPIRED" ของ Laravel ซึ่งไม่มีปุ่มให้กดต่อ ไม่บอกว่าเกิดอะไรขึ้น
 * และไม่บอกว่าต้องทำอย่างไร ผู้ใช้จึงเข้าใจว่าระบบเสีย
 *
 * ทดสอบผ่าน exception handler ตรง ๆ ไม่ใช่ยิง HTTP เพราะ VerifyCsrfToken ของ Laravel
 * ข้ามการตรวจเองเมื่อรันในชุดทดสอบ การยิง POST ด้วย token ผิดจึงไม่มีทางทำให้เกิด
 * TokenMismatchException ได้เลย
 */
class ExpiredCsrfTokenTest extends TestCase
{
    public function test_an_expired_token_on_the_login_form_returns_to_the_form_with_a_thai_message(): void
    {
        $response = $this->renderTokenMismatch(Request::create(route('login.submit'), 'POST'));

        $response->assertRedirect(route('login'));
        $this->assertStringContainsString('หน้านี้เปิดค้างไว้นานเกิน', $this->firstError($response));
    }

    public function test_an_ajax_caller_gets_json_instead_of_an_html_error_page(): void
    {
        $request = Request::create(route('login.submit'), 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['ok']);
        $this->assertStringContainsString('หน้านี้เปิดค้างไว้นานเกิน', $response->getData(true)['message']);
    }

    /** หน้าอื่นต้องกลับไปหน้าเดิมที่กดมา ไม่ใช่เด้งไปหน้าเข้าสู่ระบบจนงานที่ทำค้างหาย */
    public function test_a_form_elsewhere_returns_to_the_page_it_came_from(): void
    {
        $request = Request::create('/tasks/1/comments', 'POST');
        $request->headers->set('referer', 'http://localhost/my-tasks');

        $this->renderTokenMismatch($request)->assertRedirect('http://localhost/my-tasks');
    }

    private function renderTokenMismatch(Request $request): TestResponse
    {
        return TestResponse::fromBaseResponse(
            app(ExceptionHandler::class)->render($request, new TokenMismatchException)
        );
    }

    /** ข้อความถูก flash ไว้ใน session ของแอป ไม่ใช่บน response จึงอ่านจาก store ตรง */
    private function firstError(TestResponse $response): string
    {
        $errors = app('session.store')->get('errors');

        return (string) ($errors?->first() ?? '');
    }
}

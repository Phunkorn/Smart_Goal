{{--
    เปลือกของหน้าแจ้งปัญหาสาธารณะ — ไม่ใช้ layouts.app เพราะ layout นั้นต้องมีผู้ใช้ที่เข้าสู่ระบบ
    (อ่าน auth()->user() โดยไม่ตรวจ null) รูปแบบเดียวกับหน้าเข้าสู่ระบบ
    ไม่มีข้อมูลภายในระบบใด ๆ ในหน้านี้: ไม่มีเมนู ไม่มีชื่อผู้ใช้ ไม่มีลิงก์ภายใน
    แบรนด์ (โลโก้ + Smart Ticket + แจ้งปัญหา IT) อยู่ในการ์ดของแต่ละหน้า ผ่าน tickets/public/brand
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') | Smart Goals</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite('resources/css/pages/tickets-public.css')
    @stack('scripts')
</head>
<body class="ticket-public">
    <main class="ticket-public__main">
        @yield('content')
    </main>

    <footer class="ticket-public__footer">
        ข้อมูลที่แจ้งใช้เพื่อติดต่อกลับและแก้ไขปัญหาเท่านั้น
    </footer>
</body>
</html>

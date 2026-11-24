# นำขึ้นเซิร์ฟเวอร์ DirectAdmin

## Security headers — rollout วันที่ 24 พฤศจิกายน 2026

Baseline commit: `f1ea5e056100f7fe25acd16373543e603f2cefbb` (`Update Fix Newfeature Post`).
การแก้ครั้งนี้รักษางานที่ยังไม่ commit ของผู้ใช้ไว้ ไม่เปลี่ยน schema, business logic หรือ permissions
ปรับ frontend เฉพาะ CSP compatibility, error rendering และการตัดขอบที่ซ่อนเนื้อหาใน Ticket settings
ชุด ZIP เป็น working tree ทั้งหมด จึงรวมงานเดิมของผู้ใช้ด้วย ต้องตรวจ diff ทั้งชุดก่อนนำขึ้นจริง

### สาเหตุและขอบเขตที่ตรวจ

ก่อนแก้ไม่มี global security-header middleware; `public/.htaccess` มีเฉพาะ rewrite;
`bootstrap/app.php` เชื่อ proxy ทุก IP (`*`) และไม่มี CSP/HSTS configuration
ตรวจ middleware, exception handling, provider, routes, config/session.php, Vite entries,
Blade/JS/CSS ทั้ง tree สำหรับ external resources, inline code, fetch, image previews,
iframe/print, audio, clipboard และ fullscreen รวมถึง deploy/build-update.mjs
ไม่มี Nginx/Apache virtual-host config จริงหรือ production `.env` อยู่ใน repository
จึงยังยืนยัน upstream header ownership และ proxy IP ที่ถูกต้องไม่ได้

อ่าน Production `/login` แบบ GET วันที่ 24 พฤศจิกายน 2026 ได้ HTTP 200 ผ่าน Nginx
และพบว่าขาดทั้ง 6 headers ตาม scan ของผู้ใช้; HTTP ตอบ 301 ไป HTTPS
พบ Cache-Control และ Pragma ซ้ำอยู่แล้ว ซึ่งอาจมาจากแอปและ hosting หลายชั้น
ไม่ได้เปลี่ยน production หรืออ้างว่าได้ Grade A/A+ แล้ว

หลังผู้ใช้นำชุดแรกขึ้น Production ภาพ scan ยืนยัน **Grade B**: 4 headers ทำงานแล้ว
ยังขาด enforcing CSP และ HSTS ตามขั้น Report-Only เดิม รอบตรวจอ่านอย่างเดียวเพิ่มเติมพบว่า
TLS certificate ผ่าน validation, HTTP redirect ไป HTTPS และการส่ง X-Forwarded-Proto=http /
X-Forwarded-Host ปลอมจาก client ไม่ทำให้ form action หลุดจาก HTTPS ของโดเมนจริง
ผลนี้ยังไม่เปิดเผย proxy peer IP หรือแทนการตรวจ virtual-host config จริง

| Header | Production ก่อน deploy | แอปหลังแก้ (ค่าเริ่มต้น) |
|---|---|---|
| Strict-Transport-Security | ไม่มี | ยังปิด; เปิดเมื่อยืนยัน TLS/proxy แล้ว เฉพาะ production HTTPS |
| Content-Security-Policy | ไม่มี | เริ่มด้วย Content-Security-Policy-Report-Only |
| X-Frame-Options | ไม่มี | SAMEORIGIN (คง iframe รายงานในแอป) |
| X-Content-Type-Options | ไม่มี | nosniff |
| Referrer-Policy | ไม่มี | strict-origin-when-cross-origin |
| Permissions-Policy | ไม่มี | ปิด accelerometer/gyroscope/magnetometer/microphone/payment/usb; camera/geolocation/fullscreen/clipboard/autoplay เฉพาะ self |

### CSP และหลักฐานของ resource

Policy ประกอบจาก `config/security.php`; nonce สุ่ม 32 bytes ใหม่ทุก request
ถูกส่งเข้า Laravel Vite ก่อน render และใส่ใน inline script เดิมของ admin/departments
และ board/components/admin-assignment-flash ส่วน setup-password ย้ายไป external module
ที่ experience.js เดิม import ไม่เพิ่ม unsafe-inline, unsafe-eval, wildcard, dependency หรือ Vite entry

| Directive | เหตุผล / source of truth |
|---|---|
| default-src 'self' | Vite assets และทรัพยากรในแอปเป็นค่าเริ่มต้น |
| base-uri 'self' | จำกัดการเปลี่ยนฐาน URL |
| object-src 'none' | ไม่มี plugin/embed object ใน source |
| frame-src / frame-ancestors 'self' | inspection-report-modal ฝังรายงานจาก route ของแอป; สอดคล้อง SAMEORIGIN |
| form-action 'self' | form ส่งเข้า routes ของแอป; LINE OAuth ออกจากแอปด้วย server redirect ไม่ใช่ browser fetch |
| script-src 'self' + nonce + CDN paths | layouts/app ใช้ Bootstrap 5.3.3 bundle และ SweetAlert2 11.26.25/dist/sweetalert2.min.js จาก jsDelivr; pin ตาม package ที่ติดตั้งจริง |
| script-src-attr 'none' | event attributes ใน Blade ถูกย้ายไป external listeners แล้ว |
| style-src 'self' + nonce + Google Fonts CSS / CDN paths | auth/layout/public-ticket/report ใช้ fonts.googleapis.com, Bootstrap 5.3.3 CSS และ Bootstrap Icons 1.11.3 CSS |
| style-src-attr unsafe-hashes + hash เดียว | อนุญาตเฉพาะข้อความ `display:none !important` ที่ SweetAlert2 11.26.25 ใช้ตอนปิด popup บน Safari/iOS; style attributes อื่นไม่อนุญาต |
| font-src 'self' + fonts.gstatic.com + Bootstrap Icons font path | fonts ที่ stylesheet ข้างต้นเรียกจริง |
| img-src 'self' data: blob: | protected media, upload previews ใน employees/tickets/social/task comments และ SVG ใน Bootstrap CSS |
| connect-src 'self' | AJAX/fetch/axios ใช้ app routes; LINE/Telegram API เรียกฝั่ง PHP จึงไม่เพิ่มใน browser policy |
| media-src / manifest-src 'self' | เสียงแจ้ง Ticket และทรัพยากรแอป |
| worker-src 'none' | ไม่พบ worker/service worker ที่แอปใช้งาน |
| report-uri /security/csp-reports | collector ใหม่เนื่องจากไม่มี endpoint เดิมสำหรับ CSP; same-origin, stateless, 30 requests/IP/minute, body ไม่เกิน 16 KiB |

Collector รับ application/csp-report หรือ application/json และ log เฉพาะ directive/disposition
ไม่บันทึก URL, query string, script sample, cookie หรือชื่อผู้ใช้ รายงานที่ถูกส่งมาไม่นับเป็นหลักฐานที่เชื่อถือได้โดยลำพัง
ใช้ report-uri เพื่อรองรับ browser เดิมโดยไม่พึ่ง external reporting service
Local HMR derive HTTP/WebSocket origin จาก hot file ของ Vite เท่านั้น ไม่เปิดใน production
ต้องไม่มี `public/hot` ในชุด production

แนวทางอ้างอิง: [OWASP HTTP Headers Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html),
[MDN CSP Report-Only](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy-Report-Only)
และ implementation ของ Laravel 12 ใน vendor ของโปรเจกต์

**แก้ source ที่ขัดกับ CSP แล้ว:** password hints ใช้ external module/listeners;
ปุ่ม print สองหน้าและ iframe ใช้ printDocument ร่วมกันใน app.js entry เดิม;
workspace palette, monthly summary และ report legends รับข้อมูลเดิมผ่าน data attributes
แล้วตั้งเฉพาะ CSS properties ที่ตรวจสอบค่าใน component เดิม ไม่เปิดให้ส่ง CSS อิสระ
SweetAlert ใช้ JS build ที่ไม่ inject stylesheet และ import CSS ผ่าน components/layout.css
สี meter ย้ายไป component auth/setup-password.css เดิม ไม่เปลี่ยนสีหรือเกณฑ์รหัสผ่าน
HTTP error templates ใช้ errors.css เดิมแทน inline CSS ของ framework โดยไม่พึ่ง session

การใช้ CSS properties ผ่าน JavaScript ไม่เท่ากับ setAttribute(style)/cssText ที่ CSP บล็อก
อ้างอิง [MDN style-src-attr](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/style-src-attr)
ยังต้องตรวจ browser จริงทุก workflow ก่อนเปลี่ยนเป็น enforce; static/DOM tests ไม่ใช่ผล Console จริง

### ขั้นตอนนำขึ้นแบบ Report-Only

1. สำรอง code และ `Smart-Private/.env` ก่อน งานนี้ไม่ต้อง migrate ฐานข้อมูล
2. ขอข้อมูล Nginx/Apache/Cloudflare Tunnel จากผู้ดูแล DirectAdmin และระบุ peer IP/CIDR ที่เชื่อถือได้จริง
   ตั้ง `TRUSTED_PROXIES=` เป็นรายการคั่นด้วย comma; การเชื่อทุก IP ถูกยกเลิกแล้ว
   ถ้า PHP รับ HTTP จาก proxy ที่ยังไม่อยู่ในรายการ จะสร้าง URL/cookie ตาม scheme ผิดได้
   ต้องยืนยัน scheme จริง, forwarded proto และป้องกัน client ปลอม forwarded headers ก่อน deploy
3. ตั้ง `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` เป็น HTTPS ของโดเมนจริง
   รักษา `SESSION_ENCRYPT=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`
   และ `SESSION_SECURE_COOKIE=true` เดิมที่เหมาะสมไว้ ไม่เปิด debug เพื่อทดสอบบน production
4. ตั้ง `SECURITY_HEADERS_ENABLED=true`, `SECURITY_CSP_REPORT_ONLY=true`, `SECURITY_HSTS_ENABLED=false`
   ทดสอบบน staging ก่อน แล้วจึงสร้างชุดด้วย `npm.cmd run deploy:zip`
   สคริปต์ยืนยันว่ามี middleware/config/collector ในชุด และคง bootstrap ที่ชี้ Smart-Private
5. ตรวจ ZIP ว่าไม่มี .env/storage/private-path.php/vendor/tests/ฐาน SQLite ของเครื่องพัฒนา
   อัปและ extract โดยผู้ดูแลตามขั้นตอนเดิม ไม่อัปสำเนา baseline หรือ log การทดสอบ
6. ล้าง generated cache ที่ระบุด้านล่าง หรือใช้ artisan config:clear/view:clear เมื่อมี SSH
   หลีกเลี่ยงการลบ private-path.php; หากใช้ config:cache ให้สร้างบนเซิร์ฟเวอร์หลังปรับ .env
7. ตรวจ raw headers ด้วย GET (อย่าอ่านเฉพาะ HEAD): login, authenticated HTML, redirect,
   JSON/AJAX, 403/404/419/429/500, protected image, export/download และ 304
   แต่ละ security header ต้องมีเพียงค่าเดียว ไม่มี upstream CSP ที่บังคับอีก policy ซ้อนกัน
   ให้แอปเป็นเจ้าของ headers บน PHP responses; ตรวจและแก้ add_header/Header set ที่ hosting ตาม config จริง
   Nginx static files, HTTP redirect และ web-server errors ที่ไม่เข้า PHP ต้องตรวจแยกโดยผู้ดูแล
8. เปิด DevTools Network/Console พร้อม Preserve log ตรวจ desktop/tablet/mobile และรวบรวม CSP reports
   จาก Smart-Private/storage/logs ตาม logging channel จริง การไม่มีรายงานเพราะ throttle/เครือข่าย
   ไม่ใช่หลักฐานว่าปลอด violation และต้องไม่ cache HTML พร้อม nonce ข้าม request ที่ proxy/CDN

ตัวอย่างอ่าน response (ใช้ curl ที่ TLS ทำงานได้):

```sh
curl -sS -D - -o /dev/null https://smart-goals.premium-care.in.th/login
curl -sS -D - -o /dev/null http://smart-goals.premium-care.in.th/login
```

### HSTS / enforcement gate / rollback

หากไม่มี SSH ใช้ route ตั้งค่าเดิมแบบอ่านอย่างเดียวหลังนำชุดล่าสุดขึ้นแล้ว:
เข้าสู่ระบบด้วย admin และเปิด `/settings?security_headers=1`
ผู้ใช้ทั่วไป/viewer อ่านข้อมูลนี้ไม่ได้ และ response ไม่ถูก cache
JSON แสดง `request.is_secure`, `request.server_https`, `request.peer_is_trusted`,
`request.peer_address`, `csp_report_only`, `hsts_enabled` และ headers ที่ middleware สร้างจริง
ไม่แสดง .env, key, token, database connection หรือ X-Forwarded-For ทั้งชุด

ถ้า `request.is_secure=true` แต่ `hsts_enabled=false` ปัญหาที่เหลือคือ flag ยังปิด
หาก `is_secure=false` ให้ตรวจ TLS termination/forwarding และ peer กับผู้ดูแล hosting ก่อน
อย่านำ IP ของ client ที่ถูก mod_remoteip เขียนลง REMOTE_ADDR ไปใส่ trusted proxy โดยไม่ยืนยัน topology
diagnostics ไม่เปลี่ยน config และไม่ทำให้ระบบเปิด HSTS/CSP enforcement อัตโนมัติ

ยืนยัน certificate chain, HTTPS redirect, proxy และ secure session cookie ก่อนเปิด
`SECURITY_HSTS_ENABLED=true` โดยเริ่ม `SECURITY_HSTS_MAX_AGE=300` ตรวจ HTTPS/redirect/error
แล้วจึงค่อยเพิ่มเป็น 86400 และ 31536000 หลังช่วงติดตาม ไม่มี preload/includeSubDomains
อย่าตัดสินใจเพิ่มสองตัวนี้จากการตรวจเพียง subdomain ปัจจุบัน

เมื่อ browser regression ผ่านและไม่มี violation ที่กระทบงาน จึงตั้ง `SECURITY_CSP_REPORT_ONLY=false`
ทดสอบ staging อีกครั้งก่อน deploy enforcement แล้ว scan Production จริงหลัง deploy
Report-Only ยังไม่เท่ากับ enforcing CSP และไม่รับประกัน Grade A/A+

Rollback CSP ด้วย `SECURITY_CSP_REPORT_ONLY=true`; emergency header rollback ใช้
`SECURITY_HEADERS_ENABLED=false` แล้วล้าง/rebuild config cache
ถ้าต้องยกเลิก HSTS ที่ browser จำแล้ว ให้คง HTTPS และส่ง `max-age=0`
ด้วย enabled=true/max_age=0 ก่อนปิด ไม่ใช่เพียงลบ header (browser อาจยังจำค่าเดิม)
หากจำเป็นให้คืนชุด code/Smart-Private.env ที่สำรองไว้พร้อมกัน รวม proxy configuration เดิม
ไม่ต้อง rollback database สำหรับงาน security headers นี้

### Browser regression ที่ต้องบันทึกก่อนเปิด enforcement

Login/logout และ CSRF/session expiry; Dashboard/Daily Brief; Smart Goals/tasks;
Smart Ticket รวมกล้อง/เสียงแจ้งเตือน; Social; notification badge; global search;
desktop dropdown/mobile sidebar; modal/SweetAlert; image upload/preview/protected media;
AJAX/fetch/API; export/download และ print iframe ต้องผ่านบน desktop/tablet/mobile
ตรวจทั้ง Console, Network, CSP collector และผลการใช้งานจริง ไม่ใช้เพียง status 200

Browser host และ native pipe ของ Computer Use ไม่พร้อมใช้งานใน session ที่แก้โค้ดนี้
จึงยังไม่มีผล browser/Console/responsive test จริง ไม่อ้างว่าไม่มี CSP violations
สถานะสำหรับ staging/rollout แบบ Report-Only: **CONDITIONAL GO** โดยต้องยืนยัน proxy/hosting
สำหรับ CSP enforcement หรือการยืนยัน Grade A/A+: **NO-GO** จนผ่าน browser และ production gate ข้างต้น

### ไฟล์และการตรวจสอบในเครื่อง

สร้าง `app/Http/Middleware/SecurityHeaders.php`, `app/Http/Middleware/TrustedProxies.php`,
`app/Http/Controllers/CspReportController.php`, `config/security.php`, `tests/Feature/SecurityHeadersTest.php`
ใช้ namespace `App\Http\Middleware` และ `App\Http\Controllers`
แก้ `.env.example`, `bootstrap/app.php`, `routes/web.php`, `deploy/build-update.mjs`, `deploy/README.md`,
`resources/views/auth/setup-password.blade.php`, `resources/views/admin/departments/index.blade.php`,
`resources/views/board/components/admin-assignment-flash.blade.php` และ `tests/js/auth-setup-password.test.js`
รอบแก้เพิ่มเติมสร้าง resources/js/pages/auth/setup-password.js และ HTTP error views
4xx/5xx/minimal/components/http-error เพื่อแทน inline code; สร้าง CspRenderingTest และ security-csp.test.js
แก้ app.js/inspection-report-modal เพื่อใช้ print action ร่วมกัน, auth experience/setup-password,
workspace toolbar, daily-logs index/month-summary, reports projects/legend และ layout SweetAlert
เพิ่ม import ของ setup-password ผ่าน auth/experience.js เดิม และ SweetAlert CSS ผ่าน layout.css เดิม
CSS ที่แก้คือ auth/setup-password.css, errors.css และ tickets/settings.css
ใช้ namespace auth-setup-password, http-error-page, ws-board, daily-log และ project-report
ไม่มี Vite entry ใหม่หรือ dependency ใหม่
ไม่แตะ migration ที่เปิดใน IDE และรักษา route timeline ที่ผู้ใช้ยังไม่ commit ไว้ครบ

Security tests รวม CspRenderingTest และ diagnostics ผ่าน 31 รายการ / 423 assertions รวม HTML/JSON/redirect/error/streamed download,
early 413, nonce/Vite, session cookie flags, CSP observation/enforcement switch, HSTS,
trusted/untrusted proxy, collector validation/privacy/rate limit และ login CSRF
ตรวจ config:cache ด้วย cache path แยกใน deploy-output แล้ว /up ตอบ 200 พร้อม CSP ได้
ลบ config cache ทดสอบหลังตรวจแล้ว ไม่เปลี่ยน cache path ปกติของแอป
Production session cookie ที่อ่านเฉพาะ flags: Secure=true, HttpOnly=true, SameSite=lax
ส่วน XSRF-TOKEN เป็น Secure=true, HttpOnly=false, SameSite=lax ตามหน้าที่ให้ JavaScript อ่านได้

`npm.cmd run build`, `npm.cmd run deploy:zip`, PHP lint, Pint และ `git diff --check` ผ่าน
ตรวจ ZIP ว่ามี security files และ Smart-Private bootstrap ครบ ไม่มี private/development artifacts
เก็บผลตรวจใน `deploy-output/security-verification/` (ไม่อยู่ใน ZIP หรือ Git)
แก้ regression tests เดิมให้ตรวจโครงสร้างปัจจุบัน: assignment deep link, department overview,
preview tabs, shared typography/tokens, public-ticket department fallback และ soft-delete ที่ต้องเก็บไฟล์เพื่อกู้คืน
เพิ่ม query listener ที่ test ใช้แต่ยังไม่ได้ประกาศ; ไม่เปลี่ยน permission/business behavior เพื่อให้ tests ผ่าน
settings-modal tests จบ transition ตาม Bootstrap events แทนการรอ 40 ms ที่อาจจบก่อน dialog แสดง
คงการตรวจ focus return, backdrop, CSRF, read-only scope และการกู้คืนไฟล์แนบ

ผลรอบสุดท้ายหลังแก้ทั้งหมด:

| การตรวจ | ผล |
|---|---|
| php artisan test --compact | **1913 passed, 1 skipped, 0 failed / 13922 assertions** |
| npm.cmd test | **1591 passed, 0 failed** |
| CSP / Security HTTP tests | **31 passed / 423 assertions** |
| npm.cmd run build | ผ่าน |
| npm.cmd run deploy:zip | ผ่าน พร้อมตรวจ source/templates/bootstrap ใน ZIP |
| git diff --check / Pint | ผ่าน |
| Browser/Console/responsive จริง | ยังไม่มีผล เนื่องจากเครื่องมือไม่เชื่อมต่อ |

หนึ่ง skipped test เป็นการตรวจขนาดคอลัมน์ varchar บน MySQL ซึ่งไม่ใช้กับ SQLite ที่ suite นี้รันอยู่
ไม่ได้ skip failure ที่พบจากการแก้ CSP และไม่ได้แก้ schema เพื่อให้ tests ผ่าน
ไฟล์ SweetAlert 11.26.25 บน CDN ถูกตรวจ SHA-256 แล้วตรงกับ package ที่ใช้ใน tests
ผล DOM rendering ตรวจ login/setup-password, tasks, daily logs/monthly, Social, notifications,
workspace/board palette, admin dashboard/departments, tickets และ project report โดยเปิด CSP enforcement ใน test
การตรวจนี้ยืนยัน markup/policy compatibility ไม่แทนการ execute JavaScript/CSP ใน browser จริง

รอบตรวจเพิ่มเติม: SettingsController reuse `settings.index` สำหรับ admin-only diagnostics
และเพิ่ม `SecurityHeadersDiagnosticsTest` เพื่อตรวจ guest/user/viewer isolation, no-store,
untrusted forwarded HTTPS และข้อมูล HSTS/CSP ที่ตรงกับ response จริง
เตรียม fixture สำหรับ browser ใน deploy-output/security-verification แยก SQLite/storage
จากแอปจริง และรัน live HTTP regression ด้วย PHP server จริงผ่าน 21 checks:
session/CSRF login/logout, member/admin pages, AJAX search, CSV export, error response
และ diagnostics โดยเปิด CSP enforcement ใน fixture
ยังไม่ได้รัน browser เพราะรอเลือก Chrome headless หรือเชื่อมต่อ Browser ของแอป

## โครงสร้างบนเซิร์ฟเวอร์

```
domains/smart-goals.premium-care.in.th/
├── public_html/      ← โค้ดทั้งหมด (ซับโดเมนชี้มาที่นี่)
└── Smart-Private/    ← .env และ storage — อยู่นอกเว็บรูท
```

`public_html/.htaccess` ปิดไม่ให้เปิด `app/`, `config/`, `vendor/`, `.env` ผ่านเว็บ
แล้ว rewrite ทุก request เข้า `public/index.php`

`bootstrap/private-path.php` เป็นตัวบอก Laravel ว่า `.env` กับ `storage` อยู่ที่ไหน

---

## สร้างชุดอัป

รันจาก **PowerShell** ในโฟลเดอร์โปรเจกต์:

```powershell
npm.cmd run deploy:zip
```

> ต้องเป็น PowerShell ไม่ใช่ Git Bash — tar ของ MSYS อ่าน `D:` เป็นชื่อโฮสต์แล้วล้ม

สคริปต์จะ build Vite ก่อน แล้วสร้าง `smart-goal-update-*.zip` ไว้ที่ `deploy-output/`

ชุดเดียวกันถูกแตกไว้ให้แล้วที่ `deploy/public_html/` สำหรับคนที่อยากลากทีละโฟลเดอร์
ผ่าน FTP แทนการอัป ZIP แล้ว Extract บนเซิร์ฟเวอร์ — **เนื้อในเหมือนกันทุกไฟล์**
แนะนำให้ใช้ ZIP เพราะเร็วกว่ามาก (581 ไฟล์ vs อัปครั้งเดียว) และไม่มีไฟล์ตกหล่นกลางทาง

`deploy/` เองไม่เคยอยู่ในชุดอัป ไฟล์ `.sql` ในนี้บอกโครงสร้างฐานข้อมูลทั้งหมด
และ `.htaccess` ของเซิร์ฟเวอร์ปิดไว้แค่ `app/` `config/` `vendor/` `.env` เท่านั้น

### สิ่งที่ไม่อยู่ในชุดอัป — และเหตุผล

| ไม่รวม | เพราะ |
|---|---|
| `vendor/` | ผูกกับ `composer.lock` ถ้าไม่เปลี่ยนก็ไม่ต้องอัป (ประหยัดจาก 64 MB เหลือ ~2 MB) |
| `bootstrap/private-path.php` | เป็นไฟล์ของเซิร์ฟเวอร์เท่านั้น ถ้าอัปทับจะไปเขียนทับ path ที่ตั้งไว้ |
| `.env`, `storage/` | อยู่ใน `Smart-Private` ไม่เคยอยู่ใน `public_html` |
| `database/database.sqlite` | ฐานข้อมูลของเครื่องพัฒนา มีข้อมูลทดสอบอยู่ข้างใน |
| `tests/`, `phpunit.xml`, `.claude`, `CLAUDE.md` | ไม่ได้ใช้ตอนรันจริง |

### `bootstrap/app.php` ถูกแปลงให้อัตโนมัติ

ชุดพัฒนากับเซิร์ฟเวอร์ต่างกันสามบรรทัด (โค้ดชี้ไป `Smart-Private`)
`build-update.mjs` เติมให้เองตอนสร้างชุด จึงมีแหล่งความจริงเดียวคือชุดพัฒนา
และไม่มีทางอัปไฟล์ที่ทำเว็บล่มขึ้นไปโดยไม่ตั้งใจ — สคริปต์ตรวจซ้ำก่อนสร้าง ZIP เสมอ

---

## ขั้นตอนอัป

### 1. สำรองก่อน

- **ฐานข้อมูล** — phpMyAdmin › เลือกฐาน › Export › Quick › SQL
- **โค้ด** — ดาวน์โหลด `public_html` เก็บไว้ หรือ Compress ไว้บนเซิร์ฟเวอร์

### 2. อัปฐานข้อมูล (ทำก่อนอัปไฟล์)

ตรวจก่อนว่าเซิร์ฟเวอร์อยู่ที่รุ่นไหน:

```sql
SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;
```

**ทางลัดที่แนะนำ:** ใช้ `2026-09-09-FULL-database.sql` ไฟล์เดียวจบ

ไฟล์นั้นรวมทั้งสามไฟล์ด้านล่างไว้ตามลำดับที่ถูกต้องแล้ว รันซ้ำได้ และรันได้ไม่ว่า
ฐานข้อมูลจะอยู่รุ่นไหน ส่วนที่ทำไปแล้วจะถูกข้ามเอง จึงไม่ต้องมานั่งไล่ว่าเคยรันอะไรไปบ้าง

ทดสอบแล้วกับ MySQL 8.0.46 บนฐานข้อมูลที่จำลองสภาพโปรดักชันก่อน Deploy รอบ 2
รัน 4 รอบติดกัน 158 คำสั่ง ไม่มี error และ `php artisan migrate:status` รายงานว่า
ไม่มี migration ค้างเลย

ถ้าอยากรันแยกทีละไฟล์ ให้ทำตามตารางนี้ เปิด phpMyAdmin › เลือกฐานข้อมูล › แท็บ SQL ›
วางทั้งไฟล์ › Go ทีละไฟล์

| ลำดับ | ไฟล์ | รันเมื่อ | migration ที่ครอบคลุม |
|---|---|---|---|
| 1 | `2026-09-08-upgrade.sql` | แถวบนสุดยังเป็น `2026_09_03_000001_restore_missing_work_order_subtasks_table` (Deploy รอบ 2) | 11 ตัว |
| 2 | `2026-09-09-subtasks-and-routines.sql` | ยังไม่เห็นชื่อ `2026_09_08_000007_add_archived_at_to_work_order_lists` | 5 ตัว |
| 3 | `2026-09-09-telegram.sql` | ยังไม่เห็นชื่อ `2026_09_08_000006_create_telegram_messages_table` | 3 ตัว |

> ไฟล์รวมต้องอ่าน `information_schema` ได้ ทดสอบสิทธิ์ก่อนด้วยบรรทัดเดียว:
> `SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE();`
> ได้ตัวเลข = ผ่าน ถ้าได้ `#1044 Access denied` แปลว่าโฮสต์ปิดสิทธิ์นั้น
> ให้ใช้ `2026-09-08-upgrade.sql` (ซึ่งไม่ใช้ information_schema เลย) เป็นไฟล์แรกแทน

ไฟล์ที่รันไปแล้วให้ข้าม แต่ถ้าเผลอรันซ้ำก็ไม่เสียหาย ทุกคำสั่งตรวจของที่มีอยู่แล้วให้เอง

ไฟล์เหล่านี้แทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ที่ไม่มี SSH
ไม่มีการลบตาราง คอลัมน์ หรือแถวใดทั้งสิ้น มีแต่การเพิ่มและการย้ายค่าเดิมไปคอลัมน์ใหม่

ไฟล์ที่ 2 มีสองจุดที่ "แก้ข้อมูลเดิม" ซึ่งจำเป็นและกู้กลับได้จากไฟล์สำรอง:
- `work_order_subtasks` ทุกแถวถูก **คัดลอก** ขึ้นมาเป็นงานย่อยจริงใน `work_orders`
  (แถวเดิมไม่ถูกลบ ยังตรวจย้อนหลังได้)
- รายการงานประจำที่ยังไม่ได้เริ่ม ถูกย้ายค่าเวลาจาก `started_at` ไปที่ `planned_start_at`
  แล้วเคลียร์ `started_at` เพื่อให้ช่องนั้นหมายถึง "เวลาที่กดเริ่มจริง" เท่านั้น

> **ปฏิทินเวรงานประจำ** ต้องมี migration `2026_09_14_000001_add_shift_schedule_to_work_log_templates`
> (เพิ่มคอลัมน์ `work_log_templates.schedule_type` ค่าเริ่มต้น `weekly` และตารางใหม่ `work_log_template_shifts`)
> ยังไม่อยู่ในไฟล์ SQL ด้านบน — ดู SQL ที่ต้องรันได้จาก `php artisan migrate --pretend`
> ไม่ลบหรือแก้ข้อมูลเดิม งานประจำที่มีอยู่ทั้งหมดยังเป็นแบบ "ทุกสัปดาห์" เหมือนเดิม

**ต้องอัปฐานข้อมูลก่อนอัปไฟล์** เพราะโค้ดใหม่อ้างถึงตารางและคอลัมน์ที่ยังไม่มี
ถ้าสลับลำดับ เว็บจะขึ้น 500 ระหว่างที่ยังไม่ได้รัน SQL

### 3. อัปไฟล์

อัป ZIP เข้า `public_html` แล้ว Extract แบบเขียนทับ

### 4. ล้างไฟล์ที่ระบบสร้างไว้

ไม่มี SSH จึงต้องลบด้วย File Manager:

- `public_html/bootstrap/cache/` — ลบไฟล์ `.php` ทุกไฟล์ (เก็บ `.gitignore` ไว้)
- `Smart-Private/storage/framework/views/` — ลบไฟล์ `.php` ทุกไฟล์

Laravel สร้างใหม่ให้เองในการเปิดหน้าครั้งถัดไป
ถ้าไม่ลบ หน้าจออาจยังเป็นเทมเพลตเก่าที่คอมไพล์ไว้

### 5. ตรวจว่าใช้งานได้

| หน้า | ต้องเห็น |
|---|---|
| `/my-tasks` | เปิดได้ ไม่ขึ้น 500 |
| `/daily-logs` | เมนู "บันทึกงานประจำวัน" (ของใหม่) |
| `/workspace` | เมนู "กระดานไอเดีย" (ของใหม่) |
| `/reports/operational` | รายงานภาระงานปฏิบัติการ (ของใหม่) |
| `/admin/audit` | แท็บถังขยะมีปุ่มลบถาวร |
| `/my-tasks` มุมมองบอร์ด | กางงานหนึ่งใบแล้วเห็นแถวงานย่อย ปิดงานย่อยแล้วแถวยังอยู่ |
| `/my-tasks` มุมมองบอร์ด | ปุ่ม "โปรเจกต์ที่เสร็จแล้ว" อยู่ข้างปุ่ม "สร้างงาน" |
| `/daily-logs` | รายการงานประจำมีเวลาที่วางแผนไว้ และกด "ข้ามงาน" พร้อมเหตุผลได้ |
| `/reports/projects` | รายงานโปรเจกต์ประจำเดือน หัวหน้าแผนกเห็นภาพรวมแผนกตัวเอง เลือกพนักงานในแผนกและเดือนได้จากด้านบน ตัวกรองและ Export CSV ตรงกับตาราง (`/reports/employees` เดิม redirect มาที่นี่) |
| `/reports/projects/details` | ปุ่ม "ดูรายละเอียดทั้งหมด" เปิดได้ แถวละบรรทัดเดียว กดป้ายงานย่อยหรือไอคอนไฟล์แล้วเปิดกล่องรายละเอียด (ไฟล์แนบงาน/งานย่อย เวลาเริ่ม/เสร็จ) กดชื่อไฟล์แล้วเปิดไฟล์ได้จริง |
| `/reports/projects` (บัญชีพนักงาน) | หน้าตาเดียวกับหัวหน้าแผนก แสดงชื่อตัวเอง "(ฉัน)" แทนช่องเลือกพนักงาน เห็นเฉพาะงานของตัวเอง แก้ `?owner=` ใน URL แล้วยังเป็นของตัวเอง `/my-reports` เดิมพามาหน้านี้ |
| `/daily-logs` แท็บวันนี้ | แท็บ "วันนี้ / ปฏิทินเวร" บนหัวหน้าจอ แถบเวร 4 สัปดาห์ของหมวด (เห็นชื่อคนในแผนก) และ "เพิ่มงานใหม่" ในหน้า เลือกงานประจำ/นอกสถานที่ กรอกแล้วรายการขึ้นทันที |
| `/daily-logs?view=roster` | ตั้งงานประจำแบบ "เลือกวันจากปฏิทิน" › แท็บปฏิทินเวร › กดวันเพื่อลงชื่อ, ปุ่ม ⋯ ลงให้เพื่อน, ปุ่มท้ายแถวลงทั้งสัปดาห์ › เปิดบัญชีเพื่อนในแผนกเดียวกันเห็นชื่อกันบนปฏิทิน และได้งานของวันนั้น |
| `/work-board/departments/{id}` | หัวหน้าแผนก/admin เห็นชิป "งานประจำ x/y" และ "นอกสถานที่" บนการ์ดสมาชิก ตัวเลขตรงกับ `/reports/operational` ของวันนี้ |

แนบไฟล์ในคอมเมนต์งานหนึ่งครั้ง แล้วลบ — ไฟล์ต้องยังเปิดได้จากถังขยะ

---

## การแจ้งเตือนผ่าน Telegram — ตั้งครั้งเดียวจบ

ระบบส่งการแจ้งเตือนชุดเดียวกับที่แสดงในเว็บออกไปเป็น **แชทส่วนตัวรายบุคคล** ไม่ใช่กลุ่ม
ผู้ใช้แต่ละคนผูกบัญชีเองจากหน้า `/settings`

การส่งครั้งแรกเกิดตอนปิดท้าย request ที่สร้างการแจ้งเตือน (หลังคืนหน้าเว็บให้ผู้ใช้แล้ว)
จึง **ไม่ต้องมี queue worker**

**แต่ต้องมี cron** (หัวข้อ "งานตามเวลา (cron)" ด้านล่าง) สำหรับข้อความที่ส่งครั้งแรกไม่สำเร็จ
(เครือข่ายล่ม, Telegram ตอบ 429/5xx) — ข้อความเหล่านี้ถูกลองใหม่ห่างขึ้นเรื่อย ๆ (1, 5, 15, 30 นาที
สูงสุด 5 ครั้ง) โดยคำสั่ง `telegram:drain` ที่ scheduler เรียกทุกนาที ถ้าไม่มี cron ข้อความจะค้าง
จนกว่าจะมีการแจ้งเตือนฉบับใหม่เกิดขึ้น (เช่น Ticket ที่แจ้งตอนกลางคืนอาจไปถึงตอนเช้า)

ตรวจคิวที่ค้าง: หน้า `/admin/audit` แท็บภาพรวมจะขึ้นแถบเตือน **"Telegram มีข้อความค้าง…"**
เมื่อมีข้อความรอส่งนานเกิน 10 นาทีหรือส่งไม่สำเร็จใน 24 ชั่วโมง หรือบน SSH ใช้
`php artisan telegram:status` (แสดงจำนวนเท่านั้น ไม่แสดงเนื้อความ)

### 1. สร้างบอท

คุยกับ `@BotFather` ใน Telegram › `/newbot` › ตั้งชื่อและ username › เก็บ token ไว้

### 2. รัน SQL

เปิด phpMyAdmin › แท็บ SQL › วางไฟล์ `2026-09-09-telegram.sql` ทั้งไฟล์ › Go
(เพิ่มคอลัมน์ `telegram_*` ในตาราง `users` และสร้างตารางใหม่ 2 ตาราง — รันซ้ำได้)

### 3. เพิ่มค่าใน `Smart-Private/.env`

```
TELEGRAM_NOTIFICATIONS_ENABLED=true
TELEGRAM_BOT_TOKEN=123456:AA...
TELEGRAM_BOT_USERNAME=SmartGoalBot
TELEGRAM_WEBHOOK_SECRET=<ค่าสุ่ม 32 ตัวอักษร>
```

ตรวจด้วยว่า `APP_URL` เป็น https ของโดเมนจริง เพราะลิงก์ในข้อความ Telegram สร้างจากค่านี้
แล้วลบ `public_html/bootstrap/cache/*.php` เพื่อให้ config ใหม่มีผล

### 4. ลงทะเบียน webhook

ไม่ต้องใช้ SSH เปิด URL นี้ในเบราว์เซอร์ครั้งเดียว (แทน `<TOKEN>` และ `<SECRET>` ด้วยค่าจริง)

```
https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://smart-goals.premium-care.in.th/telegram/webhook&secret_token=<SECRET>
```

ตรวจผลที่ `https://api.telegram.org/bot<TOKEN>/getWebhookInfo`
ต้องได้ `"pending_update_count": 0` และไม่มี `last_error_message`

> `TELEGRAM_WEBHOOK_SECRET` คือสิ่งเดียวที่พิสูจน์ว่า request มาจาก Telegram จริง
> เส้นทาง `/telegram/webhook` ไม่มี session และถูกยกเว้น CSRF ห้ามเผยแพร่ค่านี้

### 5. ให้ผู้ใช้ผูกบัญชี

เข้า `/settings` › การ์ด **Telegram** › ปุ่ม "เชื่อมต่อ Telegram" › กดลิงก์ที่ขึ้นมา ›
กด **Start** ในแอป Telegram › หน้าเว็บจะอัปเดตสถานะเอง

รหัสเชื่อมต่อใช้ได้ครั้งเดียวและหมดอายุใน 10 นาที
ในแชทพิมพ์ `/stop` เพื่อหยุดรับ `/start` เพื่อกลับมารับ และ `/status` เพื่อดูสถานะ

---

## ถ้ามีการเพิ่ม/ลบ Composer package

ชุดอัปไม่มี `vendor/` เมื่อ `composer.lock` เปลี่ยน ต้องอัป `vendor/` แยกต่างหาก
ก่อนอัปโค้ดรุ่นใหม่ มิฉะนั้นจะเกิด "Class not found"

Deploy รอบ 3 (2026-09-09): `composer.json` และ `composer.lock` **ไม่เปลี่ยน**
จึงไม่ต้องแตะ `vendor/` — ตรวจซ้ำได้ด้วย `git diff --stat HEAD -- composer.lock` ต้องว่าง

---

## งานตามเวลา (cron) — ตั้งครั้งเดียวจบ

ระบบมีงานที่ต้องทำเองทุกวัน: ล้างถังขยะที่ครบ 30 วัน ล้างบันทึกกิจกรรมที่พ้นอายุ
สร้างรายการงานประจำของวัน และแจ้งเตือนงานที่ถึงกำหนด ทั้งหมดทำงานได้ก็ต่อเมื่อ
เซิร์ฟเวอร์เรียก `php artisan schedule:run` ให้ **ทุกนาที**

DirectAdmin: เมนู **Advanced Features → Cron Jobs** ตั้งเป็นทุกนาที
(`*` ทั้งห้าช่อง) แล้วใส่คำสั่ง

```
cd /home/USER/domains/DOMAIN/laravel && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

แก้ `USER`, `DOMAIN` และ path ของ PHP ให้ตรงกับเครื่องจริง (ดู path ของ PHP ได้จาก
`which php` บน SSH หรือจากหน้า PHP Selector ของ DirectAdmin)

**ถ้าตั้ง cron ไม่ได้ ระบบยังใช้งานได้ครบ** ทุกการล้างมีปุ่มกดในหน้า Audit Log ฝั่ง
ผู้ดูแลระบบ:

| งาน | ปุ่มในหน้าเว็บ |
|---|---|
| ล้างถังขยะที่ครบกำหนด | แท็บถังขยะ → "ล้างของหมดอายุ (N)" |
| ล้างบันทึกกิจกรรมเก่า | แท็บกิจกรรมผู้ใช้ → "ล้างบันทึกเก่า (N)" |
| ลบ/กู้คืนหลายรายการ | แท็บถังขยะ → ติ๊กเลือกแล้วใช้แถบด้านล่าง |

วิธีตรวจว่าตั้งสำเร็จหรือยัง: เปิด `/admin/audit` แท็บภาพรวม แถบบนสุดจะบอกเอง
ภายในไม่เกิน 5 นาที — ขึ้นสีเขียวคือ cron ทำงานแล้ว ขึ้นสีส้มคือยังไม่ทำงานและต้อง
กดล้างเอง

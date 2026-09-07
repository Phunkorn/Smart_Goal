import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

/* ไฟล์ PHP ในโปรเจกต์นี้ใช้ LF ทั้งหมด ชุดที่สร้างจึงต้องไม่ปน CRLF ของ Windows */
const EOL = String.fromCharCode(10);

const deployDirectory = dirname(fileURLToPath(import.meta.url));
const projectDirectory = resolve(deployDirectory, '..');
const outputDirectory = join(projectDirectory, 'deploy-output');
const timestamp = new Date()
    .toISOString()
    .replace(/[-:]/g, '')
    .replace('T', '-')
    .slice(0, 13);
const archivePath = join(outputDirectory, `smart-goal-update-${timestamp}.zip`);
const stagingDirectory = join(tmpdir(), `.smart-goal-deploy-${process.pid}`);

const excludedRootNames = new Set([
    '.env',
    '.env.backup',
    '.env.example',
    '.env.production',
    '.claude',
    '.codex',
    '.git',
    '.github',
    '.idea',
    '.nova',
    '.phpunit.cache',
    '.phpunit.result.cache',
    '.vscode',
    '.zed',
    'AGENTS.md',
    'CLAUDE.md',
    'auth.json',
    'deploy-output',
    'node_modules',
    'phpunit.xml',
    'storage',
    'tests',
    'vendor',
]);

function run(command, args) {
    const result = spawnSync(command, args, {
        cwd: projectDirectory,
        stdio: 'inherit',
        shell: false,
    });

    if (result.error) {
        throw result.error;
    }

    if (result.status !== 0) {
        throw new Error(`${command} exited with code ${result.status}`);
    }
}

function shouldCopy(source) {
    const pathFromRoot = relative(projectDirectory, source);
    const parts = pathFromRoot.split(sep);
    const rootName = parts[0];

    if (excludedRootNames.has(rootName)) {
        return false;
    }

    if (pathFromRoot === join('bootstrap', 'private-path.php')) {
        return false;
    }

    if (pathFromRoot === join('public', 'storage')) {
        return false;
    }

    // ฐานข้อมูล SQLite ของเครื่องพัฒนา เซิร์ฟเวอร์ใช้ MySQL และไฟล์นี้มีข้อมูลทดสอบอยู่ข้างใน
    if (pathFromRoot === join('database', 'database.sqlite')) {
        return false;
    }

    if (rootName === 'public' && /^storage_backup(?:_|$)/.test(parts[1] ?? '')) {
        return false;
    }

    if (rootName === 'bootstrap' && parts[1] === 'cache' && parts.length > 2) {
        return false;
    }

    return true;
}

/**
 * เขียน bootstrap/app.php ฉบับของเซิร์ฟเวอร์ทับลงในชุดที่จะอัป
 *
 * บนเซิร์ฟเวอร์ .env กับ storage ถูกย้ายออกไปนอก public_html (โฟลเดอร์ Smart-Private)
 * ไฟล์ app.php ที่นั่นจึงมีโค้ดสามบรรทัดเพิ่มมาเพื่อชี้ path กลับเข้าไป ซึ่งชุดพัฒนาไม่มี
 *
 * ถ้าคัดลอกไฟล์ของเครื่องพัฒนาขึ้นไปตรง ๆ การแตกไฟล์แบบเขียนทับจะลบโค้ดส่วนนั้นทิ้ง
 * เว็บจะล่มทันทีด้วยข้อความอย่าง "No application encryption key has been specified"
 * ซึ่งไม่ได้บอกเลยว่าต้นเหตุคือ app.php ถูกทับ
 *
 * แปลงตอนสร้างชุดแทนการเก็บไฟล์สองเวอร์ชัน เพื่อให้ยังมีแหล่งความจริงเดียวคือชุดพัฒนา
 * การแก้ middleware หรือ exception handler ในอนาคตจึงไหลขึ้นเซิร์ฟเวอร์เองโดยไม่ต้องจำ
 */
function writeServerBootstrap(root) {
    const target = join(root, 'bootstrap', 'app.php');
    const source = readFileSync(target, 'utf8');

    const anchor = 'return Application::configure(basePath: dirname(__DIR__))';
    const closing = '})->create();';

    // ล้มทันทีถ้าโครงไฟล์เปลี่ยนไป ดีกว่าปล่อยชุดที่ขาดโค้ดชี้ path ขึ้นเซิร์ฟเวอร์เงียบ ๆ
    if (!source.includes(anchor) || !source.includes(closing)) {
        throw new Error(
            'bootstrap/app.php ไม่ตรงกับรูปแบบที่คาดไว้ จึงเติมโค้ดชี้ Smart-Private ไม่ได้ '
            + '— ตรวจสอบ deploy/build-update.mjs ก่อนนำชุดนี้ขึ้นเซิร์ฟเวอร์'
        );
    }

    const preamble = [
        '/*',
        ' * ---------------------------------------------------------------------------',
        ' * ส่วนนี้ถูกเติมโดย deploy/build-update.mjs ตอนสร้างชุดอัป ไม่มีในโค้ดชุดพัฒนา',
        ' * ---------------------------------------------------------------------------',
        ' *',
        ' * .env และ storage อยู่นอก public_html (โฟลเดอร์ Smart-Private) เพราะ DirectAdmin',
        ' * ชี้ซับโดเมนมาที่ public_html ทุกอย่างในนั้นจึงเสี่ยงถูกเปิดผ่านเว็บ',
        ' *',
        ' * ต้องตั้งค่าก่อน Laravel bootstrap มิฉะนั้น .env จะถูกอ่านจากที่เดิมไปแล้ว',
        ' * ที่อยู่จริงถูกกำหนดไว้ที่ bootstrap/private-path.php ซึ่งเป็นไฟล์ของเซิร์ฟเวอร์',
        ' * และไม่เคยอยู่ในชุดอัป จึงไม่ถูกเขียนทับ',
        ' */',
        "$privatePath = require __DIR__.'/private-path.php';",
        '',
        '$app = Application::configure(basePath: dirname(__DIR__))',
    ].join(EOL);

    const suffix = [
        '',
        '',
        '$app->useEnvironmentPath($privatePath);',
        "$app->useStoragePath($privatePath.DIRECTORY_SEPARATOR.'storage');",
        '',
        'return $app;',
    ].join(EOL);

    const patched = source
        .replace(anchor, preamble)
        .replace(closing, closing + suffix);

    writeFileSync(target, patched, 'utf8');
}

function assertArchiveContents(root) {
    const required = [
        'artisan',
        'composer.json',
        'composer.lock',
        join('bootstrap', 'app.php'),
        join('public', 'index.php'),
        join('public', 'build', 'manifest.json'),
    ];

    for (const requiredPath of required) {
        if (!existsSync(join(root, requiredPath))) {
            throw new Error(`Deploy package is missing: ${requiredPath}`);
        }
    }

    // ชุดอัปต้องไม่มีไฟล์ที่เป็นของเซิร์ฟเวอร์เท่านั้น มิฉะนั้นการแตกทับจะไปลบของจริง
    for (const forbidden of [join('bootstrap', 'private-path.php'), '.env', join('database', 'database.sqlite')]) {
        if (existsSync(join(root, forbidden))) {
            throw new Error(`ชุดอัปต้องไม่มีไฟล์นี้: ${forbidden}`);
        }
    }

    // และ app.php ที่อยู่ในชุดต้องเป็นฉบับที่ชี้ไป Smart-Private แล้วเท่านั้น
    const bootstrap = readFileSync(join(root, 'bootstrap', 'app.php'), 'utf8');

    if (!bootstrap.includes('useEnvironmentPath') || !bootstrap.includes('useStoragePath')) {
        throw new Error('bootstrap/app.php ในชุดอัปยังไม่ได้ชี้ไป Smart-Private');
    }
}

try {
    console.log('Building CSS and JavaScript...');
    run('cmd.exe', ['/d', '/s', '/c', 'npm.cmd run build']);

    console.log('Preparing deploy files...');
    mkdirSync(outputDirectory, { recursive: true });
    mkdirSync(stagingDirectory, { recursive: true });

    for (const entry of readdirSync(projectDirectory)) {
        const source = join(projectDirectory, entry);

        if (!shouldCopy(source)) {
            continue;
        }

        cpSync(source, join(stagingDirectory, entry), {
            recursive: statSync(source).isDirectory(),
            filter: shouldCopy,
        });
    }

    writeServerBootstrap(stagingDirectory);
    assertArchiveContents(stagingDirectory);

    if (existsSync(archivePath)) {
        rmSync(archivePath, { force: true });
    }

    console.log('Creating ZIP archive...');

    /*
     * ระบุชื่อระดับบนสุดทีละตัว ไม่ใช้ '.'
     *
     * การส่ง '.' ทำให้ทุก entry ในไฟล์ขึ้นต้นด้วย './' และมี entry แรกเป็น './' เปล่า ๆ
     * ซึ่งหมายถึง "ตัวโฟลเดอร์ปลายทางเอง" ตัวแตกไฟล์ของ DirectAdmin แปลงค่านั้นแล้ว
     * พยายามเขียนไฟล์ทับ public_html ซึ่งเป็นโฟลเดอร์ จึงล้มด้วยข้อความ
     * "An error occurred while attempting to copy the file ... invalid argument"
     * โดยไม่ได้บอกเลยว่าปัญหาอยู่ที่รูปแบบชื่อ entry
     *
     * ระบุชื่อจริงแทน จะได้ entry เป็น app/ artisan config/ ... ตรง ๆ ซึ่งตัวแตกไฟล์
     * ทุกตัวรวมถึงของ DirectAdmin เข้าใจตรงกัน
     */
    const topLevelEntries = readdirSync(stagingDirectory);

    if (topLevelEntries.length === 0) {
        throw new Error('ไม่มีไฟล์ให้ใส่ในชุดอัป');
    }

    run('tar.exe', ['-a', '-c', '-f', archivePath, '-C', stagingDirectory, ...topLevelEntries]);

    console.log(`${EOL}สร้างชุดอัปแล้ว:${EOL}${archivePath}`);
    console.log('');
    console.log('ขั้นตอนต่อไป (รายละเอียดใน deploy/README.md):');
    console.log('  1. สำรองฐานข้อมูลและโค้ดเดิมไว้ก่อน');
    console.log('  2. ถ้ารอบนี้มี migration ใหม่ ให้รันไฟล์ SQL ใน phpMyAdmin ก่อนอัปไฟล์');
    console.log('  3. อัป ZIP เข้า public_html แล้ว Extract แบบเขียนทับ');
    console.log('  4. ลบไฟล์ .php ใน public_html/bootstrap/cache/');
    console.log('     และใน Smart-Private/storage/framework/views/');
} finally {
    rmSync(stagingDirectory, { recursive: true, force: true });
}

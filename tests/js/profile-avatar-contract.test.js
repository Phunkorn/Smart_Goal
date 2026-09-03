import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * ทุกจุดที่วาดวงกลมโปรไฟล์ต้องผ่าน partial เดียวกัน
 * เทสต์นี้จับกรณีที่มีคนเผลอเขียนตัวย่อชื่อตรง ๆ กลับเข้ามาใหม่
 * ซึ่งเป็นสาเหตุเดิมที่ทำให้หลายหน้าไม่เคยแสดงรูปจริงเลย
 */
const AVATAR_VIEWS = [
    'resources/views/tasks/partials/project-board-card.blade.php',
    'resources/views/tasks/partials/table-kanban.blade.php',
    'resources/views/tasks/partials/notion-task-row.blade.php',
    'resources/views/meetings/components/attendee-list.blade.php',
    'resources/views/board/components/admin-assignment-modal.blade.php',
];

test('the shared avatar partial prefers the uploaded photo over initials', () => {
    const partial = read('resources/views/components/user-avatar-content.blade.php');

    assert.match(partial, /@if\(\$user\?->profile_image\)/);
    assert.match(partial, /route\('media\.profile', \$user\)/);
    assert.match(partial, /\$avatarInitial/);
    // พารามิเตอร์ต้องตั้งชื่อกันชนกับตัวแปรของ view แม่
    assert.doesNotMatch(partial, /\$length \?\?|\$upper \?\?/);
});

test('no avatar circle renders a bare initial without checking for a real photo', () => {
    for (const path of AVATAR_VIEWS) {
        const source = read(path);
        const bareInitials = source.match(/(Str::substr|mb_substr)\(\$(person|employee|task->user|assigneeName)[^)]*\)/g) ?? [];

        assert.deepEqual(
            bareInitials.filter((match) => ! match.includes('job_topic')),
            [],
            `${path} ยังตัดตัวอักษรจากชื่อเองแทนที่จะใช้ components.user-avatar-content`,
        );
    }
});

test('every avatar circle that can hold a photo crops it to fill the circle', () => {
    const cases = [
        ['resources/css/components/task-workspace/kanban.css', /\.mytasks-kanban__avatar img\s*\{[^}]*object-fit:\s*cover/s],
        ['resources/css/pages/mytasks/project-board.css', /\.board-collaborators button > i img\s*\{[^}]*object-fit:\s*cover/s],
        ['resources/css/pages/meetings/index.css', /\.meetings-page__attendee i img\s*\{[^}]*object-fit:\s*cover/s],
        ['resources/css/pages/board/modal.css', /\.avatar-mini img\s*\{[^}]*object-fit:\s*cover/s],
    ];

    for (const [path, pattern] of cases) {
        assert.match(read(path), pattern, `${path} ไม่ได้ครอบรูปให้เต็มวงกลม`);
    }
});

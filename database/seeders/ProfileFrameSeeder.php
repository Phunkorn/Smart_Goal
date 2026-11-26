<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProfileFrameSeeder extends Seeder
{
    // Existing slugs are stored on users. Keep them stable across deployments.
    private const FRAMES = [
        ['sapphire', 'แซฟไฟร์'],
        ['gold', 'ทอง'],
        ['emerald', 'มรกต'],
        ['rose', 'โรส'],
        ['violet', 'ไวโอเล็ต'],
        ['aurora', 'ออโรรา', true],
        ['pulse', 'พัลส์', true],
        ['rainbow-cloud', 'เมฆสายรุ้ง', false, 'static'],
        ['galaxy-voyager', 'นักเดินทางกาแล็กซี', false, 'static'],
        ['forest-guardian', 'ผู้พิทักษ์พงไพร', false, 'static'],
        ['sweetheart', 'หัวใจแสนหวาน', false, 'static'],
        ['royal-crown', 'มงกุฎราชวงศ์', false, 'static'],
        ['star-medal', 'เหรียญดาวเกียรติยศ', false, 'static'],
        ['ocean-tide', 'คลื่นมหาสมุทร', false, 'static'],
        ['tech-armor', 'เกราะจักรกล', false, 'static'],
        ['flame-dragon', 'มังกรเพลิง', false, 'static'],
        ['comic-pop', 'คอมิกป๊อป', false, 'static'],
        ['star-orbit', 'วงโคจรแห่งดวงดาว', true, 'animated'],
        ['lightning-armor', 'เกราะสายฟ้า', true, 'animated'],
        ['golden-flare', 'ประกายทอง', true, 'animated'],
        ['cyber-neon', 'ไซเบอร์นีออน', true, 'animated'],
        ['space-cat', 'แมวอวกาศ', true, 'animated'],
        ['crystal-energy', 'พลังคริสตัล', true, 'animated'],
        ['enchanted-forest', 'ป่ามนตราเรืองแสง', true, 'animated', 'gif'],
        ['fantasy-ring', 'แฟนตาซีวงแหวนเรืองแสงสี่จังหวะ', true, 'animated', 'gif'],
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::FRAMES as $index => $frame) {
            [$slug, $name] = $frame;
            $animated = $frame[2] ?? false;
            $directory = $frame[3] ?? null;
            $path = $directory ? $directory.'/'.$slug : null;
            $extension = $frame[4] ?? 'webp';
            $rows[] = [
                'slug' => $slug,
                'name' => $name,
                'animated' => $animated,
                'asset_path' => $path ? $path.($animated ? '-motion' : '').'.'.$extension : null,
                'preview_path' => $path ? $path.'.webp' : null,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ];
        }

        DB::table('profile_frames')->insertOrIgnore($rows);
    }
}

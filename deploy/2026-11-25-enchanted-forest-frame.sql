-- Add only the Enchanted Forest animated frame to the existing profile_frames table.
-- Safe to repeat: existing slugs are preserved. No schema or migration ledger changes.
SET NAMES utf8mb4;

INSERT IGNORE INTO `profile_frames`
    (`slug`, `name`, `animated`, `asset_path`, `preview_path`, `sort_order`, `is_active`)
VALUES
    ('enchanted-forest', 'ป่ามนตราเรืองแสง', 1,
     'animated/enchanted-forest-motion.gif', 'animated/enchanted-forest.webp', 240, 1);

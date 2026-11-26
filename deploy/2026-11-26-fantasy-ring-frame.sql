-- Add only the Fantasy Ring animated frame to the existing profile_frames table.
-- Safe to repeat: existing slugs are preserved. No schema or migration ledger changes.
SET NAMES utf8mb4;

INSERT IGNORE INTO `profile_frames`
    (`slug`, `name`, `animated`, `asset_path`, `preview_path`, `sort_order`, `is_active`)
VALUES
    ('fantasy-ring', 'แฟนตาซีวงแหวนเรืองแสงสี่จังหวะ', 1,
     'animated/fantasy-ring-motion.gif', 'animated/fantasy-ring.webp', 250, 1);

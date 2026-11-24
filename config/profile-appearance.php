<?php

return [
    'cover' => [
        'max_kilobytes' => 4096,
        'max_dimension' => 8192,
        'mime_types' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
    // Presentation catalog; keys are persisted on users, without a separate lookup table.
    'frames' => [
        'sapphire' => ['label' => 'แซฟไฟร์', 'animated' => false],
        'gold' => ['label' => 'ทอง', 'animated' => false],
        'emerald' => ['label' => 'มรกต', 'animated' => false],
        'rose' => ['label' => 'โรส', 'animated' => false],
        'violet' => ['label' => 'ไวโอเล็ต', 'animated' => false],
        'aurora' => ['label' => 'ออโรรา', 'animated' => true],
        'pulse' => ['label' => 'พัลส์', 'animated' => true],
    ],
];

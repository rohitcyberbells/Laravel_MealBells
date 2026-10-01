<?php

return [
    'max_extra_meals' => 100,
    'advance_limit_days' => 60,
    'allowed_extra_types' => ['guest', 'visitor', 'other'],
    'canonical_skip_sources' => ['leave', 'wfh', 'hr', 'self', 'link', 'recurring'],
    'auto_skip_sources' => ['leave', 'wfh', 'recurring'],
    'manual_skip_sources' => ['hr', 'self'],
];

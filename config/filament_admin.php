<?php

return [
    'emails' => array_values(array_filter(array_map(
        static fn ($v) => strtolower(trim($v)),
        explode(',', env('FILAMENT_ADMIN_EMAILS', ''))
    ))),
];

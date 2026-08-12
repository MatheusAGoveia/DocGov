<?php

return [
    // Única identidade autorizada a usar a página externa de emergência.
    'controller_identity' => strtolower(trim((string)(getenv('MAINTENANCE_CONTROLLER_IDENTITY') ?: 'BETIM\\matheus.damiao'))),
    'session_ttl_seconds' => 600,
    'max_attempts_per_15_minutes' => 5,
];

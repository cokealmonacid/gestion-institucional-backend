<?php

return [
    'frontend_url' => env('USER_ACCESS_FRONTEND_URL', 'http://localhost:5173'),
    'invitation' => [
        'expire_minutes' => (int) env('USER_INVITATION_EXPIRE_MINUTES', 2880),
        'throttle_seconds' => (int) env('USER_INVITATION_THROTTLE_SECONDS', 60),
    ],
    'mail' => [
        'tries' => (int) env('USER_ACCESS_MAIL_TRIES', 3),
        'backoff_seconds' => (int) env('USER_ACCESS_MAIL_BACKOFF_SECONDS', 60),
    ],
];

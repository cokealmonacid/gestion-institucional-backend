<?php

return [
    // Version 0 starts without legacy institutions; environment flags remain available as a kill switch.
    'registration_enabled' => env('ONBOARDING_REGISTRATION_ENABLED', true),
    'institution_enforcement_enabled' => env('ONBOARDING_INSTITUTION_ENFORCEMENT_ENABLED', true),
    'token_hours' => 24,
    'verification_minutes' => 60,
    'resend_seconds' => 60,
    'resends_per_hour' => 5,
    'register_per_minute' => 5,
    'login_per_minute' => 5,
    'recovery_per_minute' => 5,
];

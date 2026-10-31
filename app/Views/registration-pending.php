<?php
$deliveryStatus = $deliveryStatus ?? 'not_configured';
$deliveryMessages = [
    'captured' => 'A verification message was captured by the in-memory development transport. It was not delivered to an inbox.',
    'uncertain' => 'The verification attempt could not be confirmed. Your account remains pending. A prepared key may still be valid; avoid repeating registration.',
    'failed' => 'The verification attempt could not be completed. Your account has been saved and remains pending. A prepared key may still be valid. Use your latest verification message if available, or request a new link.',
    'unavailable' => 'Verification is temporarily unavailable. Your account has been saved and remains pending. You can request a new link later.',
    'not_configured' => 'Verification uses a development capture only. Your account remains pending; real email delivery is not configured.',
];
$deliveryMessage = $deliveryMessages[$deliveryStatus] ?? $deliveryMessages['not_configured'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Account pending verification · GigHub</title><link rel="stylesheet" href="assets/registration.css?v=4"><link rel="stylesheet" href="assets/verification.css"></head>
<body><header class="site-header"><a class="brand" href="?route=/">GigHub</a></header><main class="verification-layout"><section class="card verification-card"><p class="eyebrow">REGISTRATION RECEIVED</p><h1>Your account is pending verification.</h1><p>Your account has not been activated, and you are not logged in.</p><div class="notice"><strong>No verification email has been sent.</strong><p><?= htmlspecialchars($deliveryMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><p>No external email is sent during this development phase.</p></div><p>Already have a verification message? Open its link and choose Confirm Verification.</p><p><a href="?route=/verify-email">Continue to verification →</a></p><p><a href="?route=/verify-email/resend">Request a new verification link</a></p><p><a href="?route=/">Return to home</a></p></section></main></body></html>

<?php
$deliveryStatus = $deliveryStatus ?? 'not_configured';
$deliveryMessages = [
    'accepted' => 'The mail server accepted your verification message. Check your inbox and spam folder. Acceptance does not guarantee delivery.',
    'captured' => 'A verification message was captured by the in-memory development transport. It was not delivered to an inbox.',
    'uncertain' => 'The verification attempt could not be confirmed. Your account remains pending. A prepared key may still be valid; avoid repeating registration.',
    'failed' => 'The verification attempt could not be completed. Your account has been saved and remains pending. A prepared key may still be valid. Use your latest verification message if available, or request a new link.',
    'unavailable' => 'Verification is temporarily unavailable. Your account has been saved and remains pending. You can request a new link later.',
    'not_configured' => 'Your account remains pending. Email delivery is not configured. You can request a new link once delivery is available.',
];
$deliveryMessage = $deliveryMessages[$deliveryStatus] ?? $deliveryMessages['not_configured'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Account pending verification · GigHub</title><link rel="stylesheet" href="assets/registration.css?v=4"><link rel="stylesheet" href="assets/verification.css"><?php require __DIR__ . '/partials/page-shell.php'; ?></head>
<body class="gh-shell gh-integrated"><?php require __DIR__ . '/partials/header.php'; ?><main id="main-content" tabindex="-1" class="verification-layout"><section class="card verification-card"><p class="eyebrow">REGISTRATION RECEIVED</p><h1>Your account is pending verification.</h1><p>Your account has not been activated, and you are not logged in.</p><div class="notice"><strong><?= in_array($deliveryStatus, ['captured', 'not_configured'], true) ? 'No verification email has been sent.' : 'Verification delivery status' ?></strong><p><?= htmlspecialchars($deliveryMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p></div><p>Already have a verification message? Open its link and choose Confirm Verification.</p><p><a href="?route=/verify-email">Continue to verification →</a></p><p><a href="?route=/verify-email/resend">Request a new verification link</a></p><p><a href="?route=/">Return to home</a></p></section></main><?php require __DIR__ . '/partials/footer.php'; ?></body></html>

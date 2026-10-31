<?php
$titles = ['confirm' => 'Confirm your email', 'resend' => 'Request a new verification link', 'verified' => 'Your email is verified.', 'invalid' => 'This verification link cannot be used.', 'resent' => 'Verification request received.', 'unavailable' => 'Verification is temporarily unavailable.'];
$title = $titles[$mode];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title><?= $errors !== [] ? 'Verification needs attention' : $escape($title) ?> · GigHub</title><link rel="stylesheet" href="assets/registration.css?v=4"><link rel="stylesheet" href="assets/verification.css"><script src="assets/verification.js" defer></script></head>
<body><header class="site-header"><a class="brand" href="?route=/">GigHub<span>Find your next possibility.</span></a><a href="?route=/">Back to home</a></header>
<main class="verification-layout"><section class="card verification-card" aria-labelledby="verification-title">
<svg class="verification-mark" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/></svg>
<p class="eyebrow">EMAIL VERIFICATION</p><h1 id="verification-title"><?= $escape($title) ?></h1>
<?php if ($errors !== []): ?><div class="error-summary" id="error-summary" tabindex="-1" role="alert"><strong>Please check your verification request.</strong><?php foreach ($errors as $field => $message): ?><p><?php if ($field === 'email'): ?><a href="#email"><?= $escape($message) ?></a><?php else: ?><?= $escape($message) ?><?php endif; ?></p><?php endforeach; ?></div><?php endif; ?>
<?php if ($mode === 'confirm'): ?>
<p id="verification-link-status" role="status">Use the link from your verification message, then choose Confirm Verification. Opening this page alone does not activate your account.</p>
<form id="verification-form" method="post" action="?route=/verify-email"><input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
<button type="submit">Confirm Verification <span aria-hidden="true">→</span></button>
<details id="verification-fallback"><summary>Having trouble with the link? Paste a verification key</summary><p class="hint" id="verification-key-help">Copy the full verification key from your message. This is the part after <strong>#verification=</strong> in the link.</p>
<noscript><p>JavaScript is off, so your link cannot fill this form. Open this section, paste the verification key from your message, then choose Confirm Verification. You can also request a new link below.</p></noscript>
<label for="verification-key">Verification key</label><input id="verification-key" name="verification_key" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="75" aria-describedby="verification-key-help"></details>
</form><p class="recovery"><a href="?route=/verify-email/resend">Request a new verification link</a></p>
<?php elseif ($mode === 'resend'): ?>
<p>Enter the email address you used to register. If the account is eligible, a new verification request can be prepared. Please allow at least a minute between requests.</p>
<form method="post" action="?route=/verify-email/resend"><input type="hidden" name="csrf_token" value="<?= $escape($token) ?>"><label for="email">Email Address <span>(required)</span></label><input id="email" type="email" name="email" autocomplete="email" maxlength="254" required value="<?= $escape($oldEmail) ?>"<?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="email-error"' : '' ?>><?php if (isset($errors['email'])): ?><p class="field-error" id="email-error"><?= $escape($errors['email']) ?></p><?php endif; ?><button type="submit">Request verification link <span aria-hidden="true">→</span></button></form>
<p><a href="?route=/verify-email">Already have a verification key?</a></p>
<?php elseif ($mode === 'verified'): ?>
<p>Your account is now active. You are not logged in. Sign-in will be available in a future module.</p><p><a href="?route=/">Return to home →</a></p>
<?php elseif ($mode === 'unavailable'): ?>
<p>Your verification attempt could not be completed right now. Your key may still be valid. Please try again later by reopening your original verification message or pasting its key below.</p><p class="recovery"><a href="?route=/verify-email">Retry verification or paste your key →</a></p>
<?php elseif ($mode === 'invalid'): ?>
<p>The link may have expired, already been used, or been replaced by a newer request. Use your latest message or request a new link.</p><p class="recovery"><a href="?route=/verify-email/resend">Request a new verification link →</a></p><p><a href="?route=/verify-email">Paste a verification key</a></p>
<?php else: ?>
<p>If the email belongs to an eligible pending account and request limits allow it, a verification request has been prepared. Use the latest verification message when available.</p><p><a href="?route=/verify-email">Continue to verification</a></p><p><a href="?route=/verify-email/resend">Request another link later</a></p>
<?php endif; ?>
<aside class="preview-notice"><strong>Development preview — no external email is sent.</strong><p>Verification messages use an in-memory development capture only. This page does not confirm that a message was delivered. A request made here does not expose a verification key.</p></aside>
</section></main></body></html>

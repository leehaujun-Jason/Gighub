<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose an account role · GigHub</title>
<link rel="stylesheet" href="assets/registration.css?v=5">
<?php require __DIR__ . '/partials/page-shell.php'; ?>
</head>
<body class="gh-shell gh-integrated">
<?php require __DIR__ . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="registration-role-layout">
    <h1>Choose how to join GigHub</h1>
    <?php if ($errors !== []): ?>
        <div class="error-summary" role="alert">
            <strong>Registration needs attention</strong>
            <?php foreach ($errors as $message): ?><p><?= $escape($message) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="registration-role-options">
        <a class="registration-role-card" href="?route=/register/freelancer">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3" y="7" width="18" height="13" rx="2"/>
                <path d="M8 7V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M3 12h18m-11 0v2h4v-2"/>
            </svg>
            <strong>Freelancer</strong>
            <span>Find jobs that match your skills and send proposals to Clients.</span>
        </a>
        <a class="registration-role-card" href="?route=/register/client">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="4" y="4" width="16" height="16" rx="2"/>
                <path d="M8 9h8m-8 4h8m-8 4h4"/>
            </svg>
            <strong>Client</strong>
            <span>Post a job and review proposals from Freelancers.</span>
        </a>
    </div>
    <p>Already have an account? <a href="?route=/login">Log in</a></p>
    <p>Already registered but waiting to verify? <a href="?route=/verify-email/resend">Resend verification email</a></p>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>

<?php
// View-only partial. Callers supply a validated navigationUser, logoutToken and escaping function.
$navigationLinks = [['/jobs', 'Browse Jobs']];
if ($navigationUser === null) {
    $navigationLinks[] = ['/login', 'Sign in'];
    $navigationLinks[] = ['/register', 'Create an account'];
} else {
    if ($navigationUser['role'] === 'client') {
        $navigationLinks[] = ['/client/jobs', 'My Jobs'];
        $navigationLinks[] = ['/client/jobs/create', 'Post a Job'];
        $navigationLinks[] = ['/client/wallet', 'Wallet'];
    }
    if ($navigationUser['role'] === 'freelancer') {
        $navigationLinks[] = ['/freelancer/bids', 'My Bids'];
    }
    $workspace = match ($navigationUser['role']) {
        'client' => ['/client', 'My Workspace'],
        'freelancer' => ['/freelancer', 'My Workspace'],
        'admin' => ['/admin', 'Admin Workspace'],
    };
    $navigationLinks[] = $workspace;
}
?>
<a class="gh-skip-link" href="#main-content">Skip to main content</a>
<header class="gh-header">
    <div class="gh-header-inner">
        <a class="gh-brand" href="?route=/">GigHub</a>
        <nav class="gh-navigation gh-desktop-nav" aria-label="Main navigation">
            <?php require __DIR__ . '/navigation-links.php'; ?>
        </nav>
        <details class="gh-mobile-menu">
            <summary>Menu</summary>
            <nav class="gh-navigation" aria-label="Main navigation">
                <?php require __DIR__ . '/navigation-links.php'; ?>
            </nav>
        </details>
        <?php if ($navigationUser !== null): ?>
            <?php $accountName = trim($navigationUser['display_name']); $initial = $accountName === '' ? '?' : mb_strtoupper(mb_substr($accountName, 0, 1, 'UTF-8'), 'UTF-8'); ?>
            <details class="gh-account-menu">
                <summary aria-label="Account menu"><span class="gh-avatar" aria-hidden="true"><?= $escape($initial) ?></span></summary>
                <div class="gh-account-panel"><p><?= $escape($navigationUser['display_name']) ?></p>
                    <?php if ($navigationUser['role']==='client'): ?><a class="gh-nav-link" href="?route=/client/wallet">My Wallet</a><?php endif; ?>
                    <?php if ($navigationUser['role']==='freelancer'): ?><a class="gh-nav-link" href="?route=/freelancer/profile">My Profile</a><?php endif; ?>
                    <form method="post" action="?route=/logout" class="gh-logout"><input type="hidden" name="csrf_token" value="<?= $escape($logoutToken) ?>"><button type="submit" class="gh-button gh-button-secondary">Sign out</button></form>
                </div>
            </details>
        <?php endif; ?>
    </div>
</header>

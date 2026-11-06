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
        $navigationLinks[] = ['/freelancer/wallet', 'Wallet'];
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
                <div class="gh-account-panel">
                    <div class="gh-account-header">
                        <div class="gh-account-name-header"><?= $escape($navigationUser['display_name']) ?></div>
                        <div class="gh-account-role-badge"><?= $escape(ucfirst($navigationUser['role'])) ?></div>
                    </div>
                    <hr class="gh-account-divider">
                    <?php if ($navigationUser['role'] === 'freelancer'): ?>
                        <a class="gh-account-item" href="?route=/freelancer/profile">
                            <svg class="gh-account-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <span>My Profile</span>
                        </a>
                        <a class="gh-account-item" href="?route=/freelancer/wallet">
                            <svg class="gh-account-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"></path><path d="M16 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path></svg>
                            <span>My Wallet</span>
                        </a>
                    <?php elseif ($navigationUser['role'] === 'client'): ?>
                        <a class="gh-account-item" href="?route=/client/jobs">
                            <svg class="gh-account-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            <span>My Jobs</span>
                        </a>
                        <a class="gh-account-item" href="?route=/client/wallet">
                            <svg class="gh-account-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"></path><path d="M16 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path></svg>
                            <span>My Wallet</span>
                        </a>
                    <?php elseif ($navigationUser['role'] === 'admin'): ?>
                        <a class="gh-account-item" href="?route=/admin">
                            <svg class="gh-account-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            <span>Admin Workspace</span>
                        </a>
                    <?php endif; ?>
                    <hr class="gh-account-divider">
                    <form method="post" action="?route=/logout" class="gh-logout">
                        <input type="hidden" name="csrf_token" value="<?= $escape($logoutToken) ?>">
                        <button type="submit" class="gh-account-item gh-account-signout">
                            <svg class="gh-account-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                            <span>Sign out</span>
                        </button>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </div>
</header>
<?php
$navJsFile = dirname(__DIR__, 2) . '/public/assets/navigation.js';
$navJsVersion = is_file($navJsFile) ? substr(hash_file('sha256', $navJsFile), 0, 12) : '1';
?>
<script src="assets/navigation.js?v=<?= $navJsVersion ?>" defer></script>
<?php require __DIR__ . '/notifications.php'; ?>

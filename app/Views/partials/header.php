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

$currentRoute = isset($_GET['route']) && is_string($_GET['route']) ? trim($_GET['route']) : '/';
if ($currentRoute === '') {
    $currentRoute = '/';
}
$activeNavRoute = null;
foreach ($navigationLinks as [$route, $label]) {
    if ($currentRoute === $route) {
        $activeNavRoute = $route;
        break;
    }
}
if ($activeNavRoute === null) {
    $availableRoutes = array_column($navigationLinks, 0);
    $parentCandidate = null;
    if (str_starts_with($currentRoute, '/client/jobs/')) {
        $parentCandidate = '/client/jobs';
    } elseif (str_starts_with($currentRoute, '/freelancer/bids/')) {
        $parentCandidate = '/freelancer/bids';
    } elseif (str_starts_with($currentRoute, '/jobs/')) {
        $parentCandidate = '/jobs';
    } elseif (str_starts_with($currentRoute, '/client/wallet/')) {
        $parentCandidate = '/client/wallet';
    } elseif (str_starts_with($currentRoute, '/freelancer/wallet/')) {
        $parentCandidate = '/freelancer/wallet';
    } elseif (str_starts_with($currentRoute, '/admin/')) {
        $parentCandidate = '/admin';
    }
    if ($parentCandidate !== null && in_array($parentCandidate, $availableRoutes, true)) {
        $activeNavRoute = $parentCandidate;
    }
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
            <?php $accountName = trim($navigationUser['display_name']); ?>
            <div class="gh-header-actions">
                <?php
                $unreadBadgeCount = isset($unreadNotificationCount) && is_int($unreadNotificationCount) ? $unreadNotificationCount : null;
                $hasUnread = $unreadBadgeCount !== null && $unreadBadgeCount > 0;
                $bellLabel = $hasUnread ? 'Notifications (' . $unreadBadgeCount . ' unread)' : 'Notifications';
                $badgeText = $hasUnread ? ($unreadBadgeCount > 99 ? '99+' : (string)$unreadBadgeCount) : '';
                ?>
                <a class="gh-bell-btn" href="?route=/notifications" aria-label="<?= $escape($bellLabel) ?>" id="gh-bell-btn">
                    <svg class="gh-bell-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span class="gh-bell-badge<?= $hasUnread ? ' gh-badge-active' : '' ?>" id="gh-bell-badge" aria-hidden="true" style="<?= $hasUnread ? 'display:inline-block;' : 'display:none;' ?>"><?= $escape($badgeText) ?></span>
                </a>
                <a class="gh-bell-btn gh-messages-btn" href="?route=/messages" aria-label="Messages" id="gh-messages-btn">
                    <svg class="gh-messages-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <span class="gh-bell-badge gh-messages-badge" id="gh-messages-badge" aria-hidden="true" style="display:none;"></span>
                </a>
                <details class="gh-account-menu">
                <summary aria-label="Account menu"><?php $avatarUser = $navigationUser; require __DIR__ . '/avatar.php'; ?></summary>
                <div class="gh-account-panel">
                    <div class="gh-account-header">
                        <div class="gh-account-name-header"><?= $escape($navigationUser['display_name']) ?></div>
                        <div class="gh-account-role-badge"><?= $escape(ucfirst($navigationUser['role'])) ?></div>
                    </div>
                    <hr class="gh-account-divider">
                    <?php if ($navigationUser['role'] === 'freelancer'): ?>
                        <a class="gh-account-item gh-account-workspace" href="?route=/freelancer">My Workspace</a>
                        <a class="gh-account-item gh-account-profile" href="?route=/freelancer/profile">My Profile</a>
                        <a class="gh-account-item gh-account-messages" href="?route=/messages">Messages</a>
                        <a class="gh-account-item gh-account-wallet" href="?route=/freelancer/wallet">My Wallet</a>
                    <?php elseif ($navigationUser['role'] === 'client'): ?>
                        <a class="gh-account-item gh-account-profile" href="?route=/client/profile">My Profile</a>
                        <a class="gh-account-item gh-account-workspace" href="?route=/client">My Workspace</a>
                        <a class="gh-account-item gh-account-jobs" href="?route=/client/jobs">My Jobs</a>
                        <a class="gh-account-item gh-account-messages" href="?route=/messages">Messages</a>
                        <a class="gh-account-item gh-account-wallet" href="?route=/client/wallet">My Wallet</a>
                    <?php elseif ($navigationUser['role'] === 'admin'): ?>
                        <a class="gh-account-item gh-account-admin" href="?route=/admin">Admin Workspace</a>
                    <?php endif; ?>
                    <hr class="gh-account-divider">
                    <form method="post" action="?route=/logout" class="gh-logout">
                        <input type="hidden" name="csrf_token" value="<?= $escape($logoutToken) ?>">
                        <button type="submit" class="gh-account-item gh-account-signout">Sign out</button>
                    </form>
                </div>
            </details>
            </div>
        <?php endif; ?>
    </div>
</header>
<?php
$navJsFile = dirname(__DIR__, 2) . '/public/assets/navigation.js';
$navJsVersion = is_file($navJsFile) ? substr(hash_file('sha256', $navJsFile), 0, 12) : '1';
?>
<script src="assets/navigation.js?v=<?= $navJsVersion ?>" defer></script>
<?php require __DIR__ . '/notifications.php'; ?>

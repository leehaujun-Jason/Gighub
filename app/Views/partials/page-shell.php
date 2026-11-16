<?php
// Presentation defaults only; protected controllers supply their already-validated identity.
$navigationUser = $navigationUser ?? null;
$logoutToken = $navigationUser === null ? null : \GigHub\Core\Csrf::token('logout');
$escape = $escape ?? static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetVersion = $assetVersion ?? static fn(string $sheet): string => is_file(dirname(__DIR__, 3) . '/public/assets/' . $sheet) ? substr(hash_file('sha256', dirname(__DIR__, 3) . '/public/assets/' . $sheet), 0, 12) : '1';
?>
<?php foreach (['design-tokens.css','components.css','shared-shell.css','integrated-pages.css'] as $sheet): ?>
<link rel="stylesheet" href="assets/<?= $sheet ?>?v=<?= $assetVersion($sheet) ?>">
<?php endforeach; ?>
<script src="assets/investigation-unread.js?v=<?= $assetVersion('investigation-unread.js') ?>" defer></script>

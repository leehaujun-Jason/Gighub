<?php
// Shared dismissible notifications partial.
use GigHub\Core\Notification;

if (!class_exists(Notification::class)) {
    require_once dirname(__DIR__, 2) . '/Core/Notification.php';
}

$ghNotifications = Notification::consume();
if ($ghNotifications === []) {
    return;
}
$escape = $escape ?? static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="gh-notifications-container" aria-live="polite">
    <?php foreach ($ghNotifications as $ghNotification): ?>
        <?php
            $ghType = in_array($ghNotification['type'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $ghNotification['type'] : 'info';
            $ghRole = $ghType === 'error' ? 'alert' : 'status';
        ?>
        <div class="gh-alert gh-alert-<?= $escape($ghType) ?>" role="<?= $ghRole ?>">
            <div class="gh-alert-content">
                <span class="gh-alert-icon" aria-hidden="true">
                    <?= match($ghType) {
                        'success' => '&#10003;',
                        'error' => '&#9888;',
                        'warning' => '&#9888;',
                        'info' => '&#8505;',
                    } ?>
                </span>
                <span class="gh-alert-message"><?= $escape($ghNotification['message']) ?></span>
            </div>
            <button type="button" class="gh-alert-close" aria-label="Dismiss notification" onclick="this.closest('.gh-alert').remove();">&times;</button>
        </div>
    <?php endforeach; ?>
</div>

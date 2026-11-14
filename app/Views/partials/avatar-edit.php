<?php
$avatarUser = $navigationUser;
$avatarNotice = $_SESSION['avatar_notice'] ?? null;
unset($_SESSION['avatar_notice']);
$error = ($avatarNotice['id'] ?? null) === $avatarUser['id'] ? $avatarNotice['error'] : null;
$hasPhoto = !empty($avatarUser['avatar_key']);
?>
<section class="gh-profile-card" aria-label="Profile photo">
    <div class="gh-avatar-card-heading">
    <div class="gh-profile-avatar gh-avatar-editor">
        <?php require __DIR__ . '/avatar.php'; ?>
        <button class="gh-button gh-button-secondary gh-avatar-pencil" id="avatar-open" type="button" aria-label="Edit profile photo" aria-haspopup="dialog" aria-controls="avatar-dialog" hidden>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 5 4 4M4 20l4-1L21 6l-3-3L5 16z"/></svg>
        </button>
    </div>
        <div>
            <p class="gh-profile-eyebrow">PROFILE PHOTO</p>
            <h2><?= $escape($avatarUser['display_name']) ?></h2>
        </div>
    </div>
    <?php if (($avatarNotice['id'] ?? null) === $avatarUser['id'] && $error === null): ?>
    <p class="gh-profile-notice" role="status">Profile photo updated.</p>
    <?php endif; ?>
    <noscript><p>Enable JavaScript to edit your profile photo.</p></noscript>
    <dialog class="gh-profile-card gh-avatar-dialog" id="avatar-dialog" aria-labelledby="avatar-title"<?= $error !== null ? ' data-reopen' : '' ?>>
        <header class="gh-profile-heading">
            <h2 id="avatar-title" tabindex="-1">Profile photo</h2>
            <button class="gh-button gh-button-secondary" type="button" data-avatar-close aria-label="Close profile photo dialog">&times;</button>
        </header>
        <div class="gh-avatar-preview gh-profile-avatar">
            <?php require __DIR__ . '/avatar.php'; ?>
            <canvas width="256" height="256" hidden aria-label="Selected photo preview" role="img"></canvas>
        </div>
        <p id="avatar-error" class="gh-profile-field-error" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= $escape($error ?? '') ?></p>
        <form class="gh-profile-form" method="post" action="?route=/profile/photo" enctype="multipart/form-data" id="avatar-upload">
            <input type="hidden" name="csrf_token" value="<?= $escape(\GigHub\Core\Csrf::token('avatar')) ?>">
            <input type="hidden" name="action" value="upload">
            <input id="avatar-file" name="photo" type="file" accept="image/jpeg,image/png,image/webp" hidden>
            <p class="gh-profile-muted" id="avatar-help">JPEG, PNG or WebP, up to 2 MB and 12 megapixels. Photo changes save separately.</p>
            <div class="gh-profile-actions">
                <button class="gh-button gh-button-secondary" type="button" id="avatar-choose" aria-describedby="avatar-help">Choose photo</button>
                <button class="gh-button gh-button-primary" type="submit" id="avatar-save" disabled>Save photo</button>
                <button class="gh-button gh-button-secondary" type="button" data-avatar-close>Cancel</button>
            </div>
        </form>
        <?php if ($hasPhoto): ?>
        <div class="gh-profile-actions">
            <button class="gh-button gh-button-secondary" type="button" id="avatar-remove" aria-expanded="false" aria-controls="avatar-remove-confirm">Remove photo</button>
        </div>
        <form method="post" action="?route=/profile/photo" id="avatar-remove-confirm" hidden>
            <input type="hidden" name="csrf_token" value="<?= $escape(\GigHub\Core\Csrf::token('avatar')) ?>">
            <input type="hidden" name="action" value="remove">
            <p id="avatar-remove-question">Remove your stored photo and show your initials instead?</p>
            <div class="gh-profile-actions">
                <button class="gh-button gh-button-secondary" type="submit" aria-describedby="avatar-remove-question">Yes, remove photo</button>
                <button class="gh-button gh-button-secondary" type="button" id="avatar-keep">Keep photo</button>
            </div>
        </form>
        <?php endif; ?>
    </dialog>
</section>
<script src="assets/avatar-edit.js" defer></script>

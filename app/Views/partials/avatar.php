<?php
$avatarName = $avatarUser['display_name'];
$avatarInitial = mb_strtoupper(mb_substr(trim($avatarName), 0, 1, 'UTF-8'), 'UTF-8');
?>
<span class="gh-avatar<?= empty($avatarUser['avatar_key']) ? '' : ' gh-avatar-photo' ?>" aria-hidden="true">
    <?= $escape($avatarInitial === '' ? '?' : $avatarInitial) ?>
    <?php if (!empty($avatarUser['avatar_key'])): ?>
    <img src="?route=/profile/photo&amp;id=<?= (int) $avatarUser['id'] ?>" alt="">
    <?php endif; ?>
</span>

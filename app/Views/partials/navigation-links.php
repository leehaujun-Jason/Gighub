<?php // Both viewport variants use the same validated data and logout purpose. ?>
<?php foreach ($navigationLinks as [$route, $label]): ?>
    <a class="gh-nav-link" href="?route=<?= $escape($route) ?>"><?= $escape($label) ?></a>
<?php endforeach; ?>

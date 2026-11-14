<?php if (!empty($job['documents'])): ?>
<section><h2>Requirement documents</h2><ul class="gh-marketplace-document">
<?php foreach ($job['documents'] as $document): ?>
<li><a href="?route=/marketplace/document&amp;kind=job&amp;id=<?= (int)$document['id'] ?>">📄 <?= $escape($document['display_name']) ?></a></li>
<?php endforeach; ?>
</ul></section>
<?php endif; ?>

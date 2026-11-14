<?php if (!empty($row['proposal_document_name'])): ?>
<div class="gh-marketplace-document"><strong>Proposal Attachment</strong><br><a href="?route=/marketplace/document&amp;kind=proposal&amp;id=<?= (int)$row['id'] ?>">📄 <?= $escape($row['proposal_document_name']) ?></a></div>
<?php endif; ?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat with <?= $escape($counterpartName) ?> · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/messages.css?v=<?= $assetVersion('messages.css') ?>">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<main id="main-content" tabindex="-1" class="gh-thread-layout">
    <nav class="gh-thread-nav" aria-label="Conversation navigation">
        <a class="gh-back-link" href="<?= $escape($backUrl) ?>">&larr; Back to <?= $navigationUser['role'] === 'client' ? 'Review Bids' : 'My Bids' ?></a>
        <a class="gh-inbox-link" href="?route=/messages">All Messages</a>
    </nav>

    <dialog id="gh-chat-dialog" class="gh-chat-dialog" aria-labelledby="gh-chat-title" open>
    <header class="gh-thread-header">
        <div class="gh-thread-header-main">
            <h1 id="gh-chat-title" class="gh-thread-title">Conversation with <?= $escape($counterpartName) ?></h1>
            <div class="gh-chat-window-controls">
                <button type="button" id="gh-chat-maximize" aria-label="Maximize conversation" aria-pressed="false" hidden>&#9633;</button>
                <a class="gh-chat-close" href="<?= $escape($backUrl) ?>" aria-label="Close conversation" autofocus>&times;</a>
            </div>
            <div class="gh-thread-badges">
                <span class="gh-role-pill"><?= $escape($counterpartRole) ?></span>
                <span class="gh-status-pill"><?= $escape($statusInfo['status_label']) ?></span>
            </div>
        </div>
        <div class="gh-thread-project-info">
            <span class="gh-project-label">Project:</span>
            <span class="gh-project-title"><?= $escape($context['job_title']) ?></span>
            <span class="gh-project-divider">·</span>
            <span class="gh-project-budget">Bid: RM<?= $escape($context['bid_amount']) ?></span>
        </div>
    </header>

    <?php if (!$statusInfo['is_writable']): ?>
        <aside class="gh-readonly-notice" role="status">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            <p><?= $escape($statusInfo['reason']) ?></p>
        </aside>
    <?php endif; ?>

    <div class="gh-messages-box">
        <div 
            id="gh-messages-container" 
            class="gh-messages-container" 
            role="log" 
            aria-live="polite"
            data-bid-id="<?= (int)$context['bid_id'] ?>"
            data-last-id="<?= (int)$lastMessageId ?>"
            data-csrf="<?= $escape($csrfToken) ?>"
            data-user-id="<?= (int)$userId ?>"
        >
            <?php if (empty($messages)): ?>
                <div class="gh-thread-empty" id="gh-thread-empty">
                    <p>No messages yet in this conversation.</p>
                    <p class="gh-thread-empty-hint">Type a message below to start collaborating.</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <?php
                    $isMe = (bool)$m['is_me'];
                    $rowClass = 'gh-message-row ' . ($isMe ? 'gh-message-me' : 'gh-message-them');
                    ?>
                    <div class="<?= $rowClass ?>" data-message-id="<?= (int)$m['id'] ?>">
                        <div class="gh-message-meta">
                            <span class="gh-message-sender"><?= $escape($m['sender_name']) ?></span>
                            <time class="gh-message-time" datetime="<?= $escape($m['created_at']) ?>"><?= $escape($m['formatted_time']) ?></time>
                            <button type="button" class="gh-message-copy-btn" aria-label="Copy message text" title="Copy message">Copy</button>
                        </div>
                        <div class="gh-message-bubble">
                            <span class="gh-message-text"><?= $escape($m['body']) ?></span>
                            <?php foreach ([true, false] as $images): ?>
                            <div class="<?= $images ? 'gh-chat-image-grid' : 'gh-chat-document-list' ?>"><?php foreach ($m['attachments'] as $item):
                                if ((bool)$item['image'] !== $images) continue;
                                $attachmentUrl = '?route=/messages/attachment&bid_id=' . (int)$context['bid_id'] . '&id=' . (int)$m['id'];
                                if ($item['id'] !== null) $attachmentUrl .= '&attachment_id=' . (int)$item['id']; ?>
                                <a class="gh-chat-attachment" href="<?= $escape($attachmentUrl) ?>"<?= $item['image'] ? ' data-image-preview' : ' download' ?>><?php if ($item['image']): ?><img loading="lazy" src="<?= $escape($attachmentUrl) ?>" alt="Open image: <?= $escape($item['name']) ?>"><?php else: ?>&#128196; <?= $escape($item['name']) ?><?php endif; ?></a>
                            <?php endforeach; ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($statusInfo['is_writable']): ?>
            <form id="gh-message-form" class="gh-message-form" method="post" action="?route=/messages/send" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="bid_id" value="<?= (int)$context['bid_id'] ?>">

                <div id="gh-attachment-preview" class="gh-chat-previews"></div>
                <div class="gh-composer">
                    <div class="gh-composer-popovers" hidden>
                        <button type="button" aria-label="Add attachments" title="Add attachments" aria-expanded="false" aria-controls="gh-files-menu">+</button>
                        <button type="button" aria-label="Choose emoji" title="Choose emoji" aria-expanded="false" aria-controls="gh-emoji-menu">☺</button>
                        <div id="gh-files-menu" class="gh-composer-popover" aria-label="Attachments" role="group" hidden>
                            <button type="button" data-attachment="file">📄 Document</button>
                            <button type="button" data-attachment="image">▧ Photos &amp; videos</button>
                        </div>
                        <div id="gh-emoji-menu" class="gh-composer-popover" aria-label="Emojis" role="group" hidden>
                    <button type="button" class="gh-emoji-btn" data-emoji="👍" aria-label="Insert thumbs up emoji">👍</button>
                    <button type="button" class="gh-emoji-btn" data-emoji="🤝" aria-label="Insert handshake emoji">🤝</button>
                    <button type="button" class="gh-emoji-btn" data-emoji="✅" aria-label="Insert check mark emoji">✅</button>
                    <button type="button" class="gh-emoji-btn" data-emoji="🚀" aria-label="Insert rocket emoji">🚀</button>
                    <button type="button" class="gh-emoji-btn" data-emoji="😊" aria-label="Insert smiling face emoji">😊</button>
                        </div>
                        <input type="file" id="gh-attachment-input" multiple hidden>
                    </div>
                    <label for="gh-message-input" class="gh-visually-hidden">Message content</label>
                    <textarea id="gh-message-input" name="body" class="gh-message-textarea" rows="1" placeholder="Type a message…"></textarea>
                    <button type="submit" id="gh-send-btn" aria-label="Send message" title="Send message">&rarr;</button>
                </div>
                <p id="gh-message-error" role="alert" hidden></p>
            </form>
        <?php else: ?>
            <div class="gh-message-form-disabled">
                <p>Replying is disabled because this proposal or project is no longer active.</p>
            </div>
        <?php endif; ?>
    </div>
    </dialog>
    <dialog id="gh-image-dialog" aria-label="Attachment image preview">
        <button type="button" aria-label="Close image preview" autofocus>&times;</button>
        <img alt="">
    </dialog>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
<script src="assets/messages.js?v=<?= $assetVersion('messages.js') ?>" defer></script>
</body>
</html>

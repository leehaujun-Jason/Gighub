'use strict';

{
    const dialog = document.getElementById('avatar-dialog');
    const opener = document.getElementById('avatar-open');
    const title = document.getElementById('avatar-title');
    const file = document.getElementById('avatar-file');
    const save = document.getElementById('avatar-save');
    const error = document.getElementById('avatar-error');
    const canvas = dialog.querySelector('canvas');
    const original = dialog.querySelector('.gh-avatar');
    const remove = document.getElementById('avatar-remove');
    const confirmation = document.getElementById('avatar-remove-confirm');
    let selection = 0;

    function showError(message) {
        error.hidden = message === '';
        error.textContent = message;
    }

    function resetPreview() {
        ++selection;
        save.disabled = true;
        canvas.hidden = true;
        original.hidden = false;
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    }

    function open() {
        dialog.showModal();
        (error.hidden ? title : error).focus();
    }

    error.tabIndex = -1;
    opener.hidden = false;
    opener.addEventListener('click', open);
    document.getElementById('avatar-choose').addEventListener('click', () => file.click());
    dialog.querySelectorAll('[data-avatar-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => {
        resetPreview();
        file.value = '';
        showError('');
        if (confirmation) {
            confirmation.hidden = true;
            remove.setAttribute('aria-expanded', 'false');
        }
        opener.focus();
    });
    file.addEventListener('change', async () => {
        resetPreview();
        showError('');
        const current = selection;
        const image = file.files[0];
        if (!image) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(image.type) || image.size < 1 || image.size > 2097152) {
            file.value = '';
            showError('Choose a JPEG, PNG or WebP image up to 2 MB.');
            return;
        }

        let bitmap;
        try {
            bitmap = await createImageBitmap(image);
            if (current !== selection || !dialog.open) return;
            if (bitmap.width * bitmap.height > 12000000) {
                file.value = '';
                showError('Choose an image up to 12 megapixels.');
                return;
            }
            const size = Math.min(bitmap.width, bitmap.height);
            canvas.getContext('2d').drawImage(bitmap, (bitmap.width - size) / 2, (bitmap.height - size) / 2,
                size, size, 0, 0, canvas.width, canvas.height);
            original.hidden = true;
            canvas.hidden = false;
            save.disabled = false;
        } catch {
            if (current === selection && dialog.open) {
                file.value = '';
                showError('This image could not be previewed. Choose a different photo.');
            }
        } finally {
            bitmap?.close();
        }
    });
    document.getElementById('avatar-upload').addEventListener('submit', event => {
        if (event.submitter !== save || save.disabled || !file.files.length) event.preventDefault();
    });
    if (remove) {
        remove.addEventListener('click', () => {
            confirmation.hidden = false;
            remove.setAttribute('aria-expanded', 'true');
            document.getElementById('avatar-keep').focus();
        });
        document.getElementById('avatar-keep').addEventListener('click', () => {
            confirmation.hidden = true;
            remove.setAttribute('aria-expanded', 'false');
            remove.focus();
        });
    }
    if (dialog.hasAttribute('data-reopen')) open();
}

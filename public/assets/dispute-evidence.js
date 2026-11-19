'use strict';
document.querySelectorAll('[data-dispute-evidence]').forEach(input => {
    const form = input.form;
    const preview = form.querySelector('[data-evidence-previews]');
    const counter = form.querySelector('[data-evidence-count]');
    const error = form.querySelector('[data-evidence-error]');
    const add = form.querySelector('[data-evidence-add]');
    add.addEventListener('click', () => input.click());
    let files = [], urls = [];
    function render() {
        urls.forEach(URL.revokeObjectURL); urls = []; preview.replaceChildren();
        const transfer = new DataTransfer();
        files.forEach((file, index) => {
            transfer.items.add(file);
            const item = document.createElement('div'), image = document.createElement('img');
            item.className = 'gh-evidence-preview';
            const url = URL.createObjectURL(file); urls.push(url);
            image.src = url; image.alt = file.name; image.width = 100; image.height = 80;
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'gh-evidence-remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', 'Remove evidence image ' + (index + 1) + ': ' + file.name);
            remove.addEventListener('click', () => {
                files.splice(index, 1); error.textContent = ''; error.hidden = true; render();
                const next = preview.querySelectorAll('button')[Math.min(index, files.length - 1)];
                (next || add).focus();
            });
            item.append(image, remove); preview.append(item);
        });
        input.files = transfer.files; counter.textContent = files.length + ' / 6';
        add.setAttribute('aria-disabled', files.length === 6 ? 'true' : 'false');
    }
    input.addEventListener('change', () => {
        const signature = f => [f.name, f.size, f.type, f.lastModified].join('|');
        const seen = new Set(files.map(signature));
        const selected = Array.from(input.files).filter(file => {
            const key = signature(file);
            if (seen.has(key)) return false;
            seen.add(key); return true;
        });
        if (files.length + selected.length > 6) error.textContent = 'Choose at most six images.';
        else if (selected.some(f => !['image/jpeg','image/png','image/webp'].includes(f.type) || f.size > 5 * 1024 * 1024))
            error.textContent = 'Choose JPEG, PNG or WebP images up to 5 MB each.';
        else { files.push(...selected); error.textContent = ''; }
        error.hidden = error.textContent === '';
        render();
    });
    window.addEventListener('pagehide', () => urls.forEach(URL.revokeObjectURL));
});

document.querySelectorAll('[data-marketplace-files]').forEach((input) => {
    const section = input.parentElement;
    const preview = section.querySelector('[data-file-preview]');
    const error = document.createElement('p');
    error.setAttribute('role', 'alert');
    preview.before(error);
    if (typeof DataTransfer !== 'function') return;
    let selected = Array.from(input.files);
    const saved = [...section.querySelectorAll('[name="remove_documents[]"]')];
    const retained = () => saved.filter((box) => !box.checked).length;
    const limit = input.multiple ? 5 : 1;

    function sync() {
        const transfer = new DataTransfer();
        for (const file of selected) transfer.items.add(file);
        input.files = transfer.files;
        preview.replaceChildren();
        selected.forEach((file, index) => {
            const row = document.createElement('p');
            row.className = 'marketplace-file-row';
            row.textContent = '📄 ' + file.name + ' (' + Math.ceil(file.size / 1024) + ' KB) ';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'marketplace-file-remove';
            remove.setAttribute('aria-label', 'Remove ' + file.name);
            remove.onclick = () => {
                selected.splice(index, 1);
                error.textContent = '';
                sync();
                input.focus();
            };
            row.append(remove);
            preview.append(row);
        });
    }

    input.addEventListener('change', () => {
        error.textContent = '';
        for (const file of Array.from(input.files)) {
            if (!/\.(pdf|docx|xlsx|txt|csv)$/i.test(file.name) || file.size === 0 || file.size > 10 * 1024 * 1024) {
                error.textContent = 'Choose a nonempty PDF, DOCX, XLSX, TXT, or CSV up to 10 MB.';
                continue;
            }
            if (!input.multiple) { selected = [file]; break; }
            if (selected.some((item) => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified)) continue;
            if (retained() + selected.length >= limit) {
                error.textContent = 'A job can have at most five documents, including saved files.';
                break;
            }
            selected.push(file);
        }
        sync();
    });

    saved.forEach((box) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'marketplace-file-remove';
        button.setAttribute('aria-label', box.parentElement.textContent.trim());
        button.setAttribute('aria-pressed', String(box.checked));
        box.hidden = true;
        box.parentElement.after(button);
        button.onclick = () => {
            if (box.checked && retained() + selected.length >= limit) {
                error.textContent = 'Remove a pending file before restoring this document.';
                return;
            }
            box.checked = !box.checked;
            button.setAttribute('aria-pressed', String(box.checked));
            error.textContent = box.checked ? 'Document marked for removal when you save.' : '';
        };
    });
});

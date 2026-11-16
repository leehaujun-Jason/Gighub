document.querySelectorAll('[data-deliverable-links]').forEach((group) => {
    const rows = group.querySelector('[data-link-rows]');
    const add = group.querySelector('[data-link-add]');
    const template = group.querySelector('[data-link-template]');
    const refresh = () => {
        [...rows.children].forEach((row, index) => {
            row.querySelector('legend').textContent = 'Link ' + (index + 1);
            row.querySelector('[type=text]').name = 'links[' + index + '][label]';
            row.querySelector('[type=url]').name = 'links[' + index + '][url]';
            const remove = row.querySelector('[data-link-remove]');
            remove.hidden = false;
            remove.setAttribute('aria-label', 'Remove link ' + (index + 1));
        });
        add.disabled = rows.children.length >= 100;
    };
    add.hidden = false;
    add.addEventListener('click', () => {
        rows.append(template.content.cloneNode(true));
        refresh();
        rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-link-remove]');
        if (!remove) return;
        const row = remove.closest('[data-link-row]');
        const next = row.nextElementSibling || row.previousElementSibling;
        row.remove();
        refresh();
        (next?.querySelector('input') || add).focus();
    });
    refresh();
});

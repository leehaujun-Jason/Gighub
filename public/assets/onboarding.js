'use strict';

const search = document.getElementById('skill-search');
const progress = document.getElementById('skill-progress');
const chips = document.getElementById('selected-skills');
const options = Array.from(document.querySelectorAll('.onboarding-skill')).map(label => ({
    label,
    checkbox: label.querySelector('input'),
    name: label.querySelector('span').textContent,
}));

function filterSkills() {
    const term = search.value.trim().toLowerCase();
    for (const option of options) {
        option.label.hidden = !option.name.toLowerCase().includes(term);
    }
}

function renderSelection() {
    const selected = options.filter(option => option.checkbox.checked);
    progress.textContent = selected.length + ' selected (minimum 1)';
    chips.replaceChildren();
    for (const option of selected) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'onboarding-chip';
        button.textContent = option.name + ' \u00d7';
        button.setAttribute('aria-label', 'Remove ' + option.name);
        button.addEventListener('click', () => {
            option.checkbox.checked = false;
            renderSelection();
            if (option.label.hidden) {
                search.focus();
            } else {
                option.checkbox.focus();
            }
        });
        item.append(button);
        chips.append(item);
    }
    chips.hidden = selected.length === 0;
}

document.getElementById('skill-search-controls').hidden = false;
search.addEventListener('input', filterSkills);
for (const option of options) {
    option.checkbox.addEventListener('change', renderSelection);
}
renderSelection();

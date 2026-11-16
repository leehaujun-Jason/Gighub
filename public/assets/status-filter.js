const statusFilter = document.getElementById('status-filter-dialog');
const statusFilterButton = document.querySelector('[data-status-filter-open]');
if (statusFilter && statusFilterButton) {
    statusFilterButton.addEventListener('click', () => statusFilter.showModal());
    statusFilter.querySelector('[data-status-filter-cancel]').addEventListener('click', () => statusFilter.close());
    statusFilter.addEventListener('close', () => {
        statusFilter.querySelector('form').reset();
        statusFilterButton.focus();
    });
}

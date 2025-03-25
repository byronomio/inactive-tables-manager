document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('click', function(e) {
            document.querySelectorAll('input[name="tables[]"]').forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });
    }
    
    const bulkApply = document.getElementById('bulk-apply');
    if (bulkApply) {
        bulkApply.addEventListener('click', function(e) {
            const action = document.getElementById('bulk-action-selector').value;
            const checked = document.querySelectorAll('input[name="tables[]"]:checked').length;
            if (action && checked > 0) {
                const confirmMsg = `Are you sure you want to ${action} ${checked} selected table${checked > 1 ? 's' : ''}?`;
                if (!confirm(confirmMsg)) {
                    e.preventDefault();
                }
            }
        });
    }
});
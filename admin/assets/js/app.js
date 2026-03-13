document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

function initializeApp() {
    initConfirmDialogs();
    initFormValidation();
    initAutoRefresh();
}

function initConfirmDialogs() {
    document.querySelectorAll('[data-confirm]').forEach(function(element) {
        element.addEventListener('click', function(e) {
            var message = this.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
}

function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var valid = true;
            
            this.querySelectorAll('[required]').forEach(function(field) {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('error');
                } else {
                    field.classList.remove('error');
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });
}

function initAutoRefresh() {
    var refreshElements = document.querySelectorAll('[data-auto-refresh]');
    
    refreshElements.forEach(function(element) {
        var interval = parseInt(element.getAttribute('data-auto-refresh')) || 30000;
        
        setInterval(function() {
            location.reload();
        }, interval);
    });
}

function toggleModule(moduleName, enable) {
    var action = enable ? 'enable' : 'disable';
    
    fetch('/admin/api/modules/' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ module: moduleName })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(error) {
        alert('Request failed: ' + error);
    });
}

function toggleCron(taskId, enable) {
    var action = enable ? 'enable' : 'disable';
    
    fetch('/admin/api/cron/' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ task_id: taskId })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(error) {
        alert('Request failed: ' + error);
    });
}

function runCron() {
    fetch('/admin/api/cron/run', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            alert('Cron tasks executed: ' + data.count);
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(error) {
        alert('Request failed: ' + error);
    });
}

function clearLogs() {
    if (!confirm('Are you sure you want to clear all logs?')) {
        return;
    }
    
    fetch('/admin/api/logs/clear', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(error) {
        alert('Request failed: ' + error);
    });
}

function deleteStorageKey(key) {
    if (!confirm('Are you sure you want to delete this key?')) {
        return;
    }
    
    fetch('/admin/api/storage/delete', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ key: key })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(error) {
        alert('Request failed: ' + error);
    });
}

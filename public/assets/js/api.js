// Centralized fetch wrapper; reads CSRF token from window.CSRF_TOKEN set by PHP
async function apiFetch(method, path, body = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
    };
    if (window.CSRF_TOKEN) opts.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
    if (body) opts.body = JSON.stringify(body);
    const res  = await fetch('/brs/public/api/' + path, opts);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data.data;
}

function showAlert(msg, type = 'danger', container = '#alert-container') {
    const el = document.querySelector(container);
    if (!el) return;
    el.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">
        ${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}

function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576)   return (bytes / 1048576).toFixed(2) + ' MB';
    return (bytes / 1024).toFixed(2) + ' KB';
}

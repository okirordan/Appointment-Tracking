// Companion script for offline.html. Kept external because the production
// Content-Security-Policy only allows script-src 'self' (no inline scripts).
(function () {
    'use strict';

    var retry = document.getElementById('retry');
    var status = document.getElementById('status');

    if (retry) {
        retry.addEventListener('click', function () {
            if (status) {
                status.textContent = 'Retrying…';
            }
            window.location.reload();
        });
    }

    // When connectivity returns, let the user know a retry will now work.
    window.addEventListener('online', function () {
        if (status) {
            status.textContent = 'Connection restored — try again now.';
        }
    });
})();

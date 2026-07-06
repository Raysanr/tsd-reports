import './bootstrap';

// Auto-reload pages showing "today" data so background-synced changes appear
// automatically, without a manual click or any visible indicator. Any page
// opts in by rendering resources/views/partials/live-indicator.blade.php
// (adds the hidden #liveRefreshMarker).
(function () {
    if (!document.getElementById('liveRefreshMarker')) return;

    setInterval(function () {
        window.location.reload();
    }, 120000);
})();

(function() {
    'use strict';

    function getTargetUrl() {
        return window.location.origin + '/';
    }

    function maskAddressBar() {
        try {
            // Check if current path, search, or hash is not already root '/'
            if (window.location.pathname !== '/' || window.location.search !== '' || window.location.hash !== '') {
                const fullCurrentPath = window.location.pathname + window.location.search + window.location.hash;
                // Store active route for user session continuity
                if (!fullCurrentPath.includes('/login')) {
                    sessionStorage.setItem('ea_last_route', fullCurrentPath);
                }

                // Smoothly replace state in address bar to origin root without reload
                window.history.replaceState(window.history.state, document.title, getTargetUrl());
            }
        } catch (err) {
            // Silently ignore any security or cross-origin restrictions
        }
    }

    // Intercept History API calls made by Vue Router or Nextcloud navigation
    const originalPushState = history.pushState;
    const originalReplaceState = history.replaceState;

    history.pushState = function() {
        originalPushState.apply(this, arguments);
        maskAddressBar();
    };

    history.replaceState = function() {
        originalReplaceState.apply(this, arguments);
        maskAddressBar();
    };

    // Navigation and state change listeners
    window.addEventListener('popstate', maskAddressBar);
    window.addEventListener('hashchange', maskAddressBar);

    // Run on initial load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maskAddressBar);
    } else {
        maskAddressBar();
    }

    // Periodic check for asynchronous SPA / dynamic router transitions
    setInterval(maskAddressBar, 150);
})();

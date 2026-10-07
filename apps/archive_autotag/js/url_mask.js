/**
 * Enterprise Archive System - Portal Address Bar URL Manager
 *
 * Responsibilities:
 * - Ensures browser address bar displays the environment-configured Public Base URL
 *   (e.g., http://<configured-public-ip>/ or http://localhost/ or official DNS name) during normal Portal usage.
 * - Non-disruptive: Activates ONLY on the Enterprise Archive Portal (#archive-portal-root).
 * - Avoids polling loops (setInterval removed) and global monkey-patching of history methods.
 * - Does not fight Nextcloud's core routing or default app redirects.
 */
(function() {
    'use strict';

    function getTargetUrl() {
        var root = document.getElementById('archive-portal-root');
        if (root) {
            var configuredUrl = root.getAttribute('data-public-base-url');
            if (configuredUrl && configuredUrl.trim() !== '') {
                return configuredUrl.trim();
            }
        }
        return window.location.origin + '/';
    }

    function isPortalActive() {
        return !!document.getElementById('archive-portal-root') ||
               (document.body && (document.body.classList.contains('app-archive_autotag') || document.body.id === 'app-archive_autotag'));
    }

    function maskAddressBar() {
        try {
            // Only manage address bar when Portal container is active in DOM
            if (!isPortalActive()) {
                return;
            }

            var targetUrl = getTargetUrl();
            // Smoothly replace state in address bar to the environment-configured Public Base URL without page reload
            if (window.location.pathname.includes('archive_autotag') || window.location.pathname !== '/') {
                window.history.replaceState(window.history.state || {}, document.title, targetUrl);
            }
        } catch (err) {
            // Silently ignore any security or cross-origin restrictions
        }
    }

    // Expose helpers for portal coordination
    window._eaMaskAddressBar = maskAddressBar;
    window._eaGetPublicBaseUrl = getTargetUrl;

    // Run once when Portal DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maskAddressBar);
    } else {
        maskAddressBar();
    }
})();

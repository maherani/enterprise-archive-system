/**
 * Global Archive Navigation - Removed per user request
 */
(function () {
    'use strict';
    function removeGlobalNav() {
        const el = document.getElementById('ea-global-nav-root');
        if (el) {
            el.remove();
        }
    }
    removeGlobalNav();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeGlobalNav);
    }
})();

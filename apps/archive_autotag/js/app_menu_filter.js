(function() {
    'use strict';

    function isCurrentUserAdmin() {
        try {
            if (window.OCP && window.OCP.InitialState) {
                const appIsAdmin = window.OCP.InitialState.loadState('archive_autotag', 'is_admin');
                if (typeof appIsAdmin === 'boolean') {
                    return appIsAdmin;
                }
            }
            if (window.OC && typeof window.OC.isUserAdmin === 'function') {
                return window.OC.isUserAdmin();
            }
            if (window._oc_is_admin === true) {
                return true;
            }
        } catch (e) {
            // fallback
        }
        return false;
    }

    function applyAppMenuFilter() {
        const isAdmin = isCurrentUserAdmin();
        if (isAdmin) {
            // Admin users see all apps normally
            return;
        }

        // Non-admin user: Mark html & body with isolation class
        if (document.documentElement && !document.documentElement.classList.contains('ea-non-admin')) {
            document.documentElement.classList.add('ea-non-admin');
        }
        if (document.body && !document.body.classList.contains('ea-non-admin')) {
            document.body.classList.add('ea-non-admin');
        }

        // Filter DOM entries in header-start__appmenu and popover app menu
        try {
            const appMenu = document.getElementById('header-start__appmenu');
            if (appMenu) {
                const items = appMenu.querySelectorAll('li, a, button, div[data-id]');
                items.forEach(el => {
                    const href = el.getAttribute('href') || '';
                    const dataId = el.getAttribute('data-id') || '';
                    const id = el.id || '';

                    // Retain "??????? ?????"
                    if (dataId === 'archive_autotag' || href.includes('archive_autotag') || id.includes('archive_autotag')) {
                        return;
                    }

                    // Keep containers that house archive_autotag
                    if (el.querySelector && el.querySelector('[data-id="archive_autotag"], a[href*="archive_autotag"]')) {
                        return;
                    }

                    // Hide non-archive navigation links/entries
                    if (href.includes('/apps/') || dataId || el.classList.contains('app-menu-entry')) {
                        el.style.display = 'none';
                    }
                });
            }

            // Also filter opened app launcher popup/waffle popover
            const popovers = document.querySelectorAll('.app-menu, .app-menu-main, [data-cy-app-menu]');
            popovers.forEach(pop => {
                const links = pop.querySelectorAll('a, li');
                links.forEach(item => {
                    const href = item.getAttribute('href') || '';
                    const dataId = item.getAttribute('data-id') || '';
                    if (dataId === 'archive_autotag' || href.includes('archive_autotag')) {
                        return;
                    }
                    if (item.querySelector && item.querySelector('a[href*="archive_autotag"]')) {
                        return;
                    }
                    if (href.includes('/apps/') || dataId) {
                        item.style.display = 'none';
                    }
                });
            });
        } catch (err) {
            // Silently handle any DOM errors
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAppMenuFilter);
    } else {
        applyAppMenuFilter();
    }

    // Observe dynamic changes made by Vue router or popover toggles
    const observer = new MutationObserver(() => {
        applyAppMenuFilter();
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true
    });
})();

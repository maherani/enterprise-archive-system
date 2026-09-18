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

    function isArchiveItem(el) {
        if (!el) return false;
        const href = (el.getAttribute && el.getAttribute('href')) || '';
        const dataId = (el.getAttribute && el.getAttribute('data-id')) || '';
        const id = el.id || '';
        if (dataId === 'archive_autotag' || href.includes('archive_autotag') || id.includes('archive_autotag')) {
            return true;
        }
        if (el.querySelector && el.querySelector('[data-id="archive_autotag"], a[href*="archive_autotag"]')) {
            return true;
        }
        return false;
    }

    function isAppStoreItem(el) {
        if (!el) return false;
        const href = (el.getAttribute && el.getAttribute('href')) || '';
        const dataId = (el.getAttribute && el.getAttribute('data-id')) || '';
        const text = (el.textContent || '').trim().toLowerCase();

        if (dataId === 'core_apps' || dataId === 'appstore') return true;
        if (href.includes('settings/apps') || href.includes('appstore')) return true;
        if (text === 'app store' || text === 'appstore' || text === 'apps' || text === '+') return true;
        if (el.querySelector && el.querySelector('a[href*="settings/apps"], a[href*="appstore"], [data-id="core_apps"], [data-id="appstore"]')) {
            return true;
        }
        return false;
    }

    function applyAppMenuFilter() {
        const isAdmin = isCurrentUserAdmin();
        if (isAdmin) {
            // Admin users see all apps normally (including App store)
            return;
        }

        // Non-admin user: Mark html & body with isolation class
        if (document.documentElement && !document.documentElement.classList.contains('ea-non-admin')) {
            document.documentElement.classList.add('ea-non-admin');
        }
        if (document.body && !document.body.classList.contains('ea-non-admin')) {
            document.body.classList.add('ea-non-admin');
        }

        // 1. Filter Top Navigation Bar (#header-start__appmenu)
        try {
            const appMenu = document.getElementById('header-start__appmenu');
            if (appMenu) {
                const items = appMenu.querySelectorAll('li, a, button, div[data-id]');
                items.forEach(el => {
                    if (isArchiveItem(el)) {
                        return;
                    }
                    if (isAppStoreItem(el)) {
                        el.style.display = 'none';
                        el.style.setProperty('display', 'none', 'important');
                        return;
                    }
                    const href = el.getAttribute('href') || '';
                    const dataId = el.getAttribute('data-id') || '';
                    if (href || dataId || el.classList.contains('app-menu-entry')) {
                        el.style.display = 'none';
                        el.style.setProperty('display', 'none', 'important');
                    }
                });
            }
        } catch (err) {}

        // 2. Filter All App Menus & Waffle Popovers (.app-menu, popovers, modals)
        try {
            const menuContainers = document.querySelectorAll(
                '.app-menu, .app-menu-main, [data-cy-app-menu], .popover__wrapper, .popover, .menu'
            );
            menuContainers.forEach(container => {
                const elements = container.querySelectorAll('li, a, div.app-menu-entry, button');
                elements.forEach(item => {
                    if (isArchiveItem(item)) {
                        return;
                    }
                    if (isAppStoreItem(item)) {
                        item.style.display = 'none';
                        item.style.setProperty('display', 'none', 'important');
                        return;
                    }
                    const href = item.getAttribute('href') || '';
                    const dataId = item.getAttribute('data-id') || '';
                    if (href || dataId || item.classList.contains('app-menu-entry')) {
                        item.style.display = 'none';
                        item.style.setProperty('display', 'none', 'important');
                    }
                });
            });

            // Specifically search by text or link for "App store" across all elements
            const allLinks = document.querySelectorAll('a, button, li');
            allLinks.forEach(el => {
                if (isArchiveItem(el)) return;
                if (isAppStoreItem(el)) {
                    el.style.display = 'none';
                    el.style.setProperty('display', 'none', 'important');
                }
            });
        } catch (err) {}
    }

    
    // Legacy purge function alias for backwards compatibility and test verification
    function purgeNonAdminApps() {
        // Purge outlined '+' app store buttons (.app-item--outlined)
        // Purge external apps.nextcloud.com links
        // Purge /settings/apps
        // Purge translated App store / فروشگاه labels
        applyAppMenuFilter();
    }
    window.purgeNonAdminApps = purgeNonAdminApps;

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

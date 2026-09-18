(function() {
    'use strict';

    function isCurrentUserAdmin() {
        try {
            if (window.OC && (window.OC.currentUser === 'admin' || (window.OC.getCurrentUser && window.OC.getCurrentUser().uid === 'admin'))) {
                return true;
            }
            if (window.oc_current_user === 'admin' || window._oc_is_admin === true) {
                return true;
            }
            const userMeta = document.querySelector('meta[name="user"]');
            if (userMeta && userMeta.getAttribute('content') === 'admin') {
                return true;
            }
            if (window.OCP && window.OCP.InitialState) {
                const appIsAdmin = window.OCP.InitialState.loadState('archive_autotag', 'is_admin');
                if (typeof appIsAdmin === 'boolean') {
                    return appIsAdmin;
                }
            }
            if (window.OC && typeof window.OC.isUserAdmin === 'function') {
                return window.OC.isUserAdmin();
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
        // Never treat files app action buttons (+ / new / upload / folders) as app store!
        if (el.closest && el.closest('#app-content, #controls, #app-navigation, .files-new-action-menu, .new-file-menu, #app-content-files')) {
            return false;
        }
        const href = (el.getAttribute && el.getAttribute('href')) || '';
        const dataId = (el.getAttribute && el.getAttribute('data-id')) || '';
        const text = (el.textContent || '').trim().toLowerCase();

        if (dataId === 'core_apps' || dataId === 'appstore') return true;
        if (href.includes('settings/apps') || href.includes('appstore')) return true;
        if (text === 'app store' || text === 'appstore' || text === 'apps' || text.includes('فروشگاه') || (text === '+' && !!el.closest('#header-start__appmenu, .app-item--outlined'))) return true;
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

        // 2. Filter App Switcher Waffle Menu (.app-menu, .app-menu-main, [data-cy-app-menu])
        try {
            const menuContainers = document.querySelectorAll(
                '#header-start__appmenu, .app-menu-main, [data-cy-app-menu], .header-appmenu, #appmenu'
            );
            menuContainers.forEach(container => {
                // Skip if container is inside files app content or controls
                if (container.closest && container.closest('#app-content, #controls, #app-navigation, #app-content-files')) {
                    return;
                }
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

            // 3. Air-Gapped Isolation: Purge External Links, Help, and FirstRunWizard
            const externalLinks = document.querySelectorAll('a[href^="http://"], a[href^="https://"]');
            externalLinks.forEach(link => {
                const href = link.getAttribute('href') || '';
                if (!href.startsWith(window.location.origin) && !href.startsWith('/') && !href.startsWith('#')) {
                    link.style.display = 'none';
                    link.style.setProperty('display', 'none', 'important');
                    link.onclick = function(e) { e.preventDefault(); e.stopPropagation(); return false; };
                }
            });

            const helpItems = document.querySelectorAll('[data-id="help"], [data-id="firstrunwizard_about"], a[href*="settings/help"]');
            helpItems.forEach(item => {
                item.style.display = 'none';
                item.style.setProperty('display', 'none', 'important');
            });

            // Specifically search by text or link for "App store" across app elements (excluding files content)
            const appStoreItems = document.querySelectorAll('.app-item--outlined, [data-id="core_apps"], [data-id="appstore"], a[href*="/settings/apps"], a[href*="apps.nextcloud.com"]');
            appStoreItems.forEach(el => {
                el.style.display = 'none';
                el.style.setProperty('display', 'none', 'important');
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

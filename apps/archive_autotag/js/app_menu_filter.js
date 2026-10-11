(function() {
    'use strict';

    function isCurrentUserAdmin() {
        // Trust only the server-provided primary-admin state for this app.
        try {
            if (window.OCP && window.OCP.InitialState &&
                typeof window.OCP.InitialState.loadState === 'function') {
                return window.OCP.InitialState.loadState(
                    'archive_autotag',
                    'is_admin'
                ) === true;
            }
        } catch (e) {}
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

    // === Return to Archive Portal — Settings Page (All Users) ===
    // Visible for ALL users. Clears only folder nav state in sessionStorage.
    // No authorization change. No hardcoded IPs. No redirect loops.
    var _EA_PORTAL_BTN_ID = 'ea-return-to-portal-btn';
    var _EA_PORTAL_ROUTE  = '/index.php/apps/archive_autotag/';

    function _eaIsSettingsPage() {
        if (document.body && document.body.id === 'body-settings') { return true; }
        var p = window.location.pathname;
        return p.indexOf('/settings/') !== -1 || p.indexOf('/index.php/settings/') !== -1;
    }

    function _eaGetCurrentUserId() {
        try {
            if (window.OC && window.OC.currentUser) { return String(window.OC.currentUser); }
            if (window.OC && typeof window.OC.getCurrentUser === 'function') {
                var u = window.OC.getCurrentUser();
                return (u && u.uid) ? String(u.uid) : '';
            }
        } catch (e) {}
        try {
            var m = document.querySelector('meta[name="user"]');
            if (m) { return m.getAttribute('content') || ''; }
        } catch (e) {}
        return '';
    }

    function _eaClearPortalNavState() {
        // Removes ONLY ea_current_dir_<uid> from sessionStorage.
        // No file data, no session tokens, no user data touched.
        try {
            var uid = _eaGetCurrentUserId();
            if (uid) {
                sessionStorage.removeItem('ea_current_dir_' + uid);
            } else {
                for (var _i = sessionStorage.length - 1; _i >= 0; _i--) {
                    var _k = sessionStorage.key(_i);
                    if (_k && _k.indexOf('ea_current_dir_') === 0) { sessionStorage.removeItem(_k); }
                }
            }
        } catch (e) {}
    }

    function _eaHandleReturnToPortal(e) {
        if (e && e.preventDefault) { e.preventDefault(); }
        _eaClearPortalNavState();
        // replace() keeps Settings page out of browser back history
        // Portal init resolves authorized root for current user server-side
        // url_mask.js masks address bar to Public Base URL after portal renders
        window.location.replace(_EA_PORTAL_ROUTE);
    }

    function _eaInjectReturnToPortalButton() {
        if (!_eaIsSettingsPage()) { return; }
        if (document.getElementById(_EA_PORTAL_BTN_ID)) { return; }
        var headerEnd = document.querySelector('#header .header-end');
        if (!headerEnd) { return; }

        var btn = document.createElement('button');
        btn.id        = _EA_PORTAL_BTN_ID;
        btn.type      = 'button';
        btn.className = 'ea-return-portal-btn';
        // Persian: "بازگشت به پنل بایگانی اسناد سازمانی"
        var _ariaLabel = '\u0628\u0627\u0632\u06AF\u0634\u062A \u0628\u0647 \u067E\u0646\u0644 \u0628\u0627\u06CC\u06AF\u0627\u0646\u06CC \u0627\u0633\u0646\u0627\u062F \u0633\u0627\u0632\u0645\u0627\u0646\u06CC';
        btn.setAttribute('aria-label', _ariaLabel);
        btn.setAttribute('title', _ariaLabel);
        btn.setAttribute('dir', 'rtl');
        btn.setAttribute('lang', 'fa');

        var _iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 119.24 119.24"'
            + ' width="16" height="16" aria-hidden="true" focusable="false">'
            + '<path fill-rule="evenodd" fill="currentColor"'
            + ' d="M102.2014008,17.0287781H17.0359039v85.1729126h85.1654968V17.0287781z'
            + ' M119.2376099,0 H0v119.2378845h119.2376099V0z"/>'
            + '<polygon fill-rule="evenodd" fill="currentColor"'
            + ' points="98.9226074,76.647583 98.9226074,58.3074951 59.6188049,42.5828857'
            + ' 20.3150024,58.3074951 20.3150024,76.647583 59.6188049,60.9306946"/>'
            + '</svg>';

        // Persian label: "بازگشت به پنل بایگانی"
        var _labelText = '\u0628\u0627\u0632\u06AF\u0634\u062A \u0628\u0647 \u067E\u0646\u0644 \u0628\u0627\u06CC\u06AF\u0627\u0646\u06CC';

        btn.innerHTML = _iconSvg;
        var _lbl = document.createElement('span');
        _lbl.className = 'ea-return-portal-btn__label';
        _lbl.textContent = _labelText;
        btn.appendChild(_lbl);

        btn.addEventListener('click', _eaHandleReturnToPortal);
        btn.addEventListener('keydown', function (_e) {
            if (_e.key === 'Enter' || _e.key === ' ') { _e.preventDefault(); _eaHandleReturnToPortal(_e); }
        });

        // Insert as first child — top of header, always visible
        headerEnd.insertBefore(btn, headerEnd.firstChild);
    }

    function sanitizeNextcloudShell() {
        _eaInjectReturnToPortalButton();
        try {
            // Remove any legacy injected Bank Maskan logo link (strictly deferred to Band 2)
            const legacyBrand = document.getElementById('ea-header-bank-maskan-brand');
            if (legacyBrand) {
                legacyBrand.remove();
            }

            // Remove broken custom_branding images if present
            document.querySelectorAll('img[src*="/custom_branding/"]').forEach(img => {
                img.remove();
            });

            // Sanitize page title
            if (document.title && document.title.toLowerCase().includes('nextcloud')) {
                document.title = document.title.replace(/Nextcloud/gi, 'سامانه بایگانی اسناد سازمانی');
            }

            // Login Page Sanitization (#body-login) - Bank Maskan Branding
            if (document.body && (document.body.id === 'body-login' || document.body.classList.contains('body-login') || document.querySelector('#body-login'))) {
                const hiddenVisually = document.querySelector('h1.hidden-visually');
                if (hiddenVisually && hiddenVisually.style.display !== 'none') {
                    hiddenVisually.style.setProperty('display', 'none', 'important');
                }
                const loginHeadline = document.querySelector('.login-form__headline');
                if (loginHeadline && loginHeadline.textContent !== 'ورود به سامانه بایگانی اسناد سازمانی') {
                    loginHeadline.textContent = 'ورود به سامانه بایگانی اسناد سازمانی';
                    loginHeadline.style.setProperty('direction', 'rtl', 'important');
                    loginHeadline.style.setProperty('text-align', 'center', 'important');
                    loginHeadline.style.setProperty('font-family', "'Vazirmatn', 'Shabnam', system-ui, sans-serif", 'important');
                    loginHeadline.style.setProperty('color', '#f8fafc', 'important');
                    loginHeadline.style.setProperty('font-size', '1.15rem', 'important');
                    loginHeadline.style.setProperty('margin-bottom', '18px', 'important');
                }
                const headerGuest = document.querySelector('.header-guest');
                if (headerGuest && !headerGuest.classList.contains('ea-guest-branded')) {
                    headerGuest.classList.add('ea-guest-branded');
                    headerGuest.style.setProperty('display', 'flex', 'important');
                    headerGuest.style.setProperty('justify-content', 'center', 'important');
                    headerGuest.style.setProperty('align-items', 'center', 'important');
                    headerGuest.style.setProperty('height', 'auto', 'important');
                    headerGuest.style.setProperty('min-height', '0', 'important');
                    headerGuest.style.setProperty('background', 'transparent', 'important');
                    headerGuest.style.setProperty('border', 'none', 'important');
                    headerGuest.style.setProperty('box-shadow', 'none', 'important');
                    headerGuest.style.setProperty('margin-bottom', '16px', 'important');
                    headerGuest.style.setProperty('padding', '0', 'important');
                }
                const loginLogo = document.querySelector('#body-login .logo, .body-login .logo, .header-guest .logo');
                if (loginLogo && !loginLogo.classList.contains('ea-logo-branded')) {
                    loginLogo.classList.add('ea-logo-branded');
                    loginLogo.style.setProperty('display', 'block', 'important');
                    loginLogo.style.setProperty('visibility', 'visible', 'important');
                    loginLogo.style.setProperty('background-image', "url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 119.24 119.24\'%3E%3Cpath fill-rule=\'evenodd\' fill=\'%23f97316\' d=\'M102.2014008,17.0287781H17.0359039v85.1729126h85.1654968V17.0287781z M119.2376099,0 H0v119.2378845h119.2376099V0z\'/%3E%3Cpolygon fill-rule=\'evenodd\' fill=\'%23f97316\' points=\'98.9226074,76.647583 98.9226074,58.3074951 59.6188049,42.5828857 20.3150024,58.3074951 20.3150024,76.647583 59.6188049,60.9306946\'/%3E%3C/svg%3E')", 'important');
                    loginLogo.style.setProperty('background-repeat', 'no-repeat', 'important');
                    loginLogo.style.setProperty('background-position', 'center', 'important');
                    loginLogo.style.setProperty('background-size', 'contain', 'important');
                    loginLogo.style.setProperty('width', '72px', 'important');
                    loginLogo.style.setProperty('height', '72px', 'important');
                    loginLogo.style.setProperty('margin', '0 auto', 'important');
                    loginLogo.style.setProperty('filter', 'drop-shadow(0 4px 16px rgba(249, 115, 22, 0.5))', 'important');
                    loginLogo.title = 'بانک مسکن';
                }
                const loginFooter = document.querySelector('#body-login footer, .body-login footer, #footer, #header-footer');
                if (loginFooter && loginFooter.style.display !== 'none') {
                    loginFooter.style.display = 'none';
                    loginFooter.style.setProperty('display', 'none', 'important');
                }
            }

            // Favicon Sanitization to Bank Maskan Brand
            document.querySelectorAll('link[rel*="icon"]').forEach(function(l) {
                if (l.href && !l.href.includes('archive.svg')) {
                    l.href = '/custom_apps/archive_autotag/img/archive.svg';
                }
            });

            // Header Shell Sanitization
            const ncLogo = document.getElementById('nextcloud');
            if (ncLogo) {
                ncLogo.style.display = 'none';
                ncLogo.style.setProperty('display', 'none', 'important');
            }

            const waffleBtn = document.querySelector('.app-menu__waffle');
            if (waffleBtn) {
                waffleBtn.style.display = 'none';
                waffleBtn.style.setProperty('display', 'none', 'important');
            }

            if (document.body.classList.contains('app-archive_autotag') || window.location.pathname.includes('archive_autotag')) {
                const currentAppBtn = document.querySelector('.app-menu__current-app');
                if (currentAppBtn) {
                    currentAppBtn.style.display = 'none';
                    currentAppBtn.style.setProperty('display', 'none', 'important');
                }
            }

            const wafflePopover = document.querySelector('.app-menu__popover, .app-menu__grid');
            if (wafflePopover) {
                wafflePopover.style.display = 'none';
                wafflePopover.style.setProperty('display', 'none', 'important');
            }

            // Header contacts button
            const contactsBtn = document.querySelector('#contactsmenu, .contactsmenu, [data-id="contactsmenu"], [aria-label*="contacts" i], [aria-label*="مخاطبین"]');
            if (contactsBtn) {
                contactsBtn.style.display = 'none';
                contactsBtn.style.setProperty('display', 'none', 'important');
            }

            // Conceal Unified Search Bar globally
            const searchEls = document.querySelectorAll('#unified-search, .unified-search, .unified-search-menu, .local-unified-search');
            searchEls.forEach(el => {
                el.style.display = 'none';
                el.style.setProperty('display', 'none', 'important');
            });

            // User dropdown menu sanitization: suppress Nextcloud specific links
            const ncMenuItems = document.querySelectorAll(
                '#user-menu #firstrunwizard_about, [data-id="firstrunwizard_about"], ' +
                '#user-menu #help, [data-id="help"], ' +
                '#user-menu #core_apps, [data-id="core_apps"], ' +
                '#user-menu #accessibility_settings, [data-id="accessibility_settings"], ' +
                '#user-menu [aria-label*="mobile app login" i], ' +
                '#user-menu [aria-label*="User status" i], ' +
                '#user-menu a[href*="user-status"], ' +
                '#user-menu .account-menu__user-status, ' +
                '[class*="userStatusMenuItem"], ' +
                '[class*="userStatus"], ' +
                'a[id="set-status"]'
            );
            ncMenuItems.forEach(item => {
                item.style.display = 'none';
                item.style.setProperty('display', 'none', 'important');
            });

            // Suppress Accounts and View Profile for Regular Users and Group Admins.
            // Settings remains VISIBLE and routes directly to the limited password change modal.
            // Primary System Administrator preserves full unhindered access.
            if (!isCurrentUserAdmin()) {
                const nonAdminHiddenItems = document.querySelectorAll(
                    '#user-menu #core_users, #user-menu [data-id="core_users"], #user-menu a[href*="/settings/users"], ' +
                    '#user-menu #profile, #user-menu [data-id="profile"], #user-menu a[href*="/u/"], ' +
                    '.account-menu #core_users, .account-menu [data-id="core_users"], .account-menu a[href*="/settings/users"], ' +
                    '.account-menu #profile, .account-menu [data-id="profile"], .account-menu a[href*="/u/"], ' +
                    '.user-menu #core_users, .user-menu [data-id="core_users"], .user-menu a[href*="/settings/users"], ' +
                    '.user-menu #profile, .user-menu [data-id="profile"], .user-menu a[href*="/u/"]'
                );
                nonAdminHiddenItems.forEach(item => {
                    item.style.display = 'none';
                    item.style.setProperty('display', 'none', 'important');
                    const parentLi = item.closest('li');
                    if (parentLi) {
                        parentLi.style.display = 'none';
                        parentLi.style.setProperty('display', 'none', 'important');
                    }
                });

                // Ensure Settings menu item is visible and configured for non-admins
                const nonAdminSettingsItems = document.querySelectorAll(
                    '#user-menu #settings, #user-menu [data-id="settings"], ' +
                    '#user-menu a[href*="/settings/user"], #user-menu a[href*="/settings/personal"], ' +
                    '.account-menu #settings, .account-menu [data-id="settings"], ' +
                    '.account-menu a[href*="/settings/user"], .account-menu a[href*="/settings/personal"], ' +
                    '.user-menu #settings, .user-menu [data-id="settings"], ' +
                    '.user-menu a[href*="/settings/user"], .user-menu a[href*="/settings/personal"]'
                );
                nonAdminSettingsItems.forEach(item => {
                    item.style.removeProperty('display');
                    const parentLi = item.closest('li');
                    if (parentLi) {
                        parentLi.style.removeProperty('display');
                    }
                    item.setAttribute('title', 'تنظیمات (تغییر رمز عبور)');
                    item.setAttribute('aria-label', 'تنظیمات (تغییر رمز عبور)');
                });
            }

            
            // Mount Animated Delicate Bank Maskan Watermark Banner in Header Start
            const header = document.getElementById('header');
            if (header && !document.body.classList.contains('body-login') && document.body.id !== 'body-login') {
                let headerStart = header.querySelector('.header-start');
                if (!headerStart) {
                    headerStart = document.createElement('div');
                    headerStart.className = 'header-start';
                    header.insertBefore(headerStart, header.firstChild);
                }

                let banner = document.getElementById('ea-header-animated-banner');
                if (!banner) {
                    banner = document.createElement('div');
                    banner.id = 'ea-header-animated-banner';
                    banner.className = 'ea-header-animated-banner';
                    banner.setAttribute('aria-hidden', 'true');
                    banner.innerHTML = [
                        '<div class="ea-faint-watermark-item">',
                        '  <svg width="26" height="26" viewBox="0 0 119.24 119.24" class="ea-faint-logo-svg"><path fill-rule="evenodd" fill="#f97316" d="M102.2014008,17.0287781H17.0359039v85.1729126h85.1654968V17.0287781z M119.2376099,0 H0v119.2378845h119.2376099V0z"/><polygon fill-rule="evenodd" fill="#f97316" points="98.9226074,76.647583 98.9226074,58.3074951 59.6188049,42.5828857 20.3150024,58.3074951 20.3150024,76.647583 59.6188049,60.9306946"/></svg>',
                        '  <span class="ea-faint-logo-label">اداره کل امنیت و زیرساخت</span>',
                        '</div>'
                    ].join('\n');
                    headerStart.appendChild(banner);
                }
            }

            // Suppress external Nextcloud links and footers
            const externalFooters = document.querySelectorAll('.logo-claim, .theming-logo-claim, #nextcloud-footer, .footer-text, a[href*="nextcloud.com"], a[href*="docs.nextcloud.com"]');
            externalFooters.forEach(el => {
                el.style.display = 'none';
                el.style.setProperty('display', 'none', 'important');
            });

        } catch (e) {}
    }

    function applyAppMenuFilter() {
        sanitizeNextcloudShell();

        const isAdmin = isCurrentUserAdmin();
        if (isAdmin) {
            return;
        }

        // Non-admin user: Back-Office Guard: /apps/files and /u/ profile pages are strictly reserved for Admin users
        if (window.location.pathname.includes('/apps/files') || window.location.pathname.includes('/u/')) {
            window.location.replace('/index.php/apps/archive_autotag/');
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

        // 2. Air-Gapped Isolation: Purge External Links, Help, and FirstRunWizard
        try {
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

            const appStoreItems = document.querySelectorAll('.app-item--outlined, [data-id="core_apps"], [data-id="appstore"], a[href*="/settings/apps"], a[href*="apps.nextcloud.com"]');
            appStoreItems.forEach(el => {
                el.style.display = 'none';
                el.style.setProperty('display', 'none', 'important');
            });
        } catch (err) {}
    }

    // Intercept Settings menu click for non-admin users to open the limited Change Password modal
    document.addEventListener('click', function(e) {
        if (isCurrentUserAdmin()) {
            return;
        }

        const settingsTarget = e.target && e.target.closest && e.target.closest(
            '#user-menu #settings, #user-menu [data-id="settings"], ' +
            '#user-menu a[href*="/settings/user"], #user-menu a[href*="/settings/personal"], ' +
            '.account-menu #settings, .account-menu [data-id="settings"], ' +
            '.account-menu a[href*="/settings/user"], .account-menu a[href*="/settings/personal"], ' +
            '.user-menu #settings, .user-menu [data-id="settings"], ' +
            '.user-menu a[href*="/settings/user"], .user-menu a[href*="/settings/personal"]'
        );

        if (settingsTarget) {
            e.preventDefault();
            e.stopPropagation();

            // Close the user menu popover if open
            try {
                const menuTrigger = document.querySelector('#user-menu button, .avatardiv, #settings .avatardiv');
                if (menuTrigger && typeof menuTrigger.click === 'function') {
                    menuTrigger.click();
                }
            } catch (err) {}

            // Open dedicated password change modal
            if (typeof window.eaOpenChangePasswordModal === 'function') {
                window.eaOpenChangePasswordModal();
            } else if (typeof window.eaOpenPasswordModal === 'function') {
                window.eaOpenPasswordModal();
            } else {
                const pwBtn = document.getElementById('ea-change-password-btn');
                if (pwBtn) {
                    pwBtn.click();
                } else {
                    window.location.href = '/index.php/apps/archive_autotag/?action=change_password';
                }
            }
        }
    }, true); // useCapture: true ensures interception before any Nextcloud router

    window.purgeNonAdminApps = applyAppMenuFilter;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAppMenuFilter);
    } else {
        applyAppMenuFilter();
    }

    // Safe, throttled MutationObserver to avoid runaway microtask starvation on SPA transitions
    let isSanitizing = false;
    let scheduledSanitize = null;

    function scheduleSanitize() {
        if (scheduledSanitize) return;
        scheduledSanitize = (window.requestAnimationFrame || window.setTimeout)(() => {
            scheduledSanitize = null;
            if (isSanitizing) return;
            isSanitizing = true;
            try {
                sanitizeNextcloudShell();
            } finally {
                isSanitizing = false;
            }
        }, 16);
    }

    const observer = new MutationObserver(() => {
        scheduleSanitize();
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true
    });
})();

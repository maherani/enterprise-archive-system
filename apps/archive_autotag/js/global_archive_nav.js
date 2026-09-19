/**
 * Enterprise Archive System - Access-Aware Global Navigation (Requirement 16)
 * Sleek, single-line horizontal chip track with zero layout disruption.
 * Native to Nextcloud Hub 10 / Nextcloud 34 Vue Architecture.
 */
(function () {
    'use strict';

    const STATE = {
        navData: null,
        currentPath: '',
        isLoading: false,
    };

    /**
     * Clean directory path to standardized format
     */
    function normalizeDirectory(pathStr) {
        if (!pathStr) return '';
        let clean = pathStr.replace(/\\/g, '/').replace(/\/+/g, '/').trim();
        clean = clean.replace(/^\/+|\/+$/g, '');
        return clean;
    }

    /**
     * Detect current active folder path
     */
    function detectCurrentPath() {
        try {
            // 1. Nextcloud Vue Router currentRoute (Hub 10 / Vue 3)
            if (window.OCP && window.OCP.Files && window.OCP.Files.Router) {
                const cur = window.OCP.Files.Router.currentRoute;
                if (cur && cur.value && cur.value.query && cur.value.query.dir) {
                    return normalizeDirectory(cur.value.query.dir);
                }
            }

            // 2. Query string (?dir=...)
            const params = new URLSearchParams(window.location.search);
            const dirParam = params.get('dir');
            if (dirParam) {
                return normalizeDirectory(dirParam);
            }

            // 3. Fallback: Archive Portal or default
            return 'Enterprise_Archive';
        } catch (e) {
            return 'Enterprise_Archive';
        }
    }

    /**
     * Fetch authorized navigation resources from backend
     */
    async function fetchNavResources() {
        if (STATE.isLoading || STATE.navData) return STATE.navData;
        STATE.isLoading = true;
        try {
            const token = (window.OC && window.OC.requesttoken) ? window.OC.requesttoken : '';
            const resp = await fetch('/index.php/apps/archive_autotag/api/nav/resources', {
                method: 'GET',
                headers: {
                    'OCS-APIREQUEST': 'true',
                    'requesttoken': token,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!resp.ok) {
                STATE.isLoading = false;
                return null;
            }

            const data = await resp.json();
            if (data && data.status === 'success') {
                STATE.navData = data;
            }
        } catch (err) {
            console.error('[GlobalNav] Failed to load resources:', err);
        } finally {
            STATE.isLoading = false;
        }
        return STATE.navData;
    }

    /**
     * Navigate user to specified directory smoothly without breaking layout
     */
    function navigateToDir(folderPath) {
        const fullDir = '/' + normalizeDirectory(folderPath);

        // 1. If already in Files App and Vue Router is available, navigate seamlessly without page reload!
        if (window.location.pathname.includes('/apps/files') && 
            window.OCP && window.OCP.Files && window.OCP.Files.Router) {
            try {
                window.OCP.Files.Router.goToRoute('filelist', { view: 'files' }, { dir: fullDir });
                updateActiveChip(normalizeDirectory(fullDir));
                return;
            } catch (routerErr) {
                console.warn('[GlobalNav] Router navigation fallback:', routerErr);
            }
        }

        // 2. Canonical target URL with preserved slashes (e.g. /Enterprise_Archive/Finance)
        // Nextcloud router requires slashes, NOT %2F!
        const encodedDir = fullDir.split('/').map(seg => encodeURIComponent(seg)).join('/');
        const targetUrl = '/index.php/apps/files/files?dir=' + encodedDir;

        window.location.href = targetUrl;
    }

    /**
     * Update active chip when directory changes
     */
    function updateActiveChip(pathStr) {
        STATE.currentPath = pathStr;
        const normalized = normalizeDirectory(pathStr);
        const segments = normalized.split('/');

        let activeId = 'all';
        if (normalized === 'Enterprise_Archive' || normalized === '') {
            activeId = 'root';
        } else if (segments.length > 1 && segments[0] === 'Enterprise_Archive') {
            activeId = segments[1];
        } else {
            activeId = segments[0];
        }

        const chips = document.querySelectorAll('#ea-global-nav-root .ea-nav-chip');
        chips.forEach(chip => {
            const chipId = chip.getAttribute('data-id');
            if (chipId === activeId || (activeId === 'root' && chipId === 'root')) {
                chip.classList.add('is-active');
            } else {
                chip.classList.remove('is-active');
            }
        });
    }

    /**
     * Find best mount target in DOM
     */
    function findMountTarget() {
        // 1. Archive Portal page
        const portalRoot = document.getElementById('archive-portal-root');
        if (portalRoot && portalRoot.parentNode) {
            return { parent: portalRoot.parentNode, insertBefore: portalRoot };
        }

        // 2. Files App: mount at the very top of main content area (never sibling to sidebar in #content)
        const mainContent = document.querySelector('main.app-content') ||
                            document.querySelector('#app-content-vue') ||
                            document.querySelector('.app-content') ||
                            document.getElementById('app-content');
        if (mainContent) {
            return { parent: mainContent, insertBefore: mainContent.firstChild };
        }

        // 3. Fallback: if header exists, mount before #content
        const content = document.getElementById('content');
        if (content && content.parentNode) {
            return { parent: content.parentNode, insertBefore: content };
        }

        return null;
    }

    /**
     * Main Render Function: Injects the navigation bar into DOM
     */
    async function renderGlobalNav() {
        const data = await fetchNavResources();
        if (!data || !data.items) {
            return;
        }

        const existing = document.getElementById('ea-global-nav-root');
        const mountInfo = findMountTarget();
        if (!mountInfo || !mountInfo.parent) {
            return;
        }

        // If already rendered and properly attached, update active chip
        if (existing && mountInfo.parent.contains(existing)) {
            updateActiveChip(detectCurrentPath());
            return;
        }

        if (existing) {
            existing.remove();
        }

        const rootEl = document.createElement('div');
        rootEl.id = 'ea-global-nav-root';

        // Access-Aware Global Navigation Bar
        const rowMain = document.createElement('div');
        rowMain.className = 'ea-nav-row-main';

        const brandBadge = document.createElement('div');
        brandBadge.className = 'ea-nav-brand-badge';
        brandBadge.innerHTML = '<span class="ea-nav-brand-icon">🏛️</span> <span>بایگانی سازمانی</span>';
        rowMain.appendChild(brandBadge);

        const itemsTrack = document.createElement('div');
        itemsTrack.className = 'ea-nav-items-track';

        data.items.forEach(item => {
            const chip = document.createElement('a');
            chip.className = 'ea-nav-chip';
            chip.setAttribute('data-id', item.id);
            chip.href = 'javascript:void(0)';
            chip.title = item.description || item.name;

            const iconSpan = document.createElement('span');
            iconSpan.className = 'ea-chip-icon';
            iconSpan.textContent = item.icon || '📁';
            chip.appendChild(iconSpan);

            const labelSpan = document.createElement('span');
            labelSpan.textContent = item.name;
            chip.appendChild(labelSpan);

            chip.addEventListener('click', function(e) {
                e.preventDefault();
                if (item.type === 'all') {
                    if (window.location.pathname.includes('/apps/archive_autotag')) {
                        const clearBtn = document.querySelector('#archive-portal-root .ap-filter-clear, #archive-portal-root [data-action="clear-filters"]');
                        if (clearBtn) clearBtn.click();
                        else window.location.href = '/index.php/apps/archive_autotag/';
                    } else {
                        navigateToDir('Enterprise_Archive');
                    }
                } else {
                    navigateToDir(item.path);
                }
            });

            itemsTrack.appendChild(chip);
        });
        rowMain.appendChild(itemsTrack);

        // User badge
        if (data.user) {
            const userBadge = document.createElement('div');
            userBadge.className = 'ea-nav-user-badge';
            const groupStr = (data.user.groups && data.user.groups.length > 0) ? data.user.groups.join(', ') : 'عمومی';
            userBadge.innerHTML = '<span>👤</span> <span>' + (data.user.display_name || data.user.uid) + ' (' + groupStr + ')</span>';
            rowMain.appendChild(userBadge);
        }

        rootEl.appendChild(rowMain);

        // Mount to DOM safely
        if (mountInfo.insertBefore) {
            mountInfo.parent.insertBefore(rootEl, mountInfo.insertBefore);
        } else {
            mountParent.appendChild(rootEl);
        }

        // Initialize display
        updateActiveChip(detectCurrentPath());
    }

    // Attach listeners for route and directory changes
    function setupEventListeners() {
        window.addEventListener('popstate', () => {
            setTimeout(() => {
                renderGlobalNav();
                updateActiveChip(detectCurrentPath());
            }, 50);
        });

        window.addEventListener('hashchange', () => {
            setTimeout(() => {
                renderGlobalNav();
                updateActiveChip(detectCurrentPath());
            }, 50);
        });

        // Periodic check to catch async Nextcloud Files client navigation & mount hydration
        setInterval(() => {
            const navEl = document.getElementById('ea-global-nav-root');
            if (!navEl || !document.body.contains(navEl)) {
                renderGlobalNav();
            } else {
                const detected = detectCurrentPath();
                if (detected !== STATE.currentPath) {
                    updateActiveChip(detected);
                }
            }
        }, 400);
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            renderGlobalNav();
            setupEventListeners();
        });
    } else {
        renderGlobalNav();
        setupEventListeners();
    }
})();

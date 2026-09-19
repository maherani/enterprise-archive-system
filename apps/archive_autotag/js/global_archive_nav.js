/**
 * Enterprise Archive System - Access-Aware Global Navigation Bar (Requirement 16)
 * Real-time, Zero-Leakage navigation synced with Location, Upload, Auto-Tagging, Rename, and Move.
 */
(function() {
    'use strict';

    // Prevent execution on public/login pages
    if (window.location.pathname.includes('/login') || 
        window.location.pathname.includes('/s/')) {
        return;
    }

    const STATE = {
        navData: null,
        currentPath: 'Enterprise_Archive',
        isLoading: false,
    };

    /**
     * Normalize directory string to canonical logical path rooted at Enterprise_Archive
     */
    function normalizeDirectory(rawDir) {
        if (!rawDir || rawDir === '/' || rawDir.trim() === '') {
            return 'Enterprise_Archive';
        }
        let clean = rawDir.replace(/^[\/\\]+|[\/\\]+$/g, '');
        if (!clean.startsWith('Enterprise_Archive')) {
            clean = 'Enterprise_Archive/' + clean;
        }
        return clean;
    }

    /**
     * Detect current active folder path from URL or Nextcloud Files API
     */
    function detectCurrentPath() {
        try {
            // 1. Files app client-side object
            if (window.OCA && window.OCA.Files && window.OCA.Files.App && window.OCA.Files.App.fileList) {
                const flDir = window.OCA.Files.App.fileList.getCurrentDir();
                if (flDir) {
                    return normalizeDirectory(flDir);
                }
            }

            // 2. Query param 'dir'
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
     * Navigate user to specified directory
     */
    function navigateToDir(folderPath) {
        const fullDir = '/' + normalizeDirectory(folderPath);
        const targetUrl = '/index.php/apps/files/files?dir=' + encodeURIComponent(fullDir);

        if (window.location.pathname.includes('/apps/files') && 
            window.OCA && window.OCA.Files && window.OCA.Files.App && window.OCA.Files.App.fileList) {
            try {
                window.OCA.Files.App.fileList.changeDirectory(fullDir);
                updateActiveChip(normalizeDirectory(fullDir));
                return;
            } catch (e) {
                // fallback to href
            }
        }
        window.location.href = targetUrl;
    }

    /**
     * Update active chip when directory changes
     */
    function updateActiveChip(pathStr) {
        STATE.currentPath = pathStr;
        const segments = pathStr.split('/');
        const activeDept = segments.length > 1 ? segments[1] : (pathStr === 'Enterprise_Archive' ? 'root' : 'all');

        const chips = document.querySelectorAll('#ea-global-nav-root .ea-nav-chip');
        chips.forEach(chip => {
            const chipId = chip.getAttribute('data-id');
            if (chipId === activeDept || (activeDept === 'root' && chipId === 'root')) {
                chip.classList.add('is-active');
            } else {
                chip.classList.remove('is-active');
            }
        });
    }

    /**
     * Main Render Function: Injects the navigation bar into DOM
     */
    async function renderGlobalNav() {
        // If already rendered, just update active chip
        if (document.getElementById('ea-global-nav-root')) {
            updateActiveChip(detectCurrentPath());
            return;
        }

        const data = await fetchNavResources();
        if (!data || !data.items) {
            return;
        }

        // Target mount container
        let mountParent = null;
        let referenceNode = null;

        const portalRoot = document.getElementById('archive-portal-root');
        const appContent = document.getElementById('app-content');
        const content = document.getElementById('content');

        if (portalRoot) {
            mountParent = portalRoot.parentNode;
            referenceNode = portalRoot;
        } else if (appContent) {
            mountParent = appContent;
            referenceNode = appContent.firstChild;
        } else if (content) {
            mountParent = content;
            referenceNode = content.firstChild;
        } else {
            mountParent = document.body;
            referenceNode = document.body.firstChild;
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
                        // Reset filters in archive portal if present
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

        // Mount to DOM
        if (referenceNode) {
            mountParent.insertBefore(rootEl, referenceNode);
        } else {
            mountParent.appendChild(rootEl);
        }

        // Initialize display
        updateActiveChip(detectCurrentPath());
    }

    // Attach listeners for route and directory changes
    function setupEventListeners() {
        window.addEventListener('popstate', () => {
            setTimeout(() => updateActiveChip(detectCurrentPath()), 50);
        });

        window.addEventListener('hashchange', () => {
            setTimeout(() => updateActiveChip(detectCurrentPath()), 50);
        });

        // Periodic check to catch async Nextcloud Files client navigation
        setInterval(() => {
            const detected = detectCurrentPath();
            if (detected !== STATE.currentPath && document.getElementById('ea-global-nav-root')) {
                updateActiveChip(detected);
            }
        }, 300);
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

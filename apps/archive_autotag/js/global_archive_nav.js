/**
 * Enterprise Archive System - Access-Aware Global Navigation Bar & Current Path (Requirement 16)
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
                updateCurrentPathDisplay(normalizeDirectory(fullDir));
                return;
            } catch (e) {
                // fallback to href
            }
        }
        window.location.href = targetUrl;
    }

    /**
     * Build breadcrumbs DOM elements for current path
     */
    function renderBreadcrumbs(container, pathStr) {
        container.innerHTML = '';
        const segments = pathStr.split('/');

        let accumulated = '';
        segments.forEach((seg, index) => {
            if (index > 0) {
                accumulated += '/' + seg;
            } else {
                accumulated = seg;
            }

            const isLast = (index === segments.length - 1);
            const item = document.createElement('a');
            item.className = 'ea-breadcrumb-item' + (isLast ? ' is-current' : '');
            item.textContent = seg;
            item.href = 'javascript:void(0)';
            item.title = 'انتقال به: ' + accumulated;

            if (!isLast) {
                const targetDir = accumulated;
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    navigateToDir(targetDir);
                });
            }

            container.appendChild(item);

            if (!isLast) {
                const sep = document.createElement('span');
                sep.className = 'ea-breadcrumb-sep';
                sep.textContent = '‹';
                container.appendChild(sep);
            }
        });
    }

    /**
     * Update active chip and breadcrumbs when directory changes
     */
    function updateCurrentPathDisplay(pathStr) {
        STATE.currentPath = pathStr;
        const breadcrumbContainer = document.querySelector('#ea-global-nav-root .ea-breadcrumbs');
        if (breadcrumbContainer) {
            renderBreadcrumbs(breadcrumbContainer, pathStr);
        }

        // Determine active department
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
        // If already rendered, just update path
        if (document.getElementById('ea-global-nav-root')) {
            updateCurrentPathDisplay(detectCurrentPath());
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

        // Row 1: Access-Aware Global Navigation
        const row1 = document.createElement('div');
        row1.className = 'ea-nav-row-main';

        const brandBadge = document.createElement('div');
        brandBadge.className = 'ea-nav-brand-badge';
        brandBadge.innerHTML = '<span class="ea-nav-brand-icon">🏛️</span> <span>بایگانی سازمانی</span>';
        row1.appendChild(brandBadge);

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
        row1.appendChild(itemsTrack);

        // User badge
        if (data.user) {
            const userBadge = document.createElement('div');
            userBadge.className = 'ea-nav-user-badge';
            const groupStr = (data.user.groups && data.user.groups.length > 0) ? data.user.groups.join(', ') : 'عمومی';
            userBadge.innerHTML = '<span>👤</span> <span>' + (data.user.display_name || data.user.uid) + ' (' + groupStr + ')</span>';
            row1.appendChild(userBadge);
        }

        rootEl.appendChild(row1);

        // Row 2: Current Path / Breadcrumbs
        const row2 = document.createElement('div');
        row2.className = 'ea-nav-row-path';

        const pathInner = document.createElement('div');
        pathInner.className = 'ea-path-inner';

        const pathLabel = document.createElement('div');
        pathLabel.className = 'ea-path-label';
        pathLabel.innerHTML = '<span>📍</span> <span>مسیر فعلی:</span>';
        pathInner.appendChild(pathLabel);

        const breadcrumbs = document.createElement('div');
        breadcrumbs.className = 'ea-breadcrumbs';
        pathInner.appendChild(breadcrumbs);

        row2.appendChild(pathInner);

        // Actions (Copy Path)
        const actions = document.createElement('div');
        actions.className = 'ea-path-actions';

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'ea-btn-copy-path';
        copyBtn.innerHTML = '<span>📋</span> <span>کپی مسیر</span>';
        copyBtn.title = 'کپی مسیر فعلی در کلیپ‌بورد';

        copyBtn.addEventListener('click', function() {
            const fullPathToCopy = STATE.currentPath;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(fullPathToCopy).then(() => {
                    showCopiedFeedback(copyBtn);
                }).catch(() => fallbackCopy(fullPathToCopy, copyBtn));
            } else {
                fallbackCopy(fullPathToCopy, copyBtn);
            }
        });

        actions.appendChild(copyBtn);
        row2.appendChild(actions);

        rootEl.appendChild(row2);

        // Mount to DOM
        if (referenceNode) {
            mountParent.insertBefore(rootEl, referenceNode);
        } else {
            mountParent.appendChild(rootEl);
        }

        // Initialize display
        updateCurrentPathDisplay(detectCurrentPath());
    }

    function fallbackCopy(text, btn) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showCopiedFeedback(btn);
        } catch (e) {
            // ignore
        }
        document.body.removeChild(textarea);
    }

    function showCopiedFeedback(btn) {
        btn.classList.add('is-copied');
        const origHTML = btn.innerHTML;
        btn.innerHTML = '<span>✓</span> <span>کپی شد!</span>';
        setTimeout(() => {
            btn.classList.remove('is-copied');
            btn.innerHTML = origHTML;
        }, 1800);
    }

    // Attach listeners for route and directory changes
    function setupEventListeners() {
        window.addEventListener('popstate', () => {
            setTimeout(() => updateCurrentPathDisplay(detectCurrentPath()), 50);
        });

        window.addEventListener('hashchange', () => {
            setTimeout(() => updateCurrentPathDisplay(detectCurrentPath()), 50);
        });

        // Periodic check to catch async Nextcloud Files client navigation
        setInterval(() => {
            const detected = detectCurrentPath();
            if (detected !== STATE.currentPath && document.getElementById('ea-global-nav-root')) {
                updateCurrentPathDisplay(detected);
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

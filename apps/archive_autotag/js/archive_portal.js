/**
 * Enterprise Archive Portal - Modern Native Frontend Application
 * High-Performance, Minimalist, Reactive State, Faceted Search & Quick View
 * Compatible with Nextcloud 34 / Hub 10
 */
(function () {
    'use strict';

    // Application Reactive State
    var state = {
        allTags: [],
        selectedTagIds: new Set(),
        searchTerm: '',
        tagSearchTerm: '',
        files: [],
        isLoadingTags: false,
        isLoadingFiles: false,
        viewMode: localStorage.getItem('ea_view_mode') || 'grid', // 'grid' or 'table'
        activeDrawerFile: null,
        debounceTimer: null,
        userRole: null,
        pendingRequestsCount: 0,
        isFolderView: false,
        currentFolderDir: '/',
        highlightedFileId: null
    };

    // Helper: Escape HTML to prevent XSS
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Helper: Format Persian numbers
    function toPersianDigits(n) {
        var str = String(n);
        var persianMap = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return str.replace(/[0-9]/g, function (w) {
            return persianMap[+w];
        });
    }

    // Helper: Format Date
    function formatDate(timestamp) {
        if (!timestamp) return '';
        var d = new Date(timestamp * 1000);
        return d.toLocaleDateString('fa-IR', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // Helper: Format File Bytes
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '۰ بایت';
        var k = 1024;
        var sizes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        var val = (bytes / Math.pow(k, i)).toFixed(1);
        return toPersianDigits(val) + ' ' + sizes[i];
    }

    // Helper: Determine File Category & Styling Class
    function getFileMeta(fileName, mimetype) {
        var ext = (fileName || '').split('.').pop().toLowerCase();
        var mime = (mimetype || '').toLowerCase();

        if (mime === 'httpd/unix-directory' || mime.includes('directory') || ext === 'folder') {
            return {
                cls: 'mime-folder',
                label: 'پوشه',
                iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>'
            };
        }

        if (ext === 'pdf' || mime.includes('pdf')) {
            return { cls: 'mime-pdf', label: 'PDF', iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>' };
        }
        if (ext === 'doc' || ext === 'docx' || mime.includes('word')) {
            return { cls: 'mime-doc', label: 'WORD', iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' };
        }
        if (ext === 'xls' || ext === 'xlsx' || ext === 'csv' || mime.includes('spreadsheet') || mime.includes('excel')) {
            return { cls: 'mime-xls', label: 'EXCEL', iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>' };
        }
        if (['jpg', 'jpeg', 'png', 'svg', 'webp', 'gif'].includes(ext) || mime.includes('image')) {
            return { cls: 'mime-img', label: 'IMG', iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' };
        }
        if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext) || mime.includes('zip') || mime.includes('compressed')) {
            return { cls: 'mime-zip', label: 'ZIP', iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>' };
        }
        return { cls: 'mime-default', label: ext.toUpperCase() || 'FILE', iconSvg: '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>' };
    }

    // Helper: CSRF Token
    function getCsrfToken() {
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Helper: Show Toast Notification
    function showToast(message) {
        var existing = document.querySelector('.ea-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'ea-toast';
        toast.innerHTML = '<svg width="18" height="18" fill="none" stroke="#10b981" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span>' + escapeHtml(message) + '</span>';
        document.body.appendChild(toast);

        setTimeout(function () {
            toast.classList.add('show');
        }, 10);

        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 2800);
    }

    // API: Fetch Tags
    function fetchTags() {
        state.isLoadingTags = true;
        renderApp();

        fetch('/index.php/apps/archive_autotag/api/tags', {
            headers: { 'requesttoken': getCsrfToken(), 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            state.isLoadingTags = false;
            if (data && data.status === 'success') {
                state.allTags = data.tags || [];
            }
            renderApp();
            fetchFiles();
        })
        .catch(function (err) {
            state.isLoadingTags = false;
            console.error('Failed to fetch tags', err);
            renderApp();
        });
    }

    // API: Fetch Files (Faceted Multi-Tag + Keyword Filter)
    function fetchFiles() {
        state.isLoadingFiles = true;
        renderDocumentList();

        var params = new URLSearchParams();
        if (state.selectedTagIds.size > 0) {
            params.set('tags', Array.from(state.selectedTagIds).join(','));
        } else {
            params.set('tags', 'all');
        }

        if (state.searchTerm.trim() !== '') {
            params.set('q', state.searchTerm.trim());
        }

        var url = '/index.php/apps/archive_autotag/api/filter?' + params.toString();

        fetch(url, {
            headers: { 'requesttoken': getCsrfToken(), 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            state.isLoadingFiles = false;
            if (data && data.status === 'success') {
                state.files = data.files || [];
            } else {
                state.files = [];
            }
            renderDocumentList();
            renderStatsAndRibbon();
        })
        .catch(function (err) {
            state.isLoadingFiles = false;
            console.error('Failed to fetch files', err);
            renderDocumentList();
            renderStatsAndRibbon();
        });
    }

    // Action: Toggle Tag
    function toggleTag(tagId) {
        if (state.selectedTagIds.has(tagId)) {
            state.selectedTagIds.delete(tagId);
        } else {
            state.selectedTagIds.add(tagId);
        }
        renderTagBar();
        renderStatsAndRibbon();
        fetchFiles();
    }

    // Action: Clear All Filters
    function clearAllFilters() {
        state.selectedTagIds.clear();
        state.searchTerm = '';
        state.tagSearchTerm = '';
        var input = document.getElementById('ea-search-input');
        if (input) input.value = '';
        var tagSearchInput = document.getElementById('ea-tag-filter-search-input');
        if (tagSearchInput) tagSearchInput.value = '';
        renderTagBar();
        renderStatsAndRibbon();
        fetchFiles();
    }

    // Action: Set View Mode
    function setViewMode(mode) {
        state.viewMode = mode;
        localStorage.setItem('ea_view_mode', mode);
        var gridBtn = document.getElementById('ea-view-grid-btn');
        var tableBtn = document.getElementById('ea-view-table-btn');
        if (gridBtn && tableBtn) {
            if (mode === 'grid') {
                gridBtn.classList.add('active');
                tableBtn.classList.remove('active');
            } else {
                tableBtn.classList.add('active');
                gridBtn.classList.remove('active');
            }
        }
        renderDocumentList();
    }

    // Action: Open Drawer with Push Animation
    function openDrawer(file) {
        state.activeDrawerFile = file;
        renderDrawer();
        var drawer = document.getElementById('ea-drawer-backdrop');
        if (drawer) {
            drawer.classList.add('open');
        }
        var root = document.getElementById('archive-portal-root');
        if (root) {
            root.classList.add('drawer-open');
        }
    }

    // Action: Close Drawer with Smooth Restore
    function closeDrawer() {
        var drawer = document.getElementById('ea-drawer-backdrop');
        if (drawer) {
            drawer.classList.remove('open');
        }
        var root = document.getElementById('archive-portal-root');
        if (root) {
            root.classList.remove('drawer-open');
        }
        setTimeout(function () {
            state.activeDrawerFile = null;
        }, 350);
    }

    // Action: Copy File Link
    function copyFileLink(file) {
        var targetDir = file.target_dir || (file.is_dir ? ('/' + file.path.replace(/^\/+/g, '')) : ('/' + (file.parent_dir || '').replace(/^\/+/g, '')));
        targetDir = targetDir.replace(/\/+/g, '/');
        var folderUrl = file.folder_url || file.web_url || ('/index.php/apps/files/files?dir=' + encodeURIComponent(targetDir));
        var fullUrl = window.location.origin + folderUrl;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullUrl).then(function () {
                showToast('لینک داخلی سند کپی شد.');
            });
        } else {
            var el = document.createElement('textarea');
            el.value = fullUrl;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            showToast('لینک داخلی سند کپی شد.');
        }
    }


    // -------------------------------------------------------------------------
    // In-Portal Folder Navigation & Breadcrumb Management
    // -------------------------------------------------------------------------
    function openFolderInPortal(targetDir, highlightFileId) {
        state.isFolderView = true;
        state.viewMode = 'table';
        state.selectedTagIds.clear();
        state.searchTerm = '';
        state.highlightedFileId = highlightFileId || null;

        try {
            var newUrl = window.location.pathname + (targetDir && targetDir !== '/' ? ('?dir=' + encodeURIComponent(targetDir)) : '');
            window.history.pushState({ dir: targetDir }, '', newUrl);
        } catch (e) {}

        var gridBtn = document.getElementById('ea-view-grid-btn');
        var tableBtn = document.getElementById('ea-view-table-btn');
        if (gridBtn && tableBtn) {
            tableBtn.classList.add('active');
            gridBtn.classList.remove('active');
        }

        var searchInput = document.getElementById('ea-search-input');
        if (searchInput) searchInput.value = '';
        var clearBtn = document.getElementById('ea-search-clear');
        if (clearBtn) clearBtn.style.display = 'none';

        renderTagBar();
        navigateToFolder(targetDir, highlightFileId);
    }

    function navigateToFolder(targetDir, highlightFileId) {
        state.isFolderView = true;
        var cleanDir = (targetDir || '/').trim();
        if (!cleanDir.startsWith('/')) cleanDir = '/' + cleanDir;
        cleanDir = cleanDir.replace(/\/+/g, '/');
        state.currentFolderDir = cleanDir;
        if (highlightFileId) state.highlightedFileId = highlightFileId;

        state.isLoadingFiles = true;
        renderDocumentList();
        renderStatsAndRibbon();

        var url = '/index.php/apps/archive_autotag/api/folder-files?dir=' + encodeURIComponent(cleanDir);
        fetch(url, {
            headers: { 'requesttoken': getCsrfToken(), 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            state.isLoadingFiles = false;
            if (data && data.status === 'success') {
                state.files = data.files || [];
                state.currentFolderDir = data.dir || cleanDir;
            } else {
                state.files = [];
            }
            renderDocumentList();
            renderStatsAndRibbon();

            if (state.highlightedFileId) {
                setTimeout(function () {
                    var targetRow = document.querySelector('tr[data-file-id="' + state.highlightedFileId + '"], .ea-card[data-file-id="' + state.highlightedFileId + '"]');
                    if (targetRow) {
                        targetRow.classList.add('ea-row-highlight');
                        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 150);
            }
        })
        .catch(function (err) {
            state.isLoadingFiles = false;
            console.error('Failed to fetch folder files', err);
            state.files = [];
            renderDocumentList();
            renderStatsAndRibbon();
        });
    }

    function exitFolderMode() {
        state.isFolderView = false;
        state.currentFolderDir = null;
        state.highlightedFileId = null;
        try {
            window.history.pushState({}, '', window.location.pathname);
        } catch (e) {}
        renderTagBar();
        fetchFiles();
    }

    window._eaNavigateToFolder = openFolderInPortal;

    // Render: Header & Search Ribbon
    function renderApp() {
        var root = document.getElementById('archive-portal-root');
        if (!root) return;

        var userDisplay = root.getAttribute('data-user-display') || 'کاربر سازمانی';

        root.innerHTML = [
            '<div class="ea-container">',
            '  <!-- Sticky Top Controls Section (Header, Search, Multi-Tag Filter & Breadcrumbs) -->',
            '  <div class="ea-sticky-top-section" id="ea-sticky-top-section">',
            '    <!-- Header -->',
            '    <header class="ea-portal-header">',
            '    <div class="ea-header-brand">',
            '      <div class="ea-brand-icon">',
            '        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>',
            '      </div>',
            '      <div>',
            '        <h1 class="ea-brand-title">سامانه بایگانی اسناد سازمانی</h1>',
            '        <div class="ea-brand-subtitle">پورتال دسترسی سریع، فیلتر چندتگی و جستجوی اسناد • ' + escapeHtml(userDisplay) + '</div>',
            '      </div>',
            '    </div>',
            '    <div class="ea-header-actions">',
            '      <div id="ea-workflow-actions" class="ea-workflow-actions"></div>',
            '      <button id="ea-refresh-btn" class="ea-btn" title="تازه سازی اطلاعات">',
            '        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
            '        <span>به‌روزرسانی</span>',
            '      </button>',
            '      <button type="button" id="ea-folder-view-btn" class="ea-btn" title="مشاهده و مرور ساختار درختی پوشه‌ها در همین صفحه">',
            '        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
            '        <span>نمای پوشه‌ها</span>',
            '      </button>',
            '    </div>',
            '  </header>',
            '',
            '  <!-- Search Hero -->',
            '  <section class="ea-search-hero">',
            '    <div class="ea-search-box">',
            '      <span class="ea-search-icon">',
            '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            '      </span>',
            '      <input type="text" id="ea-search-input" class="ea-search-input" placeholder="جستجو در عنوان یا مسیر سند سازمانی..." value="' + escapeHtml(state.searchTerm) + '">',
            '      <span id="ea-search-count" class="ea-search-count-pill">' + toPersianDigits(state.files.length) + ' سند</span>',
            '      <button id="ea-search-clear" class="ea-search-clear" title="پاک کردن جستجو" style="display: ' + (state.searchTerm ? 'flex' : 'none') + ';">',
            '        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            '      </button>',
            '    </div>',
            '  </section>',
            '',
            '  <!-- Faceted Tag Cloud Bar -->',
            '  <div id="ea-tag-bar-container" class="ea-tag-bar-wrapper"></div>',
            '',
            '  <!-- Active Filter Badges Ribbon -->',
            '  <div id="ea-active-ribbon-container"></div>',
            '',
            '  </div>',
            '',
            '  <!-- Controls Bar (Stats + View Toggle) -->',
            '  <div class="ea-controls-bar">',
            '    <div id="ea-results-summary" class="ea-results-summary">در حال بارگذاری اسناد...</div>',
            '    <div class="ea-view-switch">',
            '      <button id="ea-view-grid-btn" class="ea-view-btn ' + (state.viewMode === 'grid' ? 'active' : '') + '">',
            '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            '        <span>کارت‌ها</span>',
            '      </button>',
            '      <button id="ea-view-table-btn" class="ea-view-btn ' + (state.viewMode === 'table' ? 'active' : '') + '">',
            '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
            '        <span>فهرست</span>',
            '      </button>',
            '    </div>',
            '  </div>',
            '',
            '  <!-- Document Workspace List/Grid -->',
            '  <main id="ea-document-container"></main>',
            '</div>',
            '',
            '<!-- Slide-out Quick View Drawer -->',
            '<div id="ea-drawer-backdrop" class="ea-drawer-backdrop">',
            '  <div class="ea-drawer" id="ea-drawer-panel">',
            '    <div class="ea-drawer-header">',
            '      <h3 class="ea-drawer-title" id="ea-drawer-title">جزئیات سند</h3>',
            '      <button class="ea-drawer-close" id="ea-drawer-close-btn" title="بستن">',
            '        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            '      </button>',
            '    </div>',
            '    <div class="ea-drawer-content" id="ea-drawer-body"></div>',
            '    <div class="ea-drawer-footer" id="ea-drawer-actions"></div>',
            '  </div>',
            '</div>'
        ].join('\n');

        attachEventListeners();
        renderWorkflowActions();
        renderTagBar();
        renderStatsAndRibbon();
        renderDocumentList();
    }

    // Attach Event Listeners
    function attachEventListeners() {
        var uploadBtn = document.getElementById('ea-upload-btn');
        if (uploadBtn) {
            uploadBtn.addEventListener('click', function () {
                openUploadModal();
            });
        }

        var refreshBtn = document.getElementById('ea-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                if (state.isFolderView) {
                    navigateToFolder(state.currentFolderDir || '/');
                } else {
                    fetchTags();
                }
            });
        }

        var folderViewBtn = document.getElementById('ea-folder-view-btn');
        if (folderViewBtn) {
            folderViewBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openFolderInPortal('/', null);
            });
        }

        var searchInput = document.getElementById('ea-search-input');
        var clearBtn = document.getElementById('ea-search-clear');
        if (searchInput) {
            searchInput.addEventListener('input', function (e) {
                state.searchTerm = e.target.value;
                if (state.searchTerm) {
                    state.isFolderView = false;
                }
                if (clearBtn) {
                    clearBtn.style.display = state.searchTerm ? 'flex' : 'none';
                }
                clearTimeout(state.debounceTimer);
                state.debounceTimer = setTimeout(function () {
                    fetchFiles();
                }, 250);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                state.searchTerm = '';
                searchInput.value = '';
                clearBtn.style.display = 'none';
                fetchFiles();
            });
        }

        var gridBtn = document.getElementById('ea-view-grid-btn');
        if (gridBtn) {
            gridBtn.addEventListener('click', function () { setViewMode('grid'); });
        }

        var tableBtn = document.getElementById('ea-view-table-btn');
        if (tableBtn) {
            tableBtn.addEventListener('click', function () { setViewMode('table'); });
        }

        var drawerBackdrop = document.getElementById('ea-drawer-backdrop');
        var drawerCloseBtn = document.getElementById('ea-drawer-close-btn');
        if (drawerBackdrop) {
            drawerBackdrop.addEventListener('click', function (e) {
                if (e.target === drawerBackdrop) closeDrawer();
            });
        }
        if (drawerCloseBtn) {
            drawerCloseBtn.addEventListener('click', closeDrawer);
        }
    }

    // Render: Faceted Multi-Tag Filter Card with Vertical Scroll & Real-Time Search (Obsidian Theme)
    function renderTagBar() {
        var container = document.getElementById('ea-tag-bar-container');
        if (!container) return;

        if (state.isLoadingTags && state.allTags.length === 0) {
            container.innerHTML = [
                '<div class="ea-tag-filter-card">',
                '  <div class="ea-tag-chips-wrapper ea-tag-bar">',
                '    <span class="ea-skeleton" style="width: 90px; height: 32px; display: inline-block;"></span>',
                '    <span class="ea-skeleton" style="width: 110px; height: 32px; display: inline-block;"></span>',
                '    <span class="ea-skeleton" style="width: 80px; height: 32px; display: inline-block;"></span>',
                '  </div>',
                '</div>'
            ].join('\n');
            return;
        }

        var existingCard = container.querySelector('.ea-tag-filter-card');
        var chipsWrapper = container.querySelector('#ea-tag-chips-wrapper');
        var searchInput = container.querySelector('#ea-tag-filter-search-input');

        if (!existingCard || !chipsWrapper || !searchInput) {
            var cardHtml = [
                '<div class="ea-tag-filter-card">',
                '  <div class="ea-tag-filter-header">',
                '    <div class="ea-tag-filter-title">',
                '      <span class="ea-tag-icon">🏷️</span>',
                '      <span>فیلتر برچسب‌های اسناد</span>',
                '    </div>',
                '    <div class="ea-tag-filter-controls">',
                '      <input type="text" class="ea-tag-search-input" id="ea-tag-filter-search-input" placeholder="جستجوی برچسب..." value="' + escapeHtml(state.tagSearchTerm) + '">',
                '      <button type="button" class="ea-tag-toggle-btn" id="ea-tag-toggle-btn" title="جمع کردن / باز کردن برچسب‌ها">',
                '        <span id="ea-tag-toggle-icon">▲</span>',
                '        <span id="ea-tag-toggle-text">بستن</span>',
                '      </button>',
                '    </div>',
                '  </div>',
                '  <div class="ea-tag-chips-wrapper ea-tag-bar" id="ea-tag-chips-wrapper"></div>',
                '</div>'
            ].join('\n');
            container.innerHTML = cardHtml;

            chipsWrapper = container.querySelector('#ea-tag-chips-wrapper');
            searchInput = container.querySelector('#ea-tag-filter-search-input');
            var toggleBtn = container.querySelector('#ea-tag-toggle-btn');

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    var isCollapsed = chipsWrapper.classList.toggle('is-collapsed');
                    var icon = document.getElementById('ea-tag-toggle-icon');
                    var text = document.getElementById('ea-tag-toggle-text');
                    if (icon) icon.textContent = isCollapsed ? '▼' : '▲';
                    if (text) text.textContent = isCollapsed ? 'مشاهده برچسب‌ها' : 'بستن';
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    state.tagSearchTerm = this.value;
                    if (chipsWrapper.classList.contains('is-collapsed')) {
                        chipsWrapper.classList.remove('is-collapsed');
                        var icon = document.getElementById('ea-tag-toggle-icon');
                        var text = document.getElementById('ea-tag-toggle-text');
                        if (icon) icon.textContent = '▲';
                        if (text) text.textContent = 'بستن';
                    }
                    renderTagChips(chipsWrapper);
                });
            }
        } else {
            if (document.activeElement !== searchInput) {
                searchInput.value = state.tagSearchTerm || '';
            }
        }

        renderTagChips(chipsWrapper);
    }

    function renderTagChips(chipsWrapper) {
        if (!chipsWrapper) return;

        var term = (state.tagSearchTerm || '').trim().toLowerCase();
        var visibleTags = state.allTags.filter(function (t) {
            if (!term) return true;
            return t.name.toLowerCase().indexOf(term) !== -1;
        });

        var html = [];

        // "All Documents" Tag Chip (visible when no search term or if search matches 'همه اسناد')
        if (!term || 'همه اسناد'.indexOf(term) !== -1) {
            var isAllActive = state.selectedTagIds.size === 0;
            html.push(
                '<div class="ea-tag-chip ' + (isAllActive ? 'active is-active' : '') + '" data-tag-all="true" title="نمایش همه اسناد بدون فیلتر برچسب">',
                '  <span>همه اسناد</span>',
                '</div>'
            );
        }

        if (visibleTags.length === 0 && (!term || 'همه اسناد'.indexOf(term) === -1)) {
            html.push('<div class="ea-tag-empty-msg">برچسبی یافت نشد.</div>');
        } else {
            visibleTags.forEach(function (tag) {
                var isActive = state.selectedTagIds.has(tag.id);
                var checkIcon = isActive ? '✓ ' : '';
                html.push(
                    '<div class="ea-tag-chip ' + (isActive ? 'active is-active' : '') + '" data-tag-id="' + tag.id + '" title="' + escapeHtml(tag.name) + '">',
                    '  <span>' + checkIcon + escapeHtml(tag.name) + '</span>',
                    '  <span class="ea-chip-count chip-count">' + toPersianDigits(tag.count) + '</span>',
                    '</div>'
                );
            });
        }

        chipsWrapper.innerHTML = html.join('');

        // Attach chip click handlers
        chipsWrapper.querySelectorAll('.ea-tag-chip').forEach(function (chip) {
            chip.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                state.isFolderView = false;
                if (chip.getAttribute('data-tag-all') === 'true') {
                    state.selectedTagIds.clear();
                    renderTagBar();
                    renderStatsAndRibbon();
                    fetchFiles();
                } else {
                    var id = parseInt(chip.getAttribute('data-tag-id'), 10);
                    toggleTag(id);
                }
            });
        });
    }

    // Render: Active Filters Ribbon & Stats Summary
    function renderStatsAndRibbon() {
        var ribbon = document.getElementById('ea-active-ribbon-container');
        var summary = document.getElementById('ea-results-summary');
        var countPill = document.getElementById('ea-search-count');

        if (state.isFolderView) {
            if (countPill) {
                countPill.textContent = toPersianDigits(state.files.length) + ' مورد';
            }
            if (summary) {
                if (state.isLoadingFiles) {
                    summary.innerHTML = 'در حال دریافت محتوای پوشه...';
                } else {
                    var folderDisplay = state.currentFolderDir || '/';
                    summary.innerHTML = '📁 مسیر پوشه: <strong>' + escapeHtml(folderDisplay) + '</strong> (' + toPersianDigits(state.files.length) + ' سند و پوشه)';
                }
            }
        } else {
            if (countPill) {
                countPill.textContent = toPersianDigits(state.files.length) + ' سند';
            }
            if (summary) {
                if (state.isLoadingFiles) {
                    summary.innerHTML = 'در حال جستجو و فیلتر اسناد...';
                } else {
                    summary.innerHTML = 'نمایش <strong>' + toPersianDigits(state.files.length) + '</strong> سند در دسترس';
                }
            }
        }

        if (!ribbon) return;

        if (state.isFolderView) {
            var currentPath = state.currentFolderDir || '/';
            var parts = currentPath.split('/').filter(Boolean);
            var breadcrumbsHtml = [
                '<div class="ea-folder-breadcrumb-bar">',
                '  <div class="ea-breadcrumb-path">',
                '    <button type="button" class="ea-breadcrumb-btn' + (parts.length === 0 ? ' is-active' : '') + '" data-folder-dir="/">',
                '      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
                '      <span>ریشه اسناد</span>',
                '    </button>'
            ];

            var accumulated = '';
            parts.forEach(function (part, idx) {
                accumulated += '/' + part;
                var isLast = idx === parts.length - 1;
                breadcrumbsHtml.push(
                    '<span class="ea-breadcrumb-separator">/</span>',
                    '<button type="button" class="ea-breadcrumb-btn' + (isLast ? ' is-active' : '') + '" data-folder-dir="' + escapeHtml(accumulated) + '">',
                    '  <span>' + escapeHtml(part) + '</span>',
                    '</button>'
                );
            });

            breadcrumbsHtml.push('  </div>');
            breadcrumbsHtml.push('  <div class="ea-folder-nav-actions">');

            breadcrumbsHtml.push(
                '    <button type="button" id="ea-folder-upload-btn-bar" class="ea-btn ea-btn-sm ea-btn-primary" title="بارگذاری سند سازمانی جدید در این پوشه">',
                '      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
                '      <span>بارگذاری فایل</span>',
                '    </button>'
            );

            if (parts.length > 0) {
                var parentDir = '/' + parts.slice(0, -1).join('/');
                breadcrumbsHtml.push(
                    '    <button type="button" id="ea-folder-up-btn" class="ea-btn ea-btn-sm" data-parent-dir="' + escapeHtml(parentDir) + '" title="رفتن به پوشه بالایی">',
                    '      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>',
                    '      <span>پوشه بالا</span>',
                    '    </button>'
                );
            }

            breadcrumbsHtml.push(
                '    <button type="button" id="ea-exit-folder-btn" class="ea-btn ea-btn-sm ea-btn-outline" title="خروج از مرور پوشه و بازگشت به فیلتر برچسب‌ها">',
                '      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
                '      <span>همه اسناد</span>',
                '    </button>',
                '  </div>',
                '</div>'
            );

            ribbon.innerHTML = breadcrumbsHtml.join('');

            var folderUploadBtnBar = ribbon.querySelector('#ea-folder-upload-btn-bar');
            if (folderUploadBtnBar) {
                folderUploadBtnBar.addEventListener('click', function () {
                    openUploadModal(null, state.currentFolderDir);
                });
            }

            ribbon.querySelectorAll('[data-folder-dir]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = btn.getAttribute('data-folder-dir');
                    openFolderInPortal(target, null);
                });
            });

            var upBtn = ribbon.querySelector('#ea-folder-up-btn');
            if (upBtn) {
                upBtn.addEventListener('click', function () {
                    var parent = upBtn.getAttribute('data-parent-dir') || '/';
                    openFolderInPortal(parent, null);
                });
            }

            var exitBtn = ribbon.querySelector('#ea-exit-folder-btn');
            if (exitBtn) {
                exitBtn.addEventListener('click', function () {
                    exitFolderMode();
                });
            }

            return;
        }

        if (state.selectedTagIds.size === 0 && !state.searchTerm) {
            ribbon.innerHTML = '';
            return;
        }

        var html = [
            '<div class="ea-active-filters-ribbon">',
            '  <span class="ea-ribbon-label">فیلترهای فعال:</span>'
        ];

        // Search term badge
        if (state.searchTerm) {
            html.push(
                '<span class="ea-active-badge">',
                '  <span>عبارت: ' + escapeHtml(state.searchTerm) + '</span>',
                '  <button data-clear="search">✕</button>',
                '</span>'
            );
        }

        // Tag badges
        state.selectedTagIds.forEach(function (tagId) {
            var tag = state.allTags.find(function (t) { return t.id === tagId; });
            var name = tag ? tag.name : ('برچسب ' + tagId);
            html.push(
                '<span class="ea-active-badge">',
                '  <span>' + escapeHtml(name) + '</span>',
                '  <button data-clear-tag="' + tagId + '">✕</button>',
                '</span>'
            );
        });

        html.push(
            '  <button class="ea-clear-all-btn" id="ea-clear-all-filters-btn">پاکسازی همه فیلترها</button>',
            '</div>'
        );

        ribbon.innerHTML = html.join('');

        var clearSearch = ribbon.querySelector('[data-clear="search"]');
        if (clearSearch) {
            clearSearch.addEventListener('click', function () {
                state.searchTerm = '';
                var input = document.getElementById('ea-search-input');
                if (input) input.value = '';
                fetchFiles();
            });
        }

        ribbon.querySelectorAll('[data-clear-tag]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tid = parseInt(btn.getAttribute('data-clear-tag'), 10);
                toggleTag(tid);
            });
        });

        var clearAllBtn = document.getElementById('ea-clear-all-filters-btn');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', clearAllFilters);
        }
    }

    // Render: Documents List or Grid
    function renderDocumentList() {
        var container = document.getElementById('ea-document-container');
        if (!container) return;

        if (state.isLoadingFiles) {
            renderSkeleton(container);
            return;
        }

        if (state.files.length === 0) {
            renderEmptyState(container);
            return;
        }

        if (state.viewMode === 'grid') {
            renderGrid(container);
        } else {
            renderTable(container);
        }
    }

    // Skeleton Loading
    function renderSkeleton(container) {
        var items = [];
        for (var i = 0; i < 6; i++) {
            items.push(
                '<div class="ea-card" style="min-height: 180px;">',
                '  <div class="ea-skeleton" style="width: 40%; height: 16px; margin-bottom: 12px;"></div>',
                '  <div class="ea-skeleton" style="width: 85%; height: 22px; margin-bottom: 10px;"></div>',
                '  <div class="ea-skeleton" style="width: 60%; height: 14px; margin-bottom: 24px;"></div>',
                '  <div class="ea-skeleton" style="width: 100%; height: 20px;"></div>',
                '</div>'
            );
        }
        container.innerHTML = '<div class="ea-document-grid">' + items.join('') + '</div>';
    }

    // Empty State
    function renderEmptyState(container) {
        if (state.isFolderView) {
            container.innerHTML = [
                '<div class="ea-empty-state">',
                '  <div class="ea-empty-icon">📁</div>',
                '  <div class="ea-empty-title">این پوشه خالی است یا سندی در این مسیر یافت نشد</div>',
                '  <div class="ea-empty-desc">می‌توانید از نوار بالای پوشه به سطوح بالاتر بروید یا سند جدیدی بارگذاری نمایید.</div>',
                '</div>'
            ].join('');
            return;
        }

        container.innerHTML = [
            '<div class="ea-empty-state">',
            '  <div class="ea-empty-icon">📁</div>',
            '  <div class="ea-empty-title">هیچ سندی با مشخصات انتخابی یافت نشد</div>',
            '  <div class="ea-empty-desc">برچسب‌ها یا عبارت جستجو را تغییر دهید تا اسناد دیگر نمایش داده شوند.</div>',
            '  <button class="ea-btn ea-btn-primary" id="ea-empty-clear-btn">مشاهده همه اسناد بایگانی</button>',
            '</div>'
        ].join('');

        var clearBtn = document.getElementById('ea-empty-clear-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', clearAllFilters);
        }
    }

    // Grid View
    function renderGrid(container) {
        var cards = [];

        state.files.forEach(function (file) {
            var isFolder = Boolean(file.is_dir || file.type === 'folder' || file.mimetype === 'httpd/unix-directory');
            var meta = getFileMeta(file.name, isFolder ? 'httpd/unix-directory' : file.mimetype);

            var tagsHtml = '';
            if (file.tags && file.tags.length > 0) {
                tagsHtml = file.tags.slice(0, 3).map(function (t) {
                    return '<span class="ea-mini-tag">' + escapeHtml(t.name) + '</span>';
                }).join('');
                if (file.tags.length > 3) {
                    tagsHtml += '<span class="ea-mini-tag">+' + toPersianDigits(file.tags.length - 3) + '</span>';
                }
            }

            var sizeDisplay = isFolder ? '—' : toPersianDigits(file.human_size);
            var isHighlighted = state.highlightedFileId && String(file.id) === String(state.highlightedFileId);

            cards.push(
                '<div class="ea-card' + (isHighlighted ? ' ea-row-highlight' : '') + '" data-file-id="' + file.id + '" data-is-dir="' + (isFolder ? 'true' : 'false') + '" data-folder-path="' + escapeHtml(file.path) + '">',
                '  <div class="ea-card-top">',
                '    <span class="ea-mime-badge ' + meta.cls + '">' + meta.label + '</span>',
                '    <span class="ea-card-size">' + sizeDisplay + '</span>',
                '  </div>',
                '  <div class="ea-card-body">',
                '    <div class="ea-card-icon-title">',
                '      <div class="ea-file-type-icon ' + meta.cls + '">' + meta.iconSvg + '</div>',
                '      <div class="ea-card-title" title="' + escapeHtml(file.name) + '">' + (isFolder ? '📁 ' : '') + escapeHtml(file.name) + '</div>',
                '    </div>',
                '    <div class="ea-card-tags">' + tagsHtml + '</div>',
                '  </div>',
                '  <div class="ea-card-footer">',
                '    <span class="ea-card-date">' + formatDate(file.mtime) + '</span>',
                '    <div class="ea-card-actions">',
                (isFolder ? 
                '      <button type="button" class="ea-icon-btn ea-folder-open-action" data-folder-path="' + escapeHtml(file.path) + '" title="ورود به پوشه">' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' +
                '      </button>' : ''),
                '      <button type="button" class="ea-icon-btn ea-action-preview" data-file-id="' + file.id + '" title="مشاهده سریع جزئیات">',
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
                '      </button>',
                (state.userRole && state.userRole.is_admin ? 
                '      <button type="button" class="ea-icon-btn ea-card-delete ea-btn-danger-icon" data-file-id="' + file.id + '" data-file-name="' + escapeHtml(file.name) + '" data-is-dir="' + (isFolder ? 'true' : 'false') + '" data-folder-path="' + escapeHtml(file.path) + '" title="حذف دائمی">' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>' +
                '      </button>' : ''),
                (!isFolder ? 
                '      <a href="' + escapeHtml(file.download_url) + '" class="ea-icon-btn" title="دانلود مستقیم" download>' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
                '      </a>' : ''),
                '    </div>',
                '  </div>',
                '</div>'
            );
        });

        container.innerHTML = '<div class="ea-document-grid">' + cards.join('') + '</div>';

        container.querySelectorAll('.ea-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.target.closest('a') || e.target.closest('button')) return;
                var isDir = card.getAttribute('data-is-dir') === 'true';
                var folderPath = card.getAttribute('data-folder-path');
                if (isDir && folderPath) {
                    openFolderInPortal('/' + folderPath.replace(/^\/+/g, ''), null);
                    return;
                }
                var fid = parseInt(card.getAttribute('data-file-id'), 10);
                var f = state.files.find(function (item) { return item.id === fid; });
                if (f) openDrawer(f);
            });
        });

        container.querySelectorAll('.ea-folder-open-action').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var folderPath = btn.getAttribute('data-folder-path');
                if (folderPath) {
                    openFolderInPortal('/' + folderPath.replace(/^\/+/g, ''), null);
                }
            });
        });

        container.querySelectorAll('.ea-action-preview').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var fid = parseInt(btn.getAttribute('data-file-id'), 10);
                var f = state.files.find(function (item) { return item.id === fid; });
                if (f) openDrawer(f);
            });
        });

        container.querySelectorAll('.ea-card-delete').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var fid = parseInt(btn.getAttribute('data-file-id'), 10);
                var fname = btn.getAttribute('data-file-name') || '';
                var isDir = btn.getAttribute('data-is-dir') === 'true';
                var fpath = btn.getAttribute('data-folder-path') || '';
                openDeleteConfirmModal({ id: fid, name: fname, is_dir: isDir, path: fpath });
            });
        });
    }


    // -------------------------------------------------------------------------
    // Requirement 27: Responsive, Readable & User-Resizable Table Layout
    // -------------------------------------------------------------------------
    var DEFAULT_COL_WIDTHS = {
        type: 50,
        title: 380,
        tags: 240,
        size: 90,
        date: 120,
        actions: 140
    };

    var COL_CONSTRAINTS = {
        type: { min: 40, max: 90 },
        title: { min: 160, max: 850 },
        tags: { min: 100, max: 500 },
        size: { min: 70, max: 160 },
        date: { min: 95, max: 200 },
        actions: { min: 115, max: 220 }
    };

    function getTableWidthsStorageKey() {
        var uid = (state.userRole && state.userRole.uid) || 'default';
        return 'enterprise_archive_table_widths_v1_' + uid;
    }

    function loadTableWidths() {
        try {
            var raw = localStorage.getItem(getTableWidthsStorageKey());
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    var widths = Object.assign({}, DEFAULT_COL_WIDTHS);
                    Object.keys(DEFAULT_COL_WIDTHS).forEach(function (k) {
                        var w = parseInt(parsed[k], 10);
                        if (!isNaN(w) && w >= COL_CONSTRAINTS[k].min && w <= COL_CONSTRAINTS[k].max) {
                            widths[k] = w;
                        }
                    });
                    return widths;
                }
            }
        } catch (e) {}
        return Object.assign({}, DEFAULT_COL_WIDTHS);
    }

    function saveTableWidths(widths) {
        try {
            localStorage.setItem(getTableWidthsStorageKey(), JSON.stringify(widths));
        } catch (e) {}
    }

    function resetTableWidths() {
        try {
            localStorage.removeItem(getTableWidthsStorageKey());
        } catch (e) {}
        state.tableWidths = Object.assign({}, DEFAULT_COL_WIDTHS);
        renderDocumentList();
    }

    function initTableResizing(container) {
        var table = container.querySelector('#ea-resizable-table');
        if (!table) return;

        var handles = container.querySelectorAll('.ea-resize-handle');
        handles.forEach(function (handle) {
            var colKey = handle.getAttribute('data-col');
            if (!colKey || !COL_CONSTRAINTS[colKey]) return;

            handle.addEventListener('mousedown', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var startX = e.clientX;
                var colEl = container.querySelector('#ea-col-' + colKey);
                var startWidth = (state.tableWidths && state.tableWidths[colKey]) || DEFAULT_COL_WIDTHS[colKey];
                var constraints = COL_CONSTRAINTS[colKey];

                handle.classList.add('is-resizing');
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';

                function onMouseMove(moveEvt) {
                    var deltaX = startX - moveEvt.clientX; // RTL: drag left increases width
                    var newWidth = Math.round(startWidth + deltaX);
                    if (newWidth < constraints.min) newWidth = constraints.min;
                    if (newWidth > constraints.max) newWidth = constraints.max;

                    if (colEl) {
                        colEl.style.width = newWidth + 'px';
                    }
                    if (!state.tableWidths) state.tableWidths = loadTableWidths();
                    state.tableWidths[colKey] = newWidth;
                }

                function onMouseUp() {
                    handle.classList.remove('is-resizing');
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    saveTableWidths(state.tableWidths);
                }

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });

            // Double click to auto-fit / reset specific column
            handle.addEventListener('dblclick', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var defW = DEFAULT_COL_WIDTHS[colKey];
                if (!state.tableWidths) state.tableWidths = loadTableWidths();
                state.tableWidths[colKey] = defW;
                var colEl = container.querySelector('#ea-col-' + colKey);
                if (colEl) colEl.style.width = defW + 'px';
                saveTableWidths(state.tableWidths);
            });
        });

        var resetBtn = container.querySelector('#ea-table-reset-widths');
        if (resetBtn) {
            resetBtn.addEventListener('click', function (e) {
                e.preventDefault();
                resetTableWidths();
            });
        }
    }

    // Table View
    function renderTable(container) {
        if (!state.tableWidths) {
            state.tableWidths = loadTableWidths();
        }
        var tw = state.tableWidths;

        var rows = [];

        state.files.forEach(function (file) {
            var isFolder = Boolean(file.is_dir || file.type === 'folder' || file.mimetype === 'httpd/unix-directory');
            var meta = getFileMeta(file.name, isFolder ? 'httpd/unix-directory' : file.mimetype);
            var tagsText = (file.tags || []).map(function (t) {
                return '<span class="ea-mini-tag">' + escapeHtml(t.name) + '</span>';
            }).join(' ');

            var sizeDisplay = isFolder ? '—' : toPersianDigits(file.human_size);
            var isHighlighted = state.highlightedFileId && String(file.id) === String(state.highlightedFileId);

            rows.push(
                '<tr data-file-id="' + file.id + '" data-is-dir="' + (isFolder ? 'true' : 'false') + '" data-folder-path="' + escapeHtml(file.path) + '" class="' + (isFolder ? 'ea-folder-row' : 'ea-file-row') + (isHighlighted ? ' ea-row-highlight' : '') + '" style="cursor: pointer;">',
                '  <td class="ea-cell-nowrap" style="text-align: center;"><span class="ea-mime-badge ' + meta.cls + '">' + meta.label + '</span></td>',
                '  <td title="' + escapeHtml(file.name + (file.metadata && file.metadata.subject ? ' - ' + file.metadata.subject : '')) + '">',
                '    <div class="ea-cell-title-wrap">',
                '      <strong>' + (isFolder ? '📁 ' : '') + escapeHtml(file.name) + '</strong>',
                (file.metadata && file.metadata.subject ? '      <div class="ea-cell-meta-sub">📋 ' + escapeHtml(file.metadata.subject) + (file.metadata.document_number ? ' (' + escapeHtml(file.metadata.document_number) + ')' : '') + '</div>' : ''),
                '    </div>',
                '    <small class="ea-cell-path-sub" title="' + escapeHtml(file.parent_dir || file.path || 'ریشه بایگانی') + '">' + escapeHtml(file.parent_dir || file.path || 'ریشه بایگانی') + '</small>',
                '  </td>',
                '  <td><div class="ea-cell-tags-wrap">' + (tagsText || '<span style="color:var(--ea-text-subtle); font-size:0.75rem;">—</span>') + '</div></td>',
                '  <td class="ea-cell-nowrap" style="direction: ltr; text-align: left;">' + sizeDisplay + '</td>',
                '  <td class="ea-cell-nowrap">' + formatDate(file.mtime) + '</td>',
                '  <td class="ea-cell-nowrap" style="text-align: left;">',
                '    <div style="display: flex; gap: 6px; justify-content: flex-end;">',

                (state.userRole && state.userRole.is_admin ? 
                '      <button type="button" class="ea-icon-btn ea-table-share" data-file-id="' + file.id + '" data-file-name="' + escapeHtml(file.name) + '" title="اشتراک با گروه">' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>' +
                '      </button>' +
                '      <button type="button" class="ea-icon-btn ea-table-delete ea-btn-danger-icon" data-file-id="' + file.id + '" data-file-name="' + escapeHtml(file.name) + '" data-is-dir="' + (isFolder ? 'true' : 'false') + '" data-folder-path="' + escapeHtml(file.path) + '" title="حذف دائمی">' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>' +
                '      </button>' : ''),
                '      <button type="button" class="ea-icon-btn ea-table-preview" data-file-id="' + file.id + '" title="مشاهده جزئیات">' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' +
                '      </button>',
                (!isFolder ? 
                '      <a href="' + escapeHtml(file.download_url) + '" class="ea-icon-btn" title="دانلود" download>' +
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
                '      </a>' : ''),
                '    </div>',
                '  </td>',
                '</tr>'
            );
        });

        var colGroupHtml = [
            '<colgroup>',
            '  <col id="ea-col-type" style="width: ' + tw.type + 'px;">',
            '  <col id="ea-col-title" style="width: ' + tw.title + 'px;">',
            '  <col id="ea-col-tags" style="width: ' + tw.tags + 'px;">',
            '  <col id="ea-col-size" style="width: ' + tw.size + 'px;">',
            '  <col id="ea-col-date" style="width: ' + tw.date + 'px;">',
            '  <col id="ea-col-actions" style="width: ' + tw.actions + 'px;">',
            '</colgroup>'
        ].join('');

        var theadHtml = [
            '<thead>',
            '  <tr>',
            '    <th data-col="type"><div class="ea-th-content"><span>نوع</span></div><div class="ea-resize-handle" data-col="type" title="تغییر عرض ستون نوع"></div></th>',
            '    <th data-col="title"><div class="ea-th-content"><span>عنوان سند و مسیر</span></div><div class="ea-resize-handle" data-col="title" title="تغییر عرض ستون عنوان"></div></th>',
            '    <th data-col="tags"><div class="ea-th-content"><span>برچسب‌ها</span></div><div class="ea-resize-handle" data-col="tags" title="تغییر عرض ستون برچسب‌ها"></div></th>',
            '    <th data-col="size" style="direction: ltr; text-align: left;"><div class="ea-th-content"><span>حجم</span></div><div class="ea-resize-handle" data-col="size" title="تغییر عرض ستون حجم"></div></th>',
            '    <th data-col="date"><div class="ea-th-content"><span>تاریخ</span></div><div class="ea-resize-handle" data-col="date" title="تغییر عرض ستون تاریخ"></div></th>',
            '    <th data-col="actions" style="text-align: left;"><div class="ea-th-content"><span>عملیات</span></div></th>',
            '  </tr>',
            '</thead>'
        ].join('');

        var toolbarHtml = [
            '<div class="ea-table-toolbar">',
            '  <span>نمای جدولی اسناد بایگانی (ستون‌های قابل تغییر اندازه با کشیدن ماوس)</span>',
            '  <button type="button" class="ea-table-reset-btn" id="ea-table-reset-widths" title="بازنشانی اندازه تمام ستون‌ها به حالت پیش‌فرض">',
            '    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>',
            '    <span>بازنشانی اندازه ستون‌ها</span>',
            '  </button>',
            '</div>'
        ].join('');

        container.innerHTML = [
            '<div class="ea-table-container">',
            toolbarHtml,
            '  <table class="ea-table" id="ea-resizable-table">',
            colGroupHtml,
            theadHtml,
            '    <tbody>' + rows.join('') + '</tbody>',
            '  </table>',
            '</div>'
        ].join('');

        initTableResizing(container);

        container.querySelectorAll('tbody tr').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a') || e.target.closest('button')) return;
                var isDir = tr.getAttribute('data-is-dir') === 'true';
                var folderPath = tr.getAttribute('data-folder-path');
                if (isDir && folderPath) {
                    openFolderInPortal('/' + folderPath.replace(/^\/+/g, ''), null);
                    return;
                }
                var fid = parseInt(tr.getAttribute('data-file-id'), 10);
                var f = state.files.find(function (item) { return item.id === fid; });
                if (f) openDrawer(f);
            });
        });



        container.querySelectorAll('.ea-table-preview').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var fid = parseInt(btn.getAttribute('data-file-id'), 10);
                var f = state.files.find(function (item) { return item.id === fid; });
                if (f) openDrawer(f);
            });
        });

        container.querySelectorAll('.ea-table-share').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var fid = parseInt(btn.getAttribute('data-file-id'), 10);
                var fname = btn.getAttribute('data-file-name') || ('سند ' + fid);
                openGroupShareModal(fid, fname, 'file');
            });
        });

        container.querySelectorAll('.ea-table-delete').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var fid = parseInt(btn.getAttribute('data-file-id'), 10);
                var fname = btn.getAttribute('data-file-name') || '';
                var isDir = btn.getAttribute('data-is-dir') === 'true';
                var fpath = btn.getAttribute('data-folder-path') || '';
                openDeleteConfirmModal({ id: fid, name: fname, is_dir: isDir, path: fpath });
            });
        });
    }

    // Render: Quick View Drawer Content
    function renderDrawer() {
        var file = state.activeDrawerFile;
        if (!file) return;

        var titleEl = document.getElementById('ea-drawer-title');
        var bodyEl = document.getElementById('ea-drawer-body');
        var actionsEl = document.getElementById('ea-drawer-actions');

        if (titleEl) {
            titleEl.textContent = file.name;
        }

        var isFolder = Boolean(file.is_dir || file.type === 'folder' || file.mimetype === 'httpd/unix-directory');
        var meta = getFileMeta(file.name, isFolder ? 'httpd/unix-directory' : file.mimetype);

        var userGroups = (state.userRole && state.userRole.groups) || [];
        var isGlobalAdmin = state.userRole && state.userRole.is_admin;
        var isSubadmin = state.userRole && state.userRole.is_subadmin;
        var subadminGroups = (state.userRole && state.userRole.subadmin_groups) || [];

        var matchedSubadminGroup = null;
        if (isSubadmin && subadminGroups.length > 0) {
            matchedSubadminGroup = subadminGroups[0];
        }

        var tagsBadges = (file.tags || []).map(function (t) {
            var rawName = t.name;
            var cleanDisplay = rawName;
            var canRemove = isGlobalAdmin;

            if (matchedSubadminGroup) {
                var pfx = matchedSubadminGroup + '_';
                if (rawName.startsWith(pfx)) {
                    canRemove = true;
                    cleanDisplay = rawName.substring(pfx.length);
                }
            }
            var removeBtn = canRemove
                ? ' <button class="ea-tag-del-btn" onclick="window._eaRemoveTagFromFile(\'' + escapeHtml(matchedSubadminGroup) + '\', ' + t.id + ', ' + file.id + ', \'' + escapeHtml(cleanDisplay) + '\')" title="حذف این تگ از سند" style="background:none; border:none; color:#ef4444; font-weight:bold; cursor:pointer; font-size:12px; padding:0 2px; margin-right:4px;">✕</button>'
                : '';
            return '<span class="ea-mini-tag" style="background: var(--ea-primary-glow); color: var(--ea-primary); font-size: 0.8rem; padding: 4px 10px; display:inline-flex; align-items:center;">🏷️ ' + escapeHtml(cleanDisplay) + removeBtn + '</span>';
        }).join(' ');

        var targetDir = isFolder 
            ? ('/' + (file.path || file.name).replace(/^\/+/g, '')) 
            : (file.target_dir || ('/' + (file.parent_dir || '').replace(/^\/+/g, '')));
        targetDir = targetDir.replace(/\/+/g, '/');
        if (!targetDir.startsWith('/')) targetDir = '/' + targetDir;

        var docMeta = file.metadata || null;
        var metaBoxHtml = '';
        if (docMeta) {
            var confClass = 'ea-conf-normal';
            var confText = 'عادی';
            if (docMeta.confidentiality === 'confidential') {
                confClass = 'ea-conf-confidential';
                confText = 'محرمانه';
            } else if (docMeta.confidentiality === 'secret') {
                confClass = 'ea-conf-secret';
                confText = 'سری';
            }
            metaBoxHtml = [
                '<div class="ea-drawer-metadata-card">',
                '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">',
                '    <div style="font-size: 0.86rem; font-weight: 700; color: var(--ea-text-main);">📋 شناسنامه و مشخصات سند:</div>',
                '    <span class="ea-conf-badge ' + confClass + '">' + confText + '</span>',
                '  </div>',
                '  <div style="display:flex; flex-direction:column; gap:6px; font-size:0.82rem;">',
                '    <div><strong style="color:var(--ea-text-muted);">موضوع:</strong> <span style="color:var(--ea-text-main); font-weight:600;">' + escapeHtml(docMeta.subject || '—') + '</span></div>',
                (docMeta.document_number ? '    <div><strong style="color:var(--ea-text-muted);">شماره سند:</strong> <span style="font-family:monospace; color:var(--ea-text-main);">' + escapeHtml(docMeta.document_number) + '</span></div>' : ''),
                (docMeta.document_date ? '    <div><strong style="color:var(--ea-text-muted);">تاریخ سند:</strong> <span style="color:var(--ea-text-main);">' + escapeHtml(docMeta.document_date) + '</span></div>' : ''),
                (docMeta.issuer ? '    <div><strong style="color:var(--ea-text-muted);">مرجع صدور:</strong> <span style="color:var(--ea-text-main);">' + escapeHtml(docMeta.issuer) + '</span></div>' : ''),
                (docMeta.description ? '    <div style="margin-top:4px; padding-top:4px; border-top:1px dashed var(--ea-border);"><strong style="color:var(--ea-text-muted);">خلاصه:</strong> <div style="color:var(--ea-text-main); white-space:pre-wrap; margin-top:2px;">' + escapeHtml(docMeta.description) + '</div></div>' : ''),
                '  </div>',
                '</div>'
            ].join('\n');
        }

        if (bodyEl) {
            bodyEl.innerHTML = [
                '<div class="ea-drawer-preview-box">',
                '  <div class="ea-file-type-icon ' + meta.cls + '" style="width: 54px; height: 54px;">' + meta.iconSvg + '</div>',
                '  <div style="font-weight: 700; font-size: 1rem; color: var(--ea-text-main);">' + escapeHtml(file.name) + '</div>',
                '  <div style="font-size: 0.8rem; color: var(--ea-text-muted);">' + meta.label + ' Document • ' + (isFolder ? '—' : toPersianDigits(file.human_size)) + '</div>',
                '</div>',
                '',
                (metaBoxHtml ? metaBoxHtml : ''),
                '',
                '<div style="margin-bottom: 20px;">',
                '  <div style="font-size: 0.85rem; font-weight: 700; color: var(--ea-text-muted); margin-bottom: 8px;">برچسب‌های متصل سازمانی:</div>',
                '  <div style="display: flex; flex-wrap: wrap; gap: 6px;">' + (tagsBadges || '<span style="color: var(--ea-text-subtle);">فاقد برچسب</span>') + '</div>',
                '</div>','' + (isGlobalAdmin ? [
                '<div class="ea-drawer-tag-mgmt-card" style="margin-bottom: 20px; padding: 12px 14px; border-radius: var(--ea-radius); background: var(--ea-surface-elevated); border: 1px solid var(--ea-border);">',
                '  <div style="font-size: 0.84rem; font-weight: 700; color: var(--ea-text-main); margin-bottom: 8px;">🏷️ الصاق تگ به ' + (isFolder ? 'پوشه' : 'سند') + ' (مدیر ارشد):</div>',
                '  <div style="display: flex; gap: 8px;">',
                '    <select id="ea-drawer-admin-tag-select" class="ea-form-select" style="flex: 1; font-size: 0.82rem; padding: 4px 8px;">',
                '      <option value="">⏳ در حال دریافت تگ‌ها...</option>',
                '    </select>',
                '    <button type="button" id="ea-drawer-admin-add-tag-btn" class="ea-btn ea-btn-primary" style="padding: 4px 12px; font-size: 0.82rem; white-space: nowrap;">+ الصاق</button>',
                '  </div>',
                '  <div id="ea-drawer-admin-tag-msg" style="display:none; font-size: 0.8rem; margin-top: 6px;"></div>',
                '</div>'
                ].join('\n') : (matchedSubadminGroup ? [
                '<div class="ea-drawer-tag-mgmt-card" style="margin-bottom: 20px; padding: 12px 14px; border-radius: var(--ea-radius); background: var(--ea-surface-elevated); border: 1px solid var(--ea-border);">',
                '  <div style="font-size: 0.84rem; font-weight: 700; color: var(--ea-text-main); margin-bottom: 8px;">🏷️ الصاق تگ اختصاصی گروه [' + escapeHtml(matchedSubadminGroup) + ']:</div>',
                '  <div style="display: flex; gap: 8px;">',
                '    <select id="ea-drawer-tag-select" class="ea-form-select" style="flex: 1; font-size: 0.82rem; padding: 4px 8px;">',
                '      <option value="">⏳ در حال دریافت تگ‌های گروه...</option>',
                '    </select>',
                '    <button type="button" id="ea-drawer-add-tag-btn" class="ea-btn ea-btn-primary" style="padding: 4px 12px; font-size: 0.82rem; white-space: nowrap;">+ الصاق به سند</button>',
                '  </div>',
                '  <div id="ea-drawer-tag-msg" style="display:none; font-size: 0.8rem; margin-top: 6px;"></div>',
                '</div>'
                ].join('\n') : '')) + '',
                '',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">مسیر فایل:</span>',
                '  <span class="ea-meta-val"><button type="button" class="ea-drawer-path-link-btn" id="ea-drawer-path-btn" title="مشاهده این مسیر در همین صفحه">' + escapeHtml(file.path) + ' ↗</button></span>',
                '</div>',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">حجم فایل:</span>',
                '  <span class="ea-meta-val">' + (isFolder ? '—' : toPersianDigits(file.human_size)) + '</span>',
                '</div>',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">نوع محتوا (MIME):</span>',
                '  <span class="ea-meta-val">' + escapeHtml(file.mimetype) + '</span>',
                '</div>',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">تاریخ ایجاد / تغییر:</span>',
                '  <span class="ea-meta-val">' + formatDate(file.mtime) + '</span>',
                '</div>',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">شناسه یکتای سند (ID):</span>',
                '  <span class="ea-meta-val">' + toPersianDigits(file.id) + '</span>',
                '</div>'
            ].join('\n');
        }

        if (actionsEl) {
            var actionButtons = [];
            if (!isFolder) {
                actionButtons.push(
                    '<a href="' + escapeHtml(file.download_url) + '" class="ea-btn ea-btn-primary" download>',
                    '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
                    '  <span>دانلود سند</span>',
                    '</a>'
                );
            }
            actionButtons.push(
                '<button type="button" class="ea-btn" id="ea-drawer-copy-btn">',
                '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
                '  <span>کپی لینک</span>',
                '</button>',
                '<button type="button" class="ea-btn" id="ea-drawer-locate-btn" title="مشاهده مکان در پوشه در همین صفحه">',
                '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
                '  <span>مکان در پوشه</span>',
                '</button>'
            );

            if (state.userRole && state.userRole.is_admin) {
                actionButtons.push(
                    '<button type="button" class="ea-btn ea-btn-secondary" id="ea-drawer-share-btn" title="اشتراک‌گذاری با گروه‌ها">',
                    '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
                    '  <span>اشتراک با گروه</span>',
                    '</button>',
                    '<button type="button" class="ea-btn ea-btn-danger" id="ea-drawer-delete-btn" style="background:#7f1d1d;color:#fca5a5;border-color:#ef4444;" title="حذف دائمی منبع">',
                    '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>',
                    '  <span>حذف ' + (isFolder ? 'پوشه' : 'سند') + '</span>',
                    '</button>'
                );
            }
            actionsEl.innerHTML = actionButtons.join('\n');

            var copyBtn = document.getElementById('ea-drawer-copy-btn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    copyFileLink(file);
                });
            }

            function onLocateClick(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                closeDrawer();
                openFolderInPortal(targetDir, isFolder ? null : file.id);
            }

            var locateBtn = document.getElementById('ea-drawer-locate-btn');
            if (locateBtn) {
                locateBtn.addEventListener('click', onLocateClick);
            }

            var pathBtn = document.getElementById('ea-drawer-path-btn');
            if (pathBtn) {
                pathBtn.addEventListener('click', onLocateClick);
            }

            var shareBtn = document.getElementById('ea-drawer-share-btn');
            if (shareBtn) {
                shareBtn.addEventListener('click', function () {
                    openGroupShareModal(file.id, file.name, isFolder ? 'folder' : 'file');
                });
            }

            var drawerDelBtn = document.getElementById('ea-drawer-delete-btn');
            if (drawerDelBtn) {
                drawerDelBtn.addEventListener('click', function () {
                    openDeleteConfirmModal(file);
                });
            }

            if (isGlobalAdmin) {
                setupAdminDrawerTagActions(file);
            } else if (matchedSubadminGroup) {
                setupDrawerTagActions(file, matchedSubadminGroup);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Delegated Folder Creation Workflow & Modals (Group Admin & System Admin)
    // -------------------------------------------------------------------------

    function fetchUserRole() {
        fetch('/index.php/apps/archive_autotag/api/user-role', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.status === 'success' && data.role) {
                state.userRole = data.role;
                if (state.userRole.is_admin) {
                    fetchPendingRequestsCount();
                } else {
                    renderWorkflowActions();
                }
                renderDocumentList();
            }
        })
        .catch(function () {});
    }

    function fetchPendingRequestsCount() {
        fetch('/index.php/apps/archive_autotag/api/folder-requests?status=pending', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.status === 'success') {
                state.pendingRequestsCount = data.count || (data.requests ? data.requests.length : 0);
            }
            renderWorkflowActions();
        })
        .catch(function () {
            renderWorkflowActions();
        });
    }

    function renderWorkflowActions() {
        var container = document.getElementById('ea-workflow-actions');
        if (!container) return;

        if (state.userRole && state.userRole.is_group_admin) {
            container.innerHTML = [
                '<button id="ea-create-folder-req-btn" class="ea-btn ea-btn-primary" title="ثبت درخواست ایجاد پوشه جدید در آرشیو گروه">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
                '  <span>+ درخواست پوشه جدید</span>',
                '</button>',
                '<button id="ea-view-group-reqs-btn" class="ea-btn" title="مشاهده وضعیت درخواست‌های ثبت شده">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
                '  <span>درخواست‌های گروه</span>',
                '</button>',
                '<button id="ea-manage-group-tags-btn" class="ea-btn" title="مدیریت تگ‌های اختصاصی گروه">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
                '  <span>🏷️ تگ‌های گروه</span>',
                '</button>'
            ].join('\n');

            var createBtn = document.getElementById('ea-create-folder-req-btn');
            if (createBtn) createBtn.onclick = openCreateFolderRequestModal;

            var viewBtn = document.getElementById('ea-view-group-reqs-btn');
            if (viewBtn) viewBtn.onclick = openGroupRequestsModal;

            var groupTagsBtn = document.getElementById('ea-manage-group-tags-btn');
            if (groupTagsBtn) groupTagsBtn.onclick = openGroupTagManagementModal;

        } else if (state.userRole && state.userRole.is_admin) {
            var counterBadge = state.pendingRequestsCount > 0
                ? '<span class="ea-pending-counter">' + toPersianDigits(state.pendingRequestsCount) + '</span>'
                : '';
            container.innerHTML = [
                '<button id="ea-admin-create-folder-btn" class="ea-btn ea-btn-primary" title="ساخت مستقیم پوشه سازمانی جدید در ساختار آرشیو">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
                '  <span>+ ساخت پوشه جدید</span>',
                '</button>',
                '<button id="ea-admin-manage-reqs-btn" class="ea-btn ' + (state.pendingRequestsCount > 0 ? 'ea-btn-primary' : '') + '" title="بررسی و مدیریت درخواست‌های ایجاد پوشه">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="9 11 12 14 22 4"/></svg>',
                '  ' + counterBadge + '<span>مدیریت درخواست‌های پوشه</span>',
                '</button>',
                '<button id="ea-admin-manage-tags-btn" class="ea-btn" title="مدیریت مرکزی و حاکمیت تگ‌های سامانه">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
                '  <span>🏷️ مدیریت مرکزی تگ‌ها</span>',
                '</button>',
                '<button id="ea-admin-ai-security-btn" class="ea-btn" title="مدیریت سرویس‌ها، توکن‌های امن، سیاست‌های احراز هویت نمایندگی و تست زنده AI API">',
                '  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2 2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><rect x="4" y="8" width="16" height="12" rx="2"/><circle cx="9" cy="14" r="1.5"/><circle cx="15" cy="14" r="1.5"/><path d="M9 18h6"/></svg>',
                '  <span>🤖 مدیریت و تست AI API</span>',
                '</button>'
            ].join('\n');

            var createBtn = document.getElementById('ea-admin-create-folder-btn');
            if (createBtn) createBtn.onclick = openAdminCreateFolderModal;

            var adminBtn = document.getElementById('ea-admin-manage-reqs-btn');
            if (adminBtn) adminBtn.onclick = function () { openAdminManageRequestsModal(); };

            var manageTagsBtn = document.getElementById('ea-admin-manage-tags-btn');
            if (manageTagsBtn) manageTagsBtn.onclick = openAdminTagManagementModal;

            var aiSecBtn = document.getElementById('ea-admin-ai-security-btn');
            if (aiSecBtn) aiSecBtn.onclick = function () { openAiSecurityConsoleModal('services'); };
        } else {
            container.innerHTML = '';
        }
    }

    function closeModal() {
        var modal = document.getElementById('ea-active-modal');
        if (modal) {
            modal.remove();
        }
    }

    // Modal: Native File Upload with Mandatory Metadata (Requirement 25)
    function openUploadModal(initialFile, targetFolderDir) {
        closeModal();

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card" style="max-width: 600px;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            '      <span>بارگذاری سند و تکمیل متادیتای الزامی</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-upload-modal-close-btn" title="بستن">&times;</button>',
            '  </div>',
            '  <div class="ea-modal-body">',
            '    <div id="ea-upload-error" class="ea-form-error" style="display:none;"></div>',
            '    ',
            '    <label class="ea-form-label">۱. پوشه مقصد در بایگانی:</label>',
            '    <select id="ea-upload-target-select" class="ea-form-select"></select>',
            '    <div id="ea-upload-projected-tags" class="ea-upload-projected-tags" style="margin-top: 6px; margin-bottom: 12px;"></div>',
            '    ',
            '    <label class="ea-form-label">۲. انتخاب یا رها کردن فایل (Drag & Drop):</label>',
            '    <div id="ea-dropzone-box" class="ea-dropzone-box">',
            '      <div class="ea-dropzone-icon">',
            '        <svg width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            '      </div>',
            '      <div class="ea-dropzone-prompt">فایل را به این کادر بکشید و رها کنید</div>',
            '      <div class="ea-dropzone-subprompt">یا برای انتخاب فایل از رایانه خود کلیک نمایید</div>',
            '      <input type="file" id="ea-upload-file-input" style="display:none;">',
            '    </div>',
            '    ',
            '    <div id="ea-upload-file-card" class="ea-upload-file-card" style="display:none;">',
            '      <div class="ea-upload-file-icon">📄</div>',
            '      <div class="ea-upload-file-meta">',
            '        <div id="ea-upload-file-name" class="ea-upload-file-name"></div>',
            '        <div id="ea-upload-file-size" class="ea-upload-file-size"></div>',
            '      </div>',
            '      <button type="button" id="ea-upload-change-file" class="ea-btn" style="padding: 4px 10px; font-size: 0.8rem;">تغییر فایل</button>',
            '    </div>',
            '    ',
            '    <!-- Mandatory Metadata Fields (Requirement 25) -->',
            '    <div class="ea-metadata-form-section">',
            '      <div style="font-weight: 700; font-size: 0.86rem; color: var(--ea-text-main); margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">',
            '        <span>📋</span> <span>۳. مشخصات و متادیتای سند (الزامی قبل از بارگذاری):</span>',
            '      </div>',
            '      <div style="margin-bottom: 10px;">',
            '        <label class="ea-form-label" style="font-size: 0.82rem;">موضوع سند (الزامی) <span style="color: #ef4444;">*</span>:</label>',
            '        <input type="text" id="ea-meta-subject" class="ea-form-input" placeholder="مثال: گزارش تحلیلی رویدادهای امنیتی سه ماهه نخست" style="width: 100%; box-sizing: border-box;">',
            '        <div id="ea-meta-subject-hint" style="font-size: 0.76rem; color: var(--ea-text-muted); margin-top: 3px;">ورود موضوع سند الزامی است (حداقل ۲ کاراکتر).</div>',
            '      </div>',
            '      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">',
            '        <div>',
            '          <label class="ea-form-label" style="font-size: 0.82rem;">شماره سند (اختیاری):</label>',
            '          <input type="text" id="ea-meta-number" class="ea-form-input" placeholder="مثال: SEC-1405-09" style="width: 100%; box-sizing: border-box; font-family: monospace;">',
            '        </div>',
            '        <div>',
            '          <label class="ea-form-label" style="font-size: 0.82rem;">تاریخ سند (اختیاری):</label>',
            '          <input type="text" id="ea-meta-date" class="ea-form-input" placeholder="مثال: ۱۴۰۵/۰۶/۳۱" style="width: 100%; box-sizing: border-box;">',
            '        </div>',
            '      </div>',
            '      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">',
            '        <div>',
            '          <label class="ea-form-label" style="font-size: 0.82rem;">سطح محرمانگی <span style="color: #ef4444;">*</span>:</label>',
            '          <select id="ea-meta-confidentiality" class="ea-form-select" style="width: 100%; box-sizing: border-box;">',
            '            <option value="normal" selected>عادی (Normal)</option>',
            '            <option value="confidential">محرمانه (Confidential)</option>',
            '            <option value="secret">سری (Secret)</option>',
            '          </select>',
            '        </div>',
            '        <div>',
            '          <label class="ea-form-label" style="font-size: 0.82rem;">مرجع صدور / سازمان (اختیاری):</label>',
            '          <input type="text" id="ea-meta-issuer" class="ea-form-input" placeholder="مثال: مرکز عملیات امنیت (SOC)" style="width: 100%; box-sizing: border-box;">',
            '        </div>',
            '      </div>',
            '      <div>',
            '        <label class="ea-form-label" style="font-size: 0.82rem;">خلاصه و توضیحات تکمیلی (اختیاری):</label>',
            '        <textarea id="ea-meta-description" class="ea-form-input" rows="2" placeholder="توضیحات تکمیلی پیرامون محتوای سند..." style="width: 100%; box-sizing: border-box; resize: vertical;"></textarea>',
            '      </div>',
            '    </div>',
            '    ',
            '    <div id="ea-upload-progress-wrap" class="ea-upload-progress-wrap" style="display:none; margin-top: 12px;">',
            '      <div class="ea-upload-progress-bar">',
            '        <div id="ea-upload-progress-fill" class="ea-upload-progress-fill" style="width: 0%;"></div>',
            '      </div>',
            '      <div id="ea-upload-progress-text" class="ea-upload-progress-text">در حال ارسال سند: ۰٪</div>',
            '    </div>',
            '    ',
            '    <div style="margin-top: 10px; padding: 8px 12px; background: rgba(249, 115, 22, 0.08); border: 1px solid rgba(249, 115, 22, 0.22); border-radius: 6px; font-size: 0.79rem; color: #cbd5e1; line-height: 1.5;">',
            '      ✨ <strong>تگ‌گذاری خودکار سلسله‌مراتبی:</strong> پس از ذخیره متادیتا و تکمیل بارگذاری، تگ‌های پوشه والد به سند منتسب می‌شوند.',
            '    </div>',
            '  </div>',
            '  <div class="ea-modal-footer">',
            '    <button type="button" class="ea-btn" id="ea-upload-cancel-btn">انصراف</button>',
            '    <button type="button" class="ea-btn ea-btn-primary" id="ea-upload-submit-btn" disabled>',
            '      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
            '      <span>بارگذاری و ثبت سند</span>',
            '    </button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);

        var closeBtn = document.getElementById('ea-upload-modal-close-btn');
        var cancelBtn = document.getElementById('ea-upload-cancel-btn');
        var submitBtn = document.getElementById('ea-upload-submit-btn');
        var folderSelect = document.getElementById('ea-upload-target-select');
        var tagsContainer = document.getElementById('ea-upload-projected-tags');
        var dropzoneBox = document.getElementById('ea-dropzone-box');
        var fileInput = document.getElementById('ea-upload-file-input');
        var fileCard = document.getElementById('ea-upload-file-card');
        var fileNameEl = document.getElementById('ea-upload-file-name');
        var fileSizeEl = document.getElementById('ea-upload-file-size');
        var changeFileBtn = document.getElementById('ea-upload-change-file');
        var errorDiv = document.getElementById('ea-upload-error');
        var progressWrap = document.getElementById('ea-upload-progress-wrap');
        var progressFill = document.getElementById('ea-upload-progress-fill');
        var progressText = document.getElementById('ea-upload-progress-text');

        var subjectInput = document.getElementById('ea-meta-subject');
        var numberInput = document.getElementById('ea-meta-number');
        var dateInput = document.getElementById('ea-meta-date');
        var confSelect = document.getElementById('ea-meta-confidentiality');
        var issuerInput = document.getElementById('ea-meta-issuer');
        var descInput = document.getElementById('ea-meta-description');

        closeBtn.onclick = closeModal;
        cancelBtn.onclick = closeModal;

        var selectedFile = null;
        var folderMap = {};

        function checkCanSubmit() {
            var subj = (subjectInput ? subjectInput.value : '').trim();
            var hasFile = Boolean(selectedFile);
            var validSubj = subj.length >= 2;
            submitBtn.disabled = !(hasFile && validSubj);
        }

        if (subjectInput) {
            subjectInput.addEventListener('input', checkCanSubmit);
        }

        // 1. Group roots from user role
        if (state.userRole) {
            if (Array.isArray(state.userRole.member_groups) && state.userRole.member_groups.length > 0) {
                state.userRole.member_groups.forEach(function(grp) {
                    var p = '/' + grp;
                    folderMap[p] = { path: p, display: '📁 ' + grp + ' (ریشه گروه سازمانی)' };
                });
            }
            if (Array.isArray(state.userRole.subadmin_groups)) {
                state.userRole.subadmin_groups.forEach(function(grp) {
                    var p = '/' + grp;
                    folderMap[p] = { path: p, display: '📁 ' + grp + ' (مدیریت گروه سازمانی)' };
                });
            }
        }

        // 2. Scan state.files for all accessible directories
        if (Array.isArray(state.files)) {
            state.files.forEach(function(f) {
                var p = '';
                if (f.is_dir && f.target_dir) {
                    p = f.target_dir;
                } else if (f.parent_dir) {
                    p = '/' + f.parent_dir.replace(/^\/+/, '');
                }
                if (p && !p.startsWith('/files')) {
                    var clean = '/' + p.replace(/^\/+|\/+$/g, '');
                    if (!folderMap[clean]) {
                        var parts = clean.split('/').filter(Boolean);
                        var indent = '';
                        for (var i = 1; i < parts.length; i++) {
                            indent += '  ↳ ';
                        }
                        folderMap[clean] = { path: clean, display: (indent ? indent : '') + '📁 ' + clean };
                    }
                }
            });
        }

        function populateFolderSelect() {
            var preferred = targetFolderDir || (state.isFolderView ? state.currentFolderDir : '');
            if (preferred) {
                var cleanPref = '/' + preferred.replace(/^\/+|\/+$/g, '');
                if (!folderMap[cleanPref]) {
                    folderMap[cleanPref] = { path: cleanPref, display: '📁 ' + cleanPref };
                }
            }
            var paths = Object.keys(folderMap).sort();
            if (paths.length === 0) {
                folderSelect.innerHTML = '<option value="/SOC">📁 /SOC</option>';
            } else {
                folderSelect.innerHTML = paths.map(function(k) {
                    var item = folderMap[k];
                    var isSelected = preferred && (item.path === preferred || item.path.replace(/^\/+/, '') === preferred.replace(/^\/+/, ''));
                    return '<option value="' + escapeHtml(item.path) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(item.display) + '</option>';
                }).join('');
            }
            if (preferred) {
                for (var i = 0; i < folderSelect.options.length; i++) {
                    if (folderSelect.options[i].value === preferred || folderSelect.options[i].value.replace(/^\/+/, '') === preferred.replace(/^\/+/, '')) {
                        folderSelect.selectedIndex = i;
                        break;
                    }
                }
            }
            updateProjectedTags();
        }

        function updateProjectedTags() {
            var target = folderSelect.value || '';
            var segments = target.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
            if (segments.length === 0) {
                tagsContainer.innerHTML = '<span style="color:var(--ea-text-muted);">تگ خودکار پوشه: ریشه آرشیو</span>';
                return;
            }
            var pills = segments.map(function(s) {
                return '<span class="ea-upload-tag-pill">🏷️ ' + escapeHtml(s) + '</span>';
            }).join(' ');
            tagsContainer.innerHTML = '<span style="font-size:0.8rem; margin-left:6px; color:var(--ea-text-muted);">تگ‌های خودکار پوشه والد:</span> ' + pills;
        }

        folderSelect.onchange = updateProjectedTags;
        populateFolderSelect();

        // Also fetch from /api/group-folders
        if (state.userRole) {
            var groupsToQuery = (state.userRole.subadmin_groups || []).concat(state.userRole.is_admin ? ['SOC', 'CERT', 'Compliance_Unit'] : []);
            groupsToQuery.forEach(function(grp) {
                fetch('/index.php/apps/archive_autotag/api/group-folders?group_id=' + encodeURIComponent(grp), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.status === 'success' && Array.isArray(res.folders)) {
                        res.folders.forEach(function(f) {
                            var p = f.path ? '/' + grp + '/' + f.path.replace(/^\/+/, '') : '/' + grp;
                            var clean = '/' + p.replace(/^\/+|\/+$/g, '');
                            if (!folderMap[clean]) {
                                var indent = '';
                                for (var i = 0; i < (f.level || 0); i++) { indent += '  ↳ '; }
                                folderMap[clean] = { path: clean, display: indent + '📁 ' + (f.display || clean) };
                            }
                        });
                        populateFolderSelect();
                    }
                })
                .catch(function() {});
            });
        }

        function handleFile(file) {
            if (!file) return;
            selectedFile = file;
            dropzoneBox.style.display = 'none';
            fileCard.style.display = 'flex';
            fileNameEl.textContent = file.name;
            fileSizeEl.textContent = formatBytes(file.size);
            checkCanSubmit();
            errorDiv.style.display = 'none';
        }

        dropzoneBox.onclick = function() {
            fileInput.click();
        };

        fileInput.onchange = function() {
            if (fileInput.files && fileInput.files[0]) {
                handleFile(fileInput.files[0]);
            }
        };

        changeFileBtn.onclick = function() {
            selectedFile = null;
            fileInput.value = '';
            fileCard.style.display = 'none';
            dropzoneBox.style.display = 'block';
            checkCanSubmit();
        };

        dropzoneBox.ondragover = function(e) {
            e.preventDefault();
            dropzoneBox.classList.add('ea-dragover');
        };

        dropzoneBox.ondragleave = function(e) {
            e.preventDefault();
            dropzoneBox.classList.remove('ea-dragover');
        };

        dropzoneBox.ondrop = function(e) {
            e.preventDefault();
            dropzoneBox.classList.remove('ea-dragover');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                handleFile(e.dataTransfer.files[0]);
            }
        };

        if (initialFile) {
            handleFile(initialFile);
        }

        submitBtn.onclick = function() {
            var subjectVal = (subjectInput ? subjectInput.value : '').trim();
            if (!selectedFile) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'لطفاً ابتدا یک فایل را انتخاب فرمایید.';
                return;
            }
            if (!subjectVal || subjectVal.length < 2) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'ورود موضوع سند الزامی است (حداقل ۲ کاراکتر).';
                if (subjectInput) subjectInput.focus();
                return;
            }

            var targetDir = (folderSelect.value || '').replace(/^\/+|\/+$/g, '');

            var formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('target_folder', targetDir);
            formData.append('subject', subjectVal);
            if (numberInput && numberInput.value.trim()) {
                formData.append('document_number', numberInput.value.trim());
            }
            if (dateInput && dateInput.value.trim()) {
                formData.append('document_date', dateInput.value.trim());
            }
            if (confSelect && confSelect.value) {
                formData.append('confidentiality', confSelect.value);
            }
            if (issuerInput && issuerInput.value.trim()) {
                formData.append('issuer', issuerInput.value.trim());
            }
            if (descInput && descInput.value.trim()) {
                formData.append('description', descInput.value.trim());
            }

            submitBtn.disabled = true;
            cancelBtn.disabled = true;
            closeBtn.disabled = true;
            errorDiv.style.display = 'none';
            progressWrap.style.display = 'block';
            progressFill.style.width = '0%';
            progressText.textContent = 'در حال ارسال فایل و ثبت متادیتای الزامی...';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/index.php/apps/archive_autotag/api/upload-with-metadata', true);
            if (window.OC && window.OC.requestToken) {
                xhr.setRequestHeader('requesttoken', window.OC.requestToken);
            }
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = pct + '%';
                    progressText.textContent = 'در حال ارسال: ' + toPersianDigits(pct) + '٪ (' + formatBytes(e.loaded) + ' از ' + formatBytes(e.total) + ')';
                }
            };

            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    progressFill.style.width = '100%';
                    progressText.innerHTML = '<span style="color:#22c55e; font-weight:700;">✅ سند به همراه مشخصات و متادیتا با موفقیت بارگذاری و ثبت گردید.</span>';
                    setTimeout(function() {
                        closeModal();
                        showToast('سند «' + selectedFile.name + '» با موضوع «' + subjectVal + '» ثبت و تگ‌گذاری شد.');
                        if (state.isFolderView) {
                            navigateToFolder(state.currentFolderDir || '/');
                        } else {
                            fetchFiles();
                            fetchTags();
                        }
                    }, 1000);
                } else {
                    submitBtn.disabled = false;
                    cancelBtn.disabled = false;
                    closeBtn.disabled = false;
                    progressWrap.style.display = 'none';
                    errorDiv.style.display = 'block';
                    var msg = 'خطا در بارگذاری (وضعیت ' + xhr.status + ')';
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.message) msg = parsed.message;
                    } catch(e) {}
                    if (xhr.status === 403) {
                        msg = 'خطای عدم دسترسی (۴۰۳): شما مجوز بارگذاری در این پوشه را ندارید.';
                    } else if (xhr.status === 422) {
                        msg = msg || 'خطای اعتبارسنجی: تکمیل متادیتای موضوع سند الزامی است.';
                    }
                    errorDiv.textContent = msg;
                }
            };

            xhr.onerror = function() {
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
                closeBtn.disabled = false;
                progressWrap.style.display = 'none';
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'خطای ارتباط با سرور در حین بارگذاری سند.';
            };

            xhr.send(formData);
        };
    }

    function openAdminCreateFolderModal() {
        closeModal();

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card" style="max-width:540px;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
            '      <span>ساخت مستقیم پوشه سازمانی جدید در آرشیو (مدیر کل)</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-modal-close-btn">&times;</button>',
            '  </div>',
            '  <div class="ea-modal-body">',
            '    <div id="ea-form-error" class="ea-form-error" style="display:none;"></div>',
            '    <form id="ea-admin-create-folder-form">',
            '      <label class="ea-form-label">نام پوشه سازمانی جدید (اجباری):</label>',
            '      <input type="text" id="ea-admin-folder-name" class="ea-form-input" placeholder="مثال: قراردادها_1405 یا مستندات_فنی" required autocomplete="off">',
            '      <label class="ea-form-label">مسیر والد در آرشیو (پوشه والد):</label>',
            '      <select id="ea-admin-parent-path" class="ea-form-select">',
            '        <option value="">⏳ در حال دریافت ساختار پوشه‌های آرشیو...</option>',
            '      </select>',
            '      <div class="ea-form-help">پوشه‌ای که مایلید پوشه جدید درون آن ساخته شود را انتخاب نمایید (جهت ساخت در بالاترین سطح، «ریشه آرشیو سازمانی» را انتخاب فرمایید).</div>',
            '      <label class="ea-form-label">تخصیص دسترسی دپارتمان / گروه (اختیاری):</label>',
            '      <select id="ea-admin-group-id" class="ea-form-select">',
            '        <option value="">🏛️ عمومی سازمانی (کلیه کاربران و دپارتمان‌ها)</option>',
            '        <option value="SOC">🛡️ دپارتمان SOC (مرکز عملیات امنیت)</option>',
            '        <option value="CERT">🚨 دپارتمان CERT (امداد و واکنش به رخداد)</option>',
            '        <option value="Compliance_Unit">📋 واحد تطبیق و مقررات (Compliance Unit)</option>',
            '        <option value="Network">🌐 دپارتمان شبکه (Network)</option>',
            '        <option value="Finance">💰 امور مالی و حسابداری (Finance)</option>',
            '      </select>',
            '      <div class="ea-form-help">در صورت انتخاب یک دپارتمان، دسترسی و برچسب‌های سلسله‌مراتبی به صورت ایزوله به اعضای آن گروه تخصیص می‌یابد.</div>',
            '      <div class="ea-modal-footer">',
            '        <button type="button" class="ea-btn" id="ea-form-cancel-btn">انصراف</button>',
            '        <button type="submit" class="ea-btn ea-btn-primary" id="ea-form-submit-btn">ایجاد پوشه آرشیو</button>',
            '      </div>',
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);

        document.getElementById('ea-modal-close-btn').onclick = closeModal;
        document.getElementById('ea-form-cancel-btn').onclick = closeModal;

        // Fetch all archive folders for parent selection
        var parentSelect = document.getElementById('ea-admin-parent-path');
        fetch('/index.php/apps/archive_autotag/api/folders', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.status === 'success' && data.folders && data.folders.length > 0) {
                parentSelect.innerHTML = data.folders.map(function (f) {
                    return '<option value="' + escapeHtml(f.path) + '">' + escapeHtml(f.display || f.name) + '</option>';
                }).join('');
            } else {
                parentSelect.innerHTML = '<option value="">🏛️ ریشه آرشیو سازمانی (Enterprise_Archive)</option>';
            }
        })
        .catch(function () {
            parentSelect.innerHTML = '<option value="">🏛️ ریشه آرشیو سازمانی (Enterprise_Archive)</option>';
        });

        // Form submission
        var form = document.getElementById('ea-admin-create-folder-form');
        form.onsubmit = function (e) {
            e.preventDefault();
            var folderNameInput = document.getElementById('ea-admin-folder-name');
            var folderName = folderNameInput ? folderNameInput.value.trim() : '';
            var parentPath = parentSelect ? parentSelect.value : '';
            var groupSelect = document.getElementById('ea-admin-group-id');
            var groupId = groupSelect ? groupSelect.value : '';

            var errEl = document.getElementById('ea-form-error');
            errEl.style.display = 'none';

            if (!folderName) {
                errEl.innerText = 'لطفاً نام پوشه را وارد فرمایید.';
                errEl.style.display = 'block';
                return;
            }

            var submitBtn = document.getElementById('ea-form-submit-btn');
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ در حال ساخت پوشه...';

            fetch('/index.php/apps/archive_autotag/api/folders/create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'OCS-APIRequest': 'true'
                },
                body: JSON.stringify({
                    folder_name: folderName,
                    parent_path: parentPath,
                    group_id: groupId
                })
            })
            .then(function (r) {
                return r.json().then(function (data) {
                    return { ok: r.ok, data: data };
                });
            })
            .then(function (res) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'ایجاد پوشه آرشیو';
                if (!res.ok || res.data.status !== 'success') {
                    errEl.innerText = res.data.message || 'خطا در ایجاد پوشه.';
                    errEl.style.display = 'block';
                } else {
                    closeModal();
                    showToast(res.data.message || ('پوشه «' + folderName + '» با موفقیت ایجاد شد.'));
                    fetchFiles();
                    fetchTags();
                }
            })
            .catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'ایجاد پوشه آرشیو';
                errEl.innerText = 'خطای ارتباط با سرور: ' + err.message;
                errEl.style.display = 'block';
            });
        };
    }

    function openCreateFolderRequestModal() {
        closeModal();
        if (!state.userRole || !state.userRole.subadmin_groups || state.userRole.subadmin_groups.length === 0) {
            showToast('شما دسترسی ادمین برای هیچ گروهی ندارید.');
            return;
        }

        var groups = state.userRole.subadmin_groups;
        var groupOptions = groups.map(function (g) {
            return '<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>';
        }).join('');

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="#f97316" stroke-width="2.2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
            '      <span>درخواست ایجاد پوشه جدید در آرشیو</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-modal-close-btn" title="بستن">✕</button>',
            '  </div>',
            '  <div class="ea-modal-body">',
            '    <div class="ea-form-group">',
            '      <label class="ea-form-label">گروه سازمانی درخواست‌دهنده (ثابت و غیرقابل جعل):</label>',
            '      <select id="ea-form-group-id" class="ea-form-select">' + groupOptions + '</select>',
            '    </div>',
            '    <div class="ea-form-group">',
            '      <label class="ea-form-label">ادمین درخواست‌دهنده:</label>',
            '      <input type="text" class="ea-form-input" readonly value="' + escapeHtml(state.userRole.user_id) + '">',
            '    </div>',
            '    <div class="ea-form-group">',
            '      <label class="ea-form-label">نام پوشه سازمانی مورد درخواست (اجباری):</label>',
            '      <input type="text" id="ea-form-folder-name" class="ea-form-input" placeholder="مثال: گزارش_پدافند_۱۴۰۵" required>',
            '      <div class="ea-form-help">از کاراکترهای مجاز استفاده فرمایید؛ تگ متناظر با همین نام خودکار ساخته خواهد شد.</div>',
            '    </div>',
            '    <div class="ea-form-group">',
            '      <label class="ea-form-label">مسیر والد در آرشیو (پوشه والد):</label>',
            '      <select id="ea-form-target-path" class="ea-form-select">',
            '        <option value="">⏳ در حال دریافت پوشه‌های گروه...</option>',
            '      </select>',
            '      <div class="ea-form-help">پوشه‌ای که مایلید پوشه جدید داخل آن قرار گیرد را انتخاب نمایید (جهت ساخت مستقیم در سطح اصلی، «ریشه گروه» را انتخاب فرمایید).</div>',
            '    </div>',
            '    <div class="ea-form-group">',
            '      <label class="ea-form-label">توضیحات و ضرورت اداری (اجباری):</label>',
            '      <textarea id="ea-form-description" class="ea-form-textarea" rows="3" placeholder="تشریح لزوم ایجاد پوشه جهت ارزیابی و تأیید ادمین کل..." required></textarea>',
            '    </div>',
            '    <div id="ea-form-error" class="ea-rejection-box" style="display:none;"></div>',
            '  </div>',
            '  <div class="ea-modal-footer">',
            '    <button class="ea-btn" id="ea-form-cancel-btn">انصراف</button>',
            '    <button class="ea-btn ea-btn-primary" id="ea-form-submit-btn">ارسال درخواست برای مدیریت</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);

        document.getElementById('ea-modal-close-btn').onclick = closeModal;
        document.getElementById('ea-form-cancel-btn').onclick = closeModal;

        overlay.onclick = function (e) {
            if (e.target === overlay) closeModal();
        };

        var groupSelect = document.getElementById('ea-form-group-id');
        var pathSelect = document.getElementById('ea-form-target-path');

        function loadParentFolders(groupId) {
            if (!pathSelect) return;
            pathSelect.disabled = true;
            pathSelect.innerHTML = '<option value="">⏳ در حال دریافت پوشه‌های گروه ' + escapeHtml(groupId) + '...</option>';

            fetch('/index.php/apps/archive_autotag/api/group-folders?group_id=' + encodeURIComponent(groupId), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                pathSelect.disabled = false;
                if (data && data.status === 'success' && Array.isArray(data.folders) && data.folders.length > 0) {
                    pathSelect.innerHTML = data.folders.map(function (f) {
                        return '<option value="' + escapeHtml(f.path) + '">' + escapeHtml(f.display || (f.path ? '📁 ' + f.path : '📁 ریشه گروه (اصلی)')) + '</option>';
                    }).join('');
                } else {
                    pathSelect.innerHTML = '<option value="">📁 ریشه گروه (اصلی)</option>';
                }
            })
            .catch(function () {
                pathSelect.disabled = false;
                pathSelect.innerHTML = '<option value="">📁 ریشه گروه (اصلی)</option>';
            });
        }

        if (groupSelect) {
            groupSelect.onchange = function () {
                loadParentFolders(groupSelect.value);
            };
            loadParentFolders(groupSelect.value);
        }

        var submitBtn = document.getElementById('ea-form-submit-btn');
        submitBtn.onclick = function () {
            var folderName = document.getElementById('ea-form-folder-name').value.trim();
            var targetPath = document.getElementById('ea-form-target-path').value.trim();
            var desc = document.getElementById('ea-form-description').value.trim();
            var groupId = document.getElementById('ea-form-group-id').value;
            var errEl = document.getElementById('ea-form-error');

            errEl.style.display = 'none';

            if (!folderName) {
                errEl.innerText = 'لطفاً نام پوشه را وارد فرمایید.';
                errEl.style.display = 'block';
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerText = 'در حال ارسال...';

            fetch('/index.php/apps/archive_autotag/api/folder-requests', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    folder_name: folderName,
                    target_path: targetPath,
                    description: desc,
                    group_id: groupId
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'ارسال درخواست برای مدیریت';
                if (data && data.status === 'success') {
                    closeModal();
                    showToast('درخواست ایجاد پوشه با موفقیت ثبت شد و در انتظار بررسی ادمین کل است.');
                } else {
                    errEl.innerText = (data && data.message) ? data.message : 'خطا در ثبت درخواست.';
                    errEl.style.display = 'block';
                }
            })
            .catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'ارسال درخواست برای مدیریت';
                errEl.innerText = 'خطای ارتباط با سرور: ' + err.message;
                errEl.style.display = 'block';
            });
        };
    }

    function openGroupRequestsModal() {
        closeModal();
        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card ea-modal-card-lg ea-modal-card-cartable" style="max-width: 1100px; width: 95vw; max-height: 90vh;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="#f97316" stroke-width="2.2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            '      <span>درخواست‌های ایجاد پوشه گروه شما</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-modal-close-btn" title="بستن">✕</button>',
            '  </div>',
            '  <div class="ea-modal-body" id="ea-group-reqs-body">',
            '    <div style="text-align:center;padding:30px;color:var(--ea-text-muted);">در حال دریافت اطلاعات...</div>',
            '  </div>',
            '  <div class="ea-modal-footer">',
            '    <button class="ea-btn" id="ea-group-reqs-close-btn">بستن</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);
        document.getElementById('ea-modal-close-btn').onclick = closeModal;
        document.getElementById('ea-group-reqs-close-btn').onclick = closeModal;
        overlay.onclick = function (e) {
            if (e.target === overlay) closeModal();
        };

        fetch('/index.php/apps/archive_autotag/api/folder-requests', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var body = document.getElementById('ea-group-reqs-body');
            if (!body) return;

            var list = (data && data.requests) ? data.requests : [];
            if (list.length === 0) {
                body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--ea-text-muted);">هنوز هیچ درخواستی برای گروه شما ثبت نشده است.</div>';
                return;
            }

            var rows = list.map(function (req) {
                var statusHtml = getStatusBadgeHtml(req.status);
                var reasonHtml = req.rejection_reason
                    ? '<div class="ea-rejection-box" style="margin-top:6px;word-break:break-word;font-size:0.78rem;"><strong>دلیل رد درخواست:</strong> ' + escapeHtml(req.rejection_reason) + '</div>'
                    : '';
                var pathDisplay = req.target_path ? escapeHtml(req.target_path) : '<span style="color:var(--ea-text-dim);">ریشه گروه</span>';
                var descDisplay = req.description ? '<div style="font-size:0.75rem;color:var(--ea-text-dim);margin-top:3px;word-break:break-word;">' + escapeHtml(req.description) + '</div>' : '';

                return [
                    '<tr>',
                    '  <td style="word-break:break-word;min-width:140px;"><strong style="color:#ffffff;font-size:0.88rem;">' + escapeHtml(req.folder_name) + '</strong>' + descDisplay + '</td>',
                    '  <td style="word-break:break-all;font-size:0.82rem;">' + pathDisplay + '</td>',
                    '  <td style="text-align:center;white-space:nowrap;"><span class="ea-meta-tag-chip" style="margin:0;font-size:0.76rem;padding:2px 8px;">' + escapeHtml(req.group_id) + '</span></td>',
                    '  <td style="text-align:center;font-size:0.78rem;color:var(--ea-text-muted);white-space:nowrap;">' + formatDate(req.created_at) + '</td>',
                    '  <td style="text-align:center;">' + statusHtml + reasonHtml + '</td>',
                    '</tr>'
                ].join('\n');
            }).join('\n');

            body.innerHTML = [
                '<div class="ea-req-table-wrap">',
                '  <table class="ea-req-table">',
                '    <thead>',
                '      <tr>',
                '        <th style="min-width:140px;text-align:right;">نام پوشه</th>',
                '        <th style="width:120px;text-align:right;">مسیر والد</th>',
                '        <th style="width:80px;text-align:center;">گروه</th>',
                '        <th style="width:110px;text-align:center;">تاریخ ثبت</th>',
                '        <th style="width:140px;text-align:center;">وضعیت</th>',
                '      </tr>',
                '    </thead>',
                '    <tbody>' + rows + '</tbody>',
                '  </table>',
                '</div>'
            ].join('\n');
        })
        .catch(function (err) {
            var body = document.getElementById('ea-group-reqs-body');
            if (body) body.innerHTML = '<div class="ea-rejection-box">خطا در بارگذاری درخواست‌ها: ' + escapeHtml(err.message) + '</div>';
        });
    }

    function getStatusBadgeHtml(status) {
        if (status === 'approved') {
            return '<span class="ea-status-badge ea-status-approved">✔ تأیید شده</span>';
        }
        if (status === 'rejected') {
            return '<span class="ea-status-badge ea-status-rejected">✖ رد شده</span>';
        }
        if (status === 'failed') {
            return '<span class="ea-status-badge ea-status-failed">⚠ خطا در ایجاد</span>';
        }
        return '<span class="ea-status-badge ea-status-pending">⏳ در انتظار بررسی</span>';
    }

    function openAdminManageRequestsModal(filterStatus) {
        closeModal();
        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card ea-modal-card-lg ea-modal-card-cartable" style="max-width: 1280px; width: 96vw; max-height: 90vh;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="#f97316" stroke-width="2.2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="9 11 12 14 22 4"/></svg>',
            '      <span>پنل مدیریت و بررسی درخواست‌های پوشه آرشیو</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-modal-close-btn" title="بستن">✕</button>',
            '  </div>',
            '  <div class="ea-modal-toolbar">',
            '    <div class="ea-modal-toolbar-filters">',
            '      <span style="font-size:0.85rem;color:var(--ea-text-muted);font-weight:700;">فیلتر وضعیت:</span>',
            '      <select id="ea-admin-filter-status" class="ea-form-select" style="width:auto;padding:6px 14px;">',
            '        <option value="all">همه وضعیت‌ها</option>',
            '        <option value="pending"' + (filterStatus === 'pending' ? ' selected' : '') + '>در انتظار بررسی</option>',
            '        <option value="approved"' + (filterStatus === 'approved' ? ' selected' : '') + '>تأیید شده</option>',
            '        <option value="rejected"' + (filterStatus === 'rejected' ? ' selected' : '') + '>رد شده</option>',
            '        <option value="failed"' + (filterStatus === 'failed' ? ' selected' : '') + '>خطا</option>',
            '      </select>',
            '    </div>',
            '    <button id="ea-admin-refresh-list" class="ea-btn ea-btn-sm" title="به‌روزرسانی لیست">',
            '      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-left:4px;vertical-align:middle;"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
            '      <span>تازه سازی لیست</span>',
            '    </button>',
            '  </div>',
            '  <div class="ea-modal-body" id="ea-admin-reqs-body">',
            '    <div style="text-align:center;padding:30px;color:var(--ea-text-muted);">در حال بارگذاری اطلاعات...</div>',
            '  </div>',
            '  <div class="ea-modal-footer">',
            '    <button class="ea-btn" id="ea-admin-reqs-close-btn">بستن</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);
        document.getElementById('ea-modal-close-btn').onclick = closeModal;
        document.getElementById('ea-admin-reqs-close-btn').onclick = closeModal;
        overlay.onclick = function (e) {
            if (e.target === overlay) closeModal();
        };

        var statusSelect = document.getElementById('ea-admin-filter-status');
        statusSelect.onchange = function () {
            loadAdminRequests(statusSelect.value);
        };
        document.getElementById('ea-admin-refresh-list').onclick = function () {
            loadAdminRequests(statusSelect.value);
        };

        loadAdminRequests(filterStatus || 'all');
    }

    function loadAdminRequests(statusFilter) {
        var body = document.getElementById('ea-admin-reqs-body');
        if (!body) return;
        body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--ea-text-muted);">در حال بارگذاری اطلاعات...</div>';

        var url = '/index.php/apps/archive_autotag/api/folder-requests';
        if (statusFilter && statusFilter !== 'all') {
            url += '?status=' + encodeURIComponent(statusFilter);
        }

        fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var list = (data && data.requests) ? data.requests : [];
            if (list.length === 0) {
                body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--ea-text-muted);">هیچ درخواستی با این فیلتر یافت نشد.</div>';
                return;
            }

            var rows = list.map(function (req) {
                var statusHtml = getStatusBadgeHtml(req.status);
                var actionsHtml = '';

                if (req.status === 'pending') {
                    actionsHtml = [
                        '<div class="ea-table-actions" style="display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:nowrap;">',
                        '  <button type="button" class="ea-btn ea-btn-sm ea-btn-approve" data-id="' + req.id + '" data-folder-name="' + escapeHtml(req.folder_name) + '" data-group-id="' + escapeHtml(req.group_id) + '" style="white-space:nowrap;padding:6px 12px;font-size:0.82rem;">✔ تأیید و ساخت</button>',
                        '  <button type="button" class="ea-btn ea-btn-sm ea-btn-reject" data-id="' + req.id + '" data-folder-name="' + escapeHtml(req.folder_name) + '" style="white-space:nowrap;padding:6px 12px;font-size:0.82rem;">✖ رد درخواست</button>',
                        '</div>'
                    ].join('\n');
                } else if (req.status === 'rejected' && req.rejection_reason) {
                    actionsHtml = '<div style="font-size:0.75rem;color:#f87171;word-break:break-word;max-width:180px;line-height:1.3;" title="' + escapeHtml(req.rejection_reason) + '"><strong>دلیل رد:</strong> ' + escapeHtml(req.rejection_reason) + '</div>';
                } else if (req.status === 'approved') {
                    actionsHtml = '<span style="font-size:0.78rem;font-weight:700;color:#34d399;display:inline-flex;align-items:center;gap:4px;">✔ فعال شد</span>';
                } else if (req.status === 'failed' && req.error_message) {
                    actionsHtml = '<span style="font-size:0.75rem;color:#fca5a5;word-break:break-word;" title="' + escapeHtml(req.error_message) + '">⚠ خطا: ' + escapeHtml(req.error_message.substring(0, 35)) + '</span>';
                }

                var pathInfo = req.target_path ? '<div style="font-size:0.73rem;color:var(--ea-primary);margin-top:2px;">📁 مسیر: ' + escapeHtml(req.target_path) + '</div>' : '';
                var descInfo = req.description ? '<div style="font-size:0.75rem;color:var(--ea-text-dim);margin-top:4px;line-height:1.4;word-break:break-word;">' + escapeHtml(req.description) + '</div>' : '';

                return [
                    '<tr>',
                    '  <td style="text-align:center;font-weight:700;color:var(--ea-text-muted);font-size:0.8rem;white-space:nowrap;">#' + toPersianDigits(req.id) + '</td>',
                    '  <td style="word-break:break-word;min-width:240px;"><strong style="color:#ffffff;font-size:0.92rem;">' + escapeHtml(req.folder_name) + '</strong>' + pathInfo + descInfo + '</td>',
                    '  <td style="text-align:center;white-space:nowrap;"><span class="ea-meta-tag-chip" style="margin:0;font-size:0.76rem;padding:2px 8px;">' + escapeHtml(req.group_id) + '</span></td>',
                    '  <td style="text-align:center;font-size:0.8rem;word-break:break-all;">' + escapeHtml(req.requester_uid) + '</td>',
                    '  <td style="text-align:center;font-size:0.78rem;color:var(--ea-text-muted);white-space:nowrap;">' + formatDate(req.created_at) + '</td>',
                    '  <td style="text-align:center;white-space:nowrap;">' + statusHtml + '</td>',
                    '  <td style="text-align:center;min-width:200px;">' + actionsHtml + '</td>',
                    '</tr>'
                ].join('\n');
            }).join('\n');

            body.innerHTML = [
                '<div class="ea-req-table-wrap">',
                '  <table class="ea-req-table">',
                '    <thead>',
                '      <tr>',
                '        <th style="width:60px;text-align:center;">شناسه</th>',
                '        <th style="min-width:240px;text-align:right;">نام پوشه و توضیحات</th>',
                '        <th style="width:85px;text-align:center;">گروه</th>',
                '        <th style="width:110px;text-align:center;">ادمین متقاضی</th>',
                '        <th style="width:120px;text-align:center;">تاریخ ثبت</th>',
                '        <th style="width:125px;text-align:center;">وضعیت</th>',
                '        <th style="width:200px;min-width:200px;text-align:center;">عملیات</th>',
                '      </tr>',
                '    </thead>',
                '    <tbody>' + rows + '</tbody>',
                '  </table>',
                '</div>'
            ].join('\n');

            // Attach CSP-safe event listeners to Approve buttons
            body.querySelectorAll('.ea-btn-approve').forEach(function (btn) {
                btn.onclick = function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var id = btn.getAttribute('data-id');
                    var folderName = btn.getAttribute('data-folder-name') || '';
                    var groupId = btn.getAttribute('data-group-id') || '';
                    handleApproveRequest(btn, id, folderName, groupId);
                };
            });

            // Attach CSP-safe event listeners to Reject buttons
            body.querySelectorAll('.ea-btn-reject').forEach(function (btn) {
                btn.onclick = function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var id = btn.getAttribute('data-id');
                    var folderName = btn.getAttribute('data-folder-name') || '';
                    handleRejectRequest(btn, id, folderName);
                };
            });
        })
        .catch(function (err) {
            body.innerHTML = '<div class="ea-rejection-box">خطا در بارگذاری اطلاعات: ' + escapeHtml(err.message) + '</div>';
        });
    }

    function handleApproveRequest(btn, id, folderName, groupId) {
        if (!confirm('آیا از تأیید درخواست ایجاد پوشه «' + folderName + '» برای گروه «' + groupId + '» اطمینان دارید؟\nاین عملیات پوشه را در ساختار آرشیو ساخته و تگ متناظر را خودکار ثبت و مقید می‌کند.')) {
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.innerText = '⏳ در حال ساخت...';
        }

        var headers = { 'Accept': 'application/json' };
        if (window.OC && window.OC.requestToken) {
            headers['requesttoken'] = window.OC.requestToken;
        }

        fetch('/index.php/apps/archive_autotag/api/folder-requests/' + id + '/approve', {
            method: 'POST',
            headers: headers
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.status === 'success') {
                showToast('پوشه «' + folderName + '» با موفقیت ایجاد و تگ اختصاصی گروه الصاق شد.');
                fetchPendingRequestsCount();
                var statusSelect = document.getElementById('ea-admin-filter-status');
                loadAdminRequests(statusSelect ? statusSelect.value : 'all');
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '✔ تأیید و ساخت';
                }
                alert('خطا در تأیید درخواست: ' + ((data && data.message) ? data.message : 'نامشخص'));
            }
        })
        .catch(function (err) {
            if (btn) {
                btn.disabled = false;
                btn.innerText = '✔ تأیید و ساخت';
            }
            alert('خطای ارتباط با سرور: ' + err.message);
        });
    }

    function handleRejectRequest(btn, id, folderName) {
        var reason = prompt('لطفاً دلیل رد درخواست «' + folderName + '» را وارد فرمایید (اجباری - به ادمین گروه نمایش داده می‌شود):');
        if (reason === null) return;
        reason = reason.trim();
        if (!reason) {
            alert('ورود دلیل رد درخواست الزامی است.');
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.innerText = '⏳ در حال ثبت...';
        }

        var headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };
        if (window.OC && window.OC.requestToken) {
            headers['requesttoken'] = window.OC.requestToken;
        }

        fetch('/index.php/apps/archive_autotag/api/folder-requests/' + id + '/reject', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ reason: reason })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.status === 'success') {
                showToast('درخواست رد شد و دلیل ثبت گردید.');
                fetchPendingRequestsCount();
                var statusSelect = document.getElementById('ea-admin-filter-status');
                loadAdminRequests(statusSelect ? statusSelect.value : 'all');
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '✖ رد درخواست';
                }
                alert('خطا در رد درخواست: ' + ((data && data.message) ? data.message : 'نامشخص'));
            }
        })
        .catch(function (err) {
            if (btn) {
                btn.disabled = false;
                btn.innerText = '✖ رد درخواست';
            }
            alert('خطای ارتباط با سرور: ' + err.message);
        });
    }

    // Global Action Handlers for backwards compatibility
    window._eaApproveReq = function (id, folderName, groupId) {
        handleApproveRequest(null, id, folderName, groupId);
    };

    window._eaRejectReq = function (id, folderName) {
        handleRejectRequest(null, id, folderName);
    };


    function setupDrawerTagActions(file, grp) {
        var selectEl = document.getElementById('ea-drawer-tag-select');
        var addBtn = document.getElementById('ea-drawer-add-tag-btn');
        var msgEl = document.getElementById('ea-drawer-tag-msg');
        if (!selectEl || !addBtn) return;

        fetch('/index.php/apps/archive_autotag/api/group-tags?group_id=' + encodeURIComponent(grp), {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.status === 'success' && data.tags) {
                var currentTagIds = (file.tags || []).map(function (t) { return t.id; });
                var unassigned = data.tags.filter(function (t) { return currentTagIds.indexOf(t.id) === -1; });
                if (unassigned.length === 0) {
                    selectEl.innerHTML = '<option value="">(همه تگ‌های گروه روی سند اعمال شده‌اند)</option>';
                    addBtn.disabled = true;
                } else {
                    selectEl.innerHTML = '<option value="">-- انتخاب تگ گروه --</option>' + unassigned.map(function (t) {
                        return '<option value="' + t.id + '">' + escapeHtml(t.clean_name || t.name) + '</option>';
                    }).join('');
                    addBtn.disabled = false;
                }
            } else {
                selectEl.innerHTML = '<option value="">(عدم دریافت تگ‌ها)</option>';
            }
        })
        .catch(function () {
            selectEl.innerHTML = '<option value="">(خطای شبکه)</option>';
        });

        addBtn.onclick = function () {
            var selectedTagId = selectEl.value;
            if (!selectedTagId) {
                if (msgEl) {
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ef4444';
                    msgEl.innerText = 'لطفاً یک تگ را انتخاب کنید.';
                }
                return;
            }
            addBtn.disabled = true;
            addBtn.innerText = '⏳...';

            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

            fetch('/index.php/apps/archive_autotag/api/group-tags/assign', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ group_id: grp, tag_id: parseInt(selectedTagId, 10), file_id: file.id })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.status === 'success') {
                    showToast('تگ با موفقیت به سند الصاق شد.');
                    // Refresh file tags in state and reload drawer
                    var assignedTagId = parseInt(selectedTagId, 10);
                    var fullTagName = '[' + grp + '] ' + (selectEl.options[selectEl.selectedIndex].text || '');
                    if (!file.tags) file.tags = [];
                    file.tags.push({ id: assignedTagId, name: fullTagName });
                    renderDrawer();
                    renderDocumentList();
                    fetchTags();
                } else {
                    addBtn.disabled = false;
                    addBtn.innerText = '+ الصاق به سند';
                    if (msgEl) {
                        msgEl.style.display = 'block';
                        msgEl.style.color = '#ef4444';
                        msgEl.innerText = 'خطا: ' + ((data && data.message) ? data.message : 'نامشخص');
                    }
                }
            })
            .catch(function (err) {
                addBtn.disabled = false;
                addBtn.innerText = '+ الصاق به سند';
                if (msgEl) {
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ef4444';
                    msgEl.innerText = 'خطای ارتباط: ' + err.message;
                }
            });
        };
    }

    window._eaRemoveTagFromFile = function (grp, tagId, fileId, cleanName) {
        if (!confirm('آیا مایلید تگ «' + cleanName + '» از این سند جدا شود؟')) {
            return;
        }
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

        fetch('/index.php/apps/archive_autotag/api/group-tags/remove', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ group_id: grp, tag_id: tagId, file_id: fileId })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.status === 'success') {
                showToast('تگ «' + cleanName + '» از سند جدا گردید.');
                if (state.activeDrawerFile && state.activeDrawerFile.id === fileId) {
                    state.activeDrawerFile.tags = (state.activeDrawerFile.tags || []).filter(function (t) { return t.id !== tagId; });
                    renderDrawer();
                }
                // Update file in state.files as well
                for (var i = 0; i < state.files.length; i++) {
                    if (state.files[i].id === fileId) {
                        state.files[i].tags = (state.files[i].tags || []).filter(function (t) { return t.id !== tagId; });
                        break;
                    }
                }
                renderDocumentList();
                fetchTags();
            } else {
                alert('خطا در جداسازی تگ: ' + ((data && data.message) ? data.message : 'نامشخص'));
            }
        })
        .catch(function (err) {
            alert('خطای ارتباط: ' + err.message);
        });
    };

    function openGroupTagManagementModal() {
        closeModal();
        if (!state.userRole || !state.userRole.subadmin_groups || state.userRole.subadmin_groups.length === 0) {
            showToast('شما دسترسی ادمین برای هیچ گروهی ندارید.');
            return;
        }

        var groups = state.userRole.subadmin_groups;
        var selectedGroup = groups[0];

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card ea-modal-card-lg" style="max-width: 650px;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="#3b82f6" stroke-width="2.2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            '      <span>مدیریت تگ‌های اختصاصی گروه</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-modal-close-btn" title="بستن">✕</button>',
            '  </div>',
            '  <div class="ea-modal-body">',
            '    <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 16px; background: var(--ea-surface); padding: 12px 16px; border-radius: var(--ea-radius); border: 1px solid var(--ea-border);">',
            '      <label style="font-weight: 700; font-size: 0.9rem; color: var(--ea-text-main);">گروه سازمانی:</label>',
            '      <select id="ea-gtag-group-select" class="ea-form-select" style="max-width: 250px;">' + groups.map(function (g) {
                        return '<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>';
                   }).join('') + '</select>',
            '      <div style="font-size: 0.8rem; color: var(--ea-text-muted); margin-right: auto;">تگ‌های تعریف‌شده صرفاً توسط اعضای همین گروه قابل مشاهده و جستجو هستند.</div>',
            '    </div>',
            '    <!-- Create New Tag Form -->',
            '    <div style="background: var(--ea-surface-elevated); padding: 14px 16px; border-radius: var(--ea-radius); border: 1px solid var(--ea-border); margin-bottom: 20px;">',
            '      <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 10px; color: var(--ea-text-main);">+ ایجاد تگ اختصاصی جدید:</div>',
            '      <div style="display: flex; gap: 10px;">',
            '        <input type="text" id="ea-gtag-name-input" class="ea-form-input" placeholder="نام تگ جدید سازمانی (مثال: محرمانه_سطح۱، گزارش_دوره)..." style="flex: 1;">',
            '        <button id="ea-gtag-create-btn" class="ea-btn ea-btn-primary" style="white-space: nowrap;">',
            '          <span>+ ایجاد تگ</span>',
            '        </button>',
            '      </div>',
            '      <div id="ea-gtag-create-msg" style="margin-top: 8px; font-size: 0.82rem; display: none;"></div>',
            '    </div>',
            '    <!-- Group Tags Table / List -->',
            '    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">',
            '      <div style="font-weight: 700; font-size: 0.9rem; color: var(--ea-text-main);">فهرست تگ‌های ثبت‌شده برای گروه:</div>',
            '      <button id="ea-gtag-reconcile-btn" class="ea-btn" style="padding: 3px 10px; font-size: 0.8rem; background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.4); color: #60a5fa;" title="شناسایی و ترمیم تگ‌های یتیم یا فانتوم">🔄 همگام‌سازی و ترمیم تگ‌ها</button>',
            '    </div>',
            '    <div id="ea-gtag-list-container" style="max-height: 320px; overflow-y: auto; border: 1px solid var(--ea-border); border-radius: var(--ea-radius); background: var(--ea-surface);">',
            '      <div style="padding: 24px; text-align: center; color: var(--ea-text-muted);">⏳ در حال بارگذاری تگ‌ها...</div>',
            '    </div>',
            '  </div>',
            '  <div class="ea-modal-footer">',
            '    <button class="ea-btn" id="ea-gtag-close-bottom-btn">بستن</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);

        var closeBtn = document.getElementById('ea-modal-close-btn');
        if (closeBtn) closeBtn.onclick = closeModal;
        var closeBottomBtn = document.getElementById('ea-gtag-close-bottom-btn');
        if (closeBottomBtn) closeBottomBtn.onclick = closeModal;
        overlay.onclick = function (e) { if (e.target === overlay) closeModal(); };

        var reconcileBtn = document.getElementById('ea-gtag-reconcile-btn');
        if (reconcileBtn) {
            reconcileBtn.onclick = function () {
                window._eaReconcileGroupTags(selectedGroup);
            };
        }

        var groupSelect = document.getElementById('ea-gtag-group-select');
        if (groupSelect) {
            groupSelect.onchange = function () {
                selectedGroup = groupSelect.value;
                loadGroupTags(selectedGroup);
            };
        }

        var createBtn = document.getElementById('ea-gtag-create-btn');
        var inputEl = document.getElementById('ea-gtag-name-input');
        var msgEl = document.getElementById('ea-gtag-create-msg');

        if (createBtn) {
            createBtn.onclick = function () {
                var tagName = (inputEl.value || '').trim();
                if (!tagName) {
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ef4444';
                    msgEl.innerText = 'لطفاً نام تگ را وارد نمایید.';
                    return;
                }
                createBtn.disabled = true;
                createBtn.innerText = '⏳ در حال ثبت...';
                msgEl.style.display = 'none';

                var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
                if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

                fetch('/index.php/apps/archive_autotag/api/group-tags/create', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({ group_id: selectedGroup, tag_name: tagName })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    createBtn.disabled = false;
                    createBtn.innerText = '+ ایجاد تگ';
                    if (data && data.status === 'success') {
                        inputEl.value = '';
                        msgEl.style.display = 'block';
                        msgEl.style.color = '#10b981';
                        msgEl.innerText = 'تگ «' + tagName + '» با موفقیت برای گروه تعریف گردید.';
                        loadGroupTags(selectedGroup);
                        fetchTags();
                    } else {
                        msgEl.style.display = 'block';
                        msgEl.style.color = '#ef4444';
                        msgEl.innerText = 'خطا: ' + ((data && data.message) ? data.message : 'نامشخص');
                    }
                })
                .catch(function (err) {
                    createBtn.disabled = false;
                    createBtn.innerText = '+ ایجاد تگ';
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ef4444';
                    msgEl.innerText = 'خطای ارتباط: ' + err.message;
                });
            };
        }

        function loadGroupTags(grp) {
            var container = document.getElementById('ea-gtag-list-container');
            if (!container) return;
            container.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--ea-text-muted);">⏳ در حال بارگذاری تگ‌ها...</div>';

            fetch('/index.php/apps/archive_autotag/api/group-tags?group_id=' + encodeURIComponent(grp), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.status === 'success' && data.tags) {
                    if (data.tags.length === 0) {
                        container.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--ea-text-muted);">هنوز هیچ تگ اختصاصی برای این گروه تعریف نشده است.</div>';
                        return;
                    }
                    var html = [
                        '<table style="width: 100%; border-collapse: collapse; text-align: right; font-size: 0.88rem;">',
                        '  <thead>',
                        '    <tr style="border-bottom: 1px solid var(--ea-border); background: rgba(255,255,255,0.03);">',
                        '      <th style="padding: 10px 14px;">نام تگ اختصاصی</th>',
                        '      <th style="padding: 10px 14px;">اسناد متصل</th>',
                        '      <th style="padding: 10px 14px; text-align: center;">عملیات</th>',
                        '    </tr>',
                        '  </thead>',
                        '  <tbody>'
                    ];

                    data.tags.forEach(function (t) {
                        var fc = t.file_count || t.usage_count || 0;
                        var statBadge = '';
                        if (t.status === 'DELETING') {
                            statBadge = '<span style="color: #f59e0b; font-size: 0.72rem; margin-right: 6px; padding: 1px 5px; background: rgba(245,158,11,0.15); border-radius: 4px;">⏳ در حال حذف</span>';
                        } else if (t.status === 'FAILED_DELETION') {
                            statBadge = '<span style="color: #ef4444; font-size: 0.72rem; margin-right: 6px; padding: 1px 5px; background: rgba(239,68,68,0.15); border-radius: 4px;">⚠️ خطا</span>';
                        }
                        html.push(
                            '<tr style="border-bottom: 1px solid var(--ea-border); transition: background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.02)\'" onmouseout="this.style.background=\'transparent\'">',
                            '  <td style="padding: 10px 14px; font-weight: 700; color: var(--ea-text-main);"><span class="ea-mini-tag" style="font-size: 0.82rem;">🏷️ ' + escapeHtml(t.clean_name || t.name) + '</span>' + statBadge + '</td>',
                            '  <td style="padding: 10px 14px; color: var(--ea-text-muted);">' + toPersianDigits(fc) + ' سند</td>',
                            '  <td style="padding: 10px 14px; text-align: center;">',
                            '    <button class="ea-btn" style="padding: 4px 10px; font-size: 0.8rem; color: #ef4444; border-color: rgba(239,68,68,0.3);" onclick="window._eaDeleteGroupTag(\'' + escapeHtml(grp) + '\', ' + t.id + ', \'' + escapeHtml(t.clean_name || t.name) + '\', ' + fc + ')">🗑️ حذف</button>',
                            '  </td>',
                            '</tr>'
                        );
                    });

                    html.push('  </tbody></table>');
                    container.innerHTML = html.join('\n');
                } else {
                    container.innerHTML = '<div style="padding: 24px; text-align: center; color: #ef4444;">خطا در دریافت تگ‌های گروه.</div>';
                }
            })
            .catch(function (err) {
                container.innerHTML = '<div style="padding: 24px; text-align: center; color: #ef4444;">خطای شبکه: ' + err.message + '</div>';
            });
        }

        window._eaDeleteGroupTag = function (grp, tagId, tagName, fileCount) {
            fileCount = fileCount || 0;
            var force = false;
            if (fileCount > 0) {
                var confirmMsg = 'تگ اختصاصی «' + tagName + '» در حال حاضر به ' + fileCount + ' سند اختصاص یافته است.\n\n' +
                                 'آیا از حذف اجباری (Force Delete) و جداسازی کامل این تگ از اسناد اطمینان دارید؟';
                if (!confirm(confirmMsg)) {
                    return;
                }
                force = true;
            } else {
                if (!confirm('آیا از حذف قطعی تگ اختصاصی «' + tagName + '» اطمینان دارید؟')) {
                    return;
                }
            }

            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

            fetch('/index.php/apps/archive_autotag/api/group-tags/delete', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ group_id: grp, tag_id: tagId, force: force })
            })
            .then(function (r) {
                return r.json().then(function (data) {
                    return { ok: r.ok, status: r.status, data: data };
                });
            })
            .then(function (res) {
                var data = res.data;
                if (res.ok && data && data.status === 'success') {
                    showToast('تگ اختصاصی «' + tagName + '» با موفقیت و به صورت کامل حذف شد.');
                    loadGroupTags(grp);
                    fetchTags();
                    fetchFiles();
                } else if (res.status === 409 && data && data.code === 'TAG_IN_USE') {
                    if (confirm('تگ «' + tagName + '» در حال حاضر در ' + (data.usage_count || fileCount) + ' سند در حال استفاده است.\nآیا مایل به حذف اجباری (Force) و جداسازی خودکار هستید؟')) {
                        fetch('/index.php/apps/archive_autotag/api/group-tags/delete', {
                            method: 'POST',
                            headers: headers,
                            body: JSON.stringify({ group_id: grp, tag_id: tagId, force: true })
                        })
                        .then(function (r2) { return r2.json(); })
                        .then(function (d2) {
                            if (d2 && d2.status === 'success') {
                                showToast('تگ اختصاصی «' + tagName + '» به همراه جداسازی از اسناد حذف گردید.');
                                loadGroupTags(grp);
                                fetchTags();
                                fetchFiles();
                            } else {
                                alert('خطا در حذف اجباری تگ: ' + (d2.message || 'نامشخص'));
                            }
                        });
                    }
                } else {
                    alert('خطا در حذف تگ: ' + ((data && data.message) ? data.message : 'کد خطای ' + res.status));
                }
            })
            .catch(function (err) {
                alert('خطای ارتباط با سرور: ' + err.message);
            });
        };

        window._eaReconcileGroupTags = function (grp) {
            var btn = document.getElementById('ea-gtag-reconcile-btn');
            if (btn) {
                btn.disabled = true;
                btn.innerText = '⏳ در حال همگام‌سازی...';
            }

            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

            fetch('/index.php/apps/archive_autotag/api/group-tags/reconcile', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ group_id: grp })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '🔄 همگام‌سازی و ترمیم تگ‌ها';
                }
                if (data && data.status === 'success') {
                    var rep = data.report || {};
                    var msg = 'همگام‌سازی با موفقیت انجام شد: ' +
                              (rep.orphans_restored ? rep.orphans_restored.length : 0) + ' تگ بازیابی و ' +
                              (rep.ghosts_purged ? rep.ghosts_purged.length : 0) + ' رکورد فانتوم پاکسازی شد.';
                    showToast(msg);
                    loadGroupTags(grp);
                    fetchTags();
                } else {
                    alert('خطا در همگام‌سازی: ' + ((data && data.message) ? data.message : 'نامشخص'));
                }
            })
            .catch(function (err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '🔄 همگام‌سازی و ترمیم تگ‌ها';
                }
                alert('خطای ارتباط: ' + err.message);
            });
        };

        loadGroupTags(selectedGroup);
    }

    function setupAdminDrawerTagActions(file) {
        var selectEl = document.getElementById('ea-drawer-admin-tag-select');
        var addBtn = document.getElementById('ea-drawer-admin-add-tag-btn');
        var msgEl = document.getElementById('ea-drawer-admin-tag-msg');
        if (!selectEl || !addBtn) return;

        fetch('/index.php/apps/archive_autotag/api/admin/tags', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.status === 'success' && data.tags) {
                var currentTagIds = (file.tags || []).map(function (t) { return t.id; });
                var unassigned = data.tags.filter(function (t) { return currentTagIds.indexOf(t.id) === -1; });
                if (unassigned.length === 0) {
                    selectEl.innerHTML = '<option value="">(همه تگ‌های موجود روی منبع اعمال شده‌اند)</option>';
                    addBtn.disabled = true;
                } else {
                    selectEl.innerHTML = '<option value="">-- انتخاب تگ جهت الصاق --</option>' + unassigned.map(function (t) {
                        var scopeBadge = t.scope === 'system' ? '[سراسری] ' : ('[' + (t.group_id || 'گروهی') + '] ');
                        return '<option value="' + t.id + '">' + escapeHtml(scopeBadge + (t.clean_name || t.name)) + '</option>';
                    }).join('');
                    addBtn.disabled = false;
                }
            } else {
                selectEl.innerHTML = '<option value="">(عدم دریافت تگ‌ها)</option>';
            }
        })
        .catch(function () {
            selectEl.innerHTML = '<option value="">(خطای شبکه در دریافت تگ‌ها)</option>';
        });

        addBtn.onclick = function () {
            var selectedTagId = selectEl.value;
            if (!selectedTagId) {
                if (msgEl) {
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ef4444';
                    msgEl.innerText = 'لطفاً یک تگ را انتخاب فرمایید.';
                }
                return;
            }
            addBtn.disabled = true;
            addBtn.innerText = '⏳...';

            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

            fetch('/index.php/apps/archive_autotag/api/admin/tags/assign', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ tag_id: parseInt(selectedTagId, 10), file_id: file.id })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.status === 'success') {
                    showToast('تگ با موفقیت به منبع الصاق گردید.');
                    var assignedTagId = parseInt(selectedTagId, 10);
                    var tagName = (data.data && data.data.tag_name) || selectEl.options[selectEl.selectedIndex].text;
                    if (!file.tags) file.tags = [];
                    file.tags.push({ id: assignedTagId, name: tagName });
                    renderDrawer();
                    renderDocumentList();
                    fetchTags();
                } else {
                    addBtn.disabled = false;
                    addBtn.innerText = '+ الصاق';
                    if (msgEl) {
                        msgEl.style.display = 'block';
                        msgEl.style.color = '#ef4444';
                        msgEl.innerText = 'خطا: ' + ((data && data.message) ? data.message : 'نامشخص');
                    }
                }
            })
            .catch(function (err) {
                addBtn.disabled = false;
                addBtn.innerText = '+ الصاق';
                if (msgEl) {
                    msgEl.style.display = 'block';
                    msgEl.style.color = '#ef4444';
                    msgEl.innerText = 'خطای ارتباط: ' + err.message;
                }
            });
        };
    }

    window._eaAdminRemoveTag = function (tagId, fileId, tagName) {
        if (!confirm('آیا از جداسازی تگ «' + tagName + '» از این منبع اطمینان دارید؟')) {
            return;
        }
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

        fetch('/index.php/apps/archive_autotag/api/admin/tags/remove', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ tag_id: tagId, file_id: fileId })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.status === 'success') {
                showToast('تگ «' + tagName + '» با موفقیت از منبع جدا شد.');
                if (state.activeDrawerFile && state.activeDrawerFile.id === fileId) {
                    state.activeDrawerFile.tags = (state.activeDrawerFile.tags || []).filter(function (t) { return t.id !== tagId; });
                    renderDrawer();
                }
                for (var i = 0; i < state.files.length; i++) {
                    if (state.files[i].id === fileId) {
                        state.files[i].tags = (state.files[i].tags || []).filter(function (t) { return t.id !== tagId; });
                        break;
                    }
                }
                renderDocumentList();
                fetchTags();
            } else {
                alert('خطا در جداسازی تگ: ' + ((data && data.message) ? data.message : 'نامشخص'));
            }
        })
        .catch(function (err) {
            alert('خطای ارتباط: ' + err.message);
        });
    };

    // Modal: Central Tag Management for System Administrator (Requirement 28)
    function openAdminTagManagementModal() {
        closeModal();

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card" style="max-width: 960px; width: 95%; max-height: 85vh; display: flex; flex-direction: column;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            '      <span>مدیریت مرکزی تگ‌ها (System Administrator)</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-admin-tag-modal-close-btn" title="بستن">&times;</button>',
            '  </div>',
            '  <div class="ea-modal-body" style="overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 16px;">',
            '    <!-- Creation & Reconciliation Box -->',
            '    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--ea-border); border-radius: 8px; padding: 14px;">',
            '      <div style="font-size: 0.88rem; font-weight: 700; color: var(--ea-text-main); margin-bottom: 10px;">➕ ایجاد تگ جدید یا همگام‌سازی ساختار:</div>',
            '      <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">',
            '        <input type="text" id="ea-admin-new-tag-name" class="ea-input" placeholder="نام تگ جدید..." style="flex: 2; min-width: 180px; padding: 7px 12px; font-size: 0.84rem;">',
            '        <select id="ea-admin-new-tag-scope" class="ea-form-select" style="flex: 1.5; min-width: 170px; padding: 7px 10px; font-size: 0.84rem;">',
            '          <option value="system">🌐 سراسری (سیستمی)</option>',
            '        </select>',
            '        <button type="button" id="ea-admin-create-tag-btn" class="ea-btn ea-btn-primary" style="padding: 7px 16px; font-size: 0.84rem; white-space: nowrap;">+ ایجاد تگ</button>',
            '        <button type="button" id="ea-admin-reconcile-tags-btn" class="ea-btn" style="padding: 7px 14px; font-size: 0.84rem; white-space: nowrap; border-color: rgba(99,102,241,0.4); color: #818cf8;" title="بررسی سازگاری، رفع تناقض و پاکسازی رکوردهای یتیم">🔄 همگام‌سازی (Reconcile)</button>',
            '      </div>',
            '      <div id="ea-admin-tag-create-msg" style="display:none; font-size: 0.8rem; margin-top: 8px;"></div>',
            '    </div>',
            '',
            '    <!-- Search, Filter & Counter -->',
            '    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">',
            '      <div style="display: flex; gap: 8px; align-items: center; flex: 1; max-width: 420px;">',
            '        <input type="text" id="ea-admin-tag-search-input" class="ea-input" placeholder="🔍 جستجو در نام تگ..." style="width: 100%; padding: 6px 12px; font-size: 0.82rem;">',
            '      </div>',
            '      <div style="display: flex; gap: 10px; align-items: center;">',
            '        <select id="ea-admin-tag-scope-filter" class="ea-form-select" style="padding: 5px 10px; font-size: 0.82rem;">',
            '          <option value="all">همه دامنه‌ها</option>',
            '          <option value="system">فقط سراسری (سیستمی)</option>',
            '          <option value="group">فقط گروهی</option>',
            '        </select>',
            '        <span id="ea-admin-tags-count-badge" class="ea-mini-tag" style="font-size: 0.8rem;">در حال شمارش...</span>',
            '      </div>',
            '    </div>',
            '',
            '    <!-- Tags Table Container -->',
            '    <div id="ea-admin-tags-table-container" style="border: 1px solid var(--ea-border); border-radius: 8px; overflow: hidden; background: var(--ea-surface-elevated);">',
            '      <div style="padding: 24px; text-align: center; color: var(--ea-text-muted);">⏳ در حال دریافت کاتالوگ تگ‌ها...</div>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);

        var closeBtn = document.getElementById('ea-admin-tag-modal-close-btn');
        if (closeBtn) closeBtn.onclick = closeModal;
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });

        // Populate groups into scope selector
        var scopeSelect = document.getElementById('ea-admin-new-tag-scope');
        fetch('/index.php/apps/archive_autotag/api/share/groups', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.groups && scopeSelect) {
                data.groups.forEach(function (g) {
                    var opt = document.createElement('option');
                    opt.value = 'group:' + g.id;
                    opt.textContent = '👥 گروه ' + (g.display_name || g.id);
                    scopeSelect.appendChild(opt);
                });
            }
        })
        .catch(function () {});

        var allFetchedTags = [];

        function renderAdminTagsTable() {
            var container = document.getElementById('ea-admin-tags-table-container');
            var counterBadge = document.getElementById('ea-admin-tags-count-badge');
            if (!container) return;

            var q = (document.getElementById('ea-admin-tag-search-input') ? document.getElementById('ea-admin-tag-search-input').value.trim().toLowerCase() : '');
            var filterScope = (document.getElementById('ea-admin-tag-scope-filter') ? document.getElementById('ea-admin-tag-scope-filter').value : 'all');

            var filtered = allFetchedTags.filter(function (t) {
                if (filterScope === 'system' && t.scope !== 'system') return false;
                if (filterScope === 'group' && t.scope !== 'group') return false;
                if (q !== '') {
                    var matchName = (t.clean_name || t.name).toLowerCase().indexOf(q) !== -1;
                    var matchGrp = (t.group_id || '').toLowerCase().indexOf(q) !== -1;
                    return matchName || matchGrp;
                }
                return true;
            });

            if (counterBadge) {
                counterBadge.textContent = toPersianDigits(filtered.length) + ' تگ';
            }

            if (filtered.length === 0) {
                container.innerHTML = '<div style="padding: 32px; text-align: center; color: var(--ea-text-muted);">هیچ تگی با مشخصات انتخابی یافت نشد.</div>';
                return;
            }

            var html = [
                '<table style="width: 100%; border-collapse: collapse; text-align: right; font-size: 0.86rem;">',
                '  <thead>',
                '    <tr style="border-bottom: 1px solid var(--ea-border); background: rgba(255,255,255,0.03); color: var(--ea-text-muted);">',
                '      <th style="padding: 10px 14px;">شناسه</th>',
                '      <th style="padding: 10px 14px;">نام تگ</th>',
                '      <th style="padding: 10px 14px;">دامنه / گروه</th>',
                '      <th style="padding: 10px 14px;">وضعیت چرخه حیات</th>',
                '      <th style="padding: 10px 14px;">منابع متصل</th>',
                '      <th style="padding: 10px 14px;">مالک</th>',
                '      <th style="padding: 10px 14px; text-align: center;">عملیات</th>',
                '    </tr>',
                '  </thead>',
                '  <tbody>'
            ];

            filtered.forEach(function (t) {
                var isSys = (t.scope === 'system');
                var scopeBadge = isSys
                    ? '<span class="ea-scope-system">🌐 سراسری</span>'
                    : '<span class="ea-scope-group">👥 ' + escapeHtml(t.group_id || 'گروهی') + '</span>';

                var statBadge = '<span class="ea-status-active">● فعال</span>';
                if (t.status === 'DELETING') {
                    statBadge = '<span class="ea-status-deleting">⏳ در حال حذف</span>';
                }

                var cnt = t.usage_count || t.file_count || 0;
                var resCountHtml = cnt > 0
                    ? '<span style="color: var(--ea-primary); font-weight: 700;">' + toPersianDigits(cnt) + ' منبع</span>'
                    : '<span style="color: var(--ea-text-subtle);">بدون منبع</span>';

                html.push(
                    '<tr style="border-bottom: 1px solid var(--ea-border); transition: background 0.15s;" onmouseover="this.style.background=\'rgba(255,255,255,0.02)\'" onmouseout="this.style.background=\'transparent\'">',
                    '  <td style="padding: 9px 14px; color: var(--ea-text-subtle); font-family: monospace;">#' + t.id + '</td>',
                    '  <td style="padding: 9px 14px; font-weight: 700; color: var(--ea-text-main);"><span class="ea-mini-tag" style="font-size: 0.82rem;">🏷️ ' + escapeHtml(t.clean_name || t.name) + '</span></td>',
                    '  <td style="padding: 9px 14px;">' + scopeBadge + '</td>',
                    '  <td style="padding: 9px 14px;">' + statBadge + '</td>',
                    '  <td style="padding: 9px 14px;">' + resCountHtml + '</td>',
                    '  <td style="padding: 9px 14px; color: var(--ea-text-muted); font-size: 0.8rem;">' + escapeHtml(t.owner_uid || 'system') + '</td>',
                    '  <td style="padding: 9px 14px; text-align: center;">',
                    '    <button class="ea-btn ea-admin-tag-del-btn" data-tag-id="' + t.id + '" data-tag-name="' + escapeHtml(t.clean_name || t.name) + '" data-usage="' + cnt + '" style="padding: 4px 10px; font-size: 0.78rem; color: #ef4444; border-color: rgba(239,68,68,0.3);" title="حذف تگ">🗑️ حذف</button>',
                    '  </td>',
                    '</tr>'
                );
            });

            html.push('  </tbody></table>');
            container.innerHTML = html.join('\n');

            // Wire delete buttons
            container.querySelectorAll('.ea-admin-tag-del-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var tagId = parseInt(btn.getAttribute('data-tag-id'), 10);
                    var tagName = btn.getAttribute('data-tag-name');
                    var usage = parseInt(btn.getAttribute('data-usage'), 10) || 0;
                    handleAdminDeleteTag(tagId, tagName, usage);
                };
            });
        }

        function loadAdminTags() {
            var container = document.getElementById('ea-admin-tags-table-container');
            if (container) {
                container.innerHTML = '<div style="padding: 32px; text-align: center; color: var(--ea-text-muted);">⏳ در حال دریافت کاتالوگ تگ‌ها...</div>';
            }

            fetch('/index.php/apps/archive_autotag/api/admin/tags', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.status === 'success' && data.tags) {
                    allFetchedTags = data.tags;
                    renderAdminTagsTable();
                } else {
                    if (container) container.innerHTML = '<div style="padding: 32px; text-align: center; color: #ef4444;">خطا در دریافت لیست تگ‌ها.</div>';
                }
            })
            .catch(function (err) {
                if (container) container.innerHTML = '<div style="padding: 32px; text-align: center; color: #ef4444;">خطای شبکه: ' + err.message + '</div>';
            });
        }

        // Search & filter events
        var searchInput = document.getElementById('ea-admin-tag-search-input');
        if (searchInput) searchInput.oninput = renderAdminTagsTable;

        var filterScopeSelect = document.getElementById('ea-admin-tag-scope-filter');
        if (filterScopeSelect) filterScopeSelect.onchange = renderAdminTagsTable;

        // Creation handler
        var createBtn = document.getElementById('ea-admin-create-tag-btn');
        if (createBtn) {
            createBtn.onclick = function () {
                var nameInput = document.getElementById('ea-admin-new-tag-name');
                var scopeEl = document.getElementById('ea-admin-new-tag-scope');
                var msgEl = document.getElementById('ea-admin-tag-create-msg');
                var rawName = nameInput ? nameInput.value.trim() : '';
                var rawScopeVal = scopeEl ? scopeEl.value : 'system';

                if (!rawName) {
                    if (msgEl) {
                        msgEl.style.display = 'block';
                        msgEl.style.color = '#ef4444';
                        msgEl.innerText = 'ورود نام تگ الزامی است.';
                    }
                    return;
                }

                var scope = 'system';
                var groupId = null;
                if (rawScopeVal.startsWith('group:')) {
                    scope = 'group';
                    groupId = rawScopeVal.substring(6);
                }

                createBtn.disabled = true;
                createBtn.innerText = '⏳ در حال ثبت...';
                if (msgEl) msgEl.style.display = 'none';

                var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
                if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

                fetch('/index.php/apps/archive_autotag/api/admin/tags/create', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({ tag_name: rawName, scope: scope, group_id: groupId })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    createBtn.disabled = false;
                    createBtn.innerText = '+ ایجاد تگ';
                    if (data && data.status === 'success') {
                        if (nameInput) nameInput.value = '';
                        if (msgEl) {
                            msgEl.style.display = 'block';
                            msgEl.style.color = '#10b981';
                            msgEl.innerText = 'تگ «' + rawName + '» با موفقیت تعریف گردید.';
                        }
                        showToast('تگ جدید با موفقیت در سامانه ایجاد شد.');
                        loadAdminTags();
                        fetchTags();
                    } else {
                        if (msgEl) {
                            msgEl.style.display = 'block';
                            msgEl.style.color = '#ef4444';
                            msgEl.innerText = 'خطا: ' + ((data && data.message) ? data.message : 'نامشخص');
                        }
                    }
                })
                .catch(function (err) {
                    createBtn.disabled = false;
                    createBtn.innerText = '+ ایجاد تگ';
                    if (msgEl) {
                        msgEl.style.display = 'block';
                        msgEl.style.color = '#ef4444';
                        msgEl.innerText = 'خطای شبکه: ' + err.message;
                    }
                });
            };
        }

        // Reconcile handler
        var reconcileBtn = document.getElementById('ea-admin-reconcile-tags-btn');
        if (reconcileBtn) {
            reconcileBtn.onclick = function () {
                reconcileBtn.disabled = true;
                reconcileBtn.innerText = '⏳ همگام‌سازی...';

                var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
                if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

                fetch('/index.php/apps/archive_autotag/api/admin/tags/reconcile', {
                    method: 'POST',
                    headers: headers
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    reconcileBtn.disabled = false;
                    reconcileBtn.innerText = '🔄 همگام‌سازی (Reconcile)';
                    if (data && data.status === 'success') {
                        showToast('همگام‌سازی و ترمیم کلیه تگ‌ها با موفقیت انجام گردید.');
                        loadAdminTags();
                        fetchTags();
                        fetchFiles();
                    } else {
                        alert('خطا در همگام‌سازی: ' + ((data && data.message) ? data.message : 'نامشخص'));
                    }
                })
                .catch(function (err) {
                    reconcileBtn.disabled = false;
                    reconcileBtn.innerText = '🔄 همگام‌سازی (Reconcile)';
                    alert('خطای ارتباط: ' + err.message);
                });
            };
        }

        function handleAdminDeleteTag(tagId, tagName, usageCount) {
            var force = false;
            if (usageCount > 0) {
                var confirmMsg = 'تگ «' + tagName + '» در حال حاضر به ' + toPersianDigits(usageCount) + ' منبع سازمانی اختصاص دارد.\n\n' +
                                 'آیا از حذف اجباری (Force Delete) و پاکسازی آبشاری این تگ از کلیه منابع اطمینان دارید؟';
                if (!confirm(confirmMsg)) return;
                force = true;
            } else {
                if (!confirm('آیا از حذف قطعی تگ «' + tagName + '» اطمینان دارید؟')) return;
            }

            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

            fetch('/index.php/apps/archive_autotag/api/admin/tags/delete', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ tag_id: tagId, force: force })
            })
            .then(function (r) {
                return r.json().then(function (data) {
                    return { ok: r.ok, status: r.status, data: data };
                });
            })
            .then(function (res) {
                var data = res.data;
                if (res.ok && data && data.status === 'success') {
                    showToast('تگ «' + tagName + '» با موفقیت حذف گردید.');
                    loadAdminTags();
                    fetchTags();
                    fetchFiles();
                } else if (res.status === 409 && data && data.code === 'TAG_IN_USE') {
                    if (confirm('تگ به اسناد اختصاص دارد. آیا می‌خواهید به صورت اجباری (Force) حذف شود؟')) {
                        fetch('/index.php/apps/archive_autotag/api/admin/tags/delete', {
                            method: 'POST',
                            headers: headers,
                            body: JSON.stringify({ tag_id: tagId, force: true })
                        })
                        .then(function (r2) { return r2.json(); })
                        .then(function (d2) {
                            if (d2 && d2.status === 'success') {
                                showToast('تگ با موفقیت به صورت اجباری حذف شد.');
                                loadAdminTags();
                                fetchTags();
                                fetchFiles();
                            } else {
                                alert('خطا: ' + ((d2 && d2.message) ? d2.message : 'نامشخص'));
                            }
                        });
                    }
                } else {
                    alert('خطا در حذف تگ: ' + ((data && data.message) ? data.message : 'نامشخص'));
                }
            })
            .catch(function (err) {
                alert('خطای ارتباط با سرور: ' + err.message);
            });
        }

        loadAdminTags();
    }


    // Fullscreen Global Drag & Drop Handler
    function setupGlobalDragAndDrop() {
        var overlay = null;
        var dragCounter = 0;

        function getOrCreateOverlay() {
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'ea-global-drop-overlay';
                overlay.className = 'ea-global-drop-overlay';
                overlay.style.display = 'none';
                overlay.innerHTML = [
                    '<div class="ea-global-drop-box">',
                    '  <svg width="60" height="60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
                    '  <div class="ea-global-drop-title">رها کردن فایل جهت بارگذاری در بایگانی اسناد</div>',
                    '  <div class="ea-global-drop-subtitle">فایل را رها کنید تا پس از انتخاب پوشه گروه، تگ‌های خودکار پوشه والد اعمال گردند.</div>',
                    '</div>'
                ].join('\n');
                document.body.appendChild(overlay);
            }
            return overlay;
        }

        window.addEventListener('dragenter', function(e) {
            e.preventDefault();
            dragCounter++;
            var ov = getOrCreateOverlay();
            ov.style.display = 'flex';
        });

        window.addEventListener('dragleave', function(e) {
            e.preventDefault();
            dragCounter--;
            if (dragCounter <= 0) {
                var ov = getOrCreateOverlay();
                ov.style.display = 'none';
                dragCounter = 0;
            }
        });

        window.addEventListener('dragover', function(e) {
            e.preventDefault();
        });

        window.addEventListener('drop', function(e) {
            e.preventDefault();
            dragCounter = 0;
            var ov = getOrCreateOverlay();
            ov.style.display = 'none';
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                openUploadModal(e.dataTransfer.files[0]);
            }
        });
    }

    // Keyboard Shortcuts (e.g. Escape to close drawer)
    function setupKeyboardListeners() {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (document.getElementById('ea-active-modal')) {
                    closeModal();
                } else if (state.activeDrawerFile) {
                    closeDrawer();
                }
            }
        });
    }

    // Auto-Initialization when DOM is ready
    function init() {
        setupKeyboardListeners();
        setupGlobalDragAndDrop();
        window.addEventListener('popstate', function (e) {
            var urlParams = new URLSearchParams(window.location.search);
            var dirParam = urlParams.get('dir');
            if (dirParam) {
                openFolderInPortal(dirParam, null);
            } else if (state.isFolderView) {
                exitFolderMode();
            }
        });

        var root = document.getElementById('archive-portal-root');
        if (root) {
            fetchUserRole();
            var urlParams = new URLSearchParams(window.location.search);
            var dirParam = urlParams.get('dir');
            if (dirParam) {
                openFolderInPortal(dirParam, null);
            } else {
                fetchTags();
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // =========================================================================
    // AI Security, Token Lifecycle & Interactive Sandbox Console (Prompt 01 UI)
    // =========================================================================
    var aiConsoleState = {
        activeTab: 'services',
        cachedOverview: null,
        sandboxToken: '',
        sandboxFileId: '623',
        sandboxOnBehalf: '',
    };

    function openAiSecurityConsoleModal(initialTab) {
        closeModal();
        aiConsoleState.activeTab = initialTab || 'services';

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card ea-modal-card-xl" style="display:flex;flex-direction:column;max-height:92vh;">',
            '  <div class="ea-modal-header" style="flex-shrink:0;">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="#f97316" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2 2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><rect x="4" y="8" width="16" height="12" rx="2"/><circle cx="9" cy="14" r="1.5"/><circle cx="15" cy="14" r="1.5"/><path d="M9 18h6"/></svg>',
            '      <span>کنسول امنیت و مدیریت AI API &amp; Delegated Identity</span>',
            '    </div>',
            '    <div style="display:flex;align-items:center;gap:12px;">',
            '      <a href="/index.php/apps/archive_autotag/api/docs" target="_blank" class="ea-btn ea-btn-sm" style="background:rgba(249,115,22,0.12);color:#f97316;border-color:rgba(249,115,22,0.35);" title="باز کردن Swagger UI محلی و مستقل">📖 Swagger UI</a>',
            '      <button class="ea-modal-close" id="ea-modal-close-btn" title="بستن">✕</button>',
            '    </div>',
            '  </div>',
            '  <div class="ea-ai-tabs" style="flex-shrink:0;">',
            '    <button class="ea-ai-tab ' + (aiConsoleState.activeTab === 'services' ? 'active' : '') + '" data-tab="services">📌 سرویس‌ها و توکن‌ها</button>',
            '    <button class="ea-ai-tab ' + (aiConsoleState.activeTab === 'delegations' ? 'active' : '') + '" data-tab="delegations">🛡️ سیاست‌های نمایندگی (Allowlist)</button>',
            '    <button class="ea-ai-tab ' + (aiConsoleState.activeTab === 'audit' ? 'active' : '') + '" data-tab="audit">📊 لاگ‌های نظارتی (Audit Trail)</button>',
            '    <button class="ea-ai-tab ' + (aiConsoleState.activeTab === 'sandbox' ? 'active' : '') + '" data-tab="sandbox">🧪 محیط تست زنده API Sandbox</button>',
            '    <button class="ea-ai-tab ' + (aiConsoleState.activeTab === 'inspector' ? 'active' : '') + '" data-tab="inspector">🔍 بازرس مجوزهای موثر (Permission Inspector)</button>',
            '    <button class="ea-ai-tab ' + (aiConsoleState.activeTab === 'reliability' ? 'active' : '') + '" data-tab="reliability">🛡️ تاب‌آوری و سلامت ممیزی (Audit Reliability & DLQ)</button>',
            '  </div>',
            '  <div class="ea-modal-body" id="ea-ai-console-body" style="flex:1;overflow-y:auto;padding:24px;">',
            '    <div style="text-align:center;padding:40px;color:var(--ea-text-muted);">در حال بارگذاری اطلاعات امنیتی AI...</div>',
            '  </div>',
            '  <div class="ea-modal-footer" style="flex-shrink:0;">',
            '    <button class="ea-btn" id="ea-ai-modal-close-btn">بستن</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);
        document.getElementById('ea-modal-close-btn').onclick = closeModal;
        document.getElementById('ea-ai-modal-close-btn').onclick = closeModal;
        overlay.onclick = function (e) {
            if (e.target === overlay) closeModal();
        };

        var tabButtons = overlay.querySelectorAll('.ea-ai-tab');
        tabButtons.forEach(function (btn) {
            btn.onclick = function () {
                var tab = btn.getAttribute('data-tab');
                aiConsoleState.activeTab = tab;
                tabButtons.forEach(function (b) { b.classList.toggle('active', b === btn); });
                renderAiConsoleCurrentTab();
            };
        });

        loadAiConsoleOverview();
    }

    function loadAiConsoleOverview() {
        var body = document.getElementById('ea-ai-console-body');
        if (!body) return;

        fetch('/index.php/apps/archive_autotag/api/ai/admin/overview', {
            headers: { 'OCS-APIRequest': 'true' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                aiConsoleState.cachedOverview = data;
                renderAiConsoleCurrentTab();
            } else {
                body.innerHTML = '<div style="color:#ef4444;padding:20px;text-align:center;">خطا در دریافت اطلاعات: ' + escapeHtml(data.message || 'نامشخص') + '</div>';
            }
        })
        .catch(function (err) {
            body.innerHTML = '<div style="color:#ef4444;padding:20px;text-align:center;">خطای ارتباط با سرور: ' + escapeHtml(err.message) + '</div>';
        });
    }

    function renderAiConsoleCurrentTab() {
        var body = document.getElementById('ea-ai-console-body');
        if (!body || !aiConsoleState.cachedOverview) return;

        var data = aiConsoleState.cachedOverview;
        if (aiConsoleState.activeTab === 'services') {
            renderAiServicesTab(body, data);
        } else if (aiConsoleState.activeTab === 'delegations') {
            renderAiDelegationsTab(body, data);
        } else if (aiConsoleState.activeTab === 'audit') {
            renderAiAuditTab(body);
        } else if (aiConsoleState.activeTab === 'sandbox') {
            renderAiSandboxTab(body, data);
        } else if (aiConsoleState.activeTab === 'inspector') {
            renderPermissionInspectorTab(body);
        } else if (aiConsoleState.activeTab === 'reliability') {
            renderAuditReliabilityTab(body);
        }
    }

    // Tab 1: Services & Tokens
    function renderAiServicesTab(body, data) {
        var m = data.metrics || {};
        var html = [
            '<div class="ea-ai-metrics">',
            '  <div class="ea-ai-metric-card">',
            '    <div class="ea-ai-metric-val">' + toPersianDigits(m.total_services || 0) + '</div>',
            '    <div class="ea-ai-metric-label">سرویس‌های AI ثبت‌شده</div>',
            '  </div>',
            '  <div class="ea-ai-metric-card">',
            '    <div class="ea-ai-metric-val">' + toPersianDigits(m.total_tokens || 0) + '</div>',
            '    <div class="ea-ai-metric-label">توکن‌های رمزنگاری‌شده (SHA-256)</div>',
            '  </div>',
            '  <div class="ea-ai-metric-card">',
            '    <div class="ea-ai-metric-val">' + toPersianDigits(m.total_delegations || 0) + '</div>',
            '    <div class="ea-ai-metric-label">سیاست‌های نمایندگی فعال (Allowlist)</div>',
            '  </div>',
            '  <div class="ea-ai-metric-card">',
            '    <div class="ea-ai-metric-val" style="color:#ef4444;">' + toPersianDigits(m.total_blocked_attempts || 0) + '</div>',
            '    <div class="ea-ai-metric-label">تلاش‌های غیرمجاز مسدودشده</div>',
            '  </div>',
            '</div>'
        ];

        (data.services || []).forEach(function (svc) {
            var adminGateBadge = svc.allow_admin_delegation
                ? '<span class="ea-badge-rotating">⚠️ مجاز (غیر ایمن)</span>'
                : '<span class="ea-badge-active">🔒 مسدود (ایمن - Zero Escalation)</span>';

            html.push([
                '<div style="background:var(--ea-surface-card);border:1px solid var(--ea-border);border-radius:var(--ea-radius-md);padding:20px;margin-bottom:24px;">',
                '  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:12px;">',
                '    <div>',
                '      <div style="display:flex;align-items:center;gap:10px;">',
                '        <h3 style="margin:0;font-size:1.15rem;font-weight:800;color:var(--ea-text-main);">' + escapeHtml(svc.display_name || svc.service_id) + '</h3>',
                '        <span style="font-family:monospace;font-size:0.8rem;background:rgba(255,255,255,0.06);padding:2px 8px;border-radius:4px;color:var(--ea-primary);">' + escapeHtml(svc.service_id) + '</span>',
                '        <span class="ea-badge-active">فعال</span>',
                '      </div>',
                '      <p style="margin:6px 0 0;font-size:0.85rem;color:var(--ea-text-muted);">' + escapeHtml(svc.description || 'بدون توضیح') + '</p>',
                '      <div style="margin-top:8px;font-size:0.8rem;color:var(--ea-text-subtle);display:flex;gap:18px;flex-wrap:wrap;">',
                '        <span>کاربر پیش‌فرض سازمانی: <strong style="color:var(--ea-text-main);">' + escapeHtml(svc.default_actor_uid) + '</strong></span>',
                '        <span>سیاست نمایندگی: <strong style="color:var(--ea-text-main);">' + escapeHtml(svc.delegation_policy) + '</strong></span>',
                '        <span>جعل هویت Admin: ' + adminGateBadge + '</span>',
                '      </div>',
                '    </div>',
                '    <div style="display:flex;gap:8px;">',
                '      <button class="ea-btn ea-btn-sm ea-btn-primary ea-new-token-btn" data-service="' + escapeHtml(svc.service_id) + '">+ صدور توکن جدید</button>',
                '      <button class="ea-btn ea-btn-sm ea-rotate-token-btn" data-service="' + escapeHtml(svc.service_id) + '">🔄 چرخش توکن (Rotate)</button>',
                '    </div>',
                '  </div>',
                '  <div style="font-size:0.88rem;font-weight:700;color:var(--ea-text-muted);margin-bottom:10px;">توکن‌های احراز هویت این سرویس:</div>',
                '  <div style="overflow-x:auto;">',
                '    <table class="ea-table" style="width:100%;font-size:0.82rem;">',
                '      <thead>',
                '        <tr>',
                '          <th>شناسه / نام توکن</th>',
                '          <th>پیشوند امن (Prefix)</th>',
                '          <th>وضعیت</th>',
                '          <th>تاریخ صدور</th>',
                '          <th>انقضا / مهلت تنفس</th>',
                '          <th>عملیات</th>',
                '        </tr>',
                '      </thead>',
                '      <tbody>'
            ].join('\n'));

            if (!svc.tokens || svc.tokens.length === 0) {
                html.push('<tr><td colspan="6" style="text-align:center;color:var(--ea-text-muted);padding:14px;">هیچ توکنی برای این سرویس وجود ندارد.</td></tr>');
            } else {
                svc.tokens.forEach(function (tok) {
                    var statusBadge = '<span class="ea-badge-active">ACTIVE</span>';
                    if (tok.status === 'GRACE_PERIOD' || tok.status === 'ROTATING') {
                        statusBadge = '<span class="ea-badge-rotating">GRACE_PERIOD</span>';
                    } else if (tok.status === 'REVOKED') {
                        statusBadge = '<span class="ea-badge-revoked">REVOKED</span>';
                    }

                    var expStr = tok.expires_at ? formatDate(tok.expires_at) : 'نامحدود';
                    if (tok.grace_period_until) {
                        expStr = 'تنفس تا ' + formatDate(tok.grace_period_until);
                    }

                    var actions = '';
                    if (tok.status !== 'REVOKED') {
                        actions = '<button class="ea-btn ea-btn-sm ea-revoke-token-btn" data-id="' + tok.id + '" style="background:rgba(239,68,68,0.15);color:#f87171;border-color:rgba(239,68,68,0.35);padding:3px 8px;font-size:0.75rem;">🚫 ابطال آنی</button>';
                    } else {
                        actions = '<span style="color:var(--ea-text-disabled);">باطل‌شده</span>';
                    }

                    html.push([
                        '<tr>',
                        '  <td><strong>#' + tok.id + '</strong> - ' + escapeHtml(tok.token_name || 'Service Token') + '</td>',
                        '  <td><code style="background:rgba(255,255,255,0.06);padding:2px 6px;border-radius:4px;color:var(--ea-primary);">' + escapeHtml(tok.token_prefix) + '...</code></td>',
                        '  <td>' + statusBadge + '</td>',
                        '  <td>' + formatDate(tok.created_at) + '</td>',
                        '  <td>' + expStr + '</td>',
                        '  <td>' + actions + '</td>',
                        '</tr>'
                    ].join('\n'));
                });
            }

            html.push([
                '      </tbody>',
                '    </table>',
                '  </div>',
                '</div>'
            ].join('\n'));
        });

        body.innerHTML = html.join('\n');

        // Bind events
        body.querySelectorAll('.ea-new-token-btn').forEach(function (btn) {
            btn.onclick = function () {
                promptCreateToken(btn.getAttribute('data-service'));
            };
        });
        body.querySelectorAll('.ea-rotate-token-btn').forEach(function (btn) {
            btn.onclick = function () {
                promptRotateToken(btn.getAttribute('data-service'));
            };
        });
        body.querySelectorAll('.ea-revoke-token-btn').forEach(function (btn) {
            btn.onclick = function () {
                promptRevokeToken(btn.getAttribute('data-id'));
            };
        });
    }

    // Tab 2: Delegation Policies
    function renderAiDelegationsTab(body, data) {
        var groups = data.available_groups || [];
        var html = [
            '<div class="ea-policy-banner">',
            '  <strong>🛡️ معماری امنیت نمایندگی (Delegated Identity &amp; Deny-by-Default):</strong><br>',
            '  سرویس‌های هوش مصنوعی صرفاً در صورتی مجاز به خواندن فایل از طرف کاربر (از طریق هدر <code>X-On-Behalf-Of</code>) هستند که کاربر یا گروه سازمانی او صریحاً در لیست مجاز (Allowlist) زیر ثبت شده باشد.<br>',
            '  <strong>قانون قطعی:</strong> هرگونه درخواست با هویت <code>admin</code> به صورت سخت‌گیرانه با کد خطای <code>403 Forbidden</code> مسدود می‌شود تا از ارتقای سطح دسترسی (Privilege Escalation) جلوگیری گردد.',
            '</div>',
            '<div style="background:var(--ea-surface-card);border:1px solid var(--ea-border);border-radius:var(--ea-radius-md);padding:20px;margin-bottom:20px;">',
            '  <h4 style="margin:0 0 14px;color:var(--ea-text-main);">+ افزودن سیاست نمایندگی جدید</h4>',
            '  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">',
            '    <div style="flex:1;min-width:180px;">',
            '      <label style="display:block;font-size:0.8rem;color:var(--ea-text-muted);margin-bottom:4px;">سرویس هوش مصنوعی:</label>',
            '      <select id="ea-del-service-select" class="ea-form-select">',
        ];

        (data.services || []).forEach(function (s) {
            html.push('<option value="' + escapeHtml(s.service_id) + '">' + escapeHtml(s.display_name) + ' (' + escapeHtml(s.service_id) + ')</option>');
        });

        html.push([
            '      </select>',
            '    </div>',
            '    <div style="width:160px;">',
            '      <label style="display:block;font-size:0.8rem;color:var(--ea-text-muted);margin-bottom:4px;">نوع موجودیت:</label>',
            '      <select id="ea-del-type-select" class="ea-form-select">',
            '        <option value="GROUP">گروه سازمانی (GROUP)</option>',
            '        <option value="USER">کاربر اختصاصی (USER)</option>',
            '      </select>',
            '    </div>',
            '    <div style="flex:1;min-width:200px;" id="ea-del-subject-container">',
            '      <label style="display:block;font-size:0.8rem;color:var(--ea-text-muted);margin-bottom:4px;">انتخاب گروه مجاز:</label>',
            '      <select id="ea-del-subject-group" class="ea-form-select">',
        ]);

        groups.forEach(function (g) {
            if (g !== 'admin') {
                html.push('<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>');
            }
        });

        html.push([
            '      </select>',
            '      <input type="text" id="ea-del-subject-user" class="ea-form-input" placeholder="نام کاربری مثلاً Bakbari" style="display:none;" />',
            '    </div>',
            '    <button id="ea-add-delegation-btn" class="ea-btn ea-btn-primary" style="height:38px;">ثبت در لیست مجاز</button>',
            '  </div>',
            '</div>',
            '<div style="background:var(--ea-surface-card);border:1px solid var(--ea-border);border-radius:var(--ea-radius-md);padding:20px;">',
            '  <h4 style="margin:0 0 14px;color:var(--ea-text-main);">سیاست‌های نمایندگی ثبت‌شده (Allowlist):</h4>',
            '  <div style="overflow-x:auto;">',
            '    <table class="ea-table" style="width:100%;font-size:0.84rem;">',
            '      <thead>',
            '        <tr>',
            '          <th>سرویس</th>',
            '          <th>نوع موجودیت</th>',
            '          <th>موضوع مجاز (Subject)</th>',
            '          <th>تاریخ ایجاد</th>',
            '          <th>عملیات</th>',
            '        </tr>',
            '      </thead>',
            '      <tbody>'
        ]);

        var allDelCount = 0;
        (data.services || []).forEach(function (s) {
            (s.delegations || []).forEach(function (d) {
                allDelCount++;
                var typeBadge = d.subject_type === 'GROUP'
                    ? '<span class="ea-badge-active">👥 گروه سازمانی</span>'
                    : '<span class="ea-badge-rotating">👤 کاربر</span>';

                html.push([
                    '<tr>',
                    '  <td><code style="color:var(--ea-primary);">' + escapeHtml(s.service_id) + '</code></td>',
                    '  <td>' + typeBadge + '</td>',
                    '  <td><strong style="color:var(--ea-text-main);">' + escapeHtml(d.subject_id) + '</strong></td>',
                    '  <td>' + formatDate(d.created_at) + '</td>',
                    '  <td>',
                    '    <button class="ea-btn ea-btn-sm ea-del-remove-btn" data-id="' + d.id + '" style="background:rgba(239,68,68,0.15);color:#f87171;border-color:rgba(239,68,68,0.35);padding:3px 8px;font-size:0.75rem;">حذف سیاست</button>',
                    '  </td>',
                    '</tr>'
                ].join('\n'));
            });
        });

        if (allDelCount === 0) {
            html.push('<tr><td colspan="5" style="text-align:center;color:var(--ea-text-muted);padding:16px;">هیچ قانون نمایندگی تعریف نشده است (سیاست Deny-All حاکم است).</td></tr>');
        }

        html.push([
            '      </tbody>',
            '    </table>',
            '  </div>',
            '</div>'
        ].join('\n'));

        body.innerHTML = html.join('\n');

        // Toggle User vs Group Input
        var typeSelect = document.getElementById('ea-del-type-select');
        var groupInput = document.getElementById('ea-del-subject-group');
        var userInput = document.getElementById('ea-del-subject-user');
        if (typeSelect) {
            typeSelect.onchange = function () {
                if (typeSelect.value === 'USER') {
                    groupInput.style.display = 'none';
                    userInput.style.display = 'block';
                } else {
                    groupInput.style.display = 'block';
                    userInput.style.display = 'none';
                }
            };
        }

        var addBtn = document.getElementById('ea-add-delegation-btn');
        if (addBtn) {
            addBtn.onclick = function () {
                var sid = document.getElementById('ea-del-service-select').value;
                var type = typeSelect.value;
                var subject = type === 'USER' ? userInput.value.trim() : groupInput.value;
                if (!subject) {
                    alert('لطفاً نام کاربر یا گروه را وارد یا انتخاب کنید.');
                    return;
                }
                submitAddDelegation(sid, type, subject);
            };
        }

        body.querySelectorAll('.ea-del-remove-btn').forEach(function (btn) {
            btn.onclick = function () {
                var id = btn.getAttribute('data-id');
                if (confirm('آیا از حذف این سیاست نمایندگی اطمینان دارید؟')) {
                    submitRemoveDelegation(id);
                }
            };
        });
    }

    // Tab 3: Audit Trail
    function renderAiAuditTab(body) {
        body.innerHTML = [
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">',
            '  <h4 style="margin:0;color:var(--ea-text-main);">📋 گزارش لاگ‌های امنیتی اخیر (Audit Trail):</h4>',
            '  <button id="ea-refresh-audit-btn" class="ea-btn ea-btn-sm">🔄 به‌روزرسانی زنده</button>',
            '</div>',
            '<div id="ea-audit-table-container">',
            '  <div style="text-align:center;padding:30px;color:var(--ea-text-muted);">در حال دریافت آخرین لاگ‌ها...</div>',
            '</div>'
        ].join('\n');

        document.getElementById('ea-refresh-audit-btn').onclick = function () {
            loadAuditData();
        };

        loadAuditData();
    }

    function loadAuditData() {
        var container = document.getElementById('ea-audit-table-container');
        if (!container) return;

        fetch('/index.php/apps/archive_autotag/api/ai/admin/audit?limit=40', {
            headers: { 'OCS-APIRequest': 'true' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status !== 'success' || !data.logs) {
                container.innerHTML = '<div style="color:#ef4444;padding:20px;text-align:center;">خطا در دریافت لاگ‌ها.</div>';
                return;
            }

            var html = [
                '<div style="overflow-x:auto;">',
                '  <table class="ea-table" style="width:100%;font-size:0.8rem;">',
                '    <thead>',
                '      <tr>',
                '        <th>زمان</th>',
                '        <th>شناسه درخواست</th>',
                '        <th>کلاینت IP</th>',
                '        <th>سرویس / هویت</th>',
                '        <th>کاربر موثر</th>',
                '        <th>درخواست نمایندگی</th>',
                '        <th>فایل ID</th>',
                '        <th>نتیجه</th>',
                '      </tr>',
                '    </thead>',
                '    <tbody>'
            ];

            if (data.logs.length === 0) {
                html.push('<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ea-text-muted);">هیچ رویدادی در لاگ ثبت نشده است.</td></tr>');
            } else {
                data.logs.forEach(function (l) {
                    var badge = '<span class="ea-badge-active">' + escapeHtml(l.result) + '</span>';
                    if (l.result === 'FORBIDDEN' || l.result === 'BLOCKED') {
                        badge = '<span class="ea-badge-revoked">⛔ ' + escapeHtml(l.result) + '</span>';
                    } else if (l.result === 'UNAUTHORIZED') {
                        badge = '<span class="ea-badge-rotating">⚠️ 401 UNAUTHORIZED</span>';
                    }

                    var delStatus = l.delegation_status || 'NONE';
                    var delBadge = '<span style="color:var(--ea-text-subtle);">' + escapeHtml(delStatus) + '</span>';
                    if (delStatus === 'FORBIDDEN') {
                        delBadge = '<span style="color:#f87171;font-weight:700;">⛔ FORBIDDEN</span>';
                    } else if (delStatus === 'ALLOWED') {
                        delBadge = '<span style="color:#4ade80;font-weight:700;">✅ ALLOWED</span>';
                    }

                    html.push([
                        '<tr>',
                        '  <td style="white-space:nowrap;">' + formatDate(l.created_at) + '</td>',
                        '  <td><code style="font-size:0.75rem;">' + escapeHtml(l.request_id || '-') + '</code></td>',
                        '  <td>' + escapeHtml(l.client_ip || '-') + '</td>',
                        '  <td>' + escapeHtml(l.service_id || l.client_id || '-') + '</td>',
                        '  <td><strong>' + escapeHtml(l.actor_uid || '-') + '</strong></td>',
                        '  <td>' + escapeHtml(l.delegation_requested || '-') + ' (' + delBadge + ')</td>',
                        '  <td>#' + escapeHtml(String(l.file_id || '-')) + '</td>',
                        '  <td>' + badge + '</td>',
                        '</tr>'
                    ].join('\n'));
                });
            }

            html.push([
                '    </tbody>',
                '  </table>',
                '</div>'
            ].join('\n'));

            container.innerHTML = html.join('\n');
        })
        .catch(function (err) {
            container.innerHTML = '<div style="color:#ef4444;padding:20px;text-align:center;">خطا: ' + escapeHtml(err.message) + '</div>';
        });
    }

    // Tab 4: Interactive API Sandbox
    function renderAiSandboxTab(body, data) {
        var tokenOptions = [];
        (data.services || []).forEach(function (s) {
            (s.tokens || []).forEach(function (t) {
                if (t.status === 'ACTIVE') {
                    tokenOptions.push({
                        label: s.service_id + ' - ' + t.token_name + ' (' + t.token_prefix + '...)',
                        prefix: t.token_prefix
                    });
                }
            });
        });

        body.innerHTML = [
            '<div class="ea-policy-banner" style="background:rgba(59,130,246,0.08);border-color:rgba(59,130,246,0.3);color:#bfdbfe;">',
            '  <strong>🧪 محیط تست زنده و اعتبارسنجی احراز هویت هوش مصنوعی (API Sandbox):</strong><br>',
            '  در این بخش می‌توانید به صورت بلادرنگ از درون مرورگر، سناریوهای مختلف احراز هویت با توکن Bearer، تغییر هویت پویا با <code>X-On-Behalf-Of</code>، سد دفاعی جلوگیری از جعل هویت <code>admin</code> و سیاست Deny-by-Default را آزمایش کنید.',
            '</div>',
            '<div style="background:var(--ea-surface-card);border:1px solid var(--ea-border);border-radius:var(--ea-radius-md);padding:20px;">',
            '  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">',
            '    <div style="grid-column:1 / -1;">',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">توکن احراز هویت (Bearer Token):</label>',
            '      <input type="text" id="ea-sb-token" class="ea-form-input" style="font-family:monospace;" placeholder="مثلاً: ai_sec_token_... یا nc_ai_..." value="' + escapeHtml(aiConsoleState.sandboxToken || 'ai_sec_token_7021824a20719a37d5433ba2f96832d28edf57d81d307a8468d2864601e29d08') + '" />',
            '      <span style="font-size:0.75rem;color:var(--ea-text-muted);">توکن پیش‌فرض اولیه سامانه به صورت خودکار در فیلد بالا درج شده است.</span>',
            '    </div>',
            '    <div>',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">شناسه فایل آرشیو (File ID):</label>',
            '      <input type="number" id="ea-sb-file-id" class="ea-form-input" value="' + escapeHtml(aiConsoleState.sandboxFileId || '623') + '" />',
            '    </div>',
            '    <div>',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">هویت نمایندگی (X-On-Behalf-Of):</label>',
            '      <input type="text" id="ea-sb-behalf" class="ea-form-input" placeholder="خالی = هویت پیش‌فرض سرویس" value="' + escapeHtml(aiConsoleState.sandboxOnBehalf || '') + '" />',
            '    </div>',
            '  </div>',
            '  <div style="margin-top:10px;">',
            '    <span style="font-size:0.8rem;color:var(--ea-text-muted);">تست‌های سریع با یک کلیک:</span>',
            '    <div class="ea-quick-chips">',
            '      <button class="ea-chip-btn" id="ea-chip-soc">👤 Bakbari (SOC - مجاز)</button>',
            '      <button class="ea-chip-btn" id="ea-chip-cert">👤 maherani (CERT - مجاز)</button>',
            '      <button class="ea-chip-btn" id="ea-chip-admin" style="color:#f87171;border-color:rgba(239,68,68,0.4);">⛔ admin (تست سد جعل هویت)</button>',
            '      <button class="ea-chip-btn" id="ea-chip-fake" style="color:#facc15;border-color:rgba(234,179,8,0.4);">❓ stranger_user (تست Deny-by-Default)</button>',
            '      <button class="ea-chip-btn" id="ea-chip-clear">❌ پاک کردن هویت</button>',
            '    </div>',
            '  </div>',
            '  <div style="margin-top:20px;">',
            '    <button id="ea-sb-run-btn" class="ea-btn ea-btn-primary" style="padding:10px 24px;font-size:0.95rem;">🚀 ارسال درخواست و اعتبارسنجی زنده</button>',
            '  </div>',
            '</div>',
            '<div id="ea-sb-result-container" style="display:none;margin-top:20px;"></div>'
        ].join('\n');

        // Wire Chips
        document.getElementById('ea-chip-soc').onclick = function () {
            document.getElementById('ea-sb-behalf').value = 'Bakbari';
        };
        document.getElementById('ea-chip-cert').onclick = function () {
            document.getElementById('ea-sb-behalf').value = 'maherani';
        };
        document.getElementById('ea-chip-admin').onclick = function () {
            document.getElementById('ea-sb-behalf').value = 'admin';
        };
        document.getElementById('ea-chip-fake').onclick = function () {
            document.getElementById('ea-sb-behalf').value = 'stranger_user_99';
        };
        document.getElementById('ea-chip-clear').onclick = function () {
            document.getElementById('ea-sb-behalf').value = '';
        };

        // Wire Run Button
        document.getElementById('ea-sb-run-btn').onclick = function () {
            var token = document.getElementById('ea-sb-token').value.trim();
            var fileId = parseInt(document.getElementById('ea-sb-file-id').value, 10);
            var onBehalf = document.getElementById('ea-sb-behalf').value.trim();

            aiConsoleState.sandboxToken = token;
            aiConsoleState.sandboxFileId = String(fileId);
            aiConsoleState.sandboxOnBehalf = onBehalf;

            executeAiSandboxTest(token, fileId, onBehalf);
        };
    }

    function executeAiSandboxTest(token, fileId, onBehalf) {
        var resContainer = document.getElementById('ea-sb-result-container');
        if (!resContainer) return;

        resContainer.style.display = 'block';
        resContainer.innerHTML = '<div style="text-align:center;padding:24px;color:var(--ea-text-muted);">در حال اجرای درخواست و ارزیابی دسترسی...</div>';

        fetch('/index.php/apps/archive_autotag/api/ai/admin/test-api', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'OCS-APIRequest': 'true'
            },
            body: JSON.stringify({
                token: token,
                file_id: fileId,
                on_behalf_of: onBehalf
            })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var isSuccess = data.success === true;
            var badgeColor = isSuccess ? '#22c55e' : (data.http_status === 403 ? '#ef4444' : '#eab308');
            var bgGlass = isSuccess ? 'rgba(34,197,94,0.08)' : (data.http_status === 403 ? 'rgba(239,68,68,0.08)' : 'rgba(234,179,8,0.08)');
            var borderCol = isSuccess ? 'rgba(34,197,94,0.35)' : (data.http_status === 403 ? 'rgba(239,68,68,0.35)' : 'rgba(234,179,8,0.35)');

            var metaHtml = '';
            if (data.file_meta && data.file_meta.file) {
                var f = data.file_meta.file;
                var tags = (f.tags || []).map(function (t) {
                    return '<span style="background:rgba(249,115,22,0.15);color:#f97316;border:1px solid rgba(249,115,22,0.35);padding:1px 6px;border-radius:4px;font-size:0.75rem;">' + escapeHtml(t.name) + '</span>';
                }).join(' ');

                metaHtml = [
                    '<div style="margin-top:14px;background:#05070a;border:1px solid #1e293b;border-radius:8px;padding:12px;">',
                    '  <div style="font-size:0.8rem;color:var(--ea-text-muted);margin-bottom:6px;">متادیتای فایل بازیابی‌شده (Sanitized Metadata):</div>',
                    '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;font-size:0.85rem;">',
                    '    <div>نام فایل: <strong style="color:#fff;">' + escapeHtml(f.name) + '</strong></div>',
                    '    <div>حجم: <strong style="color:#fff;">' + escapeHtml(f.human_size) + '</strong></div>',
                    '    <div>مالک فایل: <strong style="color:#fff;">' + escapeHtml(f.owner) + '</strong></div>',
                    '    <div>نوع: <code style="color:#94a3b8;">' + escapeHtml(f.mimetype) + '</code></div>',
                    '  </div>',
                    '  <div style="margin-top:8px;">تگ‌های منتسب: ' + (tags || '<span style="color:#64748b;">بدون تگ</span>') + '</div>',
                    '</div>'
                ].join('\n');
            }

            resContainer.innerHTML = [
                '<div style="background:' + bgGlass + ';border:1px solid ' + borderCol + ';border-radius:var(--ea-radius-md);padding:20px;">',
                '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">',
                '    <div style="display:flex;align-items:center;gap:12px;">',
                '      <span style="font-size:1.1rem;font-weight:900;background:' + badgeColor + ';color:#000;padding:4px 12px;border-radius:6px;">' + escapeHtml(data.result_label || ('HTTP ' + data.http_status)) + '</span>',
                '      <span style="font-size:0.85rem;color:var(--ea-text-muted);">شناسه رویداد نظارتی ثبت‌شده در دیتابیس: <code>' + escapeHtml(data.request_id || '-') + '</code></span>',
                '    </div>',
                '    <div style="font-size:0.8rem;color:var(--ea-text-subtle);">هویت موثر (Effective Actor): <strong style="color:var(--ea-text-main);">' + escapeHtml(data.actor_uid || '-') + '</strong></div>',
                '  </div>',
                '  <div style="font-size:0.92rem;line-height:1.6;color:var(--ea-text-main);margin-bottom:8px;">' + escapeHtml(data.reason || '') + '</div>',
                metaHtml,
                '</div>'
            ].join('\n');
        })
        .catch(function (err) {
            resContainer.innerHTML = '<div style="color:#ef4444;padding:16px;">خطا در ارسال درخواست تست: ' + escapeHtml(err.message) + '</div>';
        });
    }

    // Modal: Show Plaintext Token (Only Once)
    function showTokenCreatedDialog(token, prefix, expires, isRotation) {
        var overlay = document.createElement('div');
        overlay.className = 'ea-modal-overlay';
        overlay.style.zIndex = '100002';
        overlay.innerHTML = [
            '<div class="ea-modal-card" style="max-width:600px;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <span style="color:#22c55e;">🔑 توکن احراز هویت جدید صادر شد</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-tok-diag-close">✕</button>',
            '  </div>',
            '  <div class="ea-modal-body">',
            '    <div class="ea-policy-banner" style="background:rgba(239,68,68,0.1);border-color:rgba(239,68,68,0.4);color:#fca5a5;">',
            '      <strong>⚠️ توجه بسیار مهم امنیتی:</strong><br>',
            '      این کلید خام فقط یک‌بار در این صفحه نمایش داده می‌شود. به دلیل ذخیره‌سازی هش رمزنگاری‌شده (SHA-256) در پایگاه داده، امکان بازیابی مجدد آن وجود نخواهد داشت.',
            '    </div>',
            '    <div style="font-size:0.85rem;color:var(--ea-text-muted);margin-bottom:6px;">کلید توکن خام (Raw Secret Token):</div>',
            '    <div class="ea-token-display-box" id="ea-raw-token-box">' + escapeHtml(token) + '</div>',
            '    <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:var(--ea-text-subtle);">',
            '      <span>پیشوند: <code>' + escapeHtml(prefix) + '...</code></span>',
            '      <span>انقضا: ' + escapeHtml(expires) + '</span>',
            '    </div>',
            '  </div>',
            '  <div class="ea-modal-footer">',
            '    <button class="ea-btn ea-btn-primary" id="ea-copy-token-btn">📋 کپی توکن در کلیپ‌بورد</button>',
            '    <button class="ea-btn" id="ea-tok-diag-ok-btn">متوجه شدم و ذخیره کردم</button>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(overlay);
        var closeFn = function () { overlay.remove(); };
        document.getElementById('ea-tok-diag-close').onclick = closeFn;
        document.getElementById('ea-tok-diag-ok-btn').onclick = closeFn;

        var copyBtn = document.getElementById('ea-copy-token-btn');
        copyBtn.onclick = function () {
            navigator.clipboard.writeText(token).then(function () {
                copyBtn.innerText = '✅ کپی شد!';
                setTimeout(function () { copyBtn.innerText = '📋 کپی توکن در کلیپ‌بورد'; }, 2000);
            });
        };
    }

    function promptCreateToken(serviceId) {
        var name = prompt('نام توکن جدید را وارد کنید:', 'Token ' + new Date().toISOString().slice(0, 10));
        if (name === null) return;

        fetch('/index.php/apps/archive_autotag/api/ai/admin/tokens/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'OCS-APIRequest': 'true'
            },
            body: JSON.stringify({
                service_id: serviceId,
                token_name: name,
                expires_days: 90
            })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                showTokenCreatedDialog(data.raw_token, data.token_prefix, data.expires_at, false);
                loadAiConsoleOverview();
            } else {
                alert('خطا: ' + (data.message || 'نامشخص'));
            }
        })
        .catch(function (err) { alert('خطا: ' + err.message); });
    }

    function promptRotateToken(serviceId) {
        var hoursStr = prompt('بازه تنفس (Grace Period) برای توکن قبلی چند ساعت باشد؟ (پیش‌فرض: ۲۴ ساعت)', '24');
        if (hoursStr === null) return;
        var hours = parseInt(hoursStr, 10) || 24;

        fetch('/index.php/apps/archive_autotag/api/ai/admin/tokens/rotate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'OCS-APIRequest': 'true'
            },
            body: JSON.stringify({
                service_id: serviceId,
                grace_hours: hours
            })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                showTokenCreatedDialog(data.new_raw_token, data.token_prefix, 'بازه تنفس تا: ' + data.grace_period_until, true);
                loadAiConsoleOverview();
            } else {
                alert('خطا: ' + (data.message || 'نامشخص'));
            }
        })
        .catch(function (err) { alert('خطا: ' + err.message); });
    }

    function promptRevokeToken(tokenId) {
        if (!confirm('آیا مطمئن هستید که می‌خواهید توکن #' + tokenId + ' را فوراً باطل کنید؟ دسترسی این توکن در همان لحظه قطع خواهد شد.')) {
            return;
        }

        fetch('/index.php/apps/archive_autotag/api/ai/admin/tokens/revoke', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'OCS-APIRequest': 'true'
            },
            body: JSON.stringify({ token_id: parseInt(tokenId, 10) })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                loadAiConsoleOverview();
            } else {
                alert('خطا: ' + (data.message || 'نامشخص'));
            }
        })
        .catch(function (err) { alert('خطا: ' + err.message); });
    }

    function submitAddDelegation(serviceId, type, subject) {
        fetch('/index.php/apps/archive_autotag/api/ai/admin/delegations/add', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'OCS-APIRequest': 'true'
            },
            body: JSON.stringify({
                service_id: serviceId,
                subject_type: type,
                subject_id: subject
            })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                loadAiConsoleOverview();
            } else {
                alert('خطا: ' + (data.message || 'نامشخص'));
            }
        })
        .catch(function (err) { alert('خطا: ' + err.message); });
    }

    function submitRemoveDelegation(delegationId) {
        fetch('/index.php/apps/archive_autotag/api/ai/admin/delegations/remove', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'OCS-APIRequest': 'true'
            },
            body: JSON.stringify({ delegation_id: parseInt(delegationId, 10) })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                loadAiConsoleOverview();
            } else {
                alert('خطا: ' + (data.message || 'نامشخص'));
            }
        })
        .catch(function (err) { alert('خطا: ' + err.message); });
    }


    // Tab 5: Effective Permission Inspector
    function renderPermissionInspectorTab(body) {
        body.innerHTML = [
            '<div class="ea-policy-banner" style="background:rgba(249,115,22,0.08);border-color:rgba(249,115,22,0.3);color:#fdba74;">',
            '  <strong>🔍 بازرس هوشمند مجوزهای موثر (Effective Permission Inspector):</strong><br>',
            '  در این بخش مدیران می‌توانند ارزیابی لحظه‌ای مجوزهای سیستم را بر اساس مدل واحد CentralPermissionResolver برای هر کاربر، منبع (فایل، پوشه، تگ) و نوع عملیات بررسی کنند. اولویت قطعی با سد دفاعی ابطال صریح (Explicit Revocation) و ساختار سازمانی دپارتمان (Hierarchy Trumps Ownership) است.',
            '</div>',
            '<div style="background:var(--ea-surface-card);border:1px solid var(--ea-border);border-radius:var(--ea-radius-md);padding:20px;">',
            '  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;">',
            '    <div>',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">نام کاربر مورد ارزیابی:</label>',
            '      <input type="text" id="ea-insp-user" class="ea-form-input" placeholder="مثلاً: Bakbari یا maherani یا admin" value="Bakbari" />',
            '    </div>',
            '    <div>',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">نوع منبع (Resource Type):</label>',
            '      <select id="ea-insp-type" class="ea-form-input">',
            '        <option value="file" selected>فایل (File)</option>',
            '        <option value="folder">پوشه سازمانی (Folder)</option>',
            '        <option value="tag">برچسب سیستمی (Tag)</option>',
            '      </select>',
            '    </div>',
            '    <div>',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">شناسه / مسیر منبع:</label>',
            '      <input type="text" id="ea-insp-id" class="ea-form-input" placeholder="مثلاً: 623 یا Enterprise_Archive/SOC" value="623" />',
            '    </div>',
            '    <div>',
            '      <label style="display:block;font-size:0.85rem;font-weight:700;color:var(--ea-text-main);margin-bottom:6px;">نوع عملیات (Operation):</label>',
            '      <select id="ea-insp-op" class="ea-form-input">',
            '        <option value="READ" selected>READ (خواندن محتوا)</option>',
            '        <option value="WRITE">WRITE (ویرایش/به‌روزرسانی)</option>',
            '        <option value="CREATE">CREATE (ایجاد در پوشه)</option>',
            '        <option value="DELETE">DELETE (حذف منبع)</option>',
            '        <option value="SHARE">SHARE (اشتراک‌گذاری)</option>',
            '        <option value="MANAGE">MANAGE (مدیریت سیستمی)</option>',
            '        <option value="READ_METADATA">READ_METADATA (مشاهده متادیتا)</option>',
            '        <option value="TAG_ASSIGN">TAG_ASSIGN (انتساب تگ)</option>',
            '      </select>',
            '    </div>',
            '  </div>',
            '  <div style="margin-top:14px;">',
            '    <span style="font-size:0.8rem;color:var(--ea-text-muted);">سناریوهای از پیش تعریف‌شده جهت آزمایش سریع:</span>',
            '    <div class="ea-quick-chips">',
            '      <button class="ea-chip-btn" id="ea-insp-chip-owner">👤 مالکیت Bakbari در SOC (مجاز)</button>',
            '      <button class="ea-chip-btn" id="ea-insp-chip-revoke" style="color:#f87171;border-color:rgba(239,68,68,0.4);">🚫 مالکیت پس از ابطال صریح (Revoked/Deny)</button>',
            '      <button class="ea-chip-btn" id="ea-insp-chip-cert-soc" style="color:#facc15;border-color:rgba(234,179,8,0.4);">⛔ دسترسی بین‌گروهی maherani (Deny-by-Default)</button>',
            '      <button class="ea-chip-btn" id="ea-insp-chip-cascade" style="color:#38bdf8;border-color:rgba(56,189,248,0.4);">📂 ارث‌بری پوشه والد (Ancestor Grant)</button>',
            '      <button class="ea-chip-btn" id="ea-insp-chip-admin">👑 سوپریوزر ادمین (Full 255)</button>',
            '    </div>',
            '  </div>',
            '  <div style="margin-top:20px;">',
            '    <button id="ea-insp-run-btn" class="ea-btn ea-btn-primary" style="padding:10px 24px;font-size:0.95rem;">🔍 استعلام و بازرسی بلادرنگ مجوزها</button>',
            '  </div>',
            '</div>',
            '<div id="ea-insp-result-container" style="display:none;margin-top:20px;"></div>'
        ].join('\n');

        document.getElementById('ea-insp-chip-owner').onclick = function () {
            document.getElementById('ea-insp-user').value = 'Bakbari';
            document.getElementById('ea-insp-type').value = 'file';
            document.getElementById('ea-insp-id').value = '623';
            document.getElementById('ea-insp-op').value = 'READ';
        };
        document.getElementById('ea-insp-chip-revoke').onclick = function () {
            document.getElementById('ea-insp-user').value = 'Bakbari';
            document.getElementById('ea-insp-type').value = 'file';
            document.getElementById('ea-insp-id').value = '623';
            document.getElementById('ea-insp-op').value = 'READ';
            executePermissionInspection('Bakbari', 'file', '623', 'READ');
        };
        document.getElementById('ea-insp-chip-cert-soc').onclick = function () {
            document.getElementById('ea-insp-user').value = 'maherani';
            document.getElementById('ea-insp-type').value = 'file';
            document.getElementById('ea-insp-id').value = '623';
            document.getElementById('ea-insp-op').value = 'READ';
        };
        document.getElementById('ea-insp-chip-cascade').onclick = function () {
            document.getElementById('ea-insp-user').value = 'Bakbari';
            document.getElementById('ea-insp-type').value = 'folder';
            document.getElementById('ea-insp-id').value = 'Enterprise_Archive/SOC';
            document.getElementById('ea-insp-op').value = 'READ';
        };
        document.getElementById('ea-insp-chip-admin').onclick = function () {
            document.getElementById('ea-insp-user').value = 'admin';
            document.getElementById('ea-insp-type').value = 'file';
            document.getElementById('ea-insp-id').value = '623';
            document.getElementById('ea-insp-op').value = 'READ';
        };

        document.getElementById('ea-insp-run-btn').onclick = function () {
            var user = document.getElementById('ea-insp-user').value.trim();
            var type = document.getElementById('ea-insp-type').value;
            var id = document.getElementById('ea-insp-id').value.trim();
            var op = document.getElementById('ea-insp-op').value;
            executePermissionInspection(user, type, id, op);
        };
    }

    function executePermissionInspection(user, type, id, op) {
        var resContainer = document.getElementById('ea-insp-result-container');
        if (!resContainer) return;

        resContainer.style.display = 'block';
        resContainer.innerHTML = '<div style="text-align:center;padding:24px;color:var(--ea-text-muted);">در حال ارزیابی ماتریس مجوزهای موثر...</div>';

        var query = '?target_user=' + encodeURIComponent(user) +
                    '&target_type=' + encodeURIComponent(type) +
                    '&target_id=' + encodeURIComponent(id) +
                    '&operation=' + encodeURIComponent(op);

        fetch('/index.php/apps/archive_autotag/api/permission/inspect' + query, {
            method: 'GET',
            headers: { 'OCS-APIRequest': 'true' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var isAllowed = data.allowed === true;
            var badgeColor = isAllowed ? '#22c55e' : '#ef4444';
            var bgGlass = isAllowed ? 'rgba(34,197,94,0.08)' : 'rgba(239,68,68,0.08)';
            var borderCol = isAllowed ? 'rgba(34,197,94,0.35)' : 'rgba(239,68,68,0.35)';

            var allOps = ['READ', 'WRITE', 'CREATE', 'DELETE', 'SHARE', 'MANAGE', 'READ_METADATA', 'TAG_ASSIGN'];
            var opPills = allOps.map(function (oName) {
                var hasOp = (data.effective_operations || []).indexOf(oName) !== -1;
                var opCol = hasOp ? '#22c55e' : '#64748b';
                var opBg = hasOp ? 'rgba(34,197,94,0.15)' : 'rgba(100,116,139,0.1)';
                var opBorder = hasOp ? 'rgba(34,197,94,0.4)' : 'rgba(100,116,139,0.2)';
                var icon = hasOp ? '✓' : '✗';
                return '<span style="display:inline-block;padding:3px 8px;margin:3px;border-radius:4px;font-size:0.75rem;font-family:monospace;background:' + opBg + ';color:' + opCol + ';border:1px solid ' + opBorder + ';">' + icon + ' ' + oName + '</span>';
            }).join(' ');

            resContainer.innerHTML = [
                '<div style="background:' + bgGlass + ';border:1px solid ' + borderCol + ';border-radius:var(--ea-radius-md);padding:20px;">',
                '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">',
                '    <div style="display:flex;align-items:center;gap:12px;">',
                '      <span style="font-size:1.1rem;font-weight:900;background:' + badgeColor + ';color:#000;padding:4px 14px;border-radius:6px;">' + (isAllowed ? '✅ دسترسی مجاز (ALLOWED)' : '⛔ عدم دسترسی (DENIED)') + '</span>',
                '      <span style="font-size:0.85rem;color:var(--ea-text-muted);">قاعده موثر: <code style="color:#fff;font-weight:bold;">' + escapeHtml(data.matched_rule || '-') + '</code></span>',
                '    </div>',
                '    <div style="font-size:0.85rem;color:var(--ea-text-subtle);">ماسک بیتی موثر: <strong style="color:var(--ea-text-main);font-family:monospace;">' + escapeHtml(String(data.effective_mask || 0)) + '</strong></div>',
                '  </div>',
                '  <div style="font-size:0.92rem;line-height:1.6;color:var(--ea-text-main);margin-bottom:14px;background:#05070a;padding:12px;border-radius:6px;border:1px solid #1e293b;">' + escapeHtml(data.reason || '') + '</div>',
                '  <div style="margin-top:10px;">',
                '    <div style="font-size:0.8rem;color:var(--ea-text-muted);margin-bottom:6px;">عملیات مجاز برای کاربر بر اساس ماسک موثر:</div>',
                '    <div>' + opPills + '</div>',
                '  </div>',
                '</div>'
            ].join('\n');
        })
        .catch(function (err) {
            resContainer.innerHTML = '<div style="color:#ef4444;padding:16px;">خطا در استعلام مجوزها: ' + escapeHtml(err.message) + '</div>';
        });
    }

    // Tab 6: Audit Reliability & Dead Letter Queue (DLQ) Monitor
    function renderAuditReliabilityTab(body) {
        body.innerHTML = [
            '<div style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">',
            '  <div>',
            '    <h3 style="margin:0 0 4px 0;font-size:16px;color:var(--ea-text-main, #f1f5f9);font-weight:700;">🛡️ داشبورد پایش تاب‌آوری ممیزی و صف پیام‌های مرده (DLQ)</h3>',
            '    <div style="font-size:12px;color:var(--ea-text-muted, #94a3b8);">نظارت بر سد دفاعی Fail-Closed، پایگاه‌های داده ممیزی و بازیابی رکوردهای اضطراری</div>',
            '  </div>',
            '  <div style="display:flex;gap:8px;flex-wrap:wrap;">',
            '    <button class="ea-btn ea-btn-secondary" id="ea-audit-rel-refresh-btn" style="padding:6px 12px;font-size:12px;">🔄 بروزرسانی وضعیت</button>',
            '    <button class="ea-btn ea-btn-secondary" id="ea-audit-rel-flush-btn" style="padding:6px 12px;font-size:12px;background:#065f46;color:#34d399;border-color:#10b981;">🚀 تخلیه و همگام‌سازی DLQ</button>',
            '    <button class="ea-btn ea-btn-secondary" id="ea-audit-rel-sim-btn" style="padding:6px 12px;font-size:12px;background:#7f1d1d;color:#fca5a5;border-color:#ef4444;">🧪 شبیه‌سازی شکست ممیزی (Fail-Closed)</button>',
            '  </div>',
            '</div>',
            '<div id="ea-audit-rel-metrics" style="margin-bottom:20px;">',
            '  <div style="text-align:center;padding:20px;color:var(--ea-text-muted);">در حال دریافت شاخص‌های پایداری ممیزی...</div>',
            '</div>',
            '<div id="ea-audit-rel-simulation-alert" style="display:none;margin-bottom:20px;padding:14px;border-radius:8px;background:rgba(239, 68, 68, 0.15);border:1px solid #ef4444;color:#fca5a5;font-size:13px;"></div>',
            '<div style="background:var(--ea-bg-card, #1e293b);border:1px solid var(--ea-border, #334155);border-radius:8px;padding:16px;">',
            '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">',
            '    <div style="font-size:14px;font-weight:600;color:var(--ea-text-main, #f1f5f9);">📋 استریم یکپارچه ممیزی ۴ دامنه سازمانی</div>',
            '    <div style="display:flex;gap:8px;align-items:center;">',
            '      <label style="font-size:12px;color:var(--ea-text-muted);">دامنه:</label>',
            '      <select id="ea-audit-rel-domain-filter" style="background:#0f172a;color:#f1f5f9;border:1px solid #334155;border-radius:6px;padding:4px 8px;font-size:12px;">',
            '        <option value="">همه دامنه‌ها (Unified)</option>',
            '        <option value="ai">واکشی اسناد AI</option>',
            '        <option value="tag">حاکمیت تگ‌های گروهی</option>',
            '        <option value="permission">تغییرات مجوزها (Grants)</option>',
            '        <option value="folder">درخواست‌های پوشه</option>',
            '      </select>',
            '    </div>',
            '  </div>',
            '  <div id="ea-audit-rel-stream-container" style="overflow-x:auto;">',
            '    <div style="text-align:center;padding:30px;color:var(--ea-text-muted);">در حال دریافت رخدادهای ممیزی...</div>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.getElementById('ea-audit-rel-refresh-btn').onclick = function () {
            loadAuditReliabilityData();
        };

        document.getElementById('ea-audit-rel-flush-btn').onclick = function () {
            var btn = document.getElementById('ea-audit-rel-flush-btn');
            btn.disabled = true;
            btn.textContent = 'در حال تخلیه...';
            fetch('/index.php/apps/archive_autotag/api/ai/audit/flush-dlq', {
                method: 'POST',
                headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                btn.disabled = false;
                btn.textContent = '🚀 تخلیه و همگام‌سازی DLQ';
                showToast('تخلیه DLQ با موفقیت انجام شد: ' + (res.result ? res.result.restored : 0) + ' رکورد بازیابی گردید.');
                loadAuditReliabilityData();
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = '🚀 تخلیه و همگام‌سازی DLQ';
                showToast('خطا در تخلیه DLQ');
            });
        };

        document.getElementById('ea-audit-rel-sim-btn').onclick = function () {
            var btn = document.getElementById('ea-audit-rel-sim-btn');
            btn.disabled = true;
            fetch('/index.php/apps/archive_autotag/api/ai/audit/simulate-failure', {
                method: 'POST',
                headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                btn.disabled = false;
                var alertBox = document.getElementById('ea-audit-rel-simulation-alert');
                if (alertBox) {
                    alertBox.style.display = 'block';
                    alertBox.innerHTML = '<strong>🛡️ نتیجه آزمون Fail-Closed:</strong> شکست دیتابیس در درج ممیزی با موفقیت شبیه‌سازی شد. عملیات متوقف گردید (Zero Data Egress) و رکورد خطا با شناسه ' + escapeHtml(res.simulated_request_id || '') + ' فوراً به صف اضطراری DLQ منتقل شد. (تعداد رکوردهای معلق: ' + res.dlq_count + ')';
                }
                loadAuditReliabilityData();
            })
            .catch(function () {
                btn.disabled = false;
            });
        };

        document.getElementById('ea-audit-rel-domain-filter').onchange = function () {
            loadAuditReliabilityStream();
        };

        loadAuditReliabilityData();
    }

    function loadAuditReliabilityData() {
        // Load Health Metrics
        fetch('/index.php/apps/archive_autotag/api/ai/audit/health', {
            headers: { 'OCS-APIRequest': 'true' }
        })
        .then(function (r) { return r.json(); })
        .then(function (h) {
            var container = document.getElementById('ea-audit-rel-metrics');
            if (!container) return;
            var isHealthy = h.status === 'HEALTHY';
            var statusColor = isHealthy ? '#10b981' : (h.status === 'DEGRADED' ? '#f59e0b' : '#ef4444');
            var statusText = isHealthy ? '🟢 سالم و پایدار (HEALTHY)' : (h.status === 'DEGRADED' ? '🟡 دارای رکوردهای اضطراری (DEGRADED)' : '🔴 بحرانی (CRITICAL)');
            var dlqCount = h.dlq_pending_count || 0;
            var dlqColor = dlqCount === 0 ? '#10b981' : '#ef4444';

            container.innerHTML = [
                '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;">',
                '  <div style="background:var(--ea-bg-card, #1e293b);border:1px solid ' + statusColor + ';border-radius:8px;padding:14px;">',
                '    <div style="font-size:11px;color:var(--ea-text-muted);">وضعیت سلامت ممیزی</div>',
                '    <div style="font-size:14px;font-weight:700;color:' + statusColor + ';margin-top:4px;">' + statusText + '</div>',
                '  </div>',
                '  <div style="background:var(--ea-bg-card, #1e293b);border:1px solid var(--ea-border, #334155);border-radius:8px;padding:14px;">',
                '    <div style="font-size:11px;color:var(--ea-text-muted);">کل رکوردهای ممیزی ثبت‌شده</div>',
                '    <div style="font-size:18px;font-weight:700;color:var(--ea-accent, #f97316);margin-top:4px;">' + toPersianDigits(h.total_audits || 0) + '</div>',
                '  </div>',
                '  <div style="background:var(--ea-bg-card, #1e293b);border:1px solid ' + (dlqCount > 0 ? '#ef4444' : 'var(--ea-border, #334155)') + ';border-radius:8px;padding:14px;">',
                '    <div style="font-size:11px;color:var(--ea-text-muted);">صف پیام‌های مرده (DLQ)</div>',
                '    <div style="font-size:18px;font-weight:700;color:' + dlqColor + ';margin-top:4px;">' + toPersianDigits(dlqCount) + ' پیام</div>',
                '  </div>',
                '  <div style="background:var(--ea-bg-card, #1e293b);border:1px solid var(--ea-border, #334155);border-radius:8px;padding:14px;">',
                '    <div style="font-size:11px;color:var(--ea-text-muted);">مدل معماری تاب‌آوری</div>',
                '    <div style="font-size:14px;font-weight:700;color:#38bdf8;margin-top:4px;">🔒 Fail-Closed & Atomic</div>',
                '  </div>',
                '</div>'
            ].join('\n');
        });

        loadAuditReliabilityStream();
    }

    function loadAuditReliabilityStream() {
        var domainSelect = document.getElementById('ea-audit-rel-domain-filter');
        var domain = domainSelect ? domainSelect.value : '';
        var url = '/index.php/apps/archive_autotag/api/ai/audit/stream?limit=50';
        if (domain) {
            url += '&domain=' + encodeURIComponent(domain);
        }

        fetch(url, { headers: { 'OCS-APIRequest': 'true' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var c = document.getElementById('ea-audit-rel-stream-container');
            if (!c) return;
            var events = data.events || [];
            if (events.length === 0) {
                c.innerHTML = '<div style="text-align:center;padding:30px;color:var(--ea-text-muted);">هیچ رویدادی در این دامنه ثبت نشده است.</div>';
                return;
            }

            var rows = events.map(function (ev) {
                var isOk = ev.result === 'ALLOWED' || ev.result === 'success';
                var resColor = isOk ? '#10b981' : '#ef4444';
                var domainIcon = ev.domain === 'ai' ? '🤖' : (ev.domain === 'tag' ? '🏷️' : (ev.domain === 'permission' ? '🔑' : '📂'));
                var timeStr = ev.created_at ? new Date(ev.created_at * 1000).toLocaleString('fa-IR') : '—';
                return [
                    '<tr style="border-bottom:1px solid #334155;">',
                    '  <td style="padding:10px 12px;white-space:nowrap;">' + domainIcon + ' ' + escapeHtml(ev.domain) + '</td>',
                    '  <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:var(--ea-accent, #f97316);">' + escapeHtml(ev.action) + '</td>',
                    '  <td style="padding:10px 12px;font-weight:600;color:#f1f5f9;">' + escapeHtml(ev.actor_uid) + '</td>',
                    '  <td style="padding:10px 12px;color:#cbd5e1;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(ev.target) + '">' + escapeHtml(ev.target) + '</td>',
                    '  <td style="padding:10px 12px;color:var(--ea-text-muted);font-size:11px;">' + escapeHtml(ev.details || '') + '</td>',
                    '  <td style="padding:10px 12px;"><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;background:rgba(' + (isOk ? '16,185,129' : '239,68,68') + ',0.15);color:' + resColor + ';border:1px solid ' + resColor + ';">' + escapeHtml(ev.result) + '</span></td>',
                    '  <td style="padding:10px 12px;font-family:monospace;font-size:11px;color:#94a3b8;">' + escapeHtml(ev.request_id || '') + '</td>',
                    '  <td style="padding:10px 12px;font-size:11px;color:#94a3b8;white-space:nowrap;">' + timeStr + '</td>',
                    '</tr>'
                ].join('\n');
            }).join('\n');

            c.innerHTML = [
                '<table style="width:100%;border-collapse:collapse;font-size:12px;text-align:right;">',
                '  <thead>',
                '    <tr style="border-bottom:2px solid #475569;color:#94a3b8;font-size:11px;">',
                '      <th style="padding:8px 12px;">دامنه</th>',
                '      <th style="padding:8px 12px;">عملیات</th>',
                '      <th style="padding:8px 12px;">کاربر عامل</th>',
                '      <th style="padding:8px 12px;">منبع / هدف</th>',
                '      <th style="padding:8px 12px;">جزئیات</th>',
                '      <th style="padding:8px 12px;">نتیجه</th>',
                '      <th style="padding:8px 12px;">شناسه رهگیری</th>',
                '      <th style="padding:8px 12px;">زمان</th>',
                '    </tr>',
                '  </thead>',
                '  <tbody>' + rows + '</tbody>',
                '</table>'
            ].join('\n');
        });
    }


    // -------------------------------------------------------------------------
    // Admin Group Share Modal & Operations (Requirement 24)
    // -------------------------------------------------------------------------
    // Secure Resource Deletion Modal (Requirement 26)
    function openDeleteConfirmModal(resource) {
        var existingModal = document.getElementById('ea-delete-confirm-modal');
        if (existingModal) existingModal.remove();

        var isFolder = Boolean(resource.is_dir || resource.type === 'folder' || resource.mimetype === 'httpd/unix-directory');
        var resName = resource.name || ('منبع #' + resource.id);
        var resPath = resource.path || '';

        var modal = document.createElement('div');
        modal.id = 'ea-delete-confirm-modal';
        modal.className = 'ea-share-modal-backdrop';
        modal.innerHTML = [
            '<div class="ea-share-modal-card" style="max-width: 480px; border-color: rgba(239, 68, 68, 0.4); box-shadow: 0 20px 40px rgba(0,0,0,0.6), 0 0 25px rgba(239, 68, 68, 0.2);">',
            '  <div class="ea-share-modal-header" style="border-bottom-color: rgba(239, 68, 68, 0.2);">',
            '    <h3 style="color: #ef4444; display: flex; align-items: center; gap: 8px;">',
            '      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>',
            '      <span>تایید حذف دائمی ' + (isFolder ? 'پوشه' : 'سند') + '</span>',
            '    </h3>',
            '    <button type="button" class="ea-btn" id="ea-close-delete-modal" style="padding:4px 8px;min-width:32px;">✕</button>',
            '  </div>',
            '  <div class="ea-share-modal-body" style="padding: 20px; display: flex; flex-direction: column; gap: 16px;">',
            '    <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 12px 14px; font-size: 0.85rem; color: #fca5a5; line-height: 1.6;">',
            '      ⚠️ <strong>توجه:</strong> این عملیات غیرقابل بازگشت است. منبع انتخاب‌شده به صورت کامل و فیزیکی از سامانه ذخیره‌سازی حذف خواهد شد.',
            (isFolder ? '<div style="margin-top: 6px; color: #f87171;">📁 <strong>هشدار پوشه:</strong> تمامی فایل‌ها، زیرپوشه‌ها، شناسنامه‌های متادیتا و دسترسی‌های زیرمجموعه نیز پاکسازی خواهند شد.</div>' : ''),
            '    </div>',
            '    <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(51, 65, 85, 0.6); border-radius: 8px; padding: 12px 14px;">',
            '      <div style="font-size: 0.85rem; color: var(--ea-text-muted); margin-bottom: 4px;">نام منبع:</div>',
            '      <div style="font-weight: 600; color: #f1f5f9; word-break: break-all;">' + (isFolder ? '📁 ' : '📄 ') + escapeHtml(resName) + '</div>',
            (resPath ? '<div style="font-size: 0.75rem; color: var(--ea-text-subtle); margin-top: 4px; direction: ltr; text-align: left;">' + escapeHtml(resPath) + '</div>' : ''),
            '    </div>',
            '    <div id="ea-delete-error-box" style="display: none; background: rgba(220, 38, 38, 0.2); border: 1px solid #ef4444; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; color: #fecaca;"></div>',
            '    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">',
            '      <button type="button" class="ea-btn" id="ea-cancel-delete-btn" style="padding: 8px 16px;">انصراف</button>',
            '      <button type="button" class="ea-btn ea-btn-danger" id="ea-confirm-delete-btn" style="padding: 8px 16px; background: #dc2626; color: #ffffff; border-color: #ef4444; font-weight: 600; display: flex; align-items: center; gap: 6px;">',
            '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>',
            '        <span>تایید و حذف دائمی</span>',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(modal);

        var closeModal = function() {
            modal.remove();
        };

        modal.querySelector('#ea-close-delete-modal').addEventListener('click', closeModal);
        modal.querySelector('#ea-cancel-delete-btn').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });

        var confirmBtn = modal.querySelector('#ea-confirm-delete-btn');
        var errBox = modal.querySelector('#ea-delete-error-box');

        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span>در حال حذف...</span>';
            errBox.style.display = 'none';

            var headers = { 'Content-Type': 'application/json' };
            if (typeof oc_requesttoken !== 'undefined') {
                headers['requesttoken'] = oc_requesttoken;
            }

            fetch('/index.php/apps/archive_autotag/api/resource/delete', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({
                    file_id: resource.id || 0,
                    folder_path: resource.path || null,
                    is_dir: isFolder
                })
            })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, status: res.status, data: data };
                });
            })
            .then(function (res) {
                if (res.ok && res.data && res.data.status === 'success') {
                    closeModal();
                    if (typeof closeDrawer === 'function') {
                        closeDrawer();
                    }
                    showToast(res.data.message || 'منبع با موفقیت به صورت کامل و دائمی حذف شد.');
                    if (state.currentFolder) {
                        openFolderInPortal(state.currentFolder, null);
                    } else {
                        fetchFiles();
                    }
                } else {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<span>تایید و حذف دائمی</span>';
                    errBox.textContent = (res.data && res.data.message) ? res.data.message : 'خطا در فرآیند حذف منبع.';
                    errBox.style.display = 'block';
                }
            })
            .catch(function (err) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<span>تایید و حذف دائمی</span>';
                errBox.textContent = 'خطای ارتباط با سرور: ' + (err.message || err);
                errBox.style.display = 'block';
            });
        });
    }

    function openGroupShareModal(resourceId, resourceName, resourceType) {
        var existingModal = document.getElementById('ea-group-share-modal');
        if (existingModal) existingModal.remove();

        var modal = document.createElement('div');
        modal.id = 'ea-group-share-modal';
        modal.className = 'ea-share-modal-backdrop';
        modal.innerHTML = [
            '<div class="ea-share-modal-card">',
            '  <div class="ea-share-modal-header">',
            '    <h3>👥 اشتراک‌گذاری با گروه‌ها: <span style="color:var(--ea-primary,#38bdf8);">' + escapeHtml(resourceName) + '</span></h3>',
            '    <button class="ea-btn" id="ea-close-share-modal" style="padding:4px 8px;min-width:32px;">✕</button>',
            '  </div>',
            '  <div class="ea-share-modal-body">',
            '    <div>',
            '      <h4 style="margin:0 0 10px 0;font-size:0.95rem;color:var(--ea-text-main);">گروه‌های دارای دسترسی</h4>',
            '      <div id="ea-shares-list-container" class="ea-share-table-wrap">',
            '        <div style="padding:16px;text-align:center;color:var(--ea-text-muted);">در حال بارگذاری اشتراک‌ها...</div>',
            '      </div>',
            '    </div>',
            '    <div class="ea-share-form">',
            '      <h4 style="margin:0;font-size:0.95rem;color:var(--ea-text-main);">افزودن یا ویرایش اشتراک گروه</h4>',
            '      <div>',
            '        <label style="display:block;margin-bottom:6px;font-size:0.85rem;color:var(--ea-text-muted);">انتخاب گروه کاربری:</label>',
            '        <select id="ea-share-group-select" class="ea-input" style="width:100%;padding:8px 12px;background:#1e293b;border:1px solid #334155;color:#f1f5f9;border-radius:6px;">',
            '          <option value="">در حال بارگذاری گروه‌ها...</option>',
            '        </select>',
            '      </div>',
            '      <div>',
            '        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">',
            '          <label style="font-size:0.85rem;color:var(--ea-text-muted);">سطوح دسترسی مجاز:</label>',
            '          <div style="display:flex;gap:6px;">',
            '            <button type="button" class="ea-btn" id="ea-preset-ro-btn" style="font-size:0.75rem;padding:2px 8px;">فقط خواندنی</button>',
            '            <button type="button" class="ea-btn" id="ea-preset-rw-btn" style="font-size:0.75rem;padding:2px 8px;">مشارکت کامل</button>',
            '          </div>',
            '        </div>',
            '        <div class="ea-share-perms-grid">',
            '          <label class="ea-share-perm-label"><input type="checkbox" id="ea-perm-read" value="1" checked disabled> <span>خواندن (Read)</span></label>',
            '          <label class="ea-share-perm-label"><input type="checkbox" id="ea-perm-create" value="4"> <span>ایجاد فایل (Create)</span></label>',
            '          <label class="ea-share-perm-label"><input type="checkbox" id="ea-perm-update" value="2"> <span>ویرایش (Update)</span></label>',
            '          <label class="ea-share-perm-label"><input type="checkbox" id="ea-perm-delete" value="8"> <span>حذف (Delete)</span></label>',
            '          <label class="ea-share-perm-label"><input type="checkbox" id="ea-perm-share" value="16"> <span>اشتراک (Share)</span></label>',
            '        </div>',
            '      </div>',
            '      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">',
            '        <span id="ea-share-status-msg" style="font-size:0.85rem;"></span>',
            '        <button class="ea-btn ea-btn-primary" id="ea-submit-share-btn" style="padding:8px 18px;">ثبت و ذخیره اشتراک</button>',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.appendChild(modal);

        modal.querySelector('#ea-close-share-modal').addEventListener('click', function () {
            modal.remove();
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.remove();
        });

        var groupSelect = modal.querySelector('#ea-share-group-select');
        var sharesContainer = modal.querySelector('#ea-shares-list-container');
        var statusMsg = modal.querySelector('#ea-share-status-msg');
        var submitBtn = modal.querySelector('#ea-submit-share-btn');

        var permRead = modal.querySelector('#ea-perm-read');
        var permCreate = modal.querySelector('#ea-perm-create');
        var permUpdate = modal.querySelector('#ea-perm-update');
        var permDelete = modal.querySelector('#ea-perm-delete');
        var permShare = modal.querySelector('#ea-perm-share');

        modal.querySelector('#ea-preset-ro-btn').addEventListener('click', function () {
            permCreate.checked = false;
            permUpdate.checked = false;
            permDelete.checked = false;
            permShare.checked = false;
        });

        modal.querySelector('#ea-preset-rw-btn').addEventListener('click', function () {
            permCreate.checked = true;
            permUpdate.checked = true;
            permDelete.checked = true;
            permShare.checked = true;
        });

        function loadGroups() {
            fetch('/index.php/apps/archive_autotag/api/share/groups', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.status === 'success' && Array.isArray(data.groups)) {
                    groupSelect.innerHTML = '<option value="">-- انتخاب گروه --</option>' + data.groups.map(function (g) {
                        return '<option value="' + escapeHtml(g.id) + '">' + escapeHtml(g.name) + '</option>';
                    }).join('');
                } else {
                    groupSelect.innerHTML = '<option value="">خطا در دریافت لیست گروه‌ها</option>';
                }
            })
            .catch(function () {
                groupSelect.innerHTML = '<option value="">عدم برقراری ارتباط با سرور</option>';
            });
        }

        function renderSharesList(shares) {
            if (!shares || shares.length === 0) {
                sharesContainer.innerHTML = '<div style="padding:16px;text-align:center;color:var(--ea-text-muted);">این منبع با هیچ گروهی به اشتراک گذاشته نشده است.</div>';
                return;
            }

            var rows = shares.map(function (s) {
                var p = s.permissions;
                var badges = [];
                if (p & 1) badges.push('<span class="ea-share-pill">خواندن</span>');
                if (p & 4) badges.push('<span class="ea-share-pill" style="color:#a78bfa;background:rgba(167,139,250,0.15);border-color:rgba(167,139,250,0.3);">ایجاد</span>');
                if (p & 2) badges.push('<span class="ea-share-pill" style="color:#fbbf24;background:rgba(251,191,36,0.15);border-color:rgba(251,191,36,0.3);">ویرایش</span>');
                if (p & 8) badges.push('<span class="ea-share-pill" style="color:#f87171;background:rgba(248,113,113,0.15);border-color:rgba(248,113,113,0.3);">حذف</span>');
                if (p & 16) badges.push('<span class="ea-share-pill" style="color:#34d399;background:rgba(52,211,153,0.15);border-color:rgba(52,211,153,0.3);">اشتراک</span>');

                return [
                    '<tr>',
                    '  <td style="font-weight:600;color:#f1f5f9;">👥 ' + escapeHtml(s.group_id) + '</td>',
                    '  <td>' + badges.join(' ') + '</td>',
                    '  <td style="text-align:left;width:80px;">',
                    '    <button class="ea-btn ea-btn-danger ea-delete-share-btn" data-group-id="' + escapeHtml(s.group_id) + '" style="padding:4px 8px;font-size:0.75rem;background:#7f1d1d;color:#fca5a5;border-color:#ef4444;" title="حذف دسترسی گروه">حذف</button>',
                    '  </td>',
                    '</tr>'
                ].join('\n');
            }).join('\n');

            sharesContainer.innerHTML = [
                '<table class="ea-share-table">',
                '  <thead>',
                '    <tr>',
                '      <th>نام گروه</th>',
                '      <th>مجوزهای اعطا شده</th>',
                '      <th style="text-align:left;">عملیات</th>',
                '    </tr>',
                '  </thead>',
                '  <tbody>' + rows + '</tbody>',
                '</table>'
            ].join('\n');

            sharesContainer.querySelectorAll('.ea-delete-share-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var gid = btn.getAttribute('data-group-id');
                    if (!confirm('آیا از لغو اشتراک منبع با گروه ' + gid + ' اطمینان دارید؟')) return;
                    btn.disabled = true;
                    btn.textContent = '...';
                    fetch('/index.php/apps/archive_autotag/api/share/group/delete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'requesttoken': (window.OC && window.OC.requestToken) || ''
                        },
                        body: JSON.stringify({ resource_id: resourceId, group_id: gid })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.status === 'success') {
                            loadShares();
                        } else {
                            alert(data.message || 'خطا در حذف اشتراک');
                            btn.disabled = false;
                            btn.textContent = 'حذف';
                        }
                    })
                    .catch(function (err) {
                        alert('خطا در برقراری ارتباط: ' + err.message);
                        btn.disabled = false;
                        btn.textContent = 'حذف';
                    });
                });
            });
        }

        function loadShares() {
            fetch('/index.php/apps/archive_autotag/api/share/resource?resource_id=' + encodeURIComponent(resourceId), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.status === 'success') {
                    renderSharesList(data.shares || []);
                } else {
                    sharesContainer.innerHTML = '<div style="padding:16px;color:#ef4444;text-align:center;">خطا در دریافت لیست اشتراک‌ها</div>';
                }
            })
            .catch(function () {
                sharesContainer.innerHTML = '<div style="padding:16px;color:#ef4444;text-align:center;">خطا در برقراری ارتباط</div>';
            });
        }

        submitBtn.addEventListener('click', function () {
            var selectedGroup = groupSelect.value;
            if (!selectedGroup) {
                statusMsg.style.color = '#f87171';
                statusMsg.textContent = 'لطفاً یک گروه را انتخاب کنید.';
                return;
            }

            var perms = 1; // Read always included
            if (permCreate.checked) perms |= 4;
            if (permUpdate.checked) perms |= 2;
            if (permDelete.checked) perms |= 8;
            if (permShare.checked) perms |= 16;

            submitBtn.disabled = true;
            submitBtn.textContent = 'در حال ثبت...';
            statusMsg.textContent = '';

            fetch('/index.php/apps/archive_autotag/api/share/group', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'requesttoken': (window.OC && window.OC.requestToken) || ''
                },
                body: JSON.stringify({
                    resource_id: resourceId,
                    group_id: selectedGroup,
                    permissions: perms
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'ثبت و ذخیره اشتراک';
                if (data && data.status === 'success') {
                    statusMsg.style.color = '#4ade80';
                    statusMsg.textContent = 'اشتراک گروهی با موفقیت اعمال شد.';
                    loadShares();
                } else {
                    statusMsg.style.color = '#f87171';
                    statusMsg.textContent = data.message || 'خطا در ثبت اشتراک';
                }
            })
            .catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'ثبت و ذخیره اشتراک';
                statusMsg.style.color = '#f87171';
                statusMsg.textContent = 'خطای ارتباط با سرور: ' + err.message;
            });
        });

        loadGroups();
        loadShares();
    }

})();

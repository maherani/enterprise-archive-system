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
        files: [],
        isLoadingTags: false,
        isLoadingFiles: false,
        viewMode: localStorage.getItem('ea_view_mode') || 'grid', // 'grid' or 'table'
        activeDrawerFile: null,
        debounceTimer: null,
        userRole: null,
        pendingRequestsCount: 0,
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

    // Helper: Determine File Category & Styling Class
    function getFileMeta(fileName, mimetype) {
        var ext = (fileName || '').split('.').pop().toLowerCase();
        var mime = (mimetype || '').toLowerCase();

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
        var input = document.getElementById('ea-search-input');
        if (input) input.value = '';
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

    // Render: Header & Search Ribbon
    function renderApp() {
        var root = document.getElementById('archive-portal-root');
        if (!root) return;

        var userDisplay = root.getAttribute('data-user-display') || 'کاربر سازمانی';

        root.innerHTML = [
            '<div class="ea-container">',
            '  <!-- Header -->',
            '  <header class="ea-portal-header">',
            '    <div class="ea-header-brand">',
            '      <div class="ea-brand-icon">',
            '        <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>',
            '      </div>',
            '      <div>',
            '        <h1 class="ea-brand-title">سامانه بایگانی اسناد سازمانی</h1>',
            '        <div class="ea-brand-subtitle">پورتال دسترسی سریع، فیلتر چندتگی و جستجوی اسناد • ' + escapeHtml(userDisplay) + '</div>',
            '      </div>',
            '    </div>',
            '    <div class="ea-header-actions">',
            '      <div id="ea-workflow-actions" class="ea-workflow-actions"></div>',
            '      <button id="ea-upload-btn" class="ea-btn ea-btn-primary" title="بارگذاری سند سازمانی جدید در پوشه گروه">',
            '        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            '        <span>📤 بارگذاری فایل</span>',
            '      </button>',
            '      <button id="ea-refresh-btn" class="ea-btn" title="تازه سازی اطلاعات">',
            '        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
            '        <span>به‌روزرسانی</span>',
            '      </button>',
            '      <a href="/apps/files/" class="ea-btn" title="مشاهده ساختار سنتی پوشه‌ها">',
            '        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
            '        <span>نمای پوشه‌ها</span>',
            '      </a>',
            '    </div>',
            '  </header>',
            '',
            '  <!-- Search Hero -->',
            '  <section class="ea-search-hero">',
            '    <div class="ea-search-box">',
            '      <span class="ea-search-icon">',
            '        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
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
                fetchTags();
            });
        }

        var searchInput = document.getElementById('ea-search-input');
        var clearBtn = document.getElementById('ea-search-clear');
        if (searchInput) {
            searchInput.addEventListener('input', function (e) {
                state.searchTerm = e.target.value;
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

    // Render: Faceted Tag Cloud Chips
    function renderTagBar() {
        var container = document.getElementById('ea-tag-bar-container');
        if (!container) return;

        if (state.isLoadingTags && state.allTags.length === 0) {
            container.innerHTML = '<div class="ea-tag-bar"><span class="ea-skeleton" style="width: 90px; height: 32px; display: inline-block;"></span><span class="ea-skeleton" style="width: 110px; height: 32px; display: inline-block;"></span><span class="ea-skeleton" style="width: 80px; height: 32px; display: inline-block;"></span></div>';
            return;
        }

        var html = ['<div class="ea-tag-bar">'];

        // "All Documents" Tag Chip
        var isAllActive = state.selectedTagIds.size === 0;
        html.push(
            '<div class="ea-tag-chip ' + (isAllActive ? 'active' : '') + '" data-tag-all="true">',
            '  <span>همه اسناد</span>',
            '</div>'
        );

        // Individual Tags
        state.allTags.forEach(function (tag) {
            var isActive = state.selectedTagIds.has(tag.id);
            html.push(
                '<div class="ea-tag-chip ' + (isActive ? 'active' : '') + '" data-tag-id="' + tag.id + '">',
                '  <span>' + escapeHtml(tag.name) + '</span>',
                '  <span class="ea-chip-count">' + toPersianDigits(tag.count) + '</span>',
                '</div>'
            );
        });

        html.push('</div>');
        container.innerHTML = html.join('');

        // Attach chip click handlers
        var chips = container.querySelectorAll('.ea-tag-chip');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
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

        if (!ribbon) return;

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

        // Event listener for ribbon clear buttons
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
            var meta = getFileMeta(file.name, file.mimetype);

            var tagsHtml = '';
            if (file.tags && file.tags.length > 0) {
                tagsHtml = file.tags.slice(0, 3).map(function (t) {
                    return '<span class="ea-mini-tag">' + escapeHtml(t.name) + '</span>';
                }).join('');
                if (file.tags.length > 3) {
                    tagsHtml += '<span class="ea-mini-tag">+' + toPersianDigits(file.tags.length - 3) + '</span>';
                }
            }

            cards.push(
                '<div class="ea-card" data-file-id="' + file.id + '">',
                '  <div class="ea-card-top">',
                '    <span class="ea-mime-badge ' + meta.cls + '">' + meta.label + '</span>',
                '    <span class="ea-card-size">' + toPersianDigits(file.human_size) + '</span>',
                '  </div>',
                '  <div class="ea-card-body">',
                '    <div class="ea-card-icon-title">',
                '      <div class="ea-file-type-icon ' + meta.cls + '">' + meta.iconSvg + '</div>',
                '      <div class="ea-card-title" title="' + escapeHtml(file.name) + '">' + escapeHtml(file.name) + '</div>',
                '    </div>',
                '    <div class="ea-card-tags">' + tagsHtml + '</div>',
                '  </div>',
                '  <div class="ea-card-footer">',
                '    <span class="ea-card-date">' + formatDate(file.mtime) + '</span>',
                '    <div class="ea-card-actions">',
                '      <button class="ea-icon-btn ea-action-preview" data-file-id="' + file.id + '" title="مشاهده سریع جزئیات">',
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
                '      </button>',
                '      <a href="' + escapeHtml(file.download_url) + '" class="ea-icon-btn" title="دانلود مستقیم" download>',
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
                '      </a>',
                '    </div>',
                '  </div>',
                '</div>'
            );
        });

        container.innerHTML = '<div class="ea-document-grid">' + cards.join('') + '</div>';

        // Card Click opens Drawer
        container.querySelectorAll('.ea-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.target.closest('a') || e.target.closest('button')) return;
                var fid = parseInt(card.getAttribute('data-file-id'), 10);
                var f = state.files.find(function (item) { return item.id === fid; });
                if (f) openDrawer(f);
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
    }

    // Table View
    function renderTable(container) {
        var rows = [];

        state.files.forEach(function (file) {
            var meta = getFileMeta(file.name, file.mimetype);
            var tagsText = (file.tags || []).map(function (t) {
                return '<span class="ea-mini-tag">' + escapeHtml(t.name) + '</span>';
            }).join(' ');

            rows.push(
                '<tr data-file-id="' + file.id + '" style="cursor: pointer;">',
                '  <td style="width: 48px;"><span class="ea-mime-badge ' + meta.cls + '">' + meta.label + '</span></td>',
                '  <td><strong>' + escapeHtml(file.name) + '</strong><br><small style="color: var(--ea-text-subtle);">' + escapeHtml(file.parent_dir || 'ریشه بایگانی') + '</small></td>',
                '  <td>' + tagsText + '</td>',
                '  <td style="direction: ltr; text-align: left;">' + toPersianDigits(file.human_size) + '</td>',
                '  <td>' + formatDate(file.mtime) + '</td>',
                '  <td style="width: 90px; text-align: left;">',
                '    <div style="display: flex; gap: 6px; justify-content: flex-end;">',
                '      <button class="ea-icon-btn ea-table-preview" data-file-id="' + file.id + '" title="مشاهده جزئیات">',
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
                '      </button>',
                '      <a href="' + escapeHtml(file.download_url) + '" class="ea-icon-btn" title="دانلود" download>',
                '        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
                '      </a>',
                '    </div>',
                '  </td>',
                '</tr>'
            );
        });

        container.innerHTML = [
            '<div class="ea-table-container">',
            '  <table class="ea-table">',
            '    <thead>',
            '      <tr>',
            '        <th>نوع</th>',
            '        <th>عنوان سند و مسیر</th>',
            '        <th>برچسب‌ها</th>',
            '        <th style="direction: ltr; text-align: left;">حجم</th>',
            '        <th>تاریخ</th>',
            '        <th style="text-align: left;">عملیات</th>',
            '      </tr>',
            '    </thead>',
            '    <tbody>' + rows.join('') + '</tbody>',
            '  </table>',
            '</div>'
        ].join('');

        container.querySelectorAll('tbody tr').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a') || e.target.closest('button')) return;
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
    }

    // Render: Quick View Drawer Content
    function renderDrawer() {
        var file = state.activeDrawerFile;
        if (!file) return;

        var titleEl = document.getElementById('ea-drawer-title');
        var bodyEl = document.getElementById('ea-drawer-body');
        var actionsEl = document.getElementById('ea-drawer-actions');

        if (titleEl) titleEl.textContent = file.name;

        var meta = getFileMeta(file.name, file.mimetype);

        var matchedSubadminGroup = null;
        if (state.userRole && state.userRole.is_group_admin && state.userRole.subadmin_groups) {
            var fPath = (file.path || '').toLowerCase();
            for (var gi = 0; gi < state.userRole.subadmin_groups.length; gi++) {
                var cand = state.userRole.subadmin_groups[gi];
                var candLower = cand.toLowerCase();
                if (fPath.indexOf(candLower + '/') === 0 || fPath.indexOf('/' + candLower + '/') !== -1 || fPath.indexOf('enterprise_archive/' + candLower) !== -1) {
                    matchedSubadminGroup = cand;
                    break;
                }
            }
        }

        var tagsBadges = (file.tags || []).map(function (t) {
            var rawName = t.name || '';
            var canRemove = false;
            var cleanDisplay = rawName;
            if (matchedSubadminGroup) {
                var pfx = '[' + matchedSubadminGroup + '] ';
                if (rawName.indexOf(pfx) === 0) {
                    canRemove = true;
                    cleanDisplay = rawName.substring(pfx.length);
                }
            }
            var removeBtn = canRemove
                ? ' <button class="ea-tag-del-btn" onclick="window._eaRemoveTagFromFile(\'' + escapeHtml(matchedSubadminGroup) + '\', ' + t.id + ', ' + file.id + ', \'' + escapeHtml(cleanDisplay) + '\')" title="حذف این تگ از سند" style="background:none; border:none; color:#ef4444; font-weight:bold; cursor:pointer; font-size:12px; padding:0 2px; margin-right:4px;">✕</button>'
                : '';
            return '<span class="ea-mini-tag" style="background: var(--ea-primary-glow); color: var(--ea-primary); font-size: 0.8rem; padding: 4px 10px; display:inline-flex; align-items:center;">🏷️ ' + escapeHtml(cleanDisplay) + removeBtn + '</span>';
        }).join(' ');

        var targetDir = file.target_dir || (file.is_dir ? ('/' + file.path.replace(/^\/+/g, '')) : ('/' + (file.parent_dir || '').replace(/^\/+/g, '')));
        targetDir = targetDir.replace(/\/+/g, '/');
        var folderUrl = file.folder_url || file.web_url || ('/index.php/apps/files/files?dir=' + encodeURIComponent(targetDir));

        if (bodyEl) {
            bodyEl.innerHTML = [
                '<div class="ea-drawer-preview-box">',
                '  <div class="ea-file-type-icon ' + meta.cls + '" style="width: 54px; height: 54px;">' + meta.iconSvg + '</div>',
                '  <div style="font-weight: 700; font-size: 1rem; color: var(--ea-text-main);">' + escapeHtml(file.name) + '</div>',
                '  <div style="font-size: 0.8rem; color: var(--ea-text-muted);">' + meta.label + ' Document • ' + toPersianDigits(file.human_size) + '</div>',
                '</div>',
                '',
                '<div style="margin-bottom: 20px;">',
                '  <div style="font-size: 0.85rem; font-weight: 700; color: var(--ea-text-muted); margin-bottom: 8px;">برچسب‌های متصل سازمانی:</div>',
                '  <div style="display: flex; flex-wrap: wrap; gap: 6px;">' + (tagsBadges || '<span style="color: var(--ea-text-subtle);">فاقد برچسب</span>') + '</div>',
                '</div>','' + (matchedSubadminGroup ? [
                '<div class="ea-drawer-tag-mgmt-card" style="margin-bottom: 20px; padding: 12px 14px; border-radius: var(--ea-radius); background: var(--ea-surface-elevated); border: 1px solid var(--ea-border);">',
                '  <div style="font-size: 0.84rem; font-weight: 700; color: var(--ea-text-main); margin-bottom: 8px;">🏷️ الصاق تگ اختصاصی گروه [' + escapeHtml(matchedSubadminGroup) + ']:</div>',
                '  <div style="display: flex; gap: 8px;">',
                '    <select id="ea-drawer-tag-select" class="ea-form-select" style="flex: 1; font-size: 0.82rem; padding: 4px 8px;">',
                '      <option value="">⏳ در حال دریافت تگ‌های گروه...</option>',
                '    </select>',
                '    <button id="ea-drawer-add-tag-btn" class="ea-btn ea-btn-primary" style="padding: 4px 12px; font-size: 0.82rem; white-space: nowrap;">+ الصاق به سند</button>',
                '  </div>',
                '  <div id="ea-drawer-tag-msg" style="display:none; font-size: 0.8rem; margin-top: 6px;"></div>',
                '</div>'
                ].join('\n') : '') + '',
                '',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">مسیر فایل:</span>',
                '  <span class="ea-meta-val"><a href="' + escapeHtml(folderUrl) + '" class="ea-drawer-path-link" target="_blank" rel="noopener noreferrer" style="color:var(--ea-primary);text-decoration:none;" title="مشاهده در نمای فایل‌ها">' + escapeHtml(file.path) + ' ↗</a></span>',
                '</div>',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">حجم فایل:</span>',
                '  <span class="ea-meta-val">' + toPersianDigits(file.human_size) + '</span>',
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
            actionsEl.innerHTML = [
                '<a href="' + escapeHtml(file.download_url) + '" class="ea-btn ea-btn-primary" download>',
                '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
                '  <span>دانلود سند</span>',
                '</a>',
                '<button class="ea-btn" id="ea-drawer-copy-btn">',
                '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
                '  <span>کپی لینک</span>',
                '</button>',
                '<a href="' + escapeHtml(folderUrl) + '" class="ea-btn" id="ea-drawer-locate-btn" target="_blank" rel="noopener noreferrer" title="مشاهده مکان فایل در پوشه">',
                '  <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
                '  <span>مکان در پوشه</span>',
                '</a>'
            ].join('\n');

            var copyBtn = document.getElementById('ea-drawer-copy-btn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    copyFileLink(file);
                });
            }

            function onLocateClick(e) {
                try {
                    sessionStorage.setItem('ea_target_dir', targetDir);
                } catch (err) {}
                if (window.OCP && window.OCP.Files && window.OCP.Files.Router) {
                    try {
                        e.preventDefault();
                        window.OCP.Files.Router.goToRoute('filelist', { view: 'files' }, { dir: targetDir });
                        return;
                    } catch (err) {}
                }
            }

            var locateBtn = document.getElementById('ea-drawer-locate-btn');
            if (locateBtn) {
                locateBtn.addEventListener('click', onLocateClick);
            }

            if (matchedSubadminGroup) {
                setupDrawerTagActions(file, matchedSubadminGroup);
            }
            var pathLink = document.querySelector('.ea-drawer-path-link');
            if (pathLink) {
                pathLink.addEventListener('click', onLocateClick);
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
                '</button>'
            ].join('\n');

            var createBtn = document.getElementById('ea-admin-create-folder-btn');
            if (createBtn) createBtn.onclick = openAdminCreateFolderModal;

            var adminBtn = document.getElementById('ea-admin-manage-reqs-btn');
            if (adminBtn) adminBtn.onclick = function () { openAdminManageRequestsModal(); };
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

    // Modal: Native File Upload to Group Folder
    function openUploadModal(initialFile) {
        closeModal();

        var overlay = document.createElement('div');
        overlay.id = 'ea-active-modal';
        overlay.className = 'ea-modal-overlay';
        overlay.innerHTML = [
            '<div class="ea-modal-card" style="max-width: 580px;">',
            '  <div class="ea-modal-header">',
            '    <div class="ea-modal-title">',
            '      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            '      <span>بارگذاری سند جدید در بایگانی گروه</span>',
            '    </div>',
            '    <button class="ea-modal-close" id="ea-upload-modal-close-btn" title="بستن">&times;</button>',
            '  </div>',
            '  <div class="ea-modal-body">',
            '    <div id="ea-upload-error" class="ea-form-error" style="display:none;"></div>',
            '    ',
            '    <label class="ea-form-label">۱. پوشه مقصد در گروه سازمانی:</label>',
            '    <select id="ea-upload-target-select" class="ea-form-select"></select>',
            '    <div id="ea-upload-projected-tags" class="ea-upload-projected-tags" style="margin-top: 6px; margin-bottom: 14px;"></div>',
            '    ',
            '    <label class="ea-form-label">۲. انتخاب یا رها کردن فایل (Drag & Drop):</label>',
            '    <div id="ea-dropzone-box" class="ea-dropzone-box">',
            '      <div class="ea-dropzone-icon">',
            '        <svg width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
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
            '    <div id="ea-upload-progress-wrap" class="ea-upload-progress-wrap" style="display:none;">',
            '      <div class="ea-upload-progress-bar">',
            '        <div id="ea-upload-progress-fill" class="ea-upload-progress-fill" style="width: 0%;"></div>',
            '      </div>',
            '      <div id="ea-upload-progress-text" class="ea-upload-progress-text">در حال بارگذاری: ۰٪</div>',
            '    </div>',
            '    ',
            '    <div style="margin-top: 12px; padding: 10px 14px; background: rgba(249, 115, 22, 0.08); border: 1px solid rgba(249, 115, 22, 0.25); border-radius: 8px; font-size: 0.82rem; color: #cbd5e1; line-height: 1.6;">',
            '      ✨ <strong>تگ‌گذاری خودکار سلسله‌مراتبی:</strong> به محض تکمیل بارگذاری، کلیه تگ‌های سلسله‌مراتبی پوشه والد به‌صورت خودکار توسط سیستم روی سند اعمال خواهند شد.',
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

        closeBtn.onclick = closeModal;
        cancelBtn.onclick = closeModal;

        var selectedFile = null;
        var folderMap = {};

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
            var paths = Object.keys(folderMap).sort();
            if (paths.length === 0) {
                folderSelect.innerHTML = '<option value="/SOC">📁 /SOC</option>';
            } else {
                folderSelect.innerHTML = paths.map(function(k) {
                    var item = folderMap[k];
                    return '<option value="' + escapeHtml(item.path) + '">' + escapeHtml(item.display) + '</option>';
                }).join('');
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

        // Also fetch from /api/group-folders if user has groups to pick up any subfolders
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
            submitBtn.disabled = false;
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
            submitBtn.disabled = true;
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
            if (!selectedFile) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'لطفاً ابتدا یک فایل را انتخاب فرمایید.';
                return;
            }
            var targetDir = (folderSelect.value || '').replace(/^\/+|\/+$/g, '');
            if (!targetDir) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'پوشه مقصد نامعتبر است.';
                return;
            }

            var userId = '';
            if (state.userRole && state.userRole.user_id) {
                userId = state.userRole.user_id;
            } else if (window.OC && (window.OC.currentUser || (window.OC.getCurrentUser && window.OC.getCurrentUser().uid))) {
                userId = window.OC.currentUser || window.OC.getCurrentUser().uid;
            } else {
                var userMeta = document.querySelector('meta[name="user"]');
                if (userMeta) userId = userMeta.getAttribute('content');
            }
            if (!userId) userId = 'admin';

            // Construct WebDAV PUT URL
            var segments = targetDir.split('/').filter(Boolean).map(encodeURIComponent);
            var davUrl = '/remote.php/dav/files/' + encodeURIComponent(userId) + '/' + segments.join('/') + '/' + encodeURIComponent(selectedFile.name);

            submitBtn.disabled = true;
            cancelBtn.disabled = true;
            closeBtn.disabled = true;
            errorDiv.style.display = 'none';
            progressWrap.style.display = 'block';
            progressFill.style.width = '0%';
            progressText.textContent = 'در حال ارسال سند به سامانه...';

            var xhr = new XMLHttpRequest();
            xhr.open('PUT', davUrl, true);
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
                if (xhr.status === 200 || xhr.status === 201 || xhr.status === 204) {
                    progressFill.style.width = '100%';
                    progressText.innerHTML = '<span style="color:#22c55e; font-weight:700;">✅ سند با موفقیت بارگذاری شد و تگ‌های سلسله‌مراتبی پوشه اعمال گردید.</span>';
                    setTimeout(function() {
                        closeModal();
                        showToast('سند «' + selectedFile.name + '» در پوشه /' + targetDir + ' بارگذاری و تگ‌گذاری شد.');
                        fetchFiles();
                        fetchTags();
                    }, 1200);
                } else {
                    submitBtn.disabled = false;
                    cancelBtn.disabled = false;
                    closeBtn.disabled = false;
                    progressWrap.style.display = 'none';
                    errorDiv.style.display = 'block';
                    var msg = 'خطا در بارگذاری (وضعیت ' + xhr.status + ')';
                    if (xhr.status === 413) {
                        msg = 'خطای محدودیت حجم: اندازه فایل فراتر از سقف مجاز است.';
                    } else if (xhr.status === 403) {
                        msg = 'خطای عدم دسترسی (۴۰۳): شما دسترسی لازم برای نوشتن در این پوشه را ندارید.';
                    } else if (xhr.status === 507) {
                        msg = 'خطای سهمیه دیسک: حجم مجاز ذخیره‌سازی تکمیل گردیده است.';
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
                errorDiv.textContent = 'خطای ارتباط شبکه هنگام بارگذاری سند.';
            };

            xhr.send(selectedFile);
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
            '<div class="ea-modal-card ea-modal-card-lg">',
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
            '<div class="ea-modal-card ea-modal-card-lg">',
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
                        '<div class="ea-table-actions">',
                        '  <button type="button" class="ea-btn ea-btn-sm ea-btn-approve" data-id="' + req.id + '" data-folder-name="' + escapeHtml(req.folder_name) + '" data-group-id="' + escapeHtml(req.group_id) + '">✔ تأیید و ساخت</button>',
                        '  <button type="button" class="ea-btn ea-btn-sm ea-btn-reject" data-id="' + req.id + '" data-folder-name="' + escapeHtml(req.folder_name) + '">✖ رد درخواست</button>',
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
                    '  <td style="word-break:break-word;min-width:180px;"><strong style="color:#ffffff;font-size:0.9rem;">' + escapeHtml(req.folder_name) + '</strong>' + pathInfo + descInfo + '</td>',
                    '  <td style="text-align:center;white-space:nowrap;"><span class="ea-meta-tag-chip" style="margin:0;font-size:0.76rem;padding:2px 8px;">' + escapeHtml(req.group_id) + '</span></td>',
                    '  <td style="text-align:center;font-size:0.8rem;word-break:break-all;">' + escapeHtml(req.requester_uid) + '</td>',
                    '  <td style="text-align:center;font-size:0.78rem;color:var(--ea-text-muted);white-space:nowrap;">' + formatDate(req.created_at) + '</td>',
                    '  <td style="text-align:center;white-space:nowrap;">' + statusHtml + '</td>',
                    '  <td style="text-align:center;min-width:130px;">' + actionsHtml + '</td>',
                    '</tr>'
                ].join('\n');
            }).join('\n');

            body.innerHTML = [
                '<div class="ea-req-table-wrap">',
                '  <table class="ea-req-table">',
                '    <thead>',
                '      <tr>',
                '        <th style="width:50px;text-align:center;">شناسه</th>',
                '        <th style="min-width:180px;text-align:right;">نام پوشه و توضیحات</th>',
                '        <th style="width:70px;text-align:center;">گروه</th>',
                '        <th style="width:90px;text-align:center;">ادمین متقاضی</th>',
                '        <th style="width:105px;text-align:center;">تاریخ ثبت</th>',
                '        <th style="width:115px;text-align:center;">وضعیت</th>',
                '        <th style="width:140px;text-align:center;">عملیات</th>',
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
            '    <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 8px; color: var(--ea-text-main);">فهرست تگ‌های ثبت‌شده برای گروه:</div>',
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
                        html.push(
                            '<tr style="border-bottom: 1px solid var(--ea-border); transition: background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.02)\'" onmouseout="this.style.background=\'transparent\'">',
                            '  <td style="padding: 10px 14px; font-weight: 700; color: var(--ea-text-main);"><span class="ea-mini-tag" style="font-size: 0.82rem;">🏷️ ' + escapeHtml(t.clean_name || t.name) + '</span></td>',
                            '  <td style="padding: 10px 14px; color: var(--ea-text-muted);">' + toPersianDigits(t.file_count || 0) + ' سند</td>',
                            '  <td style="padding: 10px 14px; text-align: center;">',
                            '    <button class="ea-btn" style="padding: 4px 10px; font-size: 0.8rem; color: #ef4444; border-color: rgba(239,68,68,0.3);" onclick="window._eaDeleteGroupTag(\'' + escapeHtml(grp) + '\', ' + t.id + ', \'' + escapeHtml(t.clean_name || t.name) + '\')">🗑️ حذف</button>',
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

        window._eaDeleteGroupTag = function (grp, tagId, tagName) {
            if (!confirm('آیا از حذف تگ اختصاصی «' + tagName + '» اطمینان دارید؟ این تگ از تمامی فایل‌های این گروه جدا خواهد شد.')) {
                return;
            }
            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.OC && window.OC.requestToken) headers['requesttoken'] = window.OC.requestToken;

            fetch('/index.php/apps/archive_autotag/api/group-tags/delete', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ group_id: grp, tag_id: tagId })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.status === 'success') {
                    showToast('تگ اختصاصی «' + tagName + '» حذف گردید.');
                    loadGroupTags(grp);
                    fetchTags();
                    fetchFiles();
                } else {
                    alert('خطا در حذف تگ: ' + ((data && data.message) ? data.message : 'نامشخص'));
                }
            })
            .catch(function (err) {
                alert('خطای ارتباط: ' + err.message);
            });
        };

        loadGroupTags(selectedGroup);
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
        var root = document.getElementById('archive-portal-root');
        if (root) {
            fetchUserRole();
            fetchTags();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

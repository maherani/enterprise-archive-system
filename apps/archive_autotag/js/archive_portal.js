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

    // Action: Open Drawer
    function openDrawer(file) {
        state.activeDrawerFile = file;
        renderDrawer();
        var drawer = document.getElementById('ea-drawer-backdrop');
        if (drawer) {
            drawer.classList.add('open');
        }
    }

    // Action: Close Drawer
    function closeDrawer() {
        var drawer = document.getElementById('ea-drawer-backdrop');
        if (drawer) {
            drawer.classList.remove('open');
        }
        setTimeout(function () {
            state.activeDrawerFile = null;
        }, 320);
    }

    // Action: Copy File Link
    function copyFileLink(file) {
        var fullUrl = window.location.origin + file.web_url;
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
        renderTagBar();
        renderStatsAndRibbon();
        renderDocumentList();
    }

    // Attach Event Listeners
    function attachEventListeners() {
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

        var tagsBadges = (file.tags || []).map(function (t) {
            return '<span class="ea-mini-tag" style="background: var(--ea-primary-glow); color: var(--ea-primary); font-size: 0.8rem; padding: 4px 10px;">🏷️ ' + escapeHtml(t.name) + '</span>';
        }).join(' ');

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
                '</div>',
                '',
                '<div class="ea-meta-item">',
                '  <span class="ea-meta-label">مسیر فایل:</span>',
                '  <span class="ea-meta-val">' + escapeHtml(file.path) + '</span>',
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
                '<a href="' + escapeHtml(file.web_url) + '" class="ea-btn" title="مشاهده در نمای فایل‌ها">',
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
        }
    }

    // Auto-Initialization when DOM is ready
    function init() {
        var root = document.getElementById('archive-portal-root');
        if (root) {
            fetchTags();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

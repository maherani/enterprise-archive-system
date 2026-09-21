/**
 * Enterprise Archive Multi-Tag Filter & Unified Folder Explorer
 * Replaces default Nextcloud file table with unified Enterprise Archive 4-column Obsidian table.
 * Hub 10 / Nextcloud 34 Vue Compatible
 */
(function () {
    'use strict';

    var state = {
        allTags: [],
        selectedTagIds: new Set(),
        filterSearchTerm: '',
        isLoadingTags: false,
        isLoadingFiles: false,
        currentDirectory: null,
        files: [],
        userRole: null,
    };

    // Global reference for diagnostics & testing
    window.EnterpriseArchiveTagFilter = {
        state: state,
        reload: fetchTags,
        render: renderFilterBar,
        loadFolder: fetchCurrentFolderFiles,
    };

    function getApiUrl(path) {
        return '/index.php/apps/archive_autotag' + path;
    }

    function getCsrfToken() {
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        var tag = document.querySelector('head > meta[name="csrf-token"]') ||
                  document.querySelector('meta[name="csrf-token"]');
        return tag ? tag.getAttribute('content') : '';
    }

    function getCurrentDirectory() {
        // 1. From URL search params
        var searchParams = new URLSearchParams(window.location.search);
        var dir = searchParams.get('dir');
        if (dir) return dir;

        // 2. From URL hash
        if (window.location.hash) {
            var match = window.location.hash.match(/[?&]dir=([^&]+)/);
            if (match) {
                return decodeURIComponent(match[1]);
            }
        }

        // 3. From Nextcloud Vue Router
        if (window.OCP && window.OCP.Files && window.OCP.Files.Router) {
            try {
                var curRoute = window.OCP.Files.Router.currentRoute;
                if (curRoute && curRoute.value && curRoute.value.query && curRoute.value.query.dir) {
                    return curRoute.value.query.dir;
                }
            } catch (e) {}
        }

        // 4. From Nextcloud Files App fileList
        if (window.OCA && window.OCA.Files && window.OCA.Files.App && window.OCA.Files.App.fileList) {
            try {
                var fDir = window.OCA.Files.App.fileList.getCurrentDirectory();
                if (fDir) return fDir;
            } catch (e) {}
        }

        return '/';
    }

    
    function fetchUserRole() {
        fetch('/index.php/apps/archive_autotag/api/user-role', {
            headers: {
                'Accept': 'application/json',
                'requesttoken': getCsrfToken()
            }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.status === 'success' && data.role) {
                state.userRole = data.role;
                if (state.files && state.files.length > 0) {
                    renderFileList({ files: state.files, total: state.files.length });
                }
            }
        })
        .catch(function (e) {
            console.warn('[ArchiveMultiTagFilter] Failed to fetch user role:', e);
        });
    }

    function fetchTags() {
        if (state.isLoadingTags) return;
        state.isLoadingTags = true;

        var url = getApiUrl('/api/tags');
        fetch(url, {
            headers: {
                'requesttoken': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            state.isLoadingTags = false;
            if (data && data.status === 'success' && Array.isArray(data.tags)) {
                state.allTags = data.tags;
                renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();
            }
        })
        .catch(function (err) {
            state.isLoadingTags = false;
            console.warn('[ArchiveMultiTagFilter] Could not load tags:', err);
        });
    }

    function findMountTarget() {
        var filesList = document.querySelector('.files-list');
        if (filesList && filesList.parentNode) {
            return { parent: filesList.parentNode, insertBefore: filesList };
        }

        var target = document.querySelector('.files-list__before');
        if (target) return { parent: target, insertBefore: null };

        var header = document.querySelector('.files-list__header');
        if (header && header.parentNode) {
            return { parent: header.parentNode, insertBefore: header.nextSibling };
        }

        var mainContent = document.querySelector('main.app-content') ||
                          document.querySelector('#app-content-vue') ||
                          document.querySelector('.app-content');
        if (mainContent) {
            return { parent: mainContent, insertBefore: mainContent.firstChild };
        }

        return null;
    }


    /**
     * Collapsible Sidebar Drawer Management - Admin Back-Office
     * Toggled by clicking on the word 'Files' in the top header
     */
    function setupSidebarDrawer() {
        var stored = localStorage.getItem('ea_sidebar_collapsed');
        if (stored === '0') {
            document.body.classList.remove('ea-sidebar-collapsed');
        } else {
            // Default to collapsed for maximized back-office workspace
            document.body.classList.add('ea-sidebar-collapsed');
        }

        bindHeaderFilesToggle();

        // Inject Sidebar Header inside .app-navigation
        var nav = document.querySelector('.app-navigation');
        if (nav && !nav.querySelector('.ea-sidebar-header')) {
            var header = document.createElement('div');
            header.className = 'ea-sidebar-header';
            header.innerHTML = '<span>📁 دسترسی به فایل‌ها</span><button type="button" class="ea-sidebar-close-btn" title="بستن منو (کشویی)">◀</button>';
            header.querySelector('.ea-sidebar-close-btn').addEventListener('click', function () {
                toggleSidebar(true);
            });
            nav.insertBefore(header, nav.firstChild);
        }
    }

    function bindHeaderFilesToggle() {
        var appBtn = document.querySelector('.app-menu__current-app');
        if (appBtn && !appBtn.hasAttribute('data-ea-btn-bound')) {
            appBtn.setAttribute('data-ea-btn-bound', 'true');
            appBtn.style.cursor = 'pointer';
            appBtn.setAttribute('title', 'کلیک برای باز/بستن منوی فایل‌ها (کشویی)');

            appBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                var isCurrentlyCollapsed = document.body.classList.contains('ea-sidebar-collapsed');
                toggleSidebar(!isCurrentlyCollapsed);
            }, true);
        }

        var filesName = document.querySelector('.app-menu__current-app-name') ||
                        document.querySelector('.app-menu__current-app .button-vue__text');
        if (filesName && !filesName.hasAttribute('data-ea-toggle-bound')) {
            filesName.setAttribute('data-ea-toggle-bound', 'true');
            filesName.style.cursor = 'pointer';
            filesName.setAttribute('title', 'کلیک برای باز/بستن منوی فایل‌ها (کشویی)');

            filesName.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                var isCurrentlyCollapsed = document.body.classList.contains('ea-sidebar-collapsed');
                toggleSidebar(!isCurrentlyCollapsed);
            }, true);
        }
    }

    function toggleSidebar(collapse) {
        if (collapse) {
            document.body.classList.add('ea-sidebar-collapsed');
            localStorage.setItem('ea_sidebar_collapsed', '1');
        } else {
            document.body.classList.remove('ea-sidebar-collapsed');
            localStorage.setItem('ea_sidebar_collapsed', '0');
        }
    }

    function ensureMounted() {
        var isFilesApp = window.location.pathname.indexOf('/apps/files') !== -1 ||
                         window.location.hash.indexOf('files') !== -1 ||
                         document.querySelector('.app-files') !== null;

        if (!isFilesApp) {
            return;
        }

        // Always hide standard Nextcloud table
        toggleStandardFileList(false);

        if (state.allTags.length === 0 && !state.isLoadingTags) {
            fetchTags();
    fetchUserRole();
        }

        var existingBar = document.querySelector('#archive-tag-filter-bar');
        if (!existingBar || !document.body.contains(existingBar)) {
            var mountInfo = findMountTarget();
            if (mountInfo && mountInfo.parent) {
                mountFilterBar(mountInfo);
            }
        }

        // Check if directory changed in Files view (when no tag filter active)
        if (state.selectedTagIds.size === 0) {
            var activeDir = getCurrentDirectory();
            var resultsContainer = document.querySelector('#archive-tag-results-container');
            if (state.currentDirectory !== activeDir || !resultsContainer) {
                state.currentDirectory = activeDir;
                fetchCurrentFolderFiles(activeDir);
            }
        }
    }

    function mountFilterBar(mountInfo) {
        var existing = document.querySelector('#archive-tag-filter-bar');
        if (existing) {
            existing.remove();
        }

        var container = document.createElement('div');
        container.id = 'archive-tag-filter-bar';
        container.className = 'archive-tag-filter-container';

        if (mountInfo.insertBefore) {
            mountInfo.parent.insertBefore(container, mountInfo.insertBefore);
        } else {
            mountInfo.parent.appendChild(container);
        }

        renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();

        if (state.selectedTagIds.size > 0) {
            fetchFilteredFiles();
        } else {
            fetchCurrentFolderFiles(getCurrentDirectory());
        }
    }

    function renderFilterBar() {
        var container = document.querySelector('#archive-tag-filter-bar');
        if (!container) return;

        var visibleTags = state.allTags.filter(function (t) {
            if (!state.filterSearchTerm) return true;
            return t.name.toLowerCase().indexOf(state.filterSearchTerm.toLowerCase()) !== -1;
        });

        var chipsHtml = '';
        if (state.isLoadingTags && state.allTags.length === 0) {
            chipsHtml = '<div style="color: #888; font-size: 12px; padding: 4px;">⏳ در حال بارگذاری برچسب‌های سازمانی...</div>';
        } else if (visibleTags.length === 0) {
            chipsHtml = '<div style="color: #888; font-size: 12px; padding: 4px;">برچسبی یافت نشد.</div>';
        } else {
            chipsHtml = visibleTags.map(function (t) {
                var isSelected = state.selectedTagIds.has(t.id);
                var activeClass = isSelected ? 'is-active' : '';
                var checkIcon = isSelected ? '✓ ' : '';
                return '<div class="archive-tag-chip ' + activeClass + '" data-tag-id="' + t.id + '">' +
                       '<span>' + checkIcon + escapeHtml(t.name) + '</span>' +
                       '<span class="chip-count">' + t.count + '</span>' +
                       '</div>';
            }).join('');
        }

        var activeBarHtml = '';
        if (state.selectedTagIds.size > 0) {
            var activeBadges = [];
            state.selectedTagIds.forEach(function (id) {
                var found = state.allTags.find(function (t) { return t.id === id; });
                if (found) {
                    activeBadges.push(
                        '<span class="archive-active-tag-badge">' +
                        escapeHtml(found.name) +
                        ' <span class="archive-active-tag-remove" data-remove-id="' + found.id + '">✕</span>' +
                        '</span>'
                    );
                }
            });

            activeBarHtml = '<div class="archive-active-filter-bar">' +
                            '<div class="archive-active-tags-list">' +
                            '<span class="archive-active-label">برچسب‌های فعال (منطق اشتراک AND):</span>' +
                            activeBadges.join('') +
                            '</div>' +
                            '<button type="button" class="archive-clear-btn" id="archive-clear-all-btn">پاک کردن همه فیلترها</button>' +
                            '</div>';
        }

        container.innerHTML = 
            '<div class="archive-tag-filter-header">' +
                '<div class="archive-tag-filter-title">' +
                    '<span class="icon-tag">🏷️</span>' +
                    '<span>فیلتر پیشرفته برچسب‌های اسناد (Multi-Tag Intersection)</span>' +
                '</div>' +
                '<div class="archive-tag-filter-controls">' +
                    '<input type="text" class="archive-tag-search-input" id="archive-tag-search-input" placeholder="جستجوی برچسب..." value="' + escapeHtml(state.filterSearchTerm) + '">' +
                '</div>' +
            '</div>' +
            '<div class="archive-tag-chips-wrapper" id="archive-tag-chips-wrapper">' +
                chipsHtml +
            '</div>' +
            activeBarHtml;

        attachEvents(container);
    }

    function attachEvents(container) {
        // Tag chips click
        var chips = container.querySelectorAll('.archive-tag-chip');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var tagId = parseInt(this.getAttribute('data-tag-id'), 10);
                if (state.selectedTagIds.has(tagId)) {
                    state.selectedTagIds.delete(tagId);
                } else {
                    state.selectedTagIds.add(tagId);
                }
                renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();
                onFilterChange();
            });
        });

        // Remove single active tag
        var removeButtons = container.querySelectorAll('.archive-active-tag-remove');
        removeButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var tagId = parseInt(this.getAttribute('data-remove-id'), 10);
                state.selectedTagIds.delete(tagId);
                renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();
                onFilterChange();
            });
        });

        // Clear all
        var clearBtn = container.querySelector('#archive-clear-all-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                state.selectedTagIds.clear();
                renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();
                onFilterChange();
            });
        }

        // Tag search filter
        var searchInput = container.querySelector('#archive-tag-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.filterSearchTerm = this.value;
                var chipsWrapper = container.querySelector('#archive-tag-chips-wrapper');
                if (chipsWrapper) {
                    var visibleTags = state.allTags.filter(function (t) {
                        if (!state.filterSearchTerm) return true;
                        return t.name.toLowerCase().indexOf(state.filterSearchTerm.toLowerCase()) !== -1;
                    });
                    chipsWrapper.innerHTML = visibleTags.map(function (t) {
                        var isSelected = state.selectedTagIds.has(t.id);
                        var activeClass = isSelected ? 'is-active' : '';
                        var checkIcon = isSelected ? '✓ ' : '';
                        return '<div class="archive-tag-chip ' + activeClass + '" data-tag-id="' + t.id + '">' +
                               '<span>' + checkIcon + escapeHtml(t.name) + '</span>' +
                               '<span class="chip-count">' + t.count + '</span>' +
                               '</div>';
                    }).join('');

                    chipsWrapper.querySelectorAll('.archive-tag-chip').forEach(function (c) {
                        c.addEventListener('click', function (e) {
                            e.preventDefault();
                            e.stopPropagation();
                            var tid = parseInt(this.getAttribute('data-tag-id'), 10);
                            if (state.selectedTagIds.has(tid)) {
                                state.selectedTagIds.delete(tid);
                            } else {
                                state.selectedTagIds.add(tid);
                            }
                            renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();
                            onFilterChange();
                        });
                    });
                }
            });
        }
    }

    function toggleStandardFileList(show) {
        var selectors = [
            '.files-list',
            '.files-filestable',
            '#fileList',
            'table[data-cy-files-list]'
        ];
        var listElements = document.querySelectorAll(selectors.join(', '));
        listElements.forEach(function (el) {
            el.style.display = show ? '' : 'none';
        });
    }

    function onFilterChange() {
        toggleStandardFileList(false);
        if (state.selectedTagIds.size === 0) {
            fetchCurrentFolderFiles(getCurrentDirectory());
            return;
        }
        fetchFilteredFiles();
    }

    function getOrCreateResultsContainer() {
        var filterBar = document.querySelector('#archive-tag-filter-bar');
        if (!filterBar) return null;

        var parent = filterBar.parentNode;
        var existingResults = document.querySelector('#archive-tag-results-container');
        if (!existingResults) {
            existingResults = document.createElement('div');
            existingResults.id = 'archive-tag-results-container';
            existingResults.className = 'archive-tag-results-container';
            if (filterBar.nextSibling) {
                parent.insertBefore(existingResults, filterBar.nextSibling);
            } else {
                parent.appendChild(existingResults);
            }
        }
        existingResults.style.display = 'block';
        return existingResults;
    }

    function fetchCurrentFolderFiles(dir) {
        var currentDir = dir || getCurrentDirectory();
        state.currentDirectory = currentDir;

        var container = getOrCreateResultsContainer();
        if (!container) return;

        toggleStandardFileList(false);

        var url = getApiUrl('/api/folder-files?dir=' + encodeURIComponent(currentDir));
        fetch(url, {
            headers: {
                'requesttoken': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (state.selectedTagIds.size > 0) return; // Ignore if user selected a tag meanwhile
            data.count_label = 'تعداد اسناد: ' + (data.total || 0) + ' سند';
            renderResults(data);
        })
        .catch(function (err) {
            console.warn('[ArchiveMultiTagFilter] Folder files fetch error:', err);
            if (state.selectedTagIds.size === 0) {
                container.innerHTML = '<div class="archive-empty-results"><div style="color: #ef4444;">خطا در دریافت لیست اسناد پوشه جاری.</div></div>';
            }
        });
    }

    function fetchFilteredFiles() {
        var tagIds = Array.from(state.selectedTagIds).join(',');
        var url = getApiUrl('/api/filter?tag_ids=' + encodeURIComponent(tagIds));

        var container = getOrCreateResultsContainer();
        if (!container) return;

        container.innerHTML = '<div class="archive-empty-results"><div>⏳ در حال انطباق برچسب‌ها و استخراج اسناد...</div></div>';
        toggleStandardFileList(false);

        fetch(url, {
            headers: {
                'requesttoken': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (state.selectedTagIds.size === 0) return; // User cleared tags
            data.count_label = 'تعداد اسناد منطبق: ' + (data.total || 0) + ' سند';
            renderResults(data);
        })
        .catch(function (err) {
            console.error('[ArchiveMultiTagFilter] Query error:', err);
            container.innerHTML = '<div class="archive-empty-results"><div style="color: #ef4444;">خطا در دریافت نتایج فیلتر.</div></div>';
        });
    }

    function renderResults(data) {
        var container = document.querySelector('#archive-tag-results-container');
        if (!container) return;

        if (!data || data.status !== 'success' || !data.files || data.files.length === 0) {
            var emptyMessage = state.selectedTagIds.size > 0
                ? '<div><strong>هیچ سندی با تمام برچسب‌های انتخابی یافت نشد.</strong></div><div style="font-size: 12px; margin-top: 6px; color: #888;">برای گسترش نتایج، برخی از تگ‌ها را لغو انتخاب نمایید.</div>'
                : '<div><strong>این پوشه خالی است یا سندی در آن وجود ندارد.</strong></div>';

            container.innerHTML = 
                '<div class="archive-empty-results">' +
                    '<div class="archive-empty-icon">📂</div>' +
                    emptyMessage +
                '</div>';
            return;
        }

        var rowsHtml = data.files.map(function (file) {
            var icon = file.is_dir ? '📁' : '📄';

            var displayPath = file.is_dir ? file.path : (file.parent_dir || file.path);
            if (!displayPath || displayPath === '.' || displayPath === '/') {
                displayPath = 'Enterprise_Archive';
            }
            displayPath = displayPath.replace(/^\/+/g, '');

            var targetDir = file.target_dir || (file.is_dir ? ('/' + file.path.replace(/^\/+/g, '')) : ('/' + (file.parent_dir || '').replace(/^\/+/g, '')));
            targetDir = targetDir.replace(/\/+/g, '/');

            var sizeDisplay = file.is_dir ? '-' : (file.human_size || '0 B');

            var actionButtonsHtml = '';
            if (file.is_dir) {
                actionButtonsHtml = '';
            } else {
                actionButtonsHtml = 
                    '<a class="archive-action-btn archive-download-btn" href="' + escapeHtml(file.download_url) + '" download title="دانلود">⬇️ دانلود</a>';
            }

            if (state.userRole && state.userRole.is_admin) {
                actionButtonsHtml += (actionButtonsHtml ? ' ' : '') + '<button type="button" class="archive-action-btn archive-share-btn" data-file-id="' + file.id + '" data-file-name="' + escapeHtml(file.name) + '" title="اشتراک با گروه">👥 اشتراک با گروه</button>';
            }

            return '<tr class="' + (file.is_dir ? 'archive-folder-row' : 'archive-file-row') + '" data-is-dir="' + (file.is_dir ? 'true' : 'false') + '" data-target-dir="' + escapeHtml(targetDir) + '" data-web-url="' + escapeHtml(file.web_url) + '"' + (file.is_dir ? ' style="cursor: pointer;"' : '') + '>' +
                   '<td title="' + escapeHtml(file.name) + '">' +
                       '<div class="archive-file-name-cell">' +
                           '<span class="archive-file-icon">' + icon + '</span>' +
                           '<a class="archive-nav-link" href="' + escapeHtml(file.web_url) + '" data-file-id="' + file.id + '" data-is-dir="' + (file.is_dir ? 'true' : 'false') + '" data-target-dir="' + escapeHtml(targetDir) + '">' + escapeHtml(file.name) + '</a>' +
                       '</div>' +
                   '</td>' +
                   '<td title="' + escapeHtml(displayPath) + '">' +
                       '<span class="archive-file-path-badge">' + escapeHtml(displayPath) + '</span>' +
                   '</td>' +
                   '<td class="archive-cell-size">' + escapeHtml(sizeDisplay) + '</td>' +
                   '<td>' +
                       '<div class="archive-actions-cell">' +
                           actionButtonsHtml +
                       '</div>' +
                   '</td>' +
                   '</tr>';
        }).join('');

        var countTitle = data.count_label || ('تعداد اسناد: ' + data.total + ' سند');

        container.innerHTML = 
            '<div class="archive-results-header">' +
                '<div class="archive-results-count">' + escapeHtml(countTitle) + '</div>' +
            '</div>' +
            '<table class="archive-results-table">' +
                '<colgroup>' +
                    '<col style="width: 36%;">' +
                    '<col style="width: 36%;">' +
                    '<col style="width: 90px;">' +
                    '<col style="width: 240px;">' +
                '</colgroup>' +
                '<thead>' +
                    '<tr>' +
                        '<th>نام سند</th>' +
                        '<th>مسیر در بایگانی</th>' +
                        '<th style="text-align: center;">حجم</th>' +
                        '<th style="text-align: left;">عملیات</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>' +
                    rowsHtml +
                '</tbody>' +
            '</table>';


        // Attach click listeners to share buttons
        var shareButtons = container.querySelectorAll('.archive-share-btn');
        shareButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var fileId = btn.getAttribute('data-file-id');
                var fileName = btn.getAttribute('data-file-name');
                openGroupShareModal(fileId, fileName);
            });
        });

        // Make folder rows clickable to navigate directly into folder
        var folderRows = container.querySelectorAll('tr[data-is-dir="true"]');
        folderRows.forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('button') || e.target.closest('a')) return;
                var targetDir = row.getAttribute('data-target-dir') || '/';
                var webUrl = row.getAttribute('data-web-url');
                if (state.selectedTagIds.size > 0) {
                    state.selectedTagIds.clear();
                    renderFilterBar();
                    setupSidebarDrawer();
                    bindHeaderFilesToggle();
                }
                navigateToDirectory(targetDir, webUrl);
            });
        });

        // Attach reliable click listeners to navigation links
        var navLinks = container.querySelectorAll('.archive-nav-link, .archive-locate-btn');
        navLinks.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (e.ctrlKey || e.metaKey || e.button === 1) {
                    return; // Allow opening in new tab
                }
                e.preventDefault();
                e.stopPropagation();

                var isDir = btn.getAttribute('data-is-dir') === 'true';
                var targetDir = btn.getAttribute('data-target-dir') || '/';
                var webUrl = btn.getAttribute('href');

                // If filter was active, clear tags
                if (state.selectedTagIds.size > 0) {
                    state.selectedTagIds.clear();
                    renderFilterBar();
        setupSidebarDrawer();
        bindHeaderFilesToggle();
                }

                navigateToDirectory(targetDir, webUrl);
            });
        });
    }


    function openGroupShareModal(resourceId, resourceName) {
        var existingModal = document.getElementById('ea-group-share-modal');
        if (existingModal) existingModal.remove();

        var modal = document.createElement('div');
        modal.id = 'ea-group-share-modal';
        modal.className = 'ea-share-modal-backdrop';
        modal.innerHTML = [
            '<div class="ea-share-modal-card">',
            '  <div class="ea-share-modal-header">',
            '    <h3>👥 اشتراک‌گذاری با گروه‌ها: <span style="color:#38bdf8;">' + escapeHtml(resourceName) + '</span></h3>',
            '    <button type="button" id="ea-close-share-modal" style="background:transparent;border:none;color:#94a3b8;font-size:1.2rem;cursor:pointer;padding:4px 8px;">✕</button>',
            '  </div>',
            '  <div class="ea-share-modal-body">',
            '    <div>',
            '      <h4 style="margin:0 0 10px 0;font-size:0.95rem;color:#f1f5f9;">گروه‌های دارای دسترسی</h4>',
            '      <div id="ea-shares-list-container" class="ea-share-table-wrap">',
            '        <div style="padding:16px;text-align:center;color:#94a3b8;">در حال بارگذاری اشتراک‌ها...</div>',
            '      </div>',
            '    </div>',
            '    <div class="ea-share-form">',
            '      <h4 style="margin:0;font-size:0.95rem;color:#f1f5f9;">افزودن یا ویرایش اشتراک گروه</h4>',
            '      <div>',
            '        <label style="display:block;margin-bottom:6px;font-size:0.85rem;color:#94a3b8;">انتخاب گروه کاربری:</label>',
            '        <select id="ea-share-group-select" style="width:100%;padding:8px 12px;background:#1e293b;border:1px solid #334155;color:#f1f5f9;border-radius:6px;direction:rtl;">',
            '          <option value="">در حال بارگذاری گروه‌ها...</option>',
            '        </select>',
            '      </div>',
            '      <div>',
            '        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">',
            '          <label style="font-size:0.85rem;color:#94a3b8;">سطوح دسترسی مجاز:</label>',
            '          <div style="display:flex;gap:6px;">',
            '            <button type="button" id="ea-preset-ro-btn" style="font-size:0.75rem;padding:3px 8px;background:#1e293b;border:1px solid #334155;color:#cbd5e1;border-radius:4px;cursor:pointer;">فقط خواندنی</button>',
            '            <button type="button" id="ea-preset-rw-btn" style="font-size:0.75rem;padding:3px 8px;background:#1e293b;border:1px solid #334155;color:#cbd5e1;border-radius:4px;cursor:pointer;">مشارکت کامل</button>',
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
            '        <button type="button" id="ea-submit-share-btn" style="padding:8px 18px;background:#0284c7;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">ثبت و ذخیره اشتراک</button>',
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
                headers: { 'Accept': 'application/json', 'requesttoken': getCsrfToken() }
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
                sharesContainer.innerHTML = '<div style="padding:16px;text-align:center;color:#94a3b8;">این منبع با هیچ گروهی به اشتراک گذاشته نشده است.</div>';
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
                    '    <button type="button" class="ea-delete-share-btn" data-group-id="' + escapeHtml(s.group_id) + '" style="padding:4px 8px;font-size:0.75rem;background:#7f1d1d;color:#fca5a5;border:1px solid #ef4444;border-radius:4px;cursor:pointer;" title="حذف دسترسی گروه">حذف</button>',
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
                            'requesttoken': getCsrfToken()
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
                headers: { 'Accept': 'application/json', 'requesttoken': getCsrfToken() }
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

            var perms = 1; // Read
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
                    'requesttoken': getCsrfToken()
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

    function navigateToDirectory(targetDir, webUrl) {
        state.currentDirectory = targetDir;

        // 1. Nextcloud Vue Router navigation
        if (window.OCP && window.OCP.Files && window.OCP.Files.Router) {
            try {
                window.OCP.Files.Router.goToRoute('filelist', { view: 'files' }, { dir: targetDir });
                fetchCurrentFolderFiles(targetDir);
                return;
            } catch (routerErr) {
                console.warn('[ArchiveMultiTagFilter] Router navigation failed:', routerErr);
            }
        }

        // 2. HTML5 pushState & fetch
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('dir', targetDir);
            window.history.pushState({ dir: targetDir }, '', url.toString());
            fetchCurrentFolderFiles(targetDir);
            return;
        } catch (e) {}

        // 3. Fallback: window.location
        if (webUrl) {
            window.location.href = webUrl;
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Startup and continuous watcher
    fetchTags();
    fetchUserRole();

    // 1. Continuous watcher for route/DOM changes
    setInterval(ensureMounted, 400);

    // 2. Observer on #content for rapid mounting on Vue render
    var observer = new MutationObserver(function () {
        ensureMounted();
    });

    function startObserver() {
        var content = document.querySelector('#content') || document.body;
        if (content) {
            observer.observe(content, { childList: true, subtree: true });
        } else {
            setTimeout(startObserver, 200);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startObserver);
    } else {
        startObserver();
    }

    window.addEventListener('popstate', function () {
        state.currentDirectory = null;
        ensureMounted();
    });
    window.addEventListener('hashchange', function () {
        state.currentDirectory = null;
        ensureMounted();
    });
})();

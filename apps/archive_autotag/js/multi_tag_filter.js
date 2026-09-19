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
                actionButtonsHtml = '<a class="archive-action-btn archive-locate-btn" href="' + escapeHtml(file.web_url) + '" data-file-id="' + file.id + '" data-is-dir="true" data-target-dir="' + escapeHtml(targetDir) + '" title="باز کردن پوشه">📂 باز کردن پوشه</a>';
            } else {
                actionButtonsHtml = 
                    '<a class="archive-action-btn archive-locate-btn" href="' + escapeHtml(file.web_url) + '" data-file-id="' + file.id + '" data-is-dir="false" data-target-dir="' + escapeHtml(targetDir) + '" title="مشاهده در پوشه">📂 مشاهده در پوشه</a>' +
                    '<a class="archive-action-btn archive-download-btn" href="' + escapeHtml(file.download_url) + '" download title="دانلود">⬇️ دانلود</a>';
            }

            return '<tr>' +
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
                    '<col style="width: 32%;">' +
                    '<col style="width: 36%;">' +
                    '<col style="width: 10%;">' +
                    '<col style="width: 22%;">' +
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
                }

                navigateToDirectory(targetDir, webUrl);
            });
        });
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

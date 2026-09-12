/**
 * Enterprise Archive Multi-Tag Filter Frontend
 * Interactive Tag Intersection for Nextcloud Files App
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
        files: [],
    };

    // Global reference for diagnostics & testing
    window.EnterpriseArchiveTagFilter = {
        state: state,
        reload: fetchTags,
        render: renderFilterBar,
    };

    function getApiUrl(path) {
        // Direct relative paths avoid any OC.generateUrl query encoding differences
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
                // Re-render immediately so chips populate the bar!
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

        if (state.allTags.length === 0 && !state.isLoadingTags) {
            fetchTags();
        }

        var existingBar = document.querySelector('#archive-tag-filter-bar');
        if (existingBar && document.body.contains(existingBar)) {
            return;
        }

        var mountInfo = findMountTarget();
        if (!mountInfo || !mountInfo.parent) {
            return;
        }

        mountFilterBar(mountInfo);
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
            onFilterChange();
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
            '#fileList'
        ];
        var listElements = document.querySelectorAll(selectors.join(', '));
        listElements.forEach(function (el) {
            el.style.display = show ? '' : 'none';
        });
    }

    function onFilterChange() {
        var resultsContainer = document.querySelector('#archive-tag-results-container');
        if (state.selectedTagIds.size === 0) {
            if (resultsContainer) {
                resultsContainer.remove();
            }
            toggleStandardFileList(true);
            return;
        }

        toggleStandardFileList(false);
        fetchFilteredFiles();
    }

    function fetchFilteredFiles() {
        var tagIds = Array.from(state.selectedTagIds).join(',');
        var url = getApiUrl('/api/filter?tag_ids=' + encodeURIComponent(tagIds));

        var filterBar = document.querySelector('#archive-tag-filter-bar');
        if (!filterBar) return;

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
        existingResults.innerHTML = '<div class="archive-empty-results"><div>⏳ در حال انطباق برچسب‌ها و استخراج اسناد...</div></div>';

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
            renderResults(data);
        })
        .catch(function (err) {
            console.error('[ArchiveMultiTagFilter] Query error:', err);
            existingResults.innerHTML = '<div class="archive-empty-results"><div style="color: #d9534f;">خطا در دریافت نتایج فیلتر.</div></div>';
        });
    }

    function renderResults(data) {
        var container = document.querySelector('#archive-tag-results-container');
        if (!container) return;

        if (!data || data.status !== 'success' || !data.files || data.files.length === 0) {
            container.innerHTML = 
                '<div class="archive-empty-results">' +
                    '<div class="archive-empty-icon">📂</div>' +
                    '<div><strong>هیچ سندی با تمام برچسب‌های انتخابی یافت نشد.</strong></div>' +
                    '<div style="font-size: 12px; margin-top: 6px; color: #888;">برای گسترش نتایج، برخی از تگ‌ها را لغو انتخاب نمایید.</div>' +
                '</div>';
            return;
        }

        var rowsHtml = data.files.map(function (file) {
            var tagsHtml = (file.tags || []).map(function (t) {
                var isMatched = state.selectedTagIds.has(t.id);
                var style = isMatched ? 'font-weight: bold; background: rgba(0, 130, 201, 0.2); border-color: var(--color-primary, #0082c9);' : '';
                return '<span class="archive-file-tag-pill" style="' + style + '">' + escapeHtml(t.name) + '</span>';
            }).join('');

            var icon = file.is_dir ? '📁' : '📄';

            return '<tr>' +
                   '<td>' +
                       '<div class="archive-file-name-cell">' +
                           '<span>' + icon + '</span>' +
                           '<a href="' + escapeHtml(file.web_url) + '">' + escapeHtml(file.name) + '</a>' +
                       '</div>' +
                   '</td>' +
                   '<td><span class="archive-file-path-badge">' + escapeHtml(file.path) + '</span></td>' +
                   '<td>' + escapeHtml(file.human_size) + '</td>' +
                   '<td>' + tagsHtml + '</td>' +
                   '<td>' +
                       '<a class="archive-action-btn" href="' + escapeHtml(file.web_url) + '" title="مشاهده در پوشه">📂 مشاهده در پوشه</a>' +
                       (!file.is_dir ? '<a class="archive-action-btn" href="' + escapeHtml(file.download_url) + '" download title="دانلود">⬇️ دانلود</a>' : '') +
                   '</td>' +
                   '</tr>';
        }).join('');

        container.innerHTML = 
            '<div class="archive-results-header">' +
                '<div class="archive-results-count">تعداد اسناد منطبق: ' + data.total + ' سند</div>' +
            '</div>' +
            '<table class="archive-results-table">' +
                '<colgroup>' +
                    '<col style="width: 24%;">' +
                    '<col style="width: 28%;">' +
                    '<col style="width: 10%;">' +
                    '<col style="width: 20%;">' +
                    '<col style="width: 18%;">' +
                '</colgroup>' +
                '<thead>' +
                    '<tr>' +
                        '<th>نام سند</th>' +
                        '<th>مسیر در بایگانی</th>' +
                        '<th>حجم</th>' +
                        '<th>برچسب‌ها</th>' +
                        '<th>عملیات</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>' +
                    rowsHtml +
                '</tbody>' +
            '</table>';
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

    // 1. Check periodically so hydration/navigation never loses the bar
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

    window.addEventListener('popstate', ensureMounted);
    window.addEventListener('hashchange', ensureMounted);
})();

/**
 * Enterprise Archive Multi-Tag Filter Frontend
 * Interactive Tag Intersection for Nextcloud Files App
 */
(function () {
    'use strict';

    if (window.ArchiveMultiTagFilterInitialized) {
        return;
    }
    window.ArchiveMultiTagFilterInitialized = true;

    var state = {
        allTags: [],
        selectedTagIds: new Set(),
        filterSearchTerm: '',
        isLoading: false,
        files: [],
    };

    function init() {
        // Observe and mount when Files app DOM is ready
        var checkInterval = setInterval(function () {
            var targetParent = document.querySelector('#app-content-files') ||
                               document.querySelector('#app-content') ||
                               document.querySelector('#content');
            if (targetParent) {
                clearInterval(checkInterval);
                loadTagsAndRender(targetParent);
            }
        }, 300);

        // Also re-verify presence on SPA navigation
        window.addEventListener('popstate', function () {
            setTimeout(function () {
                var targetParent = document.querySelector('#app-content-files') ||
                                   document.querySelector('#app-content') ||
                                   document.querySelector('#content');
                if (targetParent && !document.querySelector('#archive-tag-filter-bar')) {
                    mountFilterBar(targetParent);
                }
            }, 500);
        });
    }

    function getApiUrl(endpoint) {
        if (window.OC && window.OC.generateUrl) {
            return window.OC.generateUrl('/apps/archive_autotag' + endpoint);
        }
        return '/index.php/apps/archive_autotag' + endpoint;
    }

    function getCsrfToken() {
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        var tag = document.querySelector('head > meta[name="csrf-token"]');
        return tag ? tag.getAttribute('content') : '';
    }

    function loadTagsAndRender(targetParent) {
        var url = getApiUrl('/api/tags');
        fetch(url, {
            headers: {
                'requesttoken': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(function (res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        })
        .then(function (data) {
            if (data && data.status === 'success' && Array.isArray(data.tags)) {
                state.allTags = data.tags;
                mountFilterBar(targetParent);
            }
        })
        .catch(function (err) {
            console.warn('[ArchiveMultiTagFilter] Tag loading notice:', err);
        });
    }

    function mountFilterBar(targetParent) {
        var existing = document.querySelector('#archive-tag-filter-bar');
        if (existing) {
            existing.remove();
        }

        var container = document.createElement('div');
        container.id = 'archive-tag-filter-bar';
        container.className = 'archive-tag-filter-container';

        // Insert at the top of Files view
        if (targetParent.firstChild) {
            targetParent.insertBefore(container, targetParent.firstChild);
        } else {
            targetParent.appendChild(container);
        }

        renderFilterBar();
    }

    function renderFilterBar() {
        var container = document.querySelector('#archive-tag-filter-bar');
        if (!container) return;

        var visibleTags = state.allTags.filter(function (t) {
            if (!state.filterSearchTerm) return true;
            return t.name.toLowerCase().indexOf(state.filterSearchTerm.toLowerCase()) !== -1;
        });

        var chipsHtml = visibleTags.map(function (t) {
            var isSelected = state.selectedTagIds.has(t.id);
            var activeClass = isSelected ? 'is-active' : '';
            var checkIcon = isSelected ? '✓ ' : '';
            return '<div class="archive-tag-chip ' + activeClass + '" data-tag-id="' + t.id + '">' +
                   '<span>' + checkIcon + escapeHtml(t.name) + '</span>' +
                   '<span class="chip-count">' + t.count + '</span>' +
                   '</div>';
        }).join('');

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
            chip.addEventListener('click', function () {
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
            clearBtn.addEventListener('click', function () {
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
                    // Re-bind chip clicks
                    chipsWrapper.querySelectorAll('.archive-tag-chip').forEach(function (c) {
                        c.addEventListener('click', function () {
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

    function toggleStandardFileList(show) {
        var standardList = document.querySelector('.files-filestable') ||
                           document.querySelector('#fileList') ||
                           document.querySelector('#app-content-vue');
        if (standardList) {
            standardList.style.display = show ? '' : 'none';
        }
    }

    function fetchFilteredFiles() {
        var tagIds = Array.from(state.selectedTagIds).join(',');
        var url = getApiUrl('/api/filter?tag_ids=' + encodeURIComponent(tagIds));

        var parent = document.querySelector('#archive-tag-filter-bar').parentNode;
        var existingResults = document.querySelector('#archive-tag-results-container');
        if (!existingResults) {
            existingResults = document.createElement('div');
            existingResults.id = 'archive-tag-results-container';
            existingResults.className = 'archive-tag-results-container';
            parent.appendChild(existingResults);
        }

        existingResults.innerHTML = '<div class="archive-empty-results"><div>⏳ در حال انطباق برچسب‌ها و استخراج اسناد...</div></div>';

        fetch(url, {
            headers: {
                'requesttoken': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
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

    // Initialize when ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

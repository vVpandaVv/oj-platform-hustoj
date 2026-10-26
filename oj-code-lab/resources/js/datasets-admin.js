/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/**
 * Teacher "추천 데이터" curation screen behind [data-ocl-datasets]
 * (Addon\Console_Datasets_Page_Handler): search Kaggle, paste a direct file
 * URL (공공데이터포털/GitHub/자체 서버), or upload a file — all three land
 * in the same shared recommended-dataset list.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ocl-datasets]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');

        var messagesEl = root.querySelector('[data-field="messages"]');
        var searchForm = root.querySelector('[data-field="search-form"]');
        var searchResultsEl = root.querySelector('[data-field="search-results"]');
        var filesSection = root.querySelector('[data-field="files-section"]');
        var filesTitleEl = root.querySelector('[data-field="files-title"]');
        var filesListEl = root.querySelector('[data-field="files-list"]');
        var urlForm = root.querySelector('[data-field="url-form"]');
        var uploadForm = root.querySelector('[data-field="upload-form"]');
        var datasetBody = root.querySelector('[data-field="dataset-body"]');
        var restoreSamplesButton = root.querySelector('[data-action="restore-samples"]');

        var SOURCE_LABEL = { kaggle: 'Kaggle', url: 'URL', upload: '업로드' };

        function request(path, method, body) {
            return fetch(config.restUrl + path, {
                method: method || 'GET',
                headers: Object.assign(
                    { 'X-WP-Nonce': config.nonce },
                    body ? { 'Content-Type': 'application/json' } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    return data;
                });
            });
        }

        function requestMultipart(path, formData) {
            return fetch(config.restUrl + path, {
                method: 'POST',
                headers: { 'X-WP-Nonce': config.nonce },
                body: formData,
                credentials: 'same-origin',
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    return data;
                });
            });
        }

        // Completed actions use the platform toast where it exists (OJ
        // Platform 2.1+); errors and older platforms keep an inline notice.
        var NOTICE_TONE = { info: 'info', success: 'success', error: 'danger' };
        function showMessage(text, type) {
            var ojp = 'undefined' !== typeof window ? window.OJP : null;
            messagesEl.innerHTML = '';
            if ('success' === type && ojp && 'function' === typeof ojp.toast) {
                ojp.toast(text);
                return;
            }
            var item = document.createElement('div');
            item.className = 'ojp-notice ojp-notice--' + (NOTICE_TONE[type] || 'info');
            item.textContent = text;
            messagesEl.appendChild(item);
        }

        function sourceDetail(dataset) {
            if ('kaggle' === dataset.source) {
                return dataset.kaggleOwner + '/' + dataset.kaggleSlug + (dataset.fileName ? ' (' + dataset.fileName + ')' : '');
            }
            if ('url' === dataset.source) {
                return dataset.sourceUrl;
            }
            return dataset.fileName || '';
        }

        function loadDatasets() {
            request('/datasets/recommended').then(function (datasets) {
                datasetBody.innerHTML = '';
                if (0 === datasets.length) {
                    datasetBody.innerHTML = '<tr><td colspan="4">아직 추가한 추천 데이터가 없습니다.</td></tr>';
                    return;
                }
                datasets.forEach(function (dataset) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td></td><td></td><td></td><td><button type="button" class="ojp-button ojp-button--danger ojp-button--small" data-action="delete">삭제</button></td>';
                    tr.children[0].textContent = dataset.icon || '';
                    tr.children[1].textContent = dataset.label;
                    tr.children[2].textContent = (SOURCE_LABEL[dataset.source] || dataset.source) + ' — ' + sourceDetail(dataset);

                    tr.querySelector('[data-action="delete"]').addEventListener('click', function () {
                        confirmDelete(dataset).then(function (ok) {
                            if (!ok) { return; }
                            request('/datasets/recommended/' + dataset.id, 'DELETE').then(function () {
                                loadDatasets();
                            }).catch(function (error) {
                                showMessage(error.message, 'error');
                            });
                        });
                    });

                    datasetBody.appendChild(tr);
                });
            }).catch(function (error) {
                datasetBody.innerHTML = '<tr><td colspan="4">불러오지 못했습니다.</td></tr>';
                showMessage(error.message, 'error');
            });
        }

        // Platform 2.1+ asks first in its own sheet; older platforms keep
        // the previous one-click delete.
        function confirmDelete(dataset) {
            var ojp = window.OJP;
            if (!ojp || 'function' !== typeof ojp.confirm) {
                return Promise.resolve(true);
            }
            return ojp.confirm({
                title: '"' + dataset.label + '"을(를) 추천 목록에서 삭제할까요?',
                message: '학생 실습실의 추천 데이터 목록에서 사라집니다. 이미 가져온 학생 노트북은 바뀌지 않습니다.',
                confirmLabel: '삭제',
                destructive: true,
            });
        }

        function supportedFile(file) {
            return /\.(csv|jsonl|ndjson|zip)$/i.test(file.name || '');
        }

        function addRecommended(owner, slug, fileName, defaultLabel) {
            var label = window.prompt('학생에게 보일 이름을 입력하세요.', defaultLabel || (owner + '/' + slug));
            if (null === label || '' === label.trim()) {
                return;
            }
            var icon = window.prompt('아이콘 이모지(선택, 비워도 됩니다)', '📊') || '';

            return request('/datasets/recommended', 'POST', {
                label: label.trim(),
                icon: icon.trim(),
                source: 'kaggle',
                kaggleOwner: owner,
                kaggleSlug: slug,
                fileName: fileName || null,
            }).then(function () {
                showMessage('추천 데이터에 추가했습니다', 'success');
                loadDatasets();
            }).catch(function (error) {
                showMessage(error.message, 'error');
            });
        }

        function datasetRow(titleText, subtitle, buttonLabel, onClick, disabled) {
            var row = document.createElement('div');
            row.className = 'ocl-dataset-row';

            var main = document.createElement('div');
            main.className = 'ocl-dataset-row-main';
            var strong = document.createElement('strong');
            strong.textContent = titleText;
            main.appendChild(strong);
            if (subtitle) {
                var span = document.createElement('span');
                span.textContent = subtitle;
                main.appendChild(span);
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ojp-button ojp-button--small';
            button.textContent = buttonLabel;
            button.disabled = !!disabled;
            if (!disabled) { button.addEventListener('click', onClick); }

            row.appendChild(main);
            row.appendChild(button);
            return row;
        }

        function showFiles(owner, slug) {
            filesSection.hidden = false;
            filesTitleEl.textContent = owner + '/' + slug + ' — CSV · JSONL · NDJSON · ZIP 파일 선택';
            filesListEl.innerHTML = '<p class="ocl-empty">불러오는 중…</p>';

            request('/datasets/files?owner=' + encodeURIComponent(owner) + '&slug=' + encodeURIComponent(slug))
                .then(function (files) {
                    filesListEl.innerHTML = '';
                    if (0 === files.length) {
                        filesListEl.innerHTML = '<p class="ocl-empty">파일을 찾지 못했습니다.</p>';
                        return;
                    }
                    files.forEach(function (file) {
                        var supported = supportedFile(file);
                        filesListEl.appendChild(datasetRow(file.name, file.size, supported ? '이 파일로 추가' : '지원하지 않는 형식', function () {
                            addRecommended(owner, slug, file.name, file.name);
                        }, !supported));
                    });
                }).catch(function (error) {
                    filesListEl.textContent = error.message;
                });
        }

        function runSearch(query) {
            searchResultsEl.innerHTML = '<p class="ocl-empty">검색 중…</p>';
            request('/datasets/search?q=' + encodeURIComponent(query)).then(function (results) {
                searchResultsEl.innerHTML = '';
                if (0 === results.length) {
                    searchResultsEl.innerHTML = '<p class="ocl-empty">검색 결과가 없습니다.</p>';
                    return;
                }
                results.forEach(function (item) {
                    var parts = item.ref.split('/');
                    var row = datasetRow(item.title || item.ref, item.subtitle || item.ref, '파일 선택', function () {
                        if (2 === parts.length) {
                            showFiles(parts[0], parts[1]);
                        }
                    });

                    var quickAdd = document.createElement('button');
                    quickAdd.type = 'button';
                    quickAdd.className = 'ojp-button ojp-button--tinted ojp-button--small';
                    quickAdd.textContent = '첫 지원 파일 추가';
                    quickAdd.title = '파일 목록의 첫 CSV·JSONL·NDJSON·ZIP 파일을 추천 자료로 추가합니다.';
                    quickAdd.addEventListener('click', function () {
                        if (2 === parts.length) {
                            quickAdd.disabled = true;
                            request('/datasets/files?owner=' + encodeURIComponent(parts[0]) + '&slug=' + encodeURIComponent(parts[1]))
                                .then(function (files) {
                                    var file = files.find(supportedFile);
                                    if (!file) {
                                        throw new Error('추가할 수 있는 CSV·JSONL·NDJSON·ZIP 파일이 없습니다.');
                                    }
                                    return addRecommended(parts[0], parts[1], file.name, item.title || file.name);
                                }).catch(function (error) {
                                    showMessage(error.message, 'error');
                                }).finally(function () {
                                    quickAdd.disabled = false;
                                });
                        }
                    });
                    row.appendChild(quickAdd);

                    searchResultsEl.appendChild(row);
                });
            }).catch(function (error) {
                searchResultsEl.textContent = error.message;
            });
        }

        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var query = searchForm.querySelector('[data-field="search-input"]').value.trim();
            if (query) {
                filesSection.hidden = true;
                runSearch(query);
            }
        });

        urlForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var label = urlForm.querySelector('[data-field="url-label"]').value.trim();
            var icon = urlForm.querySelector('[data-field="url-icon"]').value.trim();
            var url = urlForm.querySelector('[data-field="url-value"]').value.trim();
            if (!label || !url) {
                return;
            }

            request('/datasets/recommended', 'POST', {
                label: label,
                icon: icon,
                source: 'url',
                sourceUrl: url,
            }).then(function () {
                showMessage('추천 데이터에 추가했습니다', 'success');
                urlForm.reset();
                loadDatasets();
            }).catch(function (error) {
                showMessage(error.message, 'error');
            });
        });

        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var label = uploadForm.querySelector('[data-field="upload-label"]').value.trim();
            var icon = uploadForm.querySelector('[data-field="upload-icon"]').value.trim();
            var fileInput = uploadForm.querySelector('[data-field="upload-file"]');
            if (!label || !fileInput.files || 0 === fileInput.files.length) {
                return;
            }

            var formData = new FormData();
            formData.append('label', label);
            formData.append('icon', icon);
            formData.append('file', fileInput.files[0]);

            requestMultipart('/datasets/recommended/upload', formData).then(function () {
                showMessage('파일을 올리고 추천 데이터에 추가했습니다', 'success');
                uploadForm.reset();
                loadDatasets();
            }).catch(function (error) {
                showMessage(error.message, 'error');
            });
        });

        restoreSamplesButton.addEventListener('click', function () {
            restoreSamplesButton.disabled = true;
            restoreSamplesButton.textContent = '복구 중…';
            request('/datasets/recommended/restore-samples', 'POST', {}).then(function (result) {
                showMessage(
                    result.restored > 0
                        ? result.restored + '개의 샘플 데이터를 복구했습니다.'
                        : '삭제된 샘플 데이터가 없습니다.',
                    'success'
                );
                loadDatasets();
            }).catch(function (error) {
                showMessage(error.message, 'error');
            }).finally(function () {
                restoreSamplesButton.disabled = false;
                restoreSamplesButton.textContent = '샘플 데이터 복구';
            });
        });

        loadDatasets();
    });
})();

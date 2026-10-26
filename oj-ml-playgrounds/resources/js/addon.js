(function () {
	'use strict';

	var SELECTOR = '.oj-ml-app[data-oj-ml-page]';

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function joinUrl(root, path) {
		return String(root || '').replace(/\/+$/, '') + '/' + String(path || '').replace(/^\/+/, '');
	}

	function formatDate(value) {
		if (!value) return '-';
		var date = new Date(value);
		return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
	}

	function unwrap(payload) {
		return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
	}

	function messageFrom(error) {
		if (error && error.message) return error.message + (error.correlationId ? ' (문의 코드: ' + error.correlationId + ')' : '');
		return '요청을 처리하지 못했습니다.';
	}

	function csvCell(value) {
		var text = String(value == null ? '' : value);
		if (/^\s*[=+@-]/.test(text)) text = "'" + text;
		return '"' + text.replace(/"/g, '""') + '"';
	}

	// D-010: platform confirmation sheet (OJ Platform 2.1+), native fallback before that.
	function confirmAction(options) {
		if (window.OJP && typeof window.OJP.confirm === 'function') return Promise.resolve(window.OJP.confirm(options));
		return Promise.resolve(window.confirm(options.message ? options.title + ' ' + options.message : options.title));
	}

	function badgeHtml(label, tone) {
		return '<span class="ojp-status-badge ojp-status-badge--' + (tone || 'neutral') + '">' + escapeHtml(label) + '</span>';
	}

	function statusTone(status) {
		if (['accepted', 'graded', 'released', 'open', 'published'].indexOf(status) !== -1) return 'success';
		if (['needs_resubmit', 'rejected', 'expired', 'upload_expired'].indexOf(status) !== -1) return 'danger';
		if (['quarantined', 'system_pending', 'uploading', 'inspecting', 'under_review', 'late_open', 'scheduled'].indexOf(status) !== -1) return 'warning';
		if (status === 'in_progress') return 'info';
		return 'neutral';
	}

	function emptyHtml(title, message) {
		return '<div class="ojp-empty-state"><div><strong>' + escapeHtml(title) + '</strong>'
			+ (message ? '<p>' + escapeHtml(message) + '</p>' : '') + '</div></div>';
	}

	var TASK_TYPE_LABELS = { classification: '분류', regression: '회귀', clustering: '군집화' };

	function randomKey() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return 'ojml-' + Date.now() + '-' + Math.random().toString(16).slice(2);
	}

	function createClient(root) {
		var restRoot = root.dataset.restRoot || '';
		var nonce = root.dataset.restNonce || '';

		async function request(path, options) {
			var requestOptions = Object.assign({ credentials: 'same-origin' }, options || {});
			requestOptions.headers = Object.assign({ Accept: 'application/json' }, requestOptions.headers || {});
			if (nonce) requestOptions.headers['X-WP-Nonce'] = nonce;
			if (requestOptions.body && !(requestOptions.body instanceof FormData) && typeof requestOptions.body !== 'string') {
				requestOptions.headers['Content-Type'] = 'application/json';
				requestOptions.body = JSON.stringify(requestOptions.body);
			}

			var response = await window.fetch(joinUrl(restRoot, path), requestOptions);
			var payload = null;
			try {
				payload = await response.json();
			} catch (ignored) {
				payload = null;
			}
			if (!response.ok) {
				var error = new Error(payload && payload.message ? payload.message : '서버 요청이 실패했습니다.');
				error.status = response.status;
				error.correlationId = payload && payload.data && payload.data.correlationId || '';
				error.code = payload && payload.code ? payload.code : 'oj_ml_request_failed';
				throw error;
			}
			return unwrap(payload);
		}

		return {
			get: function (path) { return request(path); },
			post: function (path, body) { return request(path, { method: 'POST', body: body || {} }); },
			patch: function (path, body) { return request(path, { method: 'PATCH', body: body || {} }); },
			remove: function (path, body) { return request(path, { method: 'DELETE', body: body || {} }); },
			upload: function (path, formData, onProgress) {
				return new Promise(function (resolve, reject) {
					var xhr = new XMLHttpRequest();
					xhr.open('POST', joinUrl(restRoot, path), true);
					xhr.withCredentials = true;
					xhr.timeout = 120000;
					xhr.setRequestHeader('Accept', 'application/json');
					if (nonce) xhr.setRequestHeader('X-WP-Nonce', nonce);
					xhr.upload.addEventListener('progress', function (event) {
						if (event.lengthComputable && typeof onProgress === 'function') {
							onProgress(Math.round((event.loaded / event.total) * 100));
						}
					});
					xhr.addEventListener('load', function () {
						var payload = null;
						try { payload = JSON.parse(xhr.responseText); } catch (ignored) { payload = null; }
						if (xhr.status >= 200 && xhr.status < 300) {
							resolve(unwrap(payload));
							return;
						}
						reject(new Error(payload && payload.message ? payload.message : '제출 파일 업로드에 실패했습니다.'));
					});
					xhr.addEventListener('error', function () { reject(new Error('네트워크 연결을 확인해 주세요.')); });
					xhr.addEventListener('timeout', function () { reject(new Error('응답 시간이 초과되었습니다. 접수되었을 수 있으므로 제출 결과를 확인한 뒤 같은 시도로 다시 시도하세요.')); });
				xhr.send(formData);
			});
			}
		};
	}

	function publicPcTerminated(root) {
		return root.dataset.publicPcMode === '1' && root.dataset.publicPcTerminal === '1';
	}

	function setStatus(root, text, kind) {
		if (publicPcTerminated(root)) return;
		var status = root.querySelector('[data-oj-ml-status]');
		if (!status) {
			status = document.createElement('p');
			status.className = 'oj-ml-status';
			status.setAttribute('data-oj-ml-status', '');
			status.setAttribute('role', 'status');
			root.insertBefore(status, root.firstChild);
		}
		status.textContent = text;
		status.dataset.kind = kind || 'info';
	}

	function contentNode(root) {
		var node = root.querySelector('[data-oj-ml-content]');
		if (!node) {
			node = document.createElement('div');
			node.setAttribute('data-oj-ml-content', '');
			root.appendChild(node);
		}
		return node;
	}

	function createRefreshGuard(setBusy) {
		var generation = 0;
		var pending = 0;
		return {
			begin: function () {
				generation += 1;
				pending += 1;
				setBusy(true);
				return generation;
			},
			isCurrent: function (ticket) {
				return ticket === generation;
			},
			isBusy: function () {
				return pending > 0;
			},
			finish: function () {
				pending = Math.max(0, pending - 1);
				setBusy(pending > 0);
			}
		};
	}

	function studentAssignmentUrl(root, id) {
		var template = root.dataset.labUrl || '';
		if (template) return template.replace('{assignment_id}', encodeURIComponent(id));
		return joinUrl(root.dataset.homeUrl || window.location.origin, 'oj-addons/oj-ml-playgrounds/lab/' + encodeURIComponent(id));
	}

	function studentResultUrl(root, id) {
		var template = root.dataset.resultUrl || '';
		if (template) return template.replace('{attempt_id}', encodeURIComponent(id));
		return joinUrl(root.dataset.homeUrl || window.location.origin, 'oj-addons/oj-ml-playgrounds/result/' + encodeURIComponent(id));
	}

	function assignmentItems(data) {
		if (Array.isArray(data)) return data;
		return data && Array.isArray(data.items) ? data.items : [];
	}

	function assignmentStatusLabel(item) {
		var status = learnerAssignmentState(item);
		var labels = {
			draft: '초안',
			published: '게시됨',
			scheduled: '시작 예정',
			open: '진행 가능',
			late_open: '지각 제출 가능',
			expired: '마감됨',
			closed: '종료됨',
			archived: '보관됨'
		};
		return labels[status] || status || '배정됨';
	}

	function learnerAssignmentState(item) {
		item = item && typeof item === 'object' ? item : {};
		var stored = String(item.status || '').toLowerCase();
		if (stored === 'archived') return 'archived';
		var derived = String(item.studentStatus || '').toLowerCase();
		if (['scheduled', 'open', 'late_open', 'expired', 'closed', 'archived'].indexOf(derived) !== -1) {
			return derived;
		}
		if (stored === 'closed') return stored;
		if (stored !== 'published') return stored;
		var now = Date.now();
		var opensAt = item.opensAt ? new Date(item.opensAt).getTime() : NaN;
		var dueAt = item.dueAt ? new Date(item.dueAt).getTime() : NaN;
		if (Number.isFinite(opensAt) && opensAt > now) return 'scheduled';
		if (Number.isFinite(dueAt) && dueAt < now) {
			return (item.submissionPolicy || item.policy || {}).allowLateSubmission === true ? 'late_open' : 'expired';
		}
		return 'open';
	}

	function hasSubmittedAttempt(item) {
		var attempt = item && item.latestAttempt;
		return !!(attempt && typeof attempt === 'object' && attempt.id && attempt.receivedAt);
	}

	function learnerAssignmentCardHtml(root, item, historyOnly) {
		var id = item.id || item.publicId;
		var attempt = hasSubmittedAttempt(item) ? item.latestAttempt : null;
		var actions = '';
		if (!historyOnly) {
			actions += '<a class="ojp-button ojp-button--small ojp-button--tinted" href="' + escapeHtml(studentAssignmentUrl(root, id)) + '">실습 열기</a>';
		}
		if (attempt) {
			actions += '<a class="ojp-button ojp-button--small" href="'
				+ escapeHtml(studentResultUrl(root, attempt.id)) + '">제출 결과 보기</a>';
		}
		return '<article class="oj-ml-card' + (historyOnly ? ' oj-ml-card--history' : '') + '" data-student-status="'
			+ escapeHtml(learnerAssignmentState(item)) + '"><h2>' + escapeHtml(item.title) + '</h2>'
			+ '<p>' + escapeHtml(item.description || '') + '</p>'
			+ '<p class="oj-ml-meta">마감: ' + escapeHtml(formatDate(item.dueAt || item.due_at)) + '</p>'
			+ '<p><span class="ojp-status-badge ojp-status-badge--' + statusTone(learnerAssignmentState(item)) + '">' + escapeHtml(assignmentStatusLabel(item)) + '</span></p>'
			+ '<div class="oj-ml-actions">' + actions + '</div></article>';
	}

	function pageNoticeHtml(data, shown, noun) {
		var total = data && Number.isFinite(Number(data.total)) ? Number(data.total) : shown;
		if (total <= shown) return '';
		return '<p class="oj-ml-meta" role="note">전체 ' + escapeHtml(total) + '개 중 첫 '
			+ escapeHtml(shown) + '개 ' + escapeHtml(noun) + '만 표시합니다.</p>';
	}

	function resultPaginationHtml(total, shown, page, perPage) {
		var maxPage = Math.max(1, Math.ceil(total / perPage));
		var first = shown ? ((page - 1) * perPage) + 1 : 0;
		var last = shown ? first + shown - 1 : 0;
		return '<nav class="oj-ml-actions" aria-label="학생 결과 페이지">'
			+ '<button type="button" data-results-page="previous"' + (page <= 1 ? ' disabled' : '') + '>이전</button>'
			+ '<span class="oj-ml-meta">전체 ' + escapeHtml(total) + '명 · ' + escapeHtml(first) + '–'
			+ escapeHtml(last) + '명 · ' + escapeHtml(page) + '/' + escapeHtml(maxPage) + ' 페이지</span>'
			+ '<button type="button" data-results-page="next"' + (page >= maxPage ? ' disabled' : '') + '>다음</button>'
			+ '</nav>';
	}

	function assignmentPaginationHtml(total, shown, page, perPage) {
		var maxPage = Math.max(1, Math.ceil(total / perPage));
		if (maxPage <= 1) return '';
		var first = shown ? ((page - 1) * perPage) + 1 : 0;
		var last = shown ? first + shown - 1 : 0;
		return '<nav class="oj-ml-actions" aria-label="과제 목록 페이지">'
			+ '<button type="button" data-assignment-page="previous"' + (page <= 1 ? ' disabled' : '') + '>이전</button>'
			+ '<span class="oj-ml-meta">전체 ' + escapeHtml(total) + '개 · ' + escapeHtml(first) + '–'
			+ escapeHtml(last) + '개 · ' + escapeHtml(page) + '/' + escapeHtml(maxPage) + ' 페이지</span>'
			+ '<button type="button" data-assignment-page="next"' + (page >= maxPage ? ' disabled' : '') + '>다음</button>'
			+ '</nav>';
	}

	async function loadAllReportAssignments(api) {
		var data = await api.get('console/report-assignment-options');
		var items = assignmentItems(data);
		var total = data && Number.isFinite(Number(data.total))
			? Math.max(0, Number(data.total))
			: items.length;
		return { items: items, total: total, limited: false };
	}
	async function loadAllTeachingContexts(api) {
		var contexts = [];
		var seenCursors = Object.create(null);
		var seenContexts = Object.create(null);
		var cursor = '';
		for (var page = 0; page < 1000; page += 1) {
			var path = 'console/contexts?limit=100';
			if (cursor) path += '&cursor=' + encodeURIComponent(cursor);
			var data = await api.get(path);
			assignmentItems(data).forEach(function (context) {
				var identity = String(context.type || '') + '\u0000' + String(context.id || '');
				if (!seenContexts[identity]) {
					seenContexts[identity] = true;
					contexts.push(context);
				}
			});
			var nextCursor = data && typeof data.nextCursor === 'string' ? data.nextCursor : '';
			if (!nextCursor) return contexts;
			if (seenCursors[nextCursor]) {
				throw new Error('수업·그룹 목록이 반복되었습니다. 새로고침 후 다시 시도해 주세요.');
			}
			seenCursors[nextCursor] = true;
			cursor = nextCursor;
		}
		throw new Error('수업·그룹 수가 이 화면에서 표시할 수 있는 한도를 넘었습니다.');
	}

	function resultLinkHtml(root, attemptId) {
		if (!attemptId) return '';
		return '<a class="ojp-button" href="'
			+ escapeHtml(studentResultUrl(root, attemptId)) + '">제출 결과 보기</a>';
	}

	function numericOrNull(value) {
		if (value == null || String(value).trim() === '') return null;
		var number = Number(value);
		return Number.isFinite(number) ? number : NaN;
	}

	async function renderStudentAssignments(root, api, page) {
		page = Math.max(1, Number(page) || 1);
		setStatus(root, '배정된 실습을 불러오는 중입니다.');
		var data = await api.get('assignments?page=' + page);
		if (publicPcTerminated(root)) return;
		var items = assignmentItems(data);
		var content = contentNode(root);
		var currentStates = ['scheduled', 'open', 'late_open'];
		var historyStates = ['expired', 'closed'];
		var current = items.filter(function (item) { return currentStates.indexOf(learnerAssignmentState(item)) !== -1; });
		var history = items.filter(function (item) {
			return historyStates.indexOf(learnerAssignmentState(item)) !== -1 && hasSubmittedAttempt(item);
		});
		if (!current.length && !history.length && page === 1 && !data.hasMore) {
			content.innerHTML = emptyHtml('배정된 ML 실습이 없습니다', '선생님이 실습을 게시하면 여기에 표시됩니다.');
			setStatus(root, '배정된 실습이 없습니다.');
			return;
		}
		var currentHtml = '<section class="oj-ml-assignment-section"><h2>현재 실습</h2>'
			+ (current.length ? '<div class="oj-ml-grid">' + current.map(function (item) {
				return learnerAssignmentCardHtml(root, item, false);
			}).join('') + '</div>' : emptyHtml('지금 진행할 수 있는 실습이 없습니다', '')) + '</section>';
		var historyHtml = history.length
			? '<details class="oj-ml-assignment-history"><summary>지난 실습·제출 결과 (' + escapeHtml(history.length)
				+ ')</summary><div class="oj-ml-grid">' + history.map(function (item) {
					return learnerAssignmentCardHtml(root, item, true);
				}).join('') + '</div></details>'
			: '';
		content.innerHTML = currentHtml + historyHtml + '<nav class="oj-ml-pagination" aria-label="실습 목록 페이지">'
			+ '<button type="button" data-student-page="prev"' + (page === 1 ? ' disabled' : '') + '>이전</button>'
			+ '<span>' + page + '쪽</span><button type="button" data-student-page="next"' + (!data.hasMore ? ' disabled' : '') + '>다음</button></nav>';
		content.querySelectorAll('[data-student-page]').forEach(function (button) {
			button.addEventListener('click', async function () {
				content.querySelectorAll('[data-student-page]').forEach(function (b) { b.disabled = true; });
				try { await renderStudentAssignments(root, api, page + (button.dataset.studentPage === 'next' ? 1 : -1)); }
				catch (error) { setStatus(root, messageFrom(error), 'error'); button.disabled = false; }
			});
		});
		setStatus(root, '현재 실습 ' + current.length + '개와 지난 제출 ' + history.length + '개를 불러왔습니다.', 'success');
	}

	function receiptHtml(data) {
		var receipt = data && data.receipt ? data.receipt : data;
		if (!receipt) return '';
		return '<div class="oj-ml-receipt" role="status"><strong>서버 접수 완료</strong>'
			+ '<p>시도: ' + escapeHtml(receipt.attemptNo || '-') + '</p>'
			+ '<p>접수 시각: ' + escapeHtml(formatDate(receipt.receivedAt)) + '</p>'
			+ '<p>크기: ' + escapeHtml(receipt.bytes || '-') + ' bytes</p>'
			+ '<p>SHA-256: <code>' + escapeHtml(receipt.sha256 || '-') + '</code></p></div>';
	}

	function labAttemptCountsText(assignment) {
		return '최대 ' + (assignment.maxAttempts || 1) + '회 제출 (사용 ' + (assignment.attemptsUsed || 0) + '회)';
	}

	function studentDatasetDescriptionHtml(policy) {
		var description = policy && typeof policy.datasetDescription === 'string' ? policy.datasetDescription : '';
		return description ? '<details class="oj-ml-preview-nested" data-student-dataset-description><summary>데이터 설명</summary><p class="oj-ml-preview-answer">'
			+ escapeHtml(description) + '</p></details>' : '';
	}

	async function renderLab(root, api) {
		var assignmentId = root.dataset.assignmentId;
		if (!assignmentId) throw new Error('과제 식별자가 없습니다.');
		var detail = await api.get('assignments/' + encodeURIComponent(assignmentId));
		if (publicPcTerminated(root)) return;
		var assignment = detail.assignment || detail;
		var attempt = assignment.activeAttempt || null;
		var latestAttempt = assignment.latestAttempt || attempt;
		var submissionPolicy = assignment.submissionPolicy || {};
		var maxBytes = Number(submissionPolicy.maxArtifactBytes);
		if (!Number.isSafeInteger(maxBytes) || maxBytes < 1) maxBytes = 104857600;
		root.dataset.maxUploadBytes = String(maxBytes);
		var opensAt = assignment.opensAt ? new Date(assignment.opensAt).getTime() : null;
		var dueAt = assignment.dueAt ? new Date(assignment.dueAt).getTime() : null;
		var now = Date.now();
		var blockedReason = '';
		if (assignment.status === 'closed' || assignment.status === 'archived') {
			blockedReason = '이 과제는 종료되어 새 실습을 시작할 수 없습니다.';
		} else if (Number.isFinite(opensAt) && opensAt > now) {
			blockedReason = formatDate(assignment.opensAt) + '부터 실습을 시작할 수 있습니다.';
		} else if (Number.isFinite(dueAt) && dueAt < now && submissionPolicy.allowLateSubmission !== true) {
			blockedReason = '제출 기한이 지나 새 실습을 시작하거나 제출할 수 없습니다.';
		} else if (!attempt && Number(assignment.attemptsUsed || 0) >= Number(assignment.maxAttempts || 1)) {
			blockedReason = '사용할 수 있는 제출 시도를 모두 사용했습니다.';
		}
		var content = contentNode(root);
		var playgroundUrl = root.dataset.playgroundUrl || '';
		content.innerHTML = '<section class="oj-ml-panel">'
			+ '<h2>' + escapeHtml(assignment.title || 'ML 실습') + '</h2>'
			+ '<p>' + escapeHtml(assignment.description || '') + '</p>'
			+ studentDatasetDescriptionHtml(submissionPolicy)
			+ '<p class="oj-ml-meta">시작: ' + escapeHtml(formatDate(assignment.opensAt)) + ' · 마감: '
			+ escapeHtml(formatDate(assignment.dueAt)) + ' · <span data-oj-ml-attempt-counts>'
			+ escapeHtml(labAttemptCountsText(assignment)) + '</span></p>'
			+ (blockedReason ? '<p role="note">' + escapeHtml(blockedReason) + '</p>' : '')
			+ '<div class="oj-ml-actions"><button type="button" data-variant="primary" data-oj-ml-start'
			+ (blockedReason ? ' disabled' : '') + '>' + (attempt ? '실습 이어하기' : '실습 시작') + '</button>'
			+ '<span data-oj-ml-result-link>' + resultLinkHtml(root, latestAttempt && latestAttempt.id) + '</span></div>'
			+ '</section>'
			+ '<section class="oj-ml-panel" data-oj-ml-workspace hidden><h2>실습 작업 공간</h2><div data-oj-ml-frame></div></section>'
			+ '<section class="oj-ml-panel" data-oj-ml-submit hidden><h2>결과 제출</h2>'
			+ '<p><strong>다운로드 없이 바로 제출할 수 있습니다.</strong> 작업 공간 오른쪽 위의 <strong>OJ에 제출하기</strong>를 누르면 현재 열린 프로젝트가 즉시 제출됩니다.</p>'
			+ '<p class="oj-ml-meta">버튼을 누른 시점의 프로젝트가 제출됩니다. 이후 수정한 내용은 다시 제출하기 전까지 포함되지 않습니다.</p>'
			+ '<p class="oj-ml-meta">최대 파일 크기: ' + escapeHtml(maxBytes) + ' bytes</p>'
			+ '<div data-oj-ml-receipt></div><details class="oj-ml-submit-fallback"><summary>바로 제출이 안 될 때 파일로 제출</summary>'
			+ '<form class="oj-ml-form" data-oj-ml-upload-form><div class="oj-ml-field"><label for="oj-ml-artifact">저장한 MLPX 프로젝트 파일</label><input id="oj-ml-artifact" name="artifact" type="file" accept=".mlpx,application/zip" required></div>'
			+ '<div class="oj-ml-actions"><button type="submit">선택한 파일 제출</button></div></form></details></section>';

		if (window.OJMLTeacherWorkflow) window.OJMLTeacherWorkflow.attachStudentTemplate(content, assignment);
		if (window.OJMLAssignmentPolicy) window.OJMLAssignmentPolicy.student(content, root, assignment);
		var startButton = content.querySelector('[data-oj-ml-start]');
		var workspace = content.querySelector('[data-oj-ml-workspace]');
		var submitPanel = content.querySelector('[data-oj-ml-submit]');
		var frameHolder = content.querySelector('[data-oj-ml-frame]');
		var uploadForm = content.querySelector('[data-oj-ml-upload-form]');
		var receiptHolder = content.querySelector('[data-oj-ml-receipt]');
		var resultLinkHolder = content.querySelector('[data-oj-ml-result-link]');
		var attemptCounts = content.querySelector('[data-oj-ml-attempt-counts]');
		var attemptCountsGeneration = 0;
		var iframe = null;

		async function refreshAttemptCounts() {
			if (publicPcTerminated(root) || !attemptCounts) return;
			var generation = ++attemptCountsGeneration;
			attemptCounts.textContent = '제출 횟수를 확인하는 중입니다.';
			try {
				var refreshed = await api.get('assignments/' + encodeURIComponent(assignmentId));
				if (publicPcTerminated(root) || generation !== attemptCountsGeneration || attemptCounts.isConnected === false) return;
				var latest = refreshed && (refreshed.assignment || refreshed);
				if (!latest || !Number.isSafeInteger(latest.attemptsUsed) || latest.attemptsUsed < 0
					|| !Number.isSafeInteger(latest.maxAttempts) || latest.maxAttempts < 1) {
					throw new Error('Invalid assignment attempt counts');
				}
				// Receipts may be repeated and attempt numbers need not equal the consumed allowance.
				// Refresh only the authoritative counts, keeping the live workspace and receipt intact.
				attemptCounts.textContent = labAttemptCountsText(latest);
			} catch (ignored) {
				if (publicPcTerminated(root) || generation !== attemptCountsGeneration || attemptCounts.isConnected === false) return;
				attemptCounts.textContent = '제출 횟수를 불러오지 못했습니다. 제출 결과에서 확인해 주세요.';
			}
		}

		function attemptId() {
			return attempt && (attempt.id || attempt.publicId);
		}

		function showResultLink() {
			if (resultLinkHolder) resultLinkHolder.innerHTML = resultLinkHtml(root, attemptId());
		}

		function configureIframe() {
			if (publicPcTerminated(root) || !iframe || !iframe.contentWindow || !attempt) return;
			iframe.contentWindow.postMessage({
				type: 'oj-ml:configure',
				version: 1,
				assignmentId: String(assignmentId),
				attemptId: String(attemptId()),
				assignmentPolicy: {
					version: 1,
					allowedTaskTypes: submissionPolicy.allowedTaskTypes,
					allowedAlgorithms: submissionPolicy.allowedAlgorithms
				},
				restRoot: root.dataset.restRoot,
				nonce: root.dataset.restNonce,
				maxBytes: maxBytes
			}, window.location.origin);
		}

		function handleSubmissionStatus(data) {
			if (publicPcTerminated(root)) return;
			if (!data || typeof data !== 'object') return;
			var uploadButton = uploadForm.querySelector('button[type="submit"]');
			if (data.state === 'submitting') {
				uploadButton.disabled = true;
				setStatus(root, 'MLPX 프로젝트를 서버에 제출하는 중입니다.');
			} else if (data.state === 'submitted') {
				if (data.attempt && typeof data.attempt === 'object') attempt = data.attempt;
				if (data.receipt) receiptHolder.innerHTML = receiptHtml({ receipt: data.receipt });
				showResultLink();
				refreshAttemptCounts();
				uploadButton.disabled = true;
				uploadButton.textContent = '제출 완료';
				setStatus(root, '제출이 서버에 안전하게 접수되었습니다. 결과 화면에서 처리 상태를 확인하세요.', 'success');
			} else if (data.state === 'error') {
				uploadButton.disabled = false;
				var errorMessage = data.message === 'OJ_SUBMISSION_TOO_LARGE'
					? '파일이 과제의 제출 허용 크기를 초과했습니다.'
					: '바로 제출에 실패했습니다. 다시 시도하거나 아래의 파일 제출 대체 수단을 이용해 주세요.';
				setStatus(root, errorMessage, 'error');
			}
		}

		root.addEventListener('oj-ml:trusted-submission-status', function (event) {
			handleSubmissionStatus(event && event.detail);
		});

		window.addEventListener('message', function (event) {
			if (publicPcTerminated(root)) return;
			if (!iframe || event.origin !== window.location.origin || event.source !== iframe.contentWindow || !event.data) return;
			if (event.data.version !== 1) return;
			if (event.data.type === 'oj-ml:ready') configureIframe();
			else if (event.data.type === 'oj-ml:submission-status') handleSubmissionStatus(event.data);
		});

		startButton.addEventListener('click', async function () {
			if (publicPcTerminated(root)) return;
			if (blockedReason) {
				setStatus(root, blockedReason, 'error');
				return;
			}
			startButton.disabled = true;
			try {
				if (!attempt) {
					var created = await api.post('assignments/' + encodeURIComponent(assignmentId) + '/attempts', {
						idempotencyKey: randomKey(),
						clientAppVersion: root.dataset.playgroundVersion || '',
						formatVersion: 2
					});
					attempt = created.attempt || created;
					refreshAttemptCounts();
				}
				if (publicPcTerminated(root)) return;
				workspace.hidden = false;
				submitPanel.hidden = false;
				if (!iframe) {
					iframe = document.createElement('iframe');
					iframe.className = 'oj-ml-playground-frame';
					iframe.title = 'ML 실습 작업 공간';
					iframe.src = playgroundUrl;
					iframe.setAttribute('allow', 'webgpu; fullscreen');
					frameHolder.appendChild(iframe);
					iframe.addEventListener('load', configureIframe);
				}
				setStatus(root, '실습은 이 컴퓨터에서 실행됩니다. 제출 버튼을 누르기 전까지 프로젝트는 서버에 저장되지 않습니다.', 'success');
				startButton.textContent = '실습 열림';
				if (window.OJMLDisplay) window.OJMLDisplay.labStarted(root);
			} catch (error) {
				setStatus(root, messageFrom(error), 'error');
			} finally {
				startButton.disabled = false;
			}
		});

		uploadForm.addEventListener('submit', async function (event) {
			event.preventDefault();
			if (publicPcTerminated(root)) return;
			if (!attempt) return;
			var button = uploadForm.querySelector('button[type="submit"]');
			button.disabled = true;
			if (root.dataset.publicPcMode === '1') {
				var ready = false;
				try {
					ready = typeof window.OjMlPublicPcReady === 'function'
						&& await window.OjMlPublicPcReady(root) === true;
				} catch (ignored) {}
				if (
					!ready || publicPcTerminated(root)
					|| root.dataset.publicPcCovered === '1'
					|| root.dataset.publicPcLocked === '1'
				) {
					if (!publicPcTerminated(root)) {
						button.disabled = false;
						setStatus(root, '현재 OJ 계정 확인이 끝난 뒤 다시 제출해 주세요.', 'error');
					}
					return;
				}
			}
			var input = uploadForm.querySelector('input[type="file"]');
			var file = input && input.files ? input.files[0] : null;
			if (!file) {
				button.disabled = false;
				return;
			}
			if (file.size > maxBytes) {
				setStatus(root, '파일이 제출 허용 크기를 초과했습니다.', 'error');
				button.disabled = false;
				return;
			}
			try {
				var formData = new FormData();
				formData.append('artifact', file, file.name);
				var result = await api.upload('attempts/' + encodeURIComponent(attempt.id || attempt.publicId) + '/submit', formData, function (percent) {
					setStatus(root, '제출 파일을 업로드하는 중입니다. ' + percent + '%');
				});
				if (publicPcTerminated(root)) return;
				receiptHolder.innerHTML = receiptHtml(result);
				if (result && result.attempt) attempt = result.attempt;
				showResultLink();
				refreshAttemptCounts();
				setStatus(root, '제출이 서버에 안전하게 접수되었습니다. 결과 화면에서 처리 상태를 확인하세요.', 'success');
				button.textContent = '제출 완료';
			} catch (error) {
				setStatus(root, messageFrom(error), 'error');
				button.disabled = false;
			}
		});

		if (blockedReason) {
			setStatus(root, blockedReason, 'error');
		} else {
			setStatus(root, attempt ? '진행 중인 실습을 이어갈 수 있습니다.' : '실습 시작을 누르면 학생별 시도가 생성됩니다.');
		}
	}

	function statusLabel(status) {
		var labels = { in_progress: '실습 중', uploading: '업로드 중', quarantined: '검사 대기', inspecting: '검사 중',
			accepted: '접수 완료', needs_resubmit: '재제출 필요', rejected: '검사 미통과', system_pending: '서버 확인 대기',
			under_review: '평가 중', graded: '채점 완료', cancelled: '취소됨', upload_expired: '시도 만료',
			draft: '초안', released: '공개됨', not_submitted: '미제출' };
		return labels[status] || status || '-';
	}

	async function renderStudentResult(root, api) {
		var attemptId = root.dataset.attemptId;
		if (!attemptId) throw new Error('제출 식별자가 없습니다.');
		var data = await api.get('attempts/' + encodeURIComponent(attemptId));
		if (publicPcTerminated(root)) return;
		var attempt = data.attempt || data;
		var review = attempt.review || null;
		var content = contentNode(root);
		content.innerHTML = '<section class="oj-ml-panel"><h2>' + escapeHtml(attempt.attemptNo) + '차 제출</h2>'
			+ '<p>' + badgeHtml(statusLabel(attempt.status), statusTone(attempt.status)) + '</p>'
			+ '<p>제출 시각: ' + escapeHtml(formatDate(attempt.receivedAt)) + '</p>'
			+ findingsHtml(attempt.evaluation)
			+ (['quarantined', 'system_pending'].indexOf(attempt.status) !== -1 ? '<p>제출 파일은 서버에 접수되었습니다. 서버 검사가 대기 중이므로 다시 제출할 필요가 없습니다.</p>' : '')
			+ '<div data-resubmit-info></div>'
			+ (review ? '<h3>공개된 평가</h3><p>점수: ' + escapeHtml(review.score) + ' / ' + escapeHtml(review.maxScore) + '</p><p>' + escapeHtml(review.feedback || '') + '</p>' : '<p>교사가 평가를 공개하면 점수와 피드백이 표시됩니다.</p>')
			+ (window.OJMLRubric && review ? window.OJMLRubric.releasedHtml(review) : '')
			+ '<h3>내 제출 이력</h3><div data-own-history></div></section>';
		var historyPage = 1;
		async function history() {
			var page = await api.get('assignments/' + encodeURIComponent(attempt.assignmentId) + '/history?page=' + historyPage);
			if (publicPcTerminated(root)) return;
			var holder = content.querySelector('[data-own-history]');
			holder.innerHTML = '<ul>' + page.items.map(function (item) {
				return '<li><a href="' + escapeHtml(studentResultUrl(root, item.id)) + '">' + escapeHtml(item.attemptNo) + '차 · '
					+ escapeHtml(statusLabel(item.status)) + ' · ' + escapeHtml(formatDate(item.receivedAt || item.createdAt)) + '</a></li>';
			}).join('') + '</ul><div class="oj-ml-actions"><button type="button" data-history-step="-1"' + (historyPage <= 1 ? ' disabled' : '') + '>이전</button>'
				+ '<span>' + historyPage + ' 페이지 · ' + escapeHtml(page.total) + '회</span><button type="button" data-history-step="1"'
				+ (historyPage * page.perPage >= page.total ? ' disabled' : '') + '>다음</button></div>';
			holder.querySelectorAll('[data-history-step]').forEach(function (button) {
				button.addEventListener('click', async function () {
					var previousPage = historyPage;
					var controls = Array.from(holder.querySelectorAll('button')).map(function (control) { return [control, control.disabled]; });
					controls.forEach(function (entry) { entry[0].disabled = true; });
					historyPage += Number(button.dataset.historyStep);
					try { await history(); } catch (error) {
						historyPage = previousPage;
						controls.forEach(function (entry) { entry[0].disabled = entry[1]; });
						setStatus(root, messageFrom(error), 'error');
					}
				});
			});
		}
		await history();
		try {
			var detail = await api.get('assignments/' + encodeURIComponent(attempt.assignmentId));
			if (publicPcTerminated(root)) return;
			var assignment = detail.assignment;
			var remaining = Math.max(0, assignment.maxAttempts - assignment.attemptsUsed);
			var open = ['open', 'late_open'].indexOf(assignment.studentStatus) !== -1;
			content.querySelector('[data-resubmit-info]').innerHTML = '<p>남은 새 시도: ' + escapeHtml(remaining) + '회 · 마감: '
				+ escapeHtml(formatDate(assignment.dueAt)) + '</p>'
				+ (open && (remaining > 0 || assignment.activeAttempt) ? '<a class="ojp-button ojp-button--primary" href="' + escapeHtml(studentAssignmentUrl(root, assignment.id)) + '">실습 열고 다시 제출</a>'
					: '<p>추가 제출이 필요하면 교사에게 시도 추가 또는 마감 연장을 요청하세요.</p>');
		} catch (error) {
			if (publicPcTerminated(root)) return;
			content.querySelector('[data-resubmit-info]').textContent = '기존 제출 기록입니다. 현재 과제 접근 권한 또는 운영 상태를 확인할 수 없어 추가 제출 안내를 불러오지 못했습니다.';
		}
		setStatus(root, '제출 결과와 이력을 불러왔습니다.', 'success');
	}

	function dateTimeLocalValue(value) {
		if (!value) return '';
		var date = new Date(value);
		if (!Number.isFinite(date.getTime())) return '';
		return new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
	}

	function consoleAssignmentActionsHtml(root, item) {
		var id = item.id || item.publicId;
		var revision = item.revision || 1;
		var status = String(item.status || '');
		var edit = '<button type="button" data-edit-id="' + escapeHtml(id) + '">편집</button>';
		var publish = '<button type="button" data-variant="tinted" data-publish-id="' + escapeHtml(id) + '" data-revision="'
			+ escapeHtml(revision) + '">게시</button>';
		var remove = '<button type="button" data-variant="danger" data-delete-id="' + escapeHtml(id)
			+ '" data-revision="' + escapeHtml(revision) + '">삭제</button>';
		var archive = '<button type="button" data-archive-id="' + escapeHtml(id)
			+ '" data-revision="' + escapeHtml(revision) + '">보관(학생 숨김)</button>';
		var results = '<a class="ojp-button ojp-button--small ojp-button--plain" href="'
			+ escapeHtml((root.dataset.consoleResultsUrl || '').replace('{assignment_id}', encodeURIComponent(id))) + '">결과</a>';
		var duplicate = '<button type="button" data-duplicate-id="' + escapeHtml(id) + '">복제</button>';
		function transition(target, label) {
			return '<button type="button" data-transition-id="' + escapeHtml(id)
				+ '" data-transition-status="' + target + '" data-revision="' + escapeHtml(revision) + '">' + label + '</button>';
		}
		if (status === 'draft') return edit + publish + duplicate + remove;
		if (status === 'published') return edit + transition('closed', '지금 종료') + archive + duplicate + results;
		if (status === 'closed') return transition('published', '다시 열기') + archive + duplicate + results;
		if (status === 'archived') return transition('closed', '보관 취소') + duplicate + results;
		return results;
	}

	async function renderConsoleAssignments(root, api) {
		var content = contentNode(root);
		var listId = 'oj-ml-assignments-' + Math.random().toString(36).slice(2, 10);
		content.innerHTML = '<section class="oj-ml-panel oj-ml-authoring-panel"><div class="oj-ml-panel-heading"><h2 data-oj-ml-assignment-form-title>새 ML 실습 과제</h2><span class="oj-ml-meta">초안 → 게시</span></div><form class="oj-ml-form" data-oj-ml-assignment-form>'
			+ '<div class="oj-ml-field"><label>대상 그룹<select name="context" required><option value="">불러오는 중입니다.</option></select></label></div>'
			+ '<div class="oj-ml-field"><label>과제 제목<input name="title" required maxlength="200"></label></div>'
			+ '<div class="oj-ml-field"><label>설명<textarea name="description"></textarea></label></div>'
			+ '<div class="oj-ml-form__row"><div class="oj-ml-field"><label>과제 유형<select name="taskType" required><option value="classification">분류</option><option value="regression">회귀</option><option value="clustering">군집화</option></select></label></div><div class="oj-ml-field"><label>최대 제출 횟수<input name="maxAttempts" type="number" min="1" max="100" value="3" required></label></div></div>'
			+ '<div class="oj-ml-form__row"><div class="oj-ml-field"><label>시작 시각<input name="opensAt" type="datetime-local"></label></div><div class="oj-ml-field"><label>마감 시각<input name="dueAt" type="datetime-local"></label></div></div>'
			+ '<div class="oj-ml-form__row"><div class="oj-ml-field"><label>최대 데이터 행 수<input name="maxRows" type="number" min="1" max="200000" value="20000" required></label></div><div class="oj-ml-field"><label>최대 선택 특성 수<input name="maxFeatures" type="number" min="1" max="2000" value="100" required></label></div></div>'
			+ '<div class="oj-ml-field"><label><input name="allowLateSubmission" type="checkbox"> 마감 후 제출 허용</label></div><div class="oj-ml-field"><label>최대 파일 크기(MiB)<input name="maxArtifactMiB" type="number" min="1" max="250" value="25" required></label></div>'
			+ '<p class="oj-ml-meta" data-oj-ml-assignment-form-note>초안을 만든 뒤 게시하면 학생에게 보입니다.</p>'
			+ '<div class="oj-ml-actions oj-ml-form-footer"><button type="submit" data-variant="primary">초안 만들기</button><button type="button" data-oj-ml-edit-cancel hidden>편집 취소</button></div></form></section>'
			+ '<section class="oj-ml-panel"><div class="oj-ml-panel-heading"><h2>과제 목록</h2>'
			+ '<div class="oj-ml-assignment-tabs" role="tablist" aria-label="과제 목록 구분">'
			+ '<button type="button" role="tab" id="' + listId + '-unarchived" data-assignment-view="unarchived" aria-controls="' + listId + '" aria-selected="true">과제</button>'
			+ '<button type="button" role="tab" id="' + listId + '-archived" data-assignment-view="archived" aria-controls="' + listId + '" aria-selected="false" tabindex="-1">보관</button></div></div>'
			+ '<div data-oj-ml-assignment-list role="tabpanel" id="' + listId + '" aria-labelledby="' + listId + '-unarchived" tabindex="0"></div></section>';
		if (window.OJMLTeacherWorkflow) content.insertAdjacentHTML('afterbegin', window.OJMLTeacherWorkflow.navigation(root));
		var form = content.querySelector('[data-oj-ml-assignment-form]');
		var portfolioEditor = window.OJMLTeacherWorkflow ? window.OJMLTeacherWorkflow.templateEditor(form, api) : null;
		var policyEditor = window.OJMLAssignmentPolicy ? window.OJMLAssignmentPolicy.editor(form, api) : null;
		var rubricPanel = document.createElement('div');
		rubricPanel.setAttribute('data-oj-ml-rubric-panel', '');
		form.querySelector('[data-oj-ml-assignment-form-note]').before(rubricPanel);
		if (window.OJMLTeacherWorkflow) window.OJMLTeacherWorkflow.assignmentSections(form, rubricPanel);
		var datasetPanel = document.createElement('details');
		datasetPanel.className = 'oj-ml-form-section';
		datasetPanel.setAttribute('data-dataset-description', '');
		datasetPanel.innerHTML = '<summary><span class="oj-ml-section-heading">데이터 설명</span><span class="oj-ml-section-caption">평가 근거 · 선택</span></summary><div class="oj-ml-form-section__body">'
			+ '<label>교사 공통 설명<textarea name="datasetDescription" rows="4" maxlength="4000" placeholder="데이터 출처, 변수의 의미, 예측 목표와 수집 목적">' + '</textarea></label>'
			+ '<p class="oj-ml-meta">원본 행·개인정보는 넣지 마세요. 설명과 서버가 계산한 통계 요약을 평가 근거로 사용합니다.</p>'
			+ '<label>학생 설명 문항 ID<input name="datasetDescriptionSectionId" value="dataset_description" maxlength="64" pattern="[A-Za-z][A-Za-z0-9_\\-]{0,63}" required></label>'
			+ '<div class="oj-ml-actions"><button type="button" data-dataset-description-add>학생 설명 문항 추가</button></div>'
			+ '<p class="oj-ml-meta">기존 문항의 ID를 연결하거나 양식에 선택 문항을 추가하세요. 설명이 없으면 해당 평가 항목은 교사 확인이 필요합니다.</p><p data-dataset-description-note role="status"></p></div>';
		var basicSection = form.querySelector('[data-assignment-section="basic"]');
		if (basicSection) basicSection.after(datasetPanel); else rubricPanel.before(datasetPanel);
		datasetPanel.querySelector('[data-dataset-description-add]').disabled = !portfolioEditor;
		datasetPanel.querySelector('[data-dataset-description-add]').addEventListener('click', function () {
			if (!portfolioEditor || (editing && editing.status === 'published')) return;
			var note = datasetPanel.querySelector('[data-dataset-description-note]');
			var sectionId = field('datasetDescriptionSectionId').value.trim();
			var enabled = form.querySelector('[data-template-enabled]'), wasEnabled = enabled && enabled.checked;
			try {
				if (!/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(sectionId)) throw new Error('문항 ID는 영문자로 시작하는 영문·숫자·밑줄·하이픈 1~64자로 입력하세요.');
				var hasQuestions = form.querySelector('[data-template-questions]').children.length > 0;
				if (enabled) enabled.checked = true;
				var template = hasQuestions ? portfolioEditor.value() : { title: '탐구 포트폴리오', markdown: '' };
				var requirements = portfolioEditor.requirements();
				var questions = window.OJMLTeacherWorkflow.parse(template.markdown);
				if (questions.some(function (item) { return item.id === sectionId; })) {
					if (enabled) enabled.dispatchEvent(new Event('change', { bubbles: true }));
					note.textContent = '같은 ID의 문항이 이미 있습니다. 기존 문항을 평가에 연결합니다.'; return;
				}
				if (questions.length >= 30) throw new Error('양식은 최대 30문항입니다. 기존 문항 ID를 연결하거나 문항 수를 줄이세요.');
				var markdown = template.markdown + (template.markdown ? '\n\n' : '') + '## 데이터 설명 {#' + sectionId + '}\n\n데이터 출처, 주요 변수의 의미, 예측 목표와 수집 목적을 설명하세요. 원본 데이터 행·실명·연락처 등 개인정보는 쓰지 마세요.\n';
				if (new TextEncoder().encode(markdown).length > 32768) throw new Error('포트폴리오 양식은 32 KiB 이내로 줄여 주세요.');
				portfolioEditor.load({ title: template.title, markdown: markdown }, requirements);
				note.textContent = '선택 문항을 추가했습니다. 과제를 저장하면 적용됩니다.';
			} catch (error) {
				if (enabled) { enabled.checked = wasEnabled; enabled.dispatchEvent(new Event('change', { bubbles: true })); }
				note.textContent = error.message || '문항을 추가하지 못했습니다.';
			}
		});
		function loadRubric(value, locked, isNew) {
			if (!window.OJMLRubric) return;
			rubricPanel.innerHTML = window.OJMLRubric.editorHtml(value || [], { disabled: !!locked, isNew: !!isNew });
			window.OJMLRubric.bindEditor(rubricPanel);
		}
		var list = content.querySelector('[data-oj-ml-assignment-list]');
		var contextSelect = form.querySelector('select[name="context"]');
		var submitButton = form.querySelector('button[type="submit"]');
		var cancelButton = form.querySelector('[data-oj-ml-edit-cancel]');
		var formTitle = content.querySelector('[data-oj-ml-assignment-form-title]');
		var formNote = form.querySelector('[data-oj-ml-assignment-form-note]');
		var editing = null;
		var copiedPolicy = null;
		var listedItems = [];
		var assignmentView = 'unarchived';
		var assignmentPage = 1;
		var assignmentPerPage = 100;
		var assignmentMaxPage = 1;
		function setAssignmentNavigationBusy(busy) {
			list.setAttribute('aria-busy', String(busy));
			list.querySelectorAll('[data-assignment-page]').forEach(function (button) {
				var direction = button.dataset.assignmentPage;
				button.disabled = busy
					|| (direction === 'previous' && assignmentPage <= 1)
					|| (direction === 'next' && assignmentPage >= assignmentMaxPage);
			});
		}
		var assignmentRefreshGuard = createRefreshGuard(setAssignmentNavigationBusy);
		function selectAssignmentView(view) {
			assignmentView = view;
			content.querySelectorAll('[data-assignment-view]').forEach(function (tab) {
				var selected = tab.dataset.assignmentView === view;
				tab.setAttribute('aria-selected', String(selected));
				tab.tabIndex = selected ? 0 : -1;
			});
			list.setAttribute('aria-labelledby', listId + '-' + view);
		}
		function clearAssignmentList() {
			listedItems = [];
			list.innerHTML = '<p class="oj-ml-meta" role="status">과제 목록을 불러오는 중입니다.</p>';
		}
		content.querySelectorAll('[data-assignment-view]').forEach(function (tab) {
			tab.addEventListener('click', async function () {
				selectAssignmentView(tab.dataset.assignmentView);
				assignmentPage = 1;
				clearAssignmentList();
				try {
					if (await refresh()) setStatus(root, assignmentView === 'archived' ? '보관한 과제입니다.' : '초안·게시·종료 과제입니다.', 'success');
				} catch (error) { setStatus(root, messageFrom(error), 'error'); }
			});
			tab.addEventListener('keydown', function (event) {
				if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
				event.preventDefault();
				var next = event.key === 'Home' ? 'unarchived' : event.key === 'End' ? 'archived'
					: tab.dataset.assignmentView === 'archived' ? 'unarchived' : 'archived';
				var target = content.querySelector('[data-assignment-view="' + next + '"]');
				target.focus(); target.click();
			});
		});
		var contexts = (await loadAllTeachingContexts(api)).filter(function (context) { return context.type === 'group'; });
		var hasActiveContexts = contexts.some(function (context) { return context.lifecycleState === 'active'; });
		contextSelect.innerHTML = '<option value="">그룹을 선택하세요</option>' + contexts.map(function (context, index) {
			var active = context.lifecycleState === 'active';
			var typeLabel = context.type === 'class' ? '수업' : '그룹';
			return '<option value="' + escapeHtml(index) + '" data-context-type="' + escapeHtml(context.type)
				+ '" data-context-id="' + escapeHtml(context.id) + '"' + (active ? '' : ' disabled') + '>'
				+ escapeHtml(context.label || context.id) + ' [' + escapeHtml(typeLabel) + ']'
				+ (active ? '' : ' (비활성)') + '</option>';
		}).join('');

		function contextLabel(item) {
			var match = contexts.find(function (context) {
				return String(context.type) === String(item.contextType) && String(context.id) === String(item.contextId);
			});
			if (match) return match.label || match.id;
			return (item.contextType === 'class' ? '수업' : '그룹') + ' #' + (item.contextId || '-');
		}

		function field(name) {
			return form.querySelector('[name="' + name + '"]');
		}

		function setLockedFields(locked) {
			if (policyEditor) policyEditor.lock(locked);
			var rubricFieldset = rubricPanel.querySelector('fieldset'); if (rubricFieldset) rubricFieldset.disabled = locked;
			if (portfolioEditor) portfolioEditor.lock(locked);
			datasetPanel.querySelectorAll('input, textarea, button').forEach(function (control) { control.disabled = locked || (control.tagName === 'BUTTON' && !portfolioEditor); });
			['context', 'taskType', 'opensAt', 'maxRows', 'maxFeatures', 'allowLateSubmission', 'maxArtifactMiB'].forEach(function (name) {
				var control = field(name);
				if (control) control.disabled = locked;
			});
		}

		function showFormSections(policy) {
			policy = policy || {};
			datasetPanel.open = false;
			datasetPanel.querySelector('[data-dataset-description-note]').textContent = '';
			form.querySelectorAll('[data-assignment-section]').forEach(function (section) {
				var key = section.dataset.assignmentSection;
				section.open = key === 'basic';
			});
		}

		function resetFormMode(resetValues) {
			copiedPolicy = null;
			if (resetValues) form.reset();
			if (portfolioEditor) portfolioEditor.load(null);
			loadRubric([], false, true);
			if (policyEditor) policyEditor.load({});
			showFormSections({});
			var fallback = contextSelect.querySelector('[data-edit-context]');
			if (fallback) fallback.remove();
			editing = null;
			setLockedFields(false);
			field('maxAttempts').min = '1';
			formTitle.textContent = '새 ML 실습 과제';
			formNote.textContent = '초안을 만든 뒤 게시하면 학생에게 보입니다.';
			submitButton.textContent = '초안 만들기';
			submitButton.disabled = !hasActiveContexts;
			cancelButton.hidden = true;
		}

		function beginEdit(item) {
			copiedPolicy = null;
			editing = item;
			loadRubric(item.rubric || [], item.status === 'published');
			var policy = item.policy && typeof item.policy === 'object' ? item.policy : {};
			showFormSections(policy);
			if (policyEditor) policyEditor.load(policy);
			if (portfolioEditor) portfolioEditor.load(policy.portfolioTemplate || null, policy.portfolioRequirements || []);
			field('title').value = item.title || '';
			field('description').value = item.description || '';
			field('datasetDescription').value = policy.datasetDescription || '';
			field('datasetDescriptionSectionId').value = policy.datasetDescriptionSectionId || 'dataset_description';
			field('taskType').value = item.taskType || 'classification';
			field('maxAttempts').value = item.maxAttempts || 1;
			field('opensAt').value = dateTimeLocalValue(item.opensAt);
			field('dueAt').value = dateTimeLocalValue(item.dueAt);
			field('maxRows').value = policy.maxRows || 20000;
			field('maxFeatures').value = policy.maxFeatures || 100;
			field('allowLateSubmission').checked = policy.allowLateSubmission === true;
			field('maxArtifactMiB').value = (policy.maxArtifactBytes || 26214400) / 1048576;

			var contextIndex = contexts.findIndex(function (context) {
				return String(context.type) === String(item.contextType) && String(context.id) === String(item.contextId);
			});
			var oldFallback = contextSelect.querySelector('[data-edit-context]');
			if (oldFallback) oldFallback.remove();
			if (contextIndex >= 0) {
				contextSelect.value = String(contextIndex);
			} else {
				var option = document.createElement('option');
				option.value = 'editing';
				option.dataset.contextType = item.contextType || '';
				option.dataset.contextId = item.contextId || '';
				option.dataset.editContext = '1';
				option.textContent = contextLabel(item);
				contextSelect.appendChild(option);
				contextSelect.value = 'editing';
			}

			var published = item.status === 'published';
			field('maxAttempts').min = published ? String(Math.max(1, Number(item.maxAttempts) || 1)) : '1';
			setLockedFields(published);
			formTitle.textContent = published ? '게시된 과제 편집' : '과제 초안 편집';
			formNote.textContent = published
				? '게시된 과제는 제목·설명·마감·제출 횟수만 수정할 수 있습니다.'
				: '게시 전 초안은 모든 설정을 바꿀 수 있습니다.';
			submitButton.textContent = '저장';
			submitButton.disabled = false;
			cancelButton.hidden = false;
			form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		async function refresh() {
			var requestedPage = assignmentPage;
			var requestedView = assignmentView;
			var refreshToken = assignmentRefreshGuard.begin();
			try {
			var data = await api.get('console/assignments?per_page=' + assignmentPerPage + '&page=' + requestedPage + '&status=' + requestedView);
			if (!assignmentRefreshGuard.isCurrent(refreshToken) || assignmentPage !== requestedPage || assignmentView !== requestedView) return false;
			var items = assignmentItems(data);
			var total = data && Number.isFinite(Number(data.total))
				? Math.max(0, Number(data.total))
				: ((requestedPage - 1) * assignmentPerPage) + items.length;
			var maxPage = Math.max(1, Math.ceil(total / assignmentPerPage));
			assignmentMaxPage = maxPage;
			if (requestedPage > maxPage) {
				assignmentPage = maxPage;
				return await refresh();
			}
			listedItems = items;
			if (!items.length) {
				list.innerHTML = requestedView === 'archived'
					? emptyHtml('보관한 과제가 없습니다', '보관한 과제의 결과 확인과 보관 취소는 여기에 표시됩니다.')
					: emptyHtml('표시할 과제가 없습니다', '새 초안을 만들거나 보관 탭을 확인하세요.');
				return true;
			}
			list.innerHTML = assignmentPaginationHtml(total, items.length, requestedPage, assignmentPerPage)
				+ '<div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table"><thead><tr><th>과제</th><th>수업·그룹</th><th>유형</th><th>상태</th><th>마감</th><th>버전</th><th><span class="ojp-visually-hidden">작업</span></th></tr></thead><tbody>' + items.map(function (item) {
				return '<tr><td>' + escapeHtml(item.title) + '</td><td>' + escapeHtml(contextLabel(item))
					+ '</td><td>' + escapeHtml(TASK_TYPE_LABELS[item.taskType] || item.taskType || '-') + '</td><td><span class="ojp-status-badge ojp-status-badge--' + statusTone(item.status) + '">' + escapeHtml(assignmentStatusLabel(item))
					+ '</span></td><td>' + escapeHtml(formatDate(item.dueAt)) + '</td><td>' + escapeHtml(item.revision || 1)
					+ '</td><td><div class="oj-ml-actions">' + consoleAssignmentActionsHtml(root, item) + '</div></td></tr>';
			}).join('') + '</tbody></table></div>';
			list.querySelectorAll('[data-transition-id], [data-duplicate-id]').forEach(function (button) {
				button.addEventListener('click', async function () {
					var id = button.dataset.transitionId || button.dataset.duplicateId;
					var sourceItem = listedItems.find(function (item) { return String(item.id || item.publicId) === String(id); });
					if (button.dataset.duplicateId && sourceItem && sourceItem.contextType === 'class') {
						beginEdit(Object.assign({}, sourceItem, { status: 'draft', title: (sourceItem.title || '') + ' (복사)', opensAt: null, dueAt: null }));
						editing = null;
						copiedPolicy = sourceItem.policy || {};
						var legacyOption = contextSelect.querySelector('[data-edit-context]'); if (legacyOption) legacyOption.remove();
						contextSelect.value = '';
						formTitle.textContent = '기존 과제를 새 그룹 과제로 복사';
						formNote.textContent = '과제 조건과 평가 기준·양식만 복사했습니다. 대상 그룹과 일정을 선택한 뒤 새 초안을 만드세요. 기존 학생 명단·제출·평가는 복사하지 않습니다.';
						submitButton.textContent = '그룹 과제 초안 만들기';
						submitButton.disabled = !hasActiveContexts;
						contextSelect.focus();
						return;
					}
					if (!await confirmAction(button.dataset.duplicateId
						? { title: '과제 초안을 복제할까요?', message: '현재 명단으로 새 초안을 만듭니다. 시작·마감은 다시 지정합니다.', confirmLabel: '복제' }
						: { title: '과제를 ' + button.textContent + '할까요?', message: '기존 제출 기록은 유지됩니다.', confirmLabel: button.textContent })) return;
					button.disabled = true;
					try {
						if (button.dataset.duplicateId) {
							await api.post('console/assignments/' + encodeURIComponent(id) + '/duplicate', {});
							selectAssignmentView('unarchived'); assignmentPage = 1; clearAssignmentList();
						}
						else await api.patch('console/assignments/' + encodeURIComponent(id), { expectedRevision: Number(button.dataset.revision), status: button.dataset.transitionStatus });
						if (editing && String(editing.id) === String(id)) resetFormMode(true);
						await refresh();
						setStatus(root, button.dataset.transitionStatus === 'closed' && sourceItem && sourceItem.status === 'archived'
							? '보관을 취소했습니다. 과제 탭에서 다시 열 수 있습니다.' : '과제 작업을 완료했습니다.', 'success');
					} catch (error) { setStatus(root, messageFrom(error), 'error'); button.disabled = false; }
				});
			});
			list.querySelectorAll('[data-edit-id]').forEach(function (button) {
				button.addEventListener('click', function () {
					var item = listedItems.find(function (candidate) {
						return String(candidate.id || candidate.publicId) === String(button.dataset.editId);
					});
					if (item) beginEdit(item);
				});
			});
			list.querySelectorAll('[data-publish-id]').forEach(function (button) {
				button.addEventListener('click', async function () {
					button.disabled = true;
					try {
						await api.post('console/assignments/' + encodeURIComponent(button.dataset.publishId) + '/publish', { expectedRevision: Number(button.dataset.revision) });
						if (editing && String(editing.id || editing.publicId) === String(button.dataset.publishId)) resetFormMode(true);
						await refresh();
						setStatus(root, '과제를 게시했습니다.', 'success');
					} catch (error) {
						setStatus(root, messageFrom(error), 'error');
						button.disabled = false;
					}
				});
			});
			list.querySelectorAll('[data-delete-id]').forEach(function (button) {
				button.addEventListener('click', async function () {
					if (!await confirmAction({ title: '이 초안 과제를 삭제할까요?', message: '삭제한 초안은 복구할 수 없습니다.', confirmLabel: '삭제', destructive: true })) return;
					button.disabled = true;
					try {
						await api.remove('console/assignments/' + encodeURIComponent(button.dataset.deleteId), {
							expectedRevision: Number(button.dataset.revision)
						});
						if (editing && String(editing.id || editing.publicId) === String(button.dataset.deleteId)) resetFormMode(true);
						await refresh();
						setStatus(root, '과제 초안을 삭제했습니다.', 'success');
					} catch (error) {
						setStatus(root, messageFrom(error), 'error');
						button.disabled = false;
					}
				});
			});
			list.querySelectorAll('[data-archive-id]').forEach(function (button) {
				button.addEventListener('click', async function () {
					if (!await confirmAction({ title: '이 과제를 보관할까요?', message: '학생은 더 이상 실습을 열 수 없지만 제출 결과는 유지됩니다. 보관 취소로 되돌릴 수 있습니다.', confirmLabel: '보관' })) return;
					button.disabled = true;
					try {
						await api.patch('console/assignments/' + encodeURIComponent(button.dataset.archiveId), {
							expectedRevision: Number(button.dataset.revision),
							status: 'archived'
						});
						if (editing && String(editing.id || editing.publicId) === String(button.dataset.archiveId)) resetFormMode(true);
						await refresh();
						setStatus(root, '과제를 보관 탭으로 옮겼습니다. 제출과 평가는 유지됩니다.', 'success');
					} catch (error) {
						setStatus(root, messageFrom(error), 'error');
						button.disabled = false;
					}
				});
			});
			list.querySelectorAll('[data-assignment-page]').forEach(function (button) {
				button.addEventListener('click', async function () {
					if (assignmentRefreshGuard.isBusy()) return;
					if (button.dataset.assignmentPage === 'previous' && assignmentPage > 1) assignmentPage -= 1;
					else if (button.dataset.assignmentPage === 'next' && assignmentPage < maxPage) assignmentPage += 1;
					else return;
					button.disabled = true;
					try {
						var applied = await refresh();
						if (applied) setStatus(root, '과제 목록 ' + assignmentPage + '/' + assignmentMaxPage + ' 페이지를 불러왔습니다.', 'success');
					} catch (error) {
						setStatus(root, messageFrom(error), 'error');
					} finally {
						setAssignmentNavigationBusy(assignmentRefreshGuard.isBusy());
					}
				});
			});
			return true;
			} catch (error) {
				if (!assignmentRefreshGuard.isCurrent(refreshToken) || assignmentPage !== requestedPage || assignmentView !== requestedView) return false;
				list.innerHTML = emptyHtml('목록을 불러오지 못했습니다', '과제 또는 보관 탭을 다시 눌러 재시도하세요.');
				throw error;
			} finally {
				assignmentRefreshGuard.finish();
			}
		}

		cancelButton.addEventListener('click', function () {
			resetFormMode(true);
			setStatus(root, '과제 편집을 취소했습니다.');
		});

		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			var values = new FormData(form);
			var button = submitButton;
			button.disabled = true;
			try {
				var dueAt = values.get('dueAt') ? new Date(String(values.get('dueAt'))).toISOString() : null;
				if (editing && editing.status === 'published') {
					await api.patch('console/assignments/' + encodeURIComponent(editing.id || editing.publicId), {
						expectedRevision: Number(editing.revision),
						title: String(values.get('title') || ''),
						description: String(values.get('description') || ''),
						dueAt: dueAt,
						maxAttempts: Number(values.get('maxAttempts'))
					});
					resetFormMode(true);
					await refresh();
					setStatus(root, '게시된 과제의 변경 내용을 저장했습니다.', 'success');
					return;
				}

				var selectedContext = contextSelect.selectedOptions[0];
				if (!selectedContext || !selectedContext.dataset.contextType || !selectedContext.dataset.contextId) {
					setStatus(root, '활성 그룹을 선택해 주세요.', 'error');
					return;
				}
				if (!editing && selectedContext.dataset.contextType !== 'group') throw new Error('새 과제는 그룹을 선택해 만들어 주세요.');
				var taskType = String(values.get('taskType') || '');
				var opensAt = values.get('opensAt') ? new Date(String(values.get('opensAt'))).toISOString() : null;
				var priorPolicy = editing && editing.policy && typeof editing.policy === 'object' ? editing.policy : copiedPolicy || {};
				var payload = {
					contextType: selectedContext.dataset.contextType,
					contextId: selectedContext.dataset.contextId,
					title: String(values.get('title') || ''),
					description: String(values.get('description') || ''),
					taskType: taskType,
					opensAt: opensAt,
					dueAt: dueAt,
					maxAttempts: Number(values.get('maxAttempts')),
					policy: Object.assign({}, priorPolicy, {
						datasetDescription: String(values.get('datasetDescription') || '').trim(),
						datasetDescriptionSectionId: String(values.get('datasetDescriptionSectionId') || 'dataset_description').trim(),
						portfolioTemplate: portfolioEditor ? portfolioEditor.value() : priorPolicy.portfolioTemplate || null,
						portfolioRequirements: portfolioEditor ? portfolioEditor.requirements() : priorPolicy.portfolioRequirements || [],
						allowedFormatVersions: Array.isArray(priorPolicy.allowedFormatVersions) ? priorPolicy.allowedFormatVersions : [2],
						allowedDataTypes: Array.isArray(priorPolicy.allowedDataTypes) ? priorPolicy.allowedDataTypes : ['tabular'],
						allowedTaskTypes: [taskType],
						maxRows: Number(values.get('maxRows')),
						maxFeatures: Number(values.get('maxFeatures')),
						allowLateSubmission: values.get('allowLateSubmission') === 'on',
						maxArtifactBytes: Number(values.get('maxArtifactMiB')) * 1048576,
						requireIntegrityManifest: true
					})
				};
				payload.rubric = window.OJMLRubric ? window.OJMLRubric.readEditor(rubricPanel) : editing && editing.rubric || [];
				if (policyEditor) Object.assign(payload.policy, policyEditor.value());
				var message = '과제 초안을 만들었습니다.';
				if (editing) {
					payload.expectedRevision = Number(editing.revision);
					await api.patch('console/assignments/' + encodeURIComponent(editing.id || editing.publicId), payload);
					message = '과제 초안을 저장했습니다.';
				} else {
					await api.post('console/assignments', payload);
					assignmentPage = 1;
					selectAssignmentView('unarchived'); clearAssignmentList();
				}
				resetFormMode(true);
				await refresh();
				setStatus(root, message, 'success');
			} catch (error) {
				setStatus(root, messageFrom(error), 'error');
			} finally {
				button.disabled = editing ? false : !hasActiveContexts;
			}
		});

		resetFormMode(false);
		await refresh();
		if (!hasActiveContexts) {
			setStatus(root, '과제를 낼 수 있는 활성 그룹이 없습니다. OJ 그룹에서 그룹과 학생 명단을 먼저 준비하세요. 기존 과제는 아래 목록에서 관리할 수 있습니다.', 'error');
		} else {
			setStatus(root, '그룹 ' + contexts.length + '개를 불러왔습니다. 평가 기준을 확인하고 초안을 만드세요.', 'success');
		}
	}

	function submissionFactRow(label, value) {
		if (value === null || typeof value === 'undefined' || value === '') return '';
		return '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd>';
	}

	function submissionBooleanLabel(value) {
		if (value === true) return '예';
		if (value === false) return '아니요';
		return '-';
	}

	function submissionNumberLabel(value, suffix) {
		if (value === null || typeof value === 'undefined' || value === '') return null;
		var number = Number(value);
		return Number.isFinite(number) ? number.toLocaleString() + suffix : null;
	}

	function submissionFactsHtml(evaluation) {
		var facts = evaluation && evaluation.facts && typeof evaluation.facts === 'object'
			? evaluation.facts
			: null;
		if (!facts || !Object.keys(facts).length) {
			return '<section class="oj-ml-submission-summary"><h4>제출 내용 요약</h4>'
				+ '<p class="oj-ml-meta">서버 검사가 완료되면 프로젝트 요약이 표시됩니다.</p></section>';
		}

		var taskLabels = { classification: '분류', regression: '회귀', clustering: '군집' };
		var dataLabels = { tabular: '표 데이터', image: '이미지' };
		var algorithms = Array.isArray(facts.algorithms)
			? facts.algorithms.filter(function (item) { return typeof item === 'string'; }).slice(0, 20)
			: [];
		var integrity = facts.integrity && typeof facts.integrity === 'object' ? facts.integrity : null;
		var tabular = facts.tabular && typeof facts.tabular === 'object' ? facts.tabular : null;
		var rows = '';

		rows += submissionFactRow('프로젝트 이름', facts.projectName);
		rows += submissionFactRow('프로젝트 생성', facts.projectCreatedAt ? formatDate(facts.projectCreatedAt) : null);
		rows += submissionFactRow('프로젝트 수정', facts.projectUpdatedAt ? formatDate(facts.projectUpdatedAt) : null);
		rows += submissionFactRow('과제 유형', taskLabels[facts.taskType] || facts.taskType);
		rows += submissionFactRow('데이터 유형', dataLabels[facts.dataType] || facts.dataType);
		rows += submissionFactRow('실행 수', submissionNumberLabel(facts.runCount, '회'));
		rows += submissionFactRow('알고리즘', algorithms.length ? algorithms.join(', ') : '없음');
		rows += submissionFactRow('파일 형식 버전', facts.formatVersion != null ? 'v' + facts.formatVersion : null);
		rows += submissionFactRow('실습 앱 버전', facts.appVersion);
		rows += submissionFactRow('내부 항목 수', submissionNumberLabel(facts.entryCount, '개'));
		rows += submissionFactRow('압축 해제 기준 크기', submissionNumberLabel(facts.totalUncompressedBytes, ' bytes'));
		if (integrity) {
			rows += submissionFactRow('무결성 목록', submissionBooleanLabel(integrity.present));
			rows += submissionFactRow('내부 해시 검증', submissionBooleanLabel(integrity.verified));
		}
		if (tabular && tabular.applicable === true) {
			rows += submissionFactRow('데이터셋 포함', submissionBooleanLabel(tabular.datasetPresent));
			rows += submissionFactRow('헤더 행', submissionBooleanLabel(tabular.hasHeader));
			rows += submissionFactRow('데이터 행', submissionNumberLabel(tabular.rows, '행'));
			rows += submissionFactRow('열', submissionNumberLabel(tabular.columns, '개'));
			rows += submissionFactRow('현재 선택 특성', submissionNumberLabel(tabular.currentFeatureCount, '개'));
			rows += submissionFactRow('최대 선택 특성', submissionNumberLabel(tabular.maxSelectedFeatureCount, '개'));
		}

		return '<section class="oj-ml-submission-summary"><h4>제출 내용 요약</h4><dl>' + rows + '</dl>'
			+ '<p class="oj-ml-meta">서버가 검증한 허용 항목만 표시합니다. 데이터 실제 값과 모델 파일은 이 화면에서 실행하지 않습니다.</p></section>';
	}

	function previewDisplay(value) {
		if (value === null || typeof value === 'undefined' || value === '') return '-';
		if (value === true) return '예';
		if (value === false) return '아니요';
		if (typeof value === 'number') return Number.isFinite(value) ? value.toLocaleString() : '-';
		return String(value);
	}

	function previewDlRow(label, value) {
		return '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(previewDisplay(value)) + '</dd>';
	}

	function previewNameValueHtml(items, emptyMessage) {
		items = Array.isArray(items) ? items : [];
		if (!items.length) return '<p class="oj-ml-meta">' + escapeHtml(emptyMessage || '기록 없음') + '</p>';
		return '<dl class="oj-ml-preview-dl">' + items.map(function (item) {
			return previewDlRow(item && item.name, item && item.value);
		}).join('') + '</dl>';
	}

	function previewStringListHtml(items, emptyMessage) {
		items = Array.isArray(items) ? items : [];
		if (!items.length) return '<span class="oj-ml-meta">' + escapeHtml(emptyMessage || '없음') + '</span>';
		return '<ul class="oj-ml-preview-list">' + items.map(function (item) {
			return '<li><code>' + escapeHtml(item) + '</code></li>';
		}).join('') + '</ul>';
	}

	function previewPortfolioHtml(portfolio) {
		portfolio = portfolio && typeof portfolio === 'object' ? portfolio : {};
		var sections = Array.isArray(portfolio.sections) ? portfolio.sections : [];
		var body = sections.length ? sections.map(function (section, index) {
			section = section && typeof section === 'object' ? section : {};
			var answer = section.answered === true
				? '<pre class="oj-ml-preview-answer">' + escapeHtml(section.answer || '') + '</pre>'
				: '<p class="oj-ml-meta">작성된 답변이 없습니다.</p>';
			var attachments = Number(section.attachmentCount || 0);
			return '<article class="oj-ml-preview-card"><h6>' + escapeHtml((index + 1) + '. ' + (section.title || section.id || '답변')) + '</h6>'
				+ (section.description ? '<p class="oj-ml-meta">' + escapeHtml(section.description) + '</p>' : '')
				+ answer
				+ (attachments > 0 ? '<p class="oj-ml-meta">첨부파일 ' + escapeHtml(attachments) + '개 — 내용과 경로는 바로보기에서 열지 않습니다.</p>' : '')
				+ '</article>';
		}).join('') : '<p class="oj-ml-meta">포트폴리오 답변 항목이 없습니다.</p>';
		return '<section class="oj-ml-preview-section"><h5>포트폴리오 답변</h5>' + body + '</section>';
	}

	function previewParameterGroupsHtml(groups) {
		groups = Array.isArray(groups) ? groups : [];
		if (!groups.length) return '<p class="oj-ml-meta">저장된 하이퍼파라미터가 없습니다.</p>';
		return groups.map(function (group) {
			group = group && typeof group === 'object' ? group : {};
			var label = group.algorithm || '알고리즘';
			if (group.runtime) label += ' · ' + group.runtime;
			return '<details class="oj-ml-preview-nested"><summary>' + escapeHtml(label) + '</summary>'
				+ previewNameValueHtml(group.items, '표시할 단일 값 파라미터가 없습니다.') + '</details>';
		}).join('');
	}

	function previewSettingsHtml(settings, heading) {
		settings = settings && typeof settings === 'object' ? settings : {};
		var split = settings.split && typeof settings.split === 'object' ? settings.split : {};
		var data = settings.data && typeof settings.data === 'object' ? settings.data : {};
		var algorithms = Array.isArray(settings.selectedAlgorithms) ? settings.selectedAlgorithms : [];
		var algorithmHtml = algorithms.length ? '<ul class="oj-ml-preview-list">' + algorithms.map(function (item) {
			return '<li><code>' + escapeHtml(item.algorithm || '-') + '</code>'
				+ (item.runtime ? ' <span class="oj-ml-meta">(' + escapeHtml(item.runtime) + ')</span>' : '') + '</li>';
		}).join('') + '</ul>' : '<p class="oj-ml-meta">선택된 알고리즘이 없습니다.</p>';
		var dataRows = previewDlRow('데이터셋 설정', data.datasetConfigured)
			+ previewDlRow('테스트 데이터셋 설정', data.testDatasetConfigured)
			+ previewDlRow('예측 데이터셋 설정', data.predictDatasetConfigured);
		var dataDetails = '';
		if (data.type === 'image') {
			dataRows += previewDlRow('백본', data.backboneId)
				+ previewDlRow('이미지 크기', data.canonicalSize)
				+ previewDlRow('이미지 형식', data.format)
				+ previewDlRow('이미지 품질', data.quality);
			dataDetails = '<h6>범주</h6>' + previewStringListHtml(data.categories, '범주 없음');
		} else {
			var preprocessing = data.preprocessing && typeof data.preprocessing === 'object' ? data.preprocessing : {};
			dataRows += previewDlRow('목표 열', data.target)
				+ previewDlRow('결측치 처리', preprocessing.missing)
				+ previewDlRow('스케일링', preprocessing.scaling)
				+ previewDlRow('범주형 인코딩', preprocessing.categoricalEncoding);
			dataDetails = '<h6>선택 특성</h6>' + previewStringListHtml(data.features, '선택 특성 없음');
		}
		return '<section class="oj-ml-preview-section"><h5>' + escapeHtml(heading || '제출 당시 설정') + '</h5>'
			+ '<div class="oj-ml-preview-columns"><div><h6>실행·분할</h6><dl class="oj-ml-preview-dl">'
			+ previewDlRow('실행 환경', settings.runtime) + previewDlRow('샘플 수', settings.sampleCount)
			+ previewDlRow('분할 방식', split.method) + previewDlRow('테스트 비율', split.testSize)
			+ previewDlRow('계층 추출', split.stratify) + previewDlRow('난수 시드', split.randomState)
			+ '</dl></div><div><h6>데이터 설정</h6><dl class="oj-ml-preview-dl">' + dataRows + '</dl>' + dataDetails + '</div></div>'
			+ '<h6>선택 알고리즘</h6>' + algorithmHtml
			+ '<h6>하이퍼파라미터</h6>' + previewParameterGroupsHtml(settings.hyperparameters)
			+ '</section>';
	}

	function previewPerClassHtml(rows) {
		rows = Array.isArray(rows) ? rows : [];
		if (!rows.length) return '';
		return '<h6>클래스별 지표</h6><div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table oj-ml-preview-table"><thead><tr>'
			+ '<th>클래스</th><th>정밀도</th><th>재현율</th><th>특이도</th><th>F1</th><th>지원 수</th></tr></thead><tbody>'
			+ rows.map(function (row) { return '<tr><th>' + escapeHtml(previewDisplay(row.label)) + '</th><td>'
				+ escapeHtml(previewDisplay(row.precision)) + '</td><td>' + escapeHtml(previewDisplay(row.recall))
				+ '</td><td>' + escapeHtml(previewDisplay(row.specificity)) + '</td><td>'
				+ escapeHtml(previewDisplay(row.f1)) + '</td><td>' + escapeHtml(previewDisplay(row.support)) + '</td></tr>'; }).join('')
			+ '</tbody></table></div>';
	}

	function previewConfusionMatrixHtml(value) {
		if (!value || !Array.isArray(value.labels) || !Array.isArray(value.matrix) || !value.labels.length) return '';
		return '<h6>혼동행렬</h6><div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table oj-ml-preview-matrix"><thead><tr><th>실제＼예측</th>'
			+ value.labels.map(function (label) { return '<th>' + escapeHtml(label) + '</th>'; }).join('') + '</tr></thead><tbody>'
			+ value.matrix.map(function (row, index) { return '<tr><th>' + escapeHtml(value.labels[index] || '-') + '</th>'
				+ (Array.isArray(row) ? row : []).map(function (cell) { return '<td>' + escapeHtml(previewDisplay(cell)) + '</td>'; }).join('') + '</tr>'; }).join('')
			+ '</tbody></table></div>';
	}

	function previewImportanceHtml(rows) {
		rows = Array.isArray(rows) ? rows : [];
		if (!rows.length) return '';
		return '<h6>특성 중요도</h6><div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table oj-ml-preview-table"><thead><tr><th>특성</th><th>중요도</th></tr></thead><tbody>'
			+ rows.map(function (row) { return '<tr><th>' + escapeHtml(previewDisplay(row.feature)) + '</th><td>'
				+ escapeHtml(previewDisplay(row.importance)) + '</td></tr>'; }).join('') + '</tbody></table></div>';
	}

	function previewRunHtml(run, index) {
		run = run && typeof run === 'object' ? run : {};
		var engine = run.engine && typeof run.engine === 'object' ? run.engine : {};
		var model = run.model && typeof run.model === 'object' ? run.model : {};
		var metricLabels = { accuracy: '정확도', f1Macro: 'Macro F1', r2: 'R²', rmse: 'RMSE', mae: 'MAE', silhouette: 'Silhouette', inertia: 'Inertia' };
		var metrics = (Array.isArray(run.metrics) ? run.metrics : []).map(function (item) {
			return { name: metricLabels[item.name] || item.name, value: item.value };
		});
		var messages = '';
		if (run.failureCode) messages += '<p role="alert">실패 코드: <code>' + escapeHtml(run.failureCode) + '</code></p>';
		if (run.warningCode) messages += '<p>경고 코드: <code>' + escapeHtml(run.warningCode) + '</code></p>';
		if (run.modelOmitted) messages += '<p>모델 생략: <code>' + escapeHtml(run.modelOmitted) + '</code></p>';
		var modelRows = '';
		if (model.format || model.sizeBytes != null || model.includesPreprocessing != null) {
			modelRows = '<h6>모델 기록</h6><dl class="oj-ml-preview-dl">' + previewDlRow('형식', model.format)
				+ previewDlRow('전처리 포함', model.includesPreprocessing) + previewDlRow('기록된 크기', model.sizeBytes) + '</dl>';
		}
		return '<article class="oj-ml-preview-card"><h6>실행 ' + escapeHtml(index + 1) + ' · ' + escapeHtml(run.algorithm || '-') + '</h6>'
			+ '<dl class="oj-ml-preview-dl">' + previewDlRow('상태', run.status) + previewDlRow('계산 위치', run.computedBy)
			+ previewDlRow('학습 시각', run.trainedAt) + previewDlRow('엔진', engine.kind)
			+ previewDlRow('엔진 버전', engine.version) + '</dl>' + messages
			+ '<h6>기록된 평가 지표</h6>' + previewNameValueHtml(metrics, '알려진 평가 지표가 없습니다.')
			+ '<h6>실행 하이퍼파라미터</h6>' + previewNameValueHtml(run.hyperparameters, '기록 없음')
			+ previewPerClassHtml(run.perClass) + previewConfusionMatrixHtml(run.confusionMatrix)
			+ previewImportanceHtml(run.featureImportance) + modelRows + '</article>';
	}

	function previewExperimentsHtml(experiments) {
		experiments = Array.isArray(experiments) ? experiments : [];
		if (!experiments.length) return '<section class="oj-ml-preview-section"><h5>실험·실행 기록</h5><p class="oj-ml-meta">저장된 실험 기록이 없습니다.</p></section>';
		return '<section class="oj-ml-preview-section"><h5>실험·실행 기록</h5>' + experiments.map(function (experiment, index) {
			experiment = experiment && typeof experiment === 'object' ? experiment : {};
			var runs = Array.isArray(experiment.runs) ? experiment.runs : [];
			var split = experiment.split && typeof experiment.split === 'object' ? experiment.split : {};
			return '<details class="oj-ml-preview-experiment"><summary>실험 ' + escapeHtml(index + 1) + ' · '
				+ escapeHtml(formatDate(experiment.startedAt)) + ' · 실행 ' + escapeHtml(runs.length) + '개</summary>'
				+ '<dl class="oj-ml-preview-dl">' + previewDlRow('과제 유형', experiment.taskType)
				+ previewDlRow('실행 환경', experiment.runtime) + previewDlRow('샘플 수', experiment.sampleCount)
				+ previewDlRow('분할 방식', split.method) + previewDlRow('테스트 비율', split.testSize)
				+ previewDlRow('난수 시드', split.randomState) + '</dl>'
				+ (experiment.data || experiment.hyperparameters ? '<details class="oj-ml-preview-nested"><summary>이 실험 당시 설정 보기</summary>' + previewSettingsHtml(experiment, '실험 당시 설정') + '</details>' : '')
				+ (runs.length ? runs.map(previewRunHtml).join('') : '<p class="oj-ml-meta">실행 기록이 없습니다.</p>') + '</details>';
		}).join('') + '</section>';
	}

	function previewDatasetProfilesHtml(value) {
		if (!value || typeof value !== 'object') return '<section class="oj-ml-preview-section"><h5>데이터 통계</h5><p class="oj-ml-meta">통계 요약 없음 · 교사 확인 필요</p></section>';
		var datasets = Array.isArray(value.datasets) ? value.datasets : [];
		var bindings = Array.isArray(value.bindings) ? value.bindings : [];
		var types = { numeric: '수치', categorical: '범주', empty: '모두 결측', mixed: '혼합' };
		var html = '<section class="oj-ml-preview-section oj-ml-dataset-profiles"><h5>데이터 통계</h5><p class="oj-ml-meta">제출 CSV에서 서버가 계산한 전처리 전 요약입니다. 원본 행·범주 값은 표시하거나 AI에 보내지 않습니다. 전처리 결과·성능은 재실행하지 않습니다.</p>';
		if (value.status !== 'complete') html += '<p class="oj-ml-evidence-note">통계가 일부 없거나 분석 범위가 제한되어 교사 확인이 필요합니다.</p>';
		html += datasets.slice(0, 20).map(function (dataset, index) {
			dataset = dataset && typeof dataset === 'object' ? dataset : {};
			var columns = Array.isArray(dataset.columns) ? dataset.columns : [];
			var usages = bindings.filter(function (binding) { return binding && binding.profileId === dataset.profileId; }).map(function (binding) {
				var role = binding.role === 'experiment' ? '실험 ' + (Number(binding.experimentIndex) + 1) : '현재 설정';
				var features = Array.isArray(binding.featureColumns) ? binding.featureColumns.filter(Number.isInteger).map(function (number) { return number + 1; }) : [];
				return role + ' · ' + (binding.usage === 'test' ? '평가 데이터' : '학습 데이터') + (Number.isInteger(binding.targetColumn) ? ' · 타깃 열 ' + (binding.targetColumn + 1) : '')
					+ (features.length ? ' · 특성 열 ' + features.join(', ') : '');
			});
			return '<details class="oj-ml-preview-nested"><summary>데이터 ' + (index + 1) + ' · ' + escapeHtml(previewDisplay(dataset.rowCount)) + '행 · ' + escapeHtml(previewDisplay(dataset.columnCount)) + '열'
				+ (dataset.status !== 'complete' ? ' · 교사 확인 필요' : '') + '</summary>'
				+ (usages.length ? '<p class="oj-ml-meta">' + escapeHtml(usages.join('\n')) + '</p>' : '')
				+ '<p class="oj-ml-meta">분석한 행: ' + escapeHtml(previewDisplay(dataset.rowsAnalyzed)) + ' · 열 번호는 1부터 표시합니다. 소수 집단의 통계는 숨깁니다.</p>'
				+ '<div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table oj-ml-preview-table"><caption>열별 통계 요약</caption><thead><tr><th scope="col">열</th><th scope="col">자료형</th><th scope="col">결측 수·비율</th><th scope="col">값 종류 수</th><th scope="col">평균·표준편차</th><th scope="col">익명 범주별 개수</th></tr></thead><tbody>'
				+ columns.slice(0, 100).map(function (column) {
					column = column && typeof column === 'object' ? column : {};
					var missingRate = typeof column.missingRate === 'number' && Number.isFinite(column.missingRate) ? (column.missingRate * 100).toFixed(1) + '%' : '-';
					var numeric = column.numeric && typeof column.numeric === 'object' ? column.numeric : {};
					var counts = column.distribution && Array.isArray(column.distribution.counts) ? column.distribution.counts.filter(function (count) { return Number.isInteger(count) && count >= 5; }).slice(0, 20) : [];
					var distinct = column.distinctCount == null ? (Number(column.distinctAtLeast) > 0 ? column.distinctAtLeast + '개 이상' : '숨김/없음') : column.distinctCount + '개';
					return '<tr><th scope="row">' + escapeHtml(Number.isInteger(column.index) ? column.index + 1 : '-') + '</th><td>' + escapeHtml(types[column.type] || '확인 필요') + '</td><td>'
						+ escapeHtml(previewDisplay(column.missingCount)) + ' · ' + escapeHtml(missingRate) + '</td><td>' + escapeHtml(distinct) + '</td><td>'
						+ escapeHtml(previewDisplay(numeric.mean)) + ' · ' + escapeHtml(previewDisplay(numeric.stdDev)) + '</td><td>' + escapeHtml(counts.length ? counts.join(', ') : '숨김/없음')
						+ (column.distribution && column.distribution.suppressed ? ' · 일부 숨김' : '') + '</td></tr>';
				}).join('') + '</tbody></table></div>' + (columns.length > 100 ? '<p class="oj-ml-meta">앞 100개 열만 표시합니다.</p>' : '') + '</details>';
		}).join('');
		if (!datasets.length) html += '<p class="oj-ml-meta">분석 가능한 CSV 통계가 없습니다.</p>';
		if (datasets.length > 20) html += '<p class="oj-ml-meta">앞 20개 데이터 요약만 표시합니다.</p>';
		return html + '</section>';
	}
	function previewDatasetContextHtml(context) {
		if (!context || typeof context !== 'object') return '';
		return '<section class="oj-ml-preview-section"><h5>데이터 설명</h5>'
			+ '<h6>교사 공통 설명</h6><p class="oj-ml-preview-answer">' + escapeHtml(context.teacherDescription || '설명 없음') + '</p>'
			+ '<h6>학생 설명</h6><p class="oj-ml-preview-answer">' + escapeHtml(context.studentDescription || '설명 없음') + '</p>'
			+ '<p class="oj-ml-meta">작성자가 제공한 설명이며 데이터의 의미·출처를 서버가 검증한 것은 아닙니다.</p></section>';
	}

	function submissionPreviewHtml(content) {
		content = content && typeof content === 'object' ? content : {};
		var project = content.project && typeof content.project === 'object' ? content.project : {};
		var notices = Array.isArray(content.notices) ? content.notices : [];
		var noticeHtml = notices.length ? '<aside class="oj-ml-preview-notice" role="note"><strong>표시 제한 안내</strong><ul>'
			+ notices.map(function (notice) { return '<li>' + escapeHtml(notice) + '</li>'; }).join('') + '</ul></aside>' : '';
		return '<div class="oj-ml-readonly-view"><p class="oj-ml-preview-safety" role="note"><strong>읽기 전용 안전 보기</strong> — '
			+ '기록된 설정·결과와 서버가 계산한 데이터 통계만 표시합니다. 모델·코드·첨부파일을 실행하거나 학습을 재실행하지 않습니다.</p>'
			+ '<h4>' + escapeHtml(project.name || '제출 프로젝트') + '</h4>'
			+ previewPortfolioHtml(content.portfolio) + previewDatasetContextHtml(content.datasetContext) + previewDatasetProfilesHtml(content.datasetProfiles) + previewExperimentsHtml(content.experiments)
			+ '<details class="oj-ml-preview-configuration"><summary>프로젝트 정보·실험 설정 자세히 보기</summary>'
			+ '<section class="oj-ml-preview-section"><h5>프로젝트</h5><dl class="oj-ml-preview-dl">'
			+ previewDlRow('이름', project.name) + previewDlRow('앱 버전', project.appVersion)
			+ previewDlRow('파일 형식 버전', project.formatVersion) + previewDlRow('과제 유형', project.taskType)
			+ previewDlRow('데이터 유형', project.dataType) + previewDlRow('언어', project.locale)
			+ previewDlRow('생성', project.createdAt ? formatDate(project.createdAt) : null)
			+ previewDlRow('수정', project.updatedAt ? formatDate(project.updatedAt) : null) + '</dl></section>'
			+ previewSettingsHtml(content.settings) + '</details>' + noticeHtml + '</div>';
	}

	async function loadSubmissionPreview(holder, api) {
		if (!holder || holder.dataset.previewState === 'loading' || holder.dataset.previewState === 'loaded') return;
		var attemptId = holder.dataset.attemptId || '';
		var body = holder.querySelector('[data-oj-ml-preview-body]');
		var button = holder.querySelector('[data-oj-ml-preview-load]');
		if (!attemptId || !body) return;
		holder.dataset.previewState = 'loading';
		if (button) { button.disabled = true; button.hidden = false; button.textContent = '안전하게 불러오는 중…'; }
		body.innerHTML = '<p class="oj-ml-meta" role="status">제출 파일의 무결성을 확인하고 읽기 전용 내용을 준비하고 있습니다.</p>';
		try {
			var data = await api.post('console/attempts/' + encodeURIComponent(attemptId) + '/preview', {});
			body.innerHTML = submissionPreviewHtml(data && data.content ? data.content : data);
			holder.dataset.previewState = 'loaded';
			if (button) button.hidden = true;
		} catch (error) {
			holder.dataset.previewState = 'error';
			body.innerHTML = '<p class="ojp-notice ojp-notice--danger" role="alert">바로보기를 열지 못했습니다: ' + escapeHtml(messageFrom(error)) + '</p>';
			if (button) { button.disabled = false; button.hidden = false; button.textContent = '다시 시도'; }
		}
	}
	function findingsHtml(evaluation) {
		if (!evaluation) return '<p>검사: 대기 또는 결과 없음</p>';
		var findings = Array.isArray(evaluation.findings) ? evaluation.findings : [];
		var list = findings.slice(0, 20).map(function (finding) {
			return '<li><code>' + escapeHtml(finding.code || '-') + '</code> '
				+ escapeHtml(finding.message || '') + '</li>';
		}).join('');
		return '<p>검사: <strong>' + escapeHtml(statusLabel(evaluation.status)) + '</strong> · '
			+ escapeHtml(evaluation.evaluator || '-') + ' ' + escapeHtml(evaluation.evaluatorVersion || '') + '</p>'
			+ (list ? '<ul>' + list + '</ul>' : '<p class="oj-ml-meta">검사에서 지적된 항목이 없습니다.</p>')
			+ (findings.length > 20 ? '<p class="oj-ml-meta">나머지 ' + escapeHtml(findings.length - 20) + '개 항목은 생략했습니다.</p>' : '');
	}

	function resultReviewForDisplay(row, mayReview) {
		var attempt = row && row.attempt ? row.attempt : null;
		var review = attempt && attempt.review ? attempt.review : null;
		if (!review) return null;
		return ((mayReview && row.canEvaluate === true) || review.status === 'released') ? review : null;
	}

	function aiReviewCodeMessage(code) {
		var messages = {
			OJ_ML_AI_UNAVAILABLE: 'AI 평가 플러그인에 연결할 수 없습니다. 활성화 상태와 연결 설정을 확인하세요.',
			OJ_ML_AI_RUBRIC_REQUIRED: '과제에 항목별 평가 기준과 배점을 먼저 설정하세요.',
			OJ_ML_AI_EVIDENCE_INCOMPLETE: '안전 요약에 생략된 내용이 있어 AI 평가를 요청할 수 없습니다. 제출 바로보기로 직접 검토하세요.',
			OJ_ML_AI_EVIDENCE_TOO_LARGE: 'AI 평가 자료가 제한을 초과했습니다. 이 제출물은 직접 평가하세요.',
			OJ_ML_AI_EVIDENCE_INVALID: '제출 원본의 무결성 기록을 확인할 수 없습니다. 파일 검사 상태를 확인하세요.',
			OJ_ML_AI_SUBMISSION_NOT_READY: '서버 검사를 통과한 제출물만 AI 평가를 요청할 수 있습니다.',
			OJ_ML_AI_RESULT_NOT_READY: '아직 반영할 수 있는 AI 결과가 없습니다. 진행 상태를 확인하세요.',
			OJ_ML_AI_RESULT_STALE: 'AI 결과와 현재 제출 정보가 다릅니다. 진행 상태를 확인하고 필요한 경우 다시 평가하세요.',
			OJ_ML_AI_RESULT_INVALID: 'AI 결과의 평가 항목이나 점수를 검증하지 못했습니다. 제출 내용을 직접 검토하세요.',
			OJ_ML_AI_RUBRIC_LEVEL_MISMATCH: 'AI가 루브릭에 없는 점수를 제안하여 반영을 차단했습니다. 아래 등급별 기준을 보고 교사가 직접 등급을 선택해 주세요.',
			OJ_ML_AI_LEVEL_SCORE_INVALID: 'AI가 루브릭에 없는 점수를 제안하여 반영을 차단했습니다. 아래 등급별 기준을 보고 교사가 직접 등급을 선택해 주세요.',
			OJ_ML_AI_REVIEW_REQUIRED: '근거가 부족한 항목은 교사가 직접 확인해야 합니다.',
			OJ_ML_AI_INTERNAL_ERROR: 'AI 평가 연결을 처리하지 못했습니다. 잠시 후 상태를 다시 확인하세요.',
			OJ_ML_REVIEW_VERSION_CONFLICT: '다른 곳에서 교사 평가가 변경되었습니다. 현재 입력을 별도로 보관하고 최신 평가를 확인하세요.',
			OJ_ML_REVIEW_VERSION_INVALID: '교사 평가 버전을 확인할 수 없습니다. 현재 입력을 별도로 보관하고 최신 평가를 확인하세요.',
			OJ_ML_AI_ALREADY_APPLIED: '이 AI 결과는 이미 반영되어 학생에게 공개되었습니다. 공개된 평가를 같은 AI 초안으로 덮어쓸 수 없습니다.',
			OJ_ML_AUTHENTICATION_REQUIRED: '로그인이 만료되었습니다. 현재 입력을 별도로 보관한 뒤 다시 로그인하세요.',
			OJ_ML_REST_NONCE_INVALID: '보안 토큰이 만료되었습니다. 현재 입력을 별도로 보관한 뒤 페이지를 다시 여세요.',
			rest_cookie_invalid_nonce: '보안 토큰이 만료되었습니다. 현재 입력을 별도로 보관한 뒤 페이지를 다시 여세요.',
			OJ_ML_CAPABILITY_REQUIRED: '이 제출물을 평가할 권한이 없습니다. 교사 권한을 확인하세요.',
			OJ_ML_CONTEXT_ACCESS_DENIED: '해당 수업이나 학생의 제출물을 평가할 권한이 없습니다.',
			OJ_ML_MAINTENANCE: 'ML 실습 점검 중입니다. 입력을 보관하고 잠시 후 다시 시도하세요.',
			rest_no_route: 'AI 평가 연결 경로를 찾지 못했습니다. ML 실습 플러그인 업데이트 상태를 확인하세요.',
			ml_ai_routing_required: 'OJ Platform의 AI 작업 배정에서 ‘ML 실습 제출 평가’ 작업에 제공자와 모델을 먼저 연결하세요.',
			ml_ai_source_unavailable: 'AI에 전달할 제출 자료를 확인하지 못했습니다. 원본 검사 상태와 평가 권한을 확인하세요.',
			ml_ai_consent_required: 'AI 설정이 변경되었거나 전송 동의가 없습니다. 진행 상태 확인 후 자료 전송에 다시 동의하세요.',
			ml_ai_consent_changed: '대기 중 AI 연결 설정이 변경되어 실행을 중단했습니다. 현재 설정을 확인하고 다시 동의하세요.',
			ml_ai_interrupted: 'AI 처리 도중 연결이 중단되어 결과를 확인할 수 없습니다. 이미 발생한 사용 요금은 별도로 확인하세요.',
			ml_ai_result_unavailable: 'AI 분석 사본이 만료되었거나 조회할 수 없습니다. 이미 저장한 교사 평가는 유지됩니다.',
			ml_ai_enqueue_failed: 'AI 평가 대기열에 등록하지 못했습니다. 진행 상태를 먼저 확인한 뒤 다시 요청하세요.'
		};
		return Object.prototype.hasOwnProperty.call(messages, code) ? messages[code] : '';
	}

	function aiReviewErrorMessage(error) {
		return (aiReviewCodeMessage(error && error.code) || 'AI 평가 요청을 완료하지 못했습니다. 연결과 진행 상태를 확인하세요.')
			+ (error && error.correlationId ? ' (문의 코드: ' + error.correlationId + ')' : '');
	}
	function evidenceReadinessHtml(value) {
		var entries = Array.isArray(value) ? value : value && Array.isArray(value.criteria) ? value.criteria : [];
		if (!entries.length) return '';
		var flagged = entries.filter(function (entry) { return entry.status === 'needs_review'; }).length;
		return '<details class="oj-ml-evidence-readiness"' + (flagged ? ' open' : '') + '><summary>평가 근거 · ' + (flagged ? '교사 확인 필요 ' + flagged + '항목' : '준비됨') + '</summary><ul>'
			+ entries.slice(0, 25).map(function (entry) {
				var reasons = Array.isArray(entry.reasons) ? entry.reasons : [];
				return '<li><strong>' + escapeHtml(entry.title || entry.id || '평가 항목') + '</strong> · ' + (entry.status === 'needs_review' ? '교사 확인 필요' : '근거 준비됨')
					+ (reasons.length ? '<ul>' + reasons.slice(0, 10).map(function (reason) { return '<li>' + escapeHtml(reason) + '</li>'; }).join('') + '</ul>' : '') + '</li>';
			}).join('') + '</ul><p class="oj-ml-meta">근거 준비는 선택의 적절성이나 성능 검증을 뜻하지 않습니다. 부족한 항목은 0점이 아닌 교사 확인 대상으로 남깁니다.</p></details>';
	}

	function aiReviewBodyHtml(data) {
		data = data || {};
		var result = data.result || null;
		var state = result && result.state || 'none';
		var labels = { none: '평가 전', queued: '평가 대기', running: 'AI 분석 중', ready: '교사 검토 대기', failed: 'AI 평가 실패', stale: '평가 조건 변경 · 재평가 필요', needs_review: '자료 확인 필요', unavailable: 'AI 결과 조회 불가' };
		var html = '<p><strong>' + escapeHtml(labels[state] || '상태 확인 필요') + '</strong></p>';
		html += evidenceReadinessHtml(data.evidenceReadiness);
		var requiresTeacherReview = result && Array.isArray(result.criteriaScores) && result.criteriaScores.some(function (criterion) { return criterion.reviewStatus === 'needs_review'; });
		if (data.available !== true) html += '<p class="oj-ml-meta">' + escapeHtml(aiReviewCodeMessage(data.code) || 'AI 평가를 사용할 수 없습니다. 연결·권한·평가 기준을 확인하세요.') + ' 기존 교사 평가는 계속 사용할 수 있습니다.</p>';
		if (result) {
			html += '<p class="oj-ml-meta">AI 평가 번호 ' + escapeHtml(result.id || '-') + ' · ' + escapeHtml(formatDate(result.createdAt)) + '</p>';
			var criteria = Array.isArray(result.criteriaScores) ? result.criteriaScores : [];
			if (criteria.length) html += '<ol class="oj-ml-ai-criteria">' + criteria.map(function (criterion) {
				var evidence = Array.isArray(criterion.evidence) ? criterion.evidence.join('\n') : criterion.evidence;
				var reviewNeeded = criterion.reviewStatus === 'needs_review';
				var reviewReasons = Array.isArray(criterion.reviewReasons) ? criterion.reviewReasons : [];
				return '<li><strong>' + escapeHtml(criterion.title || criterion.id || '평가 항목') + '</strong>'
					+ (reviewNeeded ? ' <span class="oj-ml-evidence-note">교사 확인 필요</span>' : criterion.score == null ? '' : ' · 제안 ' + escapeHtml(criterion.score) + '점')
					+ (!reviewNeeded && criterion.levelLabel ? ' · ' + escapeHtml(criterion.levelLabel) : '')
					+ (!reviewNeeded && criterion.levelDescription ? '<p class="oj-ml-rubric-selected-level"><strong>선택 등급 기준</strong> ' + escapeHtml(criterion.levelDescription) + '</p>' : '')
					+ (reviewNeeded && reviewReasons.length ? '<ul>' + reviewReasons.slice(0, 10).map(function (reason) { return '<li>' + escapeHtml(reason) + '</li>'; }).join('') + '</ul>' : '')
					+ '<p class="oj-ml-preview-answer">' + escapeHtml(criterion.feedback || '') + '</p>'
					+ (evidence ? '<p class="oj-ml-preview-answer"><strong>근거</strong> ' + escapeHtml(evidence) + '</p>' : '') + '</li>';
			}).join('') + '</ol>';
			if (result.score != null && !requiresTeacherReview) html += '<p><strong>제안 합계 ' + escapeHtml(result.score) + ' / ' + escapeHtml(result.maxScore) + '</strong></p>';
			if (result.feedback) html += '<p class="oj-ml-preview-answer">' + escapeHtml(result.feedback) + '</p>';
			if (Array.isArray(result.limitations) && result.limitations.length) html += '<p><strong>확인할 한계</strong></p><ul>' + result.limitations.map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('') + '</ul>';
			if (state === 'needs_review') html += '<p>자료가 부족하거나 검토가 필요하여 AI 점수를 반영할 수 없습니다. 제출 내용을 확인하고 아래에서 직접 평가하세요.</p>';
			if (state === 'failed') html += '<p>AI 평가 실패는 학생의 0점을 의미하지 않습니다. 설정을 확인한 뒤 재요청하거나 직접 평가하세요. 재요청 시 AI 사용 요금이 다시 발생할 수 있습니다.</p>';
			if (result.errorCode && aiReviewCodeMessage(result.errorCode)) html += '<p class="oj-ml-meta">' + escapeHtml(aiReviewCodeMessage(result.errorCode)) + '</p>';
			if (state === 'unavailable') html += '<p>보관된 AI 결과를 조회하지 못했습니다. 다시 동의하고 AI 분석을 재요청하거나 아래에서 직접 평가하세요. 재요청 시 AI 사용 요금이 다시 발생할 수 있으며, 기존 교사 평가는 유지됩니다.</p>';
		}
		if (data.available === true && ['none', 'failed', 'stale', 'unavailable'].indexOf(state) !== -1) {
			html += '<label class="oj-ml-ai-consent"><input type="checkbox" data-oj-ml-ai-consent> 데이터 설명·통계 요약·프로젝트 요약·포트폴리오·현재 및 실험별 설정·실행 기록과 지표를 설정된 AI에 전달하는 데 동의합니다. 설명·답안과 열 이름·설정 문자열에 개인정보나 원본 데이터 행을 넣지 않았는지 확인했습니다.</label>'
				+ '<p class="oj-ml-meta">원본 데이터셋·모델 파일은 전송하거나 실행하지 않습니다. 학생이 기록한 성능 지표는 서버 재실행으로 검증한 값이 아닙니다.</p>';
		}
		html += '<div class="oj-ml-actions"><button type="button" data-variant="tinted" data-oj-ml-ai-action="status">진행 상태 확인</button>';
		if (data.available === true && ['none', 'failed', 'stale', 'unavailable'].indexOf(state) !== -1) html += '<button type="button" data-variant="primary" data-oj-ml-ai-action="enqueue">' + (state === 'none' ? 'AI 평가 요청' : 'AI 평가 다시 요청') + '</button>';
		if (data.available === true && state === 'ready' && !requiresTeacherReview) html += '<button type="button" data-variant="primary" data-oj-ml-ai-action="apply">비공개 교사 초안에 반영</button>';
		return html + '</div>';
	}

	function applyAiReviewToForm(form, review) {
		if (!form || !review || !Number.isInteger(Number(review.version)) || Number(review.version) < 1) throw new Error('반영된 평가 버전을 확인할 수 없습니다. 현재 입력을 보존했으므로 최신 평가를 다시 확인하세요.');
		var details = review.details || {};
		var criteria = Array.isArray(details.criteriaScores) ? details.criteriaScores : [];
		// Validate every level before changing any unsaved teacher input.
		form.querySelectorAll('[data-rubric-score-item]').forEach(function (row) {
			var select = row.querySelector('[data-rubric-level]');
			if (!select) return;
			var entry = criteria.find(function (item) { return item.id === row.dataset.criterionId; });
			if (!entry || (entry.score != null && (!entry.levelId || !Array.from(select.options).some(function (option) { return option.value === entry.levelId; })))) {
				throw new Error('AI 결과의 선택 등급이 현재 루브릭과 다릅니다. 입력은 보존했으므로 평가를 다시 확인하세요.');
			}
		});
		form.querySelectorAll('[data-rubric-score-item]').forEach(function (row) {
			var entry = criteria.find(function (item) { return item.id === row.dataset.criterionId; }) || {};
			var select = row.querySelector('[data-rubric-level]');
			if (select) select.value = entry.levelId || '';
			row.querySelector('[data-rubric-score]').value = entry.score == null ? '' : String(entry.score);
			row.querySelector('[data-rubric-feedback]').value = entry.feedback || '';
		});
		['score', 'maxScore', 'feedback', 'expectedVersion'].forEach(function (name) {
			var field = form.elements[name];
			var value = name === 'expectedVersion' ? review.version : review[name];
			if (field) field.value = value == null ? '' : String(value);
		});
		var version = form.querySelector('[data-oj-ml-review-version]');
		if (version) version.textContent = '현재 평가 버전: ' + review.version + ' · 상태: 비공개 초안';
		var rubricArea = form.querySelector('[data-rubric-review]');
		(rubricArea || form).dispatchEvent(new Event('input', { bubbles: true }));
	}

	function bindAiReview(holder, api) {
		if (!holder || holder.dataset.aiBound) return;
		holder.dataset.aiBound = '1';
		holder.addEventListener('toggle', function (event) {
			if (event.target === holder && holder.open && holder.dataset.aiLoaded !== '1') loadAiReview(holder, api, 'status');
		});
		holder.addEventListener('click', async function (event) {
			var button = event.target.closest('[data-oj-ml-ai-action]');
			if (!button || !holder.contains(button)) return;
			await loadAiReview(holder, api, button.dataset.ojMlAiAction);
		});
	}

	async function loadAiReview(holder, api, action) {
		if (!holder || holder.dataset.aiBusy === '1') return;
		action = action || 'status';
		var note = holder.querySelector('[data-oj-ml-ai-status]');
		var body = holder.querySelector('[data-oj-ml-ai-body]');
		var form = holder.parentElement.querySelector('[data-oj-ml-review-form]');
		var data = holder.ojMlAiData || {};
		var payload = { action: action };
		if (action === 'enqueue') {
			var consent = holder.querySelector('[data-oj-ml-ai-consent]');
			if (data.available !== true || !consent || !consent.checked || !data.consentFingerprint) {
				note.textContent = '전달할 자료와 AI 설정을 확인하고 동의 항목을 선택한 뒤 요청하세요.';
				return;
			}
			payload.consent = { approved: true, routingFingerprint: data.consentFingerprint };
		}
		if (action === 'apply') {
			if (!form || form.dataset.reviewSaving === '1' || !data.result || data.result.state !== 'ready') return;
			if (holder.dataset.aiAppliedEvaluation === String(data.result.id)) return;
			if (!await confirmAction({ title: 'AI 제안을 비공개 교사 초안에 반영할까요?', message: '현재 입력한 점수와 피드백을 AI 제안으로 바꾸고 새 비공개 버전으로 저장합니다. 학생에게는 아직 공개되지 않습니다. 내용을 검토한 뒤 별도로 공개하세요.', confirmLabel: '초안에 반영' })) return;
			if (holder.dataset.aiBusy === '1' || form.dataset.reviewSaving === '1') return;
			payload.evaluationId = data.result.id;
			payload.inputHash = data.result.inputHash;
			payload.expectedVersion = Number(form.elements.expectedVersion.value || 0);
		}
		holder.dataset.aiBusy = '1';
		holder.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
		var previousDisabled = [];
		if (action === 'apply') form.querySelectorAll('input, textarea, select, button').forEach(function (control) {
			previousDisabled.push([control, control.disabled]); control.disabled = true;
		});
		note.textContent = action === 'apply' ? 'AI 제안을 비공개 초안에 저장하는 중입니다.' : 'AI 평가 상태를 확인하는 중입니다.';
		try {
			var response = await api.post('console/attempts/' + encodeURIComponent(holder.dataset.attemptId) + '/ai-review', payload);
			if (holder.isConnected === false) return;
			if (action === 'apply') {
				applyAiReviewToForm(form, response.review);
				holder.dataset.aiAppliedEvaluation = String(data.result.id);
				note.textContent = 'AI 제안을 비공개 교사 초안에 저장했습니다. 아래 점수와 피드백을 검토·수정한 뒤 학생에게 공개하세요.';
			} else {
				holder.ojMlAiData = response || {};
				body.innerHTML = aiReviewBodyHtml(response);
				holder.dataset.aiLoaded = '1';
				if (action === 'enqueue' && (!response || response.available !== true || !response.result)) {
					note.textContent = 'AI 평가 요청의 접수를 확인하지 못했습니다. ' + (aiReviewCodeMessage(response && response.code) || '진행 상태를 먼저 확인한 뒤 다시 요청하세요.');
				} else {
					note.textContent = action === 'enqueue' ? 'AI 평가 요청 상태를 불러왔습니다. 아래 진행 상태와 결과를 확인하세요.' : 'AI 상태를 확인했습니다. 교사 평가 입력은 그대로 유지됩니다.';
				}
			}
		} catch (error) {
			note.textContent = aiReviewErrorMessage(error) + ' 기존 교사 평가 입력은 유지했습니다. 상태를 다시 확인하거나 직접 평가하세요.';
		} finally {
			holder.dataset.aiBusy = '0';
			holder.querySelectorAll('button').forEach(function (button) {
				button.disabled = button.dataset.ojMlAiAction === 'apply' && holder.ojMlAiData && holder.ojMlAiData.result
					&& holder.dataset.aiAppliedEvaluation === String(holder.ojMlAiData.result.id);
			});
			previousDisabled.forEach(function (entry) { entry[0].disabled = entry[1]; });
		}
	}

	function resultDetailsRowHtml(row, mayReview) {
		var attempt = row.attempt;
		if (!attempt) return '';
		var canEvaluate = mayReview && row.canEvaluate === true;
		var review = resultReviewForDisplay(row, mayReview);
		var artifact = attempt.artifact || null;
		var canReview = canEvaluate && ['accepted', 'under_review', 'graded'].indexOf(attempt.status) !== -1;
		var canPreview = canEvaluate && artifact && ['accepted', 'under_review', 'graded'].indexOf(attempt.status) !== -1;
		var downloadSpec = canEvaluate && artifact && artifact.download ? artifact.download : null;
		var download = downloadSpec
			? '<form class="oj-ml-download-form" method="post" action="' + escapeHtml(downloadSpec.url) + '">'
				+ '<input type="hidden" name="action" value="' + escapeHtml(downloadSpec.action) + '">'
				+ '<input type="hidden" name="attempt" value="' + escapeHtml(downloadSpec.attempt) + '">'
				+ '<input type="hidden" name="_wpnonce" value="' + escapeHtml(downloadSpec.nonce) + '">'
				+ '<button type="submit">원본 .mlpx 다운로드</button></form>'
			: '';
		var preview = canPreview
			? '<section class="oj-ml-preview-shell" data-oj-ml-preview data-attempt-id="' + escapeHtml(attempt.id) + '" data-preview-state="idle">'
				+ '<div class="oj-ml-panel-heading"><h4>제출 내용</h4><span class="oj-ml-meta">읽기 전용</span></div>'
				+ '<button type="button" data-variant="tinted" data-oj-ml-preview-load>바로보기 불러오기</button>'
				+ '<div data-oj-ml-preview-body aria-live="polite"></div></section>'
			: '';
		var canReinspect = canEvaluate && ['system_pending', 'quarantined'].indexOf(attempt.status) !== -1;
		var prefix = 'oj-ml-review-' + String(attempt.id || '').replace(/[^A-Za-z0-9_-]/g, '') + '-' + Math.random().toString(36).slice(2, 10);
		var reviewForm = '';
		var criterionFields = typeof window !== 'undefined' && window.OJMLRubric ? window.OJMLRubric.reviewFields(attempt.rubric, review) : '';
		if (canReview) {
			reviewForm = '<details class="oj-ml-ai-review" data-oj-ml-ai-review data-attempt-id="' + escapeHtml(attempt.id) + '"><summary>AI 평가 보조 <span class="oj-ml-section-caption">선택</span></summary>'
				+ '<p class="oj-ml-meta">AI 없이도 직접 채점할 수 있습니다. 동의 후 분석하며 학생에게 자동 공개되지 않습니다.</p>'
				+ '<details class="oj-ml-ai-scope"><summary>AI에 전달되는 자료와 제외되는 자료</summary><dl class="oj-ml-preview-dl">'
				+ '<dt>과제·평가 기준</dt><dd>과제 안내, 항목별 기준과 배점</dd><dt>프로젝트 정보</dt><dd>앱·형식 버전, 학습·데이터 유형, 기록 개수 (이름·ID 제외)</dd>'
				+ '<dt>포트폴리오</dt><dd>문항과 답안 텍스트 (첨부 파일 제외)</dd><dt>실험·설정</dt><dd>현재 및 실험 당시의 타깃·특성·전처리, 데이터 분할, 선택 모델과 매개변수</dd>'
				+ '<dt>실행 기록</dt><dd>알고리즘·상태·매개변수·지표·엔진, 실패·경고 코드와 모델 메타데이터</dd><dt>전송 제외</dt><dd>원본 데이터 행, 모델 바이너리, 이미지·첨부, 프로젝트·실험 이름과 ID, 학생 계정 정보, 파일 경로와 원시 로그</dd></dl>'
				+ '<p class="oj-ml-meta">제출 파일에 기록되고 안전하게 추출된 정보만 전달합니다. 크기 한도나 생략 때문에 근거가 불완전하면 AI 요청을 중단하고 직접 평가하도록 안내합니다. 학습을 다시 실행하거나 지표의 진위를 검증하지는 않습니다. 자유서술·열 이름에 포함된 개인정보는 교사가 확인해야 합니다.</p></details>'
				+ '<div data-oj-ml-ai-body><button type="button" data-variant="tinted" data-oj-ml-ai-action="status">AI 평가 연결 확인</button></div>'
				+ '<p data-oj-ml-ai-status role="status" aria-live="polite"></p></details>'
				+ '<h4>교사 평가</h4><form class="oj-ml-form" data-oj-ml-review-form data-attempt-id="'
				+ escapeHtml(attempt.id) + '">'
				+ '<input type="hidden" name="expectedVersion" value="' + escapeHtml(review ? review.version : 0) + '">'
				+ (criterionFields || '<div class="oj-ml-form__row"><div class="oj-ml-field"><label for="' + escapeHtml(prefix)
				+ '-score">점수<input id="' + escapeHtml(prefix) + '-score" name="score" type="number" min="0" step="any" value="'
				+ escapeHtml(review && review.score != null ? review.score : '') + '"></label></div>'
				+ '<div class="oj-ml-field"><label for="' + escapeHtml(prefix) + '-max">만점<input id="'
				+ escapeHtml(prefix) + '-max" name="maxScore" type="number" min="0.000001" step="any" value="'
				+ escapeHtml(review && review.maxScore != null ? review.maxScore : '') + '"></label></div></div>')
				+ '<div class="oj-ml-field"><label for="' + escapeHtml(prefix) + '-feedback">피드백<textarea id="'
				+ escapeHtml(prefix) + '-feedback" name="feedback" maxlength="50000">'
				+ escapeHtml(review ? review.feedback : '') + '</textarea></label></div>'
				+ '<p class="oj-ml-meta" data-oj-ml-review-version>현재 평가 버전: ' + escapeHtml(review ? review.version : 0) + ' · 상태: '
				+ escapeHtml(review ? review.status : '없음') + '</p>'
				+ '<div class="oj-ml-actions oj-ml-form-footer"><button type="submit" data-variant="primary" data-review-action="save">평가 저장</button>'
				+ '<button type="submit" data-variant="tinted" data-review-action="release">저장 후 학생에게 공개</button></div></form>';
		}
		var reinspect = canReinspect
			? '<div class="oj-ml-actions"><button type="button" data-reinspect-id="' + escapeHtml(attempt.id)
				+ '">서버 사전검사 다시 실행</button></div>'
			: '';
		if (canEvaluate && attempt.canReconsider === true && attempt.evaluation && attempt.evaluation.id) {
			reinspect += '<details class="oj-ml-reconsider"><summary>기존 반려 제출 재검토 요청</summary><p>같은 원본 파일을 현재 검사기로 다시 확인합니다. 기존 검사·시도 횟수·평가 기록은 보존하며 통과를 보장하지 않습니다.</p><form data-reconsider-form data-attempt-id="' + escapeHtml(attempt.id) + '" data-evaluation-id="' + escapeHtml(attempt.evaluation.id) + '"><label>재검토 사유<input name="reason" maxlength="300" required placeholder="예: 검사기 업데이트 후 기존 서버 오류 재확인"></label><button type="submit">감사 기록을 남기고 재검토 요청</button><p data-reconsider-status role="status"></p></form></details>';
		}
		if (typeof window !== 'undefined' && window.OJMLTeacherWorkflow) return window.OJMLTeacherWorkflow.detail(row, {
			canEvaluate: canEvaluate, preview: preview, reviewForm: reviewForm, reinspect: reinspect,
			download: download, findings: findingsHtml(attempt.evaluation), facts: submissionFactsHtml(attempt.evaluation)
		});
		return '<tr><td colspan="6"><details data-oj-ml-result-details><summary>' + escapeHtml(attempt.attemptNo) + '차 제출 내용 및 평가 보기</summary>'
			+ '<p>ID: <code>' + escapeHtml(attempt.id || '-') + '</code> · 상태: <strong>'
			+ escapeHtml(statusLabel(attempt.status)) + '</strong>' + (attempt.late ? ' · 지각 제출' : '') + '</p>'
			+ '<p>접수: ' + escapeHtml(formatDate(attempt.receivedAt)) + ' · 클라이언트: '
			+ escapeHtml(attempt.clientAppVersion || '-') + ' · 형식 버전: ' + escapeHtml(attempt.formatVersion || '-') + '</p>'
			+ (artifact ? '<p>파일: ' + (canEvaluate && artifact.originalName ? '<code>' + escapeHtml(artifact.originalName) + '</code> · ' : '')
				+ escapeHtml(artifact.bytes) + ' bytes · SHA-256 <code>'
				+ escapeHtml(artifact.sha256) + '</code></p>' : '<p class="oj-ml-meta">접수된 제출 파일이 없습니다.</p>')
			+ (download ? '<div class="oj-ml-actions">' + download + '</div>' : '')
			+ (attempt.failure ? '<p>실패: <code>' + escapeHtml(attempt.failure.code || '-') + '</code></p>' : '')
			+ submissionFactsHtml(attempt.evaluation) + preview + findingsHtml(attempt.evaluation)
			+ reinspect + reviewForm + '</details></td></tr>';
	}

	async function renderConsoleResults(root, api) {
		var content = contentNode(root);
		var mayReview = root.dataset.canReview === '1';
		content.innerHTML = '<section class="oj-ml-panel"><h2>학생별 제출 결과</h2>'
			+ (mayReview ? '' : '<p class="ojp-notice ojp-notice--info" role="note">지금 계정은 결과를 보기만 할 수 있습니다.</p>') + '<div class="oj-ml-field"><label>과제 선택<select data-oj-ml-result-assignment></select></label></div><div data-oj-ml-assignment-limit></div><div data-oj-ml-results></div></section>';
		if (window.OJMLTeacherWorkflow) content.insertAdjacentHTML('afterbegin', window.OJMLTeacherWorkflow.navigation(root));
		var filterNode = document.createElement('form');
		filterNode.className = 'oj-ml-actions';
		filterNode.innerHTML = '<label>학생 검색 <input name="search" type="search" maxlength="100" placeholder="이름 또는 계정"></label>'
			+ '<label>상태 <select name="status"><option value="">전체</option>'
			+ ['not_submitted', 'needs_resubmit', 'quarantined', 'system_pending', 'accepted', 'under_review', 'graded'].map(function (status) {
				return '<option value="' + status + '">' + escapeHtml(statusLabel(status)) + '</option>';
			}).join('') + '</select></label><button type="submit" data-variant="tinted">검색</button><button type="button" data-export-csv>CSV 내보내기</button>';
		content.querySelector('[data-oj-ml-results]').before(filterNode);
		var savedFilters = new URLSearchParams(window.location.search);
		filterNode.elements.search.value = savedFilters.get('ml_search') || '';
		filterNode.elements.status.value = savedFilters.get('ml_status') || '';
		function resultFilterQuery() {
			return '&search=' + encodeURIComponent(filterNode.elements.search.value.trim()) + '&status=' + encodeURIComponent(filterNode.elements.status.value);
		}
		var select = content.querySelector('[data-oj-ml-result-assignment]');
		var results = content.querySelector('[data-oj-ml-results]');
		var assignmentLimit = content.querySelector('[data-oj-ml-assignment-limit]');
		var resultPage = 1;
		var resultPerPage = 100;
		var resultMaxPage = 1;
		function setResultControlsBusy(busy) {
			select.disabled = busy;
			filterNode.querySelectorAll('button, input, select').forEach(function (control) { control.disabled = busy; });
			results.querySelectorAll('[data-results-page]').forEach(function (button) {
				var direction = button.dataset.resultsPage;
				button.disabled = busy
					|| (direction === 'previous' && resultPage <= 1)
					|| (direction === 'next' && resultPage >= resultMaxPage);
			});
		}
		var resultRefreshGuard = createRefreshGuard(setResultControlsBusy);
		var listData = await loadAllReportAssignments(api);
		var items = assignmentItems(listData);
		select.innerHTML = '<option value="">과제를 선택하세요</option>' + items.map(function (item) {
			return '<option value="' + escapeHtml(item.id || item.publicId) + '">' + escapeHtml(item.title) + '</option>';
		}).join('');
		assignmentLimit.innerHTML = listData.limited ? pageNoticeHtml(listData, items.length, '과제') : '';
		if (root.dataset.assignmentId) {
			if (!items.some(function (item) { return String(item.id || item.publicId) === String(root.dataset.assignmentId); })) {
				select.insertAdjacentHTML('beforeend', '<option value="' + escapeHtml(root.dataset.assignmentId)
					+ '">과제 #' + escapeHtml(root.dataset.assignmentId) + '</option>');
			}
			select.value = root.dataset.assignmentId;
		}

		async function refresh() {
			var requestedAssignment = String(select.value || '');
			var requestedFilter = resultFilterQuery();
			var requestedPage = resultPage;
			var refreshToken = resultRefreshGuard.begin();
			try {
			if (!requestedAssignment) {
				results.innerHTML = emptyHtml('과제를 선택하세요', '선택한 과제의 명단과 제출 결과가 표시됩니다.');
				return true;
			}
			setStatus(root, '학생별 결과를 불러오는 중입니다.');
			var data = await api.get('console/assignments/' + encodeURIComponent(requestedAssignment) + '/results?per_page='
				+ resultPerPage + '&page=' + requestedPage + requestedFilter);
			if (!resultRefreshGuard.isCurrent(refreshToken)
				|| String(select.value || '') !== requestedAssignment || resultPage !== requestedPage || resultFilterQuery() !== requestedFilter) return false;
			var rows = assignmentItems(data);
			var total = data && Number.isFinite(Number(data.total)) ? Number(data.total) : ((requestedPage - 1) * resultPerPage) + rows.length;
			var maxPage = Math.max(1, Math.ceil(total / resultPerPage));
			resultMaxPage = maxPage;
			if (requestedPage > maxPage) {
				resultPage = maxPage;
				return await refresh();
			}
			if (!rows.length) {
				results.innerHTML = emptyHtml('표시할 학생이나 제출 결과가 없습니다', '검색어나 상태 조건을 바꿔 보세요.');
				setStatus(root, '표시할 결과가 없습니다.');
				return true;
			}
			results.innerHTML = (window.OJMLTeacherWorkflow ? window.OJMLTeacherWorkflow.pageStats(rows) : '') + resultPaginationHtml(total, rows.length, requestedPage, resultPerPage)
				+ '<div class="oj-ml-table-wrap ojp-table-wrap oj-ml-results-table"><table class="oj-ml-table ojp-table"><thead><tr><th>학생</th><th>상태</th><th>시도</th><th>접수</th><th>검사</th><th>점수</th></tr></thead><tbody>' + rows.map(function (row) {
				var attempt = row.attempt || null;
				var review = resultReviewForDisplay(row, mayReview);
				var main = '<tr class="oj-ml-student-row"><td data-label="학생">' + escapeHtml(row.displayName || ('사용자 #' + row.userId))
					+ '<br><span class="oj-ml-meta">ID ' + escapeHtml(row.userId) + '</span></td><td data-label="상태">'
					+ badgeHtml(attempt ? statusLabel(attempt.status) : '미제출', attempt ? statusTone(attempt.status) : 'neutral') + '</td><td data-label="시도">'
					+ escapeHtml(attempt ? attempt.attemptNo : 0) + ' / 사용 ' + escapeHtml(row.attemptsUsed || 0)
					+ '</td><td data-label="접수">' + escapeHtml(formatDate(attempt && attempt.receivedAt)) + (attempt && attempt.late ? ' (지각)' : '')
					+ '</td><td data-label="검사">' + escapeHtml(attempt && attempt.evaluation ? statusLabel(attempt.evaluation.status) : '-')
					+ '</td><td data-label="점수·작업">' + escapeHtml(review && review.score != null ? review.score + ' / ' + review.maxScore : '-')
					+ '<div class="oj-ml-row-actions">' + (attempt ? '<button type="button" data-variant="tinted" data-result-open="view">'
						+ (['accepted', 'under_review', 'graded'].indexOf(attempt.status) !== -1 ? '제출 보기·평가' : '상태·해결 방법') + '</button>' : '')
					+ '<button type="button" data-result-open="history" data-result-user="' + escapeHtml(row.userId) + '">제출 이력</button>'
					+ '<button type="button" data-result-open="allowance" data-result-user="' + escapeHtml(row.userId) + '">재제출 설정</button></div></td></tr>';
				return main + resultDetailsRowHtml(row, mayReview) + '<tr><td colspan="6" data-learner-tools="' + escapeHtml(row.userId) + '"></td></tr>';
			}).join('') + '</tbody></table></div>';

			bindResultContent(results);
			bindLearnerTools(rows, requestedAssignment, data.assignment || {});
			results.querySelectorAll('[data-result-open]').forEach(function (button) {
				button.addEventListener('click', function () {
					var target = button.dataset.resultOpen === 'view'
						? button.closest('tr').nextElementSibling.querySelector('[data-oj-ml-result-details]')
						: results.querySelector('[data-learner-tools="' + button.dataset.resultUser + '"] [data-' + button.dataset.resultOpen + '-panel]');
					if (!target) return;
					target.open = true;
					target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					target.querySelector('summary').focus();
					if (button.dataset.resultOpen === 'history') target.querySelector('[data-load-history]').click();
				});
			});
			results.querySelectorAll('[data-results-page]').forEach(function (button) {
				button.addEventListener('click', async function () {
					if (resultRefreshGuard.isBusy()) return;
					if (button.dataset.resultsPage === 'previous' && resultPage > 1) resultPage -= 1;
					else if (button.dataset.resultsPage === 'next' && resultPage < maxPage) resultPage += 1;
					else return;
					button.disabled = true;
					try {
						await refresh();
					} catch (error) {
						setStatus(root, messageFrom(error), 'error');
					} finally {
						setResultControlsBusy(resultRefreshGuard.isBusy());
					}
				});
			});
			var first = ((requestedPage - 1) * resultPerPage) + 1;
			setStatus(root, '전체 ' + total + '명 중 ' + first + '–' + (first + rows.length - 1) + '명의 상태를 불러왔습니다.', 'success');
			return true;
			} finally {
				resultRefreshGuard.finish();
			}
		}


		filterNode.addEventListener('submit', async function (event) {
			event.preventDefault();
			resultPage = 1;
			var url = new URL(window.location.href);
			url.searchParams.set('ml_search', filterNode.elements.search.value.trim());
			url.searchParams.set('ml_status', filterNode.elements.status.value);
			window.history.replaceState(null, '', url.href);
			try { await refresh(); } catch (error) { setStatus(root, messageFrom(error), 'error'); }
		});
		filterNode.querySelector('[data-export-csv]').addEventListener('click', async function () {
			if (!select.value) { setStatus(root, '먼저 과제를 선택하세요.', 'error'); return; }
			var assignmentId = select.value;
			var query = resultFilterQuery();
			setResultControlsBusy(true);
			try {
				var records = [['학생', '계정 ID', '상태', '시도', '사용 횟수', '접수', '검사', '점수', '만점']];
				for (var page = 1; page <= 50; page += 1) {
					var data = await api.get('console/assignments/' + encodeURIComponent(assignmentId) + '/results?per_page=100&page=' + page + query);
					data.items.forEach(function (row) {
						var attempt = row.attempt || {};
						var review = resultReviewForDisplay(row, mayReview) || {};
						records.push([row.displayName, row.userId, statusLabel(attempt.status || 'not_submitted'), attempt.attemptNo || 0,
							row.attemptsUsed, attempt.receivedAt || '', statusLabel((attempt.evaluation || {}).status), review.score == null ? '' : review.score, review.maxScore == null ? '' : review.maxScore]);
					});
					if (page * 100 >= data.total) break;
					if (page === 50) throw new Error('내보내기 인원이 지원 한도를 초과합니다.');
				}

				var csv = '\ufeff' + records.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
				var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
				var link = document.createElement('a');
				link.href = url; link.download = 'ml-results-' + assignmentId + '.csv'; link.click();
				window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
				setStatus(root, '성적표를 CSV로 내보냈습니다. 다운로드한 학생 정보는 기관 보관 정책에 따라 관리하세요.', 'success');
			} catch (error) { setStatus(root, messageFrom(error), 'error'); }
			finally { setResultControlsBusy(resultRefreshGuard.isBusy()); }
		});

		function bindResultContent(container) {
			container.querySelectorAll('[data-reconsider-form]').forEach(function (form) {
				form.addEventListener('submit', async function (event) {
					event.preventDefault(); var button = form.querySelector('button'), note = form.querySelector('[data-reconsider-status]');
					var reason = form.elements.reason.value.trim(); if (!reason) return;
					if (form.dataset.sentReason && form.dataset.sentReason !== reason) { delete form.dataset.requestId; }
					form.dataset.requestId = form.dataset.requestId || randomKey(); form.dataset.sentReason = reason; button.disabled = true;
					try { await api.post('console/attempts/' + encodeURIComponent(form.dataset.attemptId) + '/reconsider', { reason: reason, expectedEvaluationId: Number(form.dataset.evaluationId), idempotencyKey: form.dataset.requestId }); await refresh(); setStatus(root, '재검토 대기열에 등록했습니다. 원본과 기존 기록은 보존됩니다.', 'success'); }
					catch (error) { note.textContent = messageFrom(error) + ' 응답이 불확실하면 같은 사유로 재요청해도 중복 처리하지 않습니다.'; button.disabled = false; }
				});
			});
			container.querySelectorAll('[data-oj-ml-preview]').forEach(function (holder) {
				var button = holder.querySelector('[data-oj-ml-preview-load]');
				if (button) button.addEventListener('click', function () { loadSubmissionPreview(holder, api); });
			});
			container.querySelectorAll('[data-oj-ml-ai-review]').forEach(function (holder) { bindAiReview(holder, api); });
			container.querySelectorAll('[data-oj-ml-result-details]').forEach(function (details) {
				details.addEventListener('toggle', function () {
					if (!details.open) return;
					loadSubmissionPreview(details.querySelector('[data-oj-ml-preview]'), api);
				});
			});
			container.querySelectorAll('[data-oj-ml-review-form]').forEach(function (form) {
				if (window.OJMLRubric) window.OJMLRubric.bindReview(form);
				form.addEventListener('submit', async function (event) {
					event.preventDefault();
					if (form.dataset.reviewSaving === '1') return;
					var values = new FormData(form);
					var score = numericOrNull(values.get('score'));
					var maxScore = numericOrNull(values.get('maxScore'));
					var criteriaPayload = null;
					try { criteriaPayload = window.OJMLRubric ? window.OJMLRubric.readReview(form) : null; }
					catch (error) { setStatus(root, error.message, 'error'); return; }
					if (Number.isNaN(score) || Number.isNaN(maxScore) || (score === null) !== (maxScore === null)
						|| (score !== null && (score < 0 || maxScore <= 0 || score > maxScore))) {
						setStatus(root, '점수와 만점을 함께 입력하고 올바른 범위를 확인해 주세요.', 'error');
						return;
					}
					var submitter = event.submitter || document.activeElement;
					var action = submitter && submitter.dataset ? submitter.dataset.reviewAction : 'save';
					var previousDisabled = [], previousBusy = form.getAttribute('aria-busy');
					form.dataset.reviewSaving = '1';
					form.setAttribute('aria-busy', 'true');
					form.querySelectorAll('input, textarea, select, button').forEach(function (control) {
						previousDisabled.push([control, control.disabled]); control.disabled = true;
					});
					try {
						var saved = await api.post('console/attempts/' + encodeURIComponent(form.dataset.attemptId) + '/review', Object.assign({
							score: score,
							maxScore: maxScore,
							feedback: String(values.get('feedback') || ''),
							expectedVersion: Number(values.get('expectedVersion') || 0)
						}, criteriaPayload || {}));
						var savedReview = saved.review || saved;
						form.elements.expectedVersion.value = String(savedReview.version);
						if (action === 'release') {
							await api.post('console/attempts/' + encodeURIComponent(form.dataset.attemptId) + '/release', {
								expectedVersion: Number(savedReview.version)
							});
						}
						await refresh();
						setStatus(
							root,
							action === 'release' ? '평가를 저장하고 학생에게 공개했습니다.' : '평가 초안을 새 버전으로 저장했습니다.',
							'success'
						);
					} catch (error) {
						setStatus(root, messageFrom(error) + ' 입력한 점수와 피드백은 유지했습니다. 버전 충돌이면 입력을 복사한 뒤 최신 평가를 다시 확인하세요.', 'error');
					} finally {
						previousDisabled.forEach(function (entry) { entry[0].disabled = entry[1]; });
						if (previousBusy === null) form.removeAttribute('aria-busy'); else form.setAttribute('aria-busy', previousBusy);
						delete form.dataset.reviewSaving;
					}
				});
			});

			container.querySelectorAll('[data-reinspect-id]').forEach(function (button) {
				button.addEventListener('click', async function () {
					button.disabled = true;
					try {
						setStatus(root, '서버 사전검사를 다시 실행하는 중입니다.');
						await api.post('console/attempts/' + encodeURIComponent(button.dataset.reinspectId) + '/reinspect', {});
						await refresh();
						setStatus(root, '서버 사전검사를 다시 실행했습니다.', 'success');
					} catch (error) {
						try {
							await refresh();
						} catch (ignored) {
							if (button.isConnected) button.disabled = false;
						}
						setStatus(root, messageFrom(error), 'error');
					}
				});
			});

		}

		function bindLearnerTools(rows, assignmentId, assignment) {
			results.querySelectorAll('[data-learner-tools]').forEach(function (holder) {
				var row = rows.find(function (item) { return String(item.userId) === holder.dataset.learnerTools; });
				if (!row) return;
				var allowance = row.allowance || {};
				holder.innerHTML = '<details><summary>전체 시도 이력 / 학생별 제출 설정</summary><button type="button" data-load-history>시도 이력 보기</button><div data-attempt-history></div>'
					+ (root.dataset.canManage === '1' && assignment.status === 'published' ? '<form class="oj-ml-form" data-allowance-form><h4>이 학생에게만 추가 허용</h4>'
						+ '<div class="oj-ml-field"><label>최대 시도 횟수<input type="number" name="maxAttempts" min="1" max="100" value="'
						+ escapeHtml(Math.min(100, Math.max(Number(assignment.maxAttempts || 1), Number(allowance.maxAttempts || 0), Number(row.attemptsUsed || 0) + 1))) + '" required></label></div>'
						+ '<div class="oj-ml-field"><label>개인 마감 연장(선택)<input type="datetime-local" name="dueAt" value="' + escapeHtml(dateTimeLocalValue(allowance.dueAt)) + '"></label></div>'
						+ '<div class="oj-ml-field"><label>변경 사유<input name="reason" maxlength="300" required></label></div><button type="submit">이 학생에게 허용</button></form>' : '') + '</details>';
				if (window.OJMLTeacherWorkflow) holder.innerHTML = window.OJMLTeacherWorkflow.tools(row, assignment, root.dataset.canManage === '1', dateTimeLocalValue);
				var historyPage = 1;
				var loadButton = holder.querySelector('[data-load-history]');
				async function loadHistory() {
					loadButton.disabled = true;
					try {
						var page = await api.get('console/assignments/' + encodeURIComponent(assignmentId) + '/learners/' + row.userId + '/history?page=' + historyPage);
						if (!holder.isConnected) return;
						var history = holder.querySelector('[data-attempt-history]');
						history.innerHTML = '<p>전체 ' + escapeHtml(page.total) + '회 · ' + historyPage + ' 페이지</p><div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table"><tbody>'
							+ page.items.map(function (attempt) { return resultDetailsRowHtml({ attempt: attempt, canEvaluate: attempt.canEvaluate }, mayReview); }).join('')
							+ '</tbody></table></div><button type="button" data-history-back' + (historyPage <= 1 ? ' disabled' : '') + '>이전</button>'
							+ '<button type="button" data-history-next' + (historyPage * page.perPage >= page.total ? ' disabled' : '') + '>다음</button>';
						bindResultContent(history);
						history.querySelector('[data-history-back]').onclick = function () { history.querySelectorAll('[data-history-back], [data-history-next]').forEach(function (b) { b.disabled = true; }); historyPage -= 1; loadHistory(); };
						history.querySelector('[data-history-next]').onclick = function () { history.querySelectorAll('[data-history-back], [data-history-next]').forEach(function (b) { b.disabled = true; }); historyPage += 1; loadHistory(); };
					} catch (error) { setStatus(root, messageFrom(error), 'error'); }
					finally { if (loadButton.isConnected) loadButton.disabled = false; }
				}
				loadButton.onclick = loadHistory;
				var form = holder.querySelector('[data-allowance-form]');
				if (form) form.addEventListener('submit', async function (event) {
					event.preventDefault();
					var values = new FormData(form);
					form.querySelector('button').disabled = true;
					try {
						await api.post('console/assignments/' + encodeURIComponent(assignmentId) + '/learners/' + row.userId + '/allowance', {
							expectedRevision: Number(allowance.revision || 0), maxAttempts: Number(values.get('maxAttempts')),
							dueAt: values.get('dueAt') ? new Date(String(values.get('dueAt'))).toISOString() : null, reason: String(values.get('reason') || '')
						});
						await refresh();
						setStatus(root, '이 학생의 제출 허용 설정을 저장했습니다.', 'success');
					} catch (error) { setStatus(root, messageFrom(error), 'error'); if (form.isConnected) form.querySelector('button').disabled = false; }
				});
			});
		}

		select.addEventListener('change', async function () {
			resultPage = 1;
			try {
				await refresh();
			} catch (error) {
				setStatus(root, messageFrom(error), 'error');
			} finally {
				setResultControlsBusy(resultRefreshGuard.isBusy());
			}
		});
		await refresh();
	}

	function runtimeCodeLabel(runtime) {
		if (runtime && runtime.state === 'ready' && runtime.code === '') return '없음 (정상)';
		if (runtime && typeof runtime.code === 'string') {
			var code = runtime.code.trim();
			if (code !== '') return code;
			if (runtime.code !== '') return 'invalid';
		}
		return 'unknown';
	}

	async function renderConsoleSettings(root, api) {
		var content = contentNode(root);
		setStatus(root, '서버 운영 상태를 확인하는 중입니다.');
		var responses = await Promise.all([api.get('console/health'), api.get('console/settings')]);
		var health = responses[0].health || {}, settings = responses[1].settings || {};
		var privacySources = { platform: '사이트 개인정보 처리방침', custom: 'ML 실습 전용 주소', filter: '기관 연결 필터', bundled: 'ML 실습 기본 안내' };
		var privacySource = privacySources[root.dataset.privacySource] || '현재 연결 주소';
		var migration = new URLSearchParams(window.location.search).get('migration');
		var migrationNotice = migration === 'updated'
			? '<div class="ojp-notice ojp-notice--success" role="status" data-ojp-flash>DB 업데이트를 완료했습니다.</div>'
			: migration === 'failed' ? '<div class="ojp-notice ojp-notice--danger" role="alert" data-ojp-flash>DB 업데이트에 실패했습니다. 서버 로그와 설치 안내를 확인하세요.</div>' : '';
		var migrateForm = root.dataset.adminPostUrl && root.dataset.migrateNonce
			? '<h3>DB 업데이트</h3><p>먼저 DB와 비공개 제출물을 함께 백업하고 학생 제출을 잠시 중지하세요. 실패한 업데이트는 다시 실행할 수 있습니다.</p>'
				+ '<form method="post" action="' + escapeHtml(root.dataset.adminPostUrl) + '" data-migrate>'
				+ '<input type="hidden" name="action" value="oj_ml_migrate"><input type="hidden" name="_wpnonce" value="' + escapeHtml(root.dataset.migrateNonce) + '">'
				+ '<div class="oj-ml-actions"><button type="submit">DB 업데이트 및 검증</button></div></form>'
			: '';
		content.innerHTML = migrationNotice + '<section class="oj-ml-settings-intro oj-ml-panel-heading"><div><h2>운영 설정</h2><p class="oj-ml-meta">모든 ML 실습에 적용됩니다.</p></div>'
			+ (root.dataset.canManage === '1' && root.dataset.consoleAssignmentsUrl ? '<a class="ojp-button ojp-button--tinted" href="' + escapeHtml(root.dataset.consoleAssignmentsUrl) + '">과제 설정</a>' : '') + '</section>'
			+ '<details class="oj-ml-panel oj-ml-settings-section" data-settings-section="health"' + (health.ok ? '' : ' open') + '><summary>서버 상태 · ' + (health.ok ? '정상' : '확인 필요') + '</summary>'
			+ '<p>학습은 학생 브라우저에서 실행됩니다. 서버에서는 제출 파일을 저장하고 실행 없이 검사합니다.</p>'
			+ '<p class="oj-ml-meta">버전 ' + escapeHtml(health.version || '') + ' · DB 스키마 ' + escapeHtml(health.schemaVersion || '확인 안 됨') + '</p>'
			+ '<div class="oj-ml-table-wrap ojp-table-wrap"><table class="oj-ml-table ojp-table"><thead><tr><th>항목</th><th>상태</th><th>안내</th></tr></thead><tbody>'
			+ (health.checks || []).map(function (check) {
				var tone = check.status === 'ok' ? 'success' : check.status === 'warning' ? 'warning' : 'danger';
				var label = check.status === 'ok' ? '정상' : check.status === 'warning' ? '확인 필요' : '오류';
				return '<tr><th scope="row">' + escapeHtml(check.label) + '</th><td>' + badgeHtml(label, tone)
					+ '</td><td>' + escapeHtml(check.message) + '</td></tr>';
			}).join('') + '</tbody></table></div>'
			+ '<details class="oj-ml-technical"><summary>기술 정보</summary>'
			+ '<p>Runtime 진단 코드: <code>' + escapeHtml(runtimeCodeLabel(health.runtime || {})) + '</code></p>'
			+ '<p>서버 명령: <code>wp oj-ml doctor</code>, <code>wp oj-ml recover</code>, <code>wp oj-ml reconcile</code></p></details>'
			+ '<p>저장량: ' + escapeHtml(((health.metrics || {}).stored_bytes || 0) / 1048576) + ' MiB · 검사 대기: '
			+ escapeHtml((health.metrics || {}).pending || 0) + ' · 운영자 확인: ' + escapeHtml((health.metrics || {}).needs_operator || 0) + '</p>'
			+ '<div class="oj-ml-actions"><button type="button" data-recover>대기 제출 검사 재시도</button></div>' + migrateForm + '</details>'
			+ '<section class="oj-ml-panel oj-ml-settings-panel"><form data-settings class="oj-ml-form">'
			+ '<fieldset class="oj-ml-display-settings"><legend>실습 화면 기본 보기</legend>'
			+ '<label><input name="labFocusOnStart" type="checkbox"> 실습 시작 시 집중 모드로 넓게 열기</label>'
			+ '<label><input name="labGuidanceCollapsed" type="checkbox"> 실습 시작 후 과제·양식 안내 기본 접기</label>'
			+ '<p class="oj-ml-meta">다음 실습부터 적용 · 학생이 변경 가능</p></fieldset>'
			+ '<details class="oj-ml-form-section" data-settings-section="capacity"><summary>저장 용량·검사 한도 (고급)</summary><div class="oj-ml-form-section__body"><p class="oj-ml-meta">기본값으로 시작할 수 있습니다. 과제별 제한과 별개인 서버 전체 한도입니다.</p>'
			+ '<label>파일당 한도 (MiB)<input name="maxArtifactMiB" type="number" min="1" max="250" required></label>'
			+ '<label>전체 제출물 저장 한도 (GiB)<input name="siteQuotaGiB" type="number" min="1" max="1024" required></label>'
			+ '<label>최소 여유 공간 (MiB)<input name="minimumFreeMiB" type="number" min="100" max="102400" required></label>'
			+ '<label>동시 검사 수<input name="inspectionSlots" type="number" min="1" max="8" required></label>'
			+ '</div></details><details class="oj-ml-form-section" data-settings-section="retention"><summary>보존·개인정보 정책</summary><div class="oj-ml-form-section__body">'
			+ '<label>원본 파일 보존기간 (일, 0 = 무기한)<input name="retentionDays" type="number" min="0" max="3650" required></label>'
			+ '<label><input name="retentionHold" type="checkbox"> 기록 보존 유예: 원본·개인정보 삭제 중지</label>'
			+ '<label><input name="allowPersonalErasure" type="checkbox"> WordPress의 확인된 개인정보 삭제 요청 허용</label>'
			+ '<label>기관 개인정보 안내 (선택, HTTPS)<input name="privacyUrl" type="url" maxlength="2048" placeholder="비워 두면 사이트 개인정보 처리방침을 씁니다"></label>'
			+ '<p class="oj-ml-meta">기본값은 사이트의 개인정보 처리방침입니다. 별도 주소가 필요할 때만 입력하세요. 기관 연결 필터가 있으면 우선 적용됩니다.</p>'
			+ '<p class="oj-ml-meta">현재 연결: ' + escapeHtml(privacySource) + ' · ' + escapeHtml(root.dataset.privacyUrl || '')
				+ '<br>주소 연결 여부이며 문서 게시 여부의 검증 결과는 아닙니다. 변경 후에는 이 페이지를 다시 여세요.</p>'
			+ '<p>보존기간만 설정해도 파일이 즉시 삭제되지는 않습니다. 운영자가 서버 명령 <code>wp oj-ml retention</code>으로 대상을 확인한 뒤 실행합니다. 개인정보 삭제 전에는 기관 정책과 백업을 확인하세요.</p>'
			+ '</div></details><details class="oj-ml-form-section" data-settings-section="maintenance"' + (settings.maintenanceMode ? ' open' : '') + '><summary>점검 모드 · ' + (settings.maintenanceMode ? '켜짐' : '꺼짐') + '</summary><div class="oj-ml-form-section__body">'
			+ '<label><input name="maintenanceMode" type="checkbox"> 실습 점검 모드 (새 시도·제출·평가·자동 검사 중지)</label><p class="oj-ml-meta">점검 모드를 켜고 저장하면 학생의 새 제출과 교사 평가도 중지됩니다. 수업 중에는 주의하세요.</p></div></details>'
			+ '<details class="oj-ml-help"><summary>운영 도움말</summary><p>그룹·일정·평가 기준·포트폴리오는 각 과제에서 정합니다. AI 연결 없이도 교사 직접 평가가 가능합니다.</p><p>서버 PHP·Nginx 업로드 한도는 별도로 설정해야 합니다. 공용 PC는 계정별 브라우저/OS 프로필과 사용 후 삭제를 권장합니다.</p></details>'
			+ '<div class="oj-ml-actions oj-ml-form-footer"><button type="submit" data-variant="primary">설정 저장</button></div></form></section>';
		if (window.OJMLTeacherWorkflow) content.insertAdjacentHTML('afterbegin', window.OJMLTeacherWorkflow.navigation(root));
		var form = content.querySelector('[data-settings]');
		form.addEventListener('invalid', function (event) {
			var parent = event.target.parentElement;
			while (parent && parent !== form) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
		}, true);
		[['maxArtifactMiB','maxArtifactBytes',1048576],['siteQuotaGiB','siteQuotaBytes',1073741824],
			['minimumFreeMiB','minimumFreeBytes',1048576],['inspectionSlots','inspectionSlots',1],['retentionDays','retentionDays',1]
		].forEach(function (entry) { form.elements[entry[0]].value = Number(settings[entry[1]]) / entry[2]; });
		form.elements.maintenanceMode.checked = settings.maintenanceMode === true;
		form.elements.labFocusOnStart.checked = settings.labFocusOnStart === true;
		form.elements.labGuidanceCollapsed.checked = settings.labGuidanceCollapsed !== false;
		form.elements.retentionHold.checked = settings.retentionHold === true;
		form.elements.allowPersonalErasure.checked = settings.allowPersonalErasure === true;
		form.elements.privacyUrl.value = settings.privacyUrl || '';
		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			if (!form.reportValidity()) return;
			if ((!form.elements.retentionHold.checked || form.elements.allowPersonalErasure.checked)
				&& !await confirmAction({ title: '삭제를 허용하는 설정을 저장할까요?', message: '보존기간이 지난 원본이나 확인된 개인정보 삭제 요청이 처리될 수 있습니다. 기관의 보존 정책과 백업을 먼저 확인하세요.', confirmLabel: '저장', destructive: true })) return;
			var button = form.querySelector('button[type="submit"]'); button.disabled = true;
			var submittedMaintenance = form.elements.maintenanceMode.checked;
			try {
				await api.post('console/settings', {
					maxArtifactBytes: Number(form.elements.maxArtifactMiB.value) * 1048576,
					siteQuotaBytes: Number(form.elements.siteQuotaGiB.value) * 1073741824,
					minimumFreeBytes: Number(form.elements.minimumFreeMiB.value) * 1048576,
					inspectionSlots: Number(form.elements.inspectionSlots.value), retentionDays: Number(form.elements.retentionDays.value),
					maintenanceMode: submittedMaintenance,
					labFocusOnStart: form.elements.labFocusOnStart.checked,
					labGuidanceCollapsed: form.elements.labGuidanceCollapsed.checked,
					retentionHold: form.elements.retentionHold.checked, allowPersonalErasure: form.elements.allowPersonalErasure.checked,
					privacyUrl: form.elements.privacyUrl.value
				});
				form.querySelector('[data-settings-section="maintenance"] > summary').textContent = '점검 모드 · ' + (submittedMaintenance ? '켜짐' : '꺼짐');
				setStatus(root, '운영 설정을 저장했습니다. 상태를 다시 확인하려면 이 페이지를 새로 고치세요.', 'success');
				if (window.OJP && typeof window.OJP.toast === 'function') window.OJP.toast('설정을 저장했습니다');
			} catch (error) { setStatus(root, messageFrom(error), 'error'); }
			finally { button.disabled = false; }
		});
		var migrate = content.querySelector('[data-migrate]');
		if (migrate) migrate.addEventListener('submit', async function (event) {
			if (migrate.dataset.confirmed === '1') return;
			event.preventDefault();
			if (!await confirmAction({ title: 'DB 업데이트를 실행할까요?', message: 'DB와 비공개 제출물을 백업했는지 확인하세요. 업데이트 중에는 학생 제출을 잠시 중지하는 것이 안전합니다.', confirmLabel: '업데이트' })) return;
			migrate.dataset.confirmed = '1';
			migrate.querySelector('button[type="submit"]').disabled = true;
			migrate.submit();
		});
		content.querySelector('[data-recover]').addEventListener('click', async function (event) {
			var button = event.currentTarget; button.disabled = true;
			try {
				var result = await api.post('console/recover', {});
				var recovery = result.recovery || {};
				setStatus(root, recovery.maintenance ? '점검 모드라 검사를 재시도하지 않았습니다.'
					: '검사 재시도: 처리 ' + Number(recovery.processed || 0) + '건 · 실패 ' + Number(recovery.failed || 0) + '건 · 진행 중이라 건너뜀 ' + Number(recovery.busy || 0) + '건', 'success');
			} catch (error) { setStatus(root, messageFrom(error), 'error'); }
			finally { button.disabled = false; }
		});
		setStatus(root, health.ok ? '서버 운영 상태가 정상입니다.' : '서버 운영 상태에 확인이 필요한 항목이 있습니다.', health.ok ? 'success' : 'error');
	}

	async function initialize(root) {
		if (root.dataset.publicPcMode === '1') {
			if (typeof window.OjMlPublicPcReady !== 'function' || await window.OjMlPublicPcReady(root) !== true) return;
		}
		if (publicPcTerminated(root)) return;
		var page = root.dataset.ojMlPage;
		var api = createClient(root);
		try {
			if (page === 'assignments') await renderStudentAssignments(root, api);
			else if (page === 'lab') await renderLab(root, api);
			else if (page === 'result') await renderStudentResult(root, api);
			else if (page === 'console-assignments') await renderConsoleAssignments(root, api);
			else if (page === 'console-results') await renderConsoleResults(root, api);
			else if (page === 'console-settings') await renderConsoleSettings(root, api);
			else setStatus(root, '지원하지 않는 ML 실습 화면입니다.', 'error');
			if (window.OJMLDisplay && !publicPcTerminated(root)) window.OJMLDisplay.attach(root);
		} catch (error) {
			setStatus(root, messageFrom(error), 'error');
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll(SELECTOR).forEach(initialize);
	});
}());

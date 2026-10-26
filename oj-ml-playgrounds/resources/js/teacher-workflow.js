(function (global) {
	'use strict';
	function html(value) {
		return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}
	// D-010: in-page sheet and toast on OJ Platform 2.1+, native fallback before that.
	function ask(options) {
		if (global.OJP && typeof global.OJP.confirm === 'function') return Promise.resolve(global.OJP.confirm(options));
		return Promise.resolve(global.confirm(options.message ? options.title + ' ' + options.message : options.title));
	}
	function notify(message, tone) {
		if (global.OJP && typeof global.OJP.toast === 'function') global.OJP.toast(message, tone || 'success');
		else global.alert(message);
	}
	var NOTICE_TONES = { danger: 'danger', warning: 'warning', success: 'success', neutral: 'info' };
	function guidance(attempt, mayEvaluate) {
		var status = attempt && attempt.status || 'not_submitted';
		var code = attempt && attempt.failure && attempt.failure.code || '';
		if (status === 'needs_resubmit') {
			var findings = attempt.evaluation && Array.isArray(attempt.evaluation.findings) ? attempt.evaluation.findings : [];
			var hasPolicyFailure = function (policyCode) {
				return code === policyCode || findings.some(function (finding) { return finding && finding.severity === 'error' && finding.code === policyCode; });
			};
			var taskConflict = hasPolicyFailure('OJ_ML_POLICY_TASK_TYPE'), algorithmConflict = hasPolicyFailure('OJ_ML_POLICY_ALGORITHM');
			if (taskConflict || algorithmConflict) return {
				tone: 'danger', title: '과제 조건과 실습 기록을 확인하세요',
				message: (taskConflict ? '제출 프로젝트의 학습 유형이 미선택이거나 이 과제의 허용 유형과 다릅니다. ' : '')
					+ (algorithmConflict ? '실행 기록에 허용되지 않은 모델이 있습니다. 현재 선택뿐 아니라 과거 실험과 실패·취소 실행도 검사합니다.' : '아래 검사 설명의 제출 유형·허용 유형을 실습의 학습 유형과 비교하세요.'),
				next: '아래 검사 설명의 허용 조건과 현재 선택·이전 실험 기록을 확인하세요. 같은 파일을 다시 제출하거나 재검토해도 조건 불일치는 해결되지 않습니다. '
					+ (algorithmConflict ? '현재 모델 선택만 바꾸어도 이전 실행 기록은 남습니다. ' : '')
					+ '원본 프로젝트와 포트폴리오를 보존하고, 필요하면 새 프로젝트에서 허용 유형·모델로 실습한 뒤 제출하도록 안내하세요. 횟수나 마감이 부족하면 학생별 재제출 설정에서 추가로 허용합니다.', step: 1
			};
		}
		if (status === 'needs_resubmit') return {
			tone: 'danger', title: '다시 제출할 준비가 필요합니다',
			message: code === 'OJ_ML_MLPX_NOT_ZIP'
				? '제출 파일을 정상적인 MLPX 압축파일로 열지 못했습니다. 파일 손상이나 잘못된 형식인지 확인이 필요하며, 이 화면만으로 학생의 잘못이라고 단정할 수 없습니다.'
				: '제출 파일이 이 과제의 검사 기준을 통과하지 못했습니다. 아래 검사 설명을 확인해 주세요.',
			next: '학생에게 작업 공간의 OJ에 제출하기로 다시 제출하도록 안내하세요. 횟수나 마감이 부족하면 학생별 재제출 설정에서 추가로 허용합니다. 반복되면 운영자가 원본과 서버 로그를 확인해야 합니다.', step: 1
		};
		if (['quarantined', 'system_pending', 'uploading'].indexOf(status) !== -1) return {
			tone: 'warning', title: '서버가 제출 파일을 확인하고 있습니다',
			message: '아직 내용 확인이나 평가를 시작할 수 없습니다. 검사 대기는 학생의 학습 결과가 틀렸다는 뜻이 아닙니다.',
			next: '학생에게 중복 제출을 요구하지 말고 검사 재시도를 확인하세요. 계속 대기하면 운영 설정에서 서버 상태를 확인합니다.', step: 1
		};
		if (['accepted', 'under_review', 'graded'].indexOf(status) !== -1) return {
			tone: 'success', title: status === 'graded' ? '교사 평가가 저장된 제출입니다' : '제출 내용을 확인할 수 있습니다',
			message: '파일 사전검사를 통과했습니다. 기록된 모델 성능은 서버가 재계산한 점수가 아니므로 답변과 실험 과정을 함께 검토하세요.',
			next: mayEvaluate ? '포트폴리오·실험 기록을 읽고 평가를 입력하세요. 평가 저장은 비공개이며 학생에게 공개를 눌러야 전달됩니다.' : '현재 계정은 결과 요약만 볼 수 있습니다. 내용 확인·평가는 ML 평가/관리 권한과 해당 수업 평가 권한이 필요합니다.', step: status === 'graded' ? 3 : 2
		};
		return { tone: 'neutral', title: '아직 접수된 결과가 없습니다', message: '실습 중인 작업은 학생 브라우저에 있습니다. 학생이 OJ에 제출하기를 눌러야 서버에서 확인할 수 있습니다.', next: '필요하면 제출 기한과 남은 횟수를 확인하세요.', step: 0 };
	}
	function banner(attempt, canEvaluate) {
		var info = guidance(attempt, canEvaluate);
		if (info.tone === 'success') {
			var state = !canEvaluate ? '결과 요약만 볼 수 있습니다.'
				: attempt.review && attempt.review.status === 'released' ? '평가를 학생에게 공개했습니다.' : '평가 저장은 비공개입니다.';
			return '<section class="oj-ml-workflow-banner ojp-notice ojp-notice--success" data-tone="success"><p>검사 통과 · ' + html(state) + '</p>'
				+ '<details class="oj-ml-help"><summary>평가 안내</summary><p>' + html(info.message) + '</p><p>' + html(info.next) + '</p></details></section>';
		}
		return '<section class="oj-ml-workflow-banner ojp-notice ojp-notice--' + NOTICE_TONES[info.tone] + '" data-tone="' + info.tone + '"><h4>' + html(info.title) + '</h4><p>' + html(info.message) + '</p><p><strong>다음 작업</strong> ' + html(info.next) + '</p></section>';
	}
	function detail(row, parts) {
		var attempt = row.attempt, artifact = attempt.artifact || {}, info = guidance(attempt, parts.canEvaluate);
		var steps = ['학생 제출', '파일 검사', '내용 확인·평가', '평가 상태 확인'];
		var findings = attempt.evaluation && Array.isArray(attempt.evaluation.findings) ? attempt.evaluation.findings : [];
		return '<tr class="oj-ml-detail-row"><td colspan="6"><details data-oj-ml-result-details class="oj-ml-result-workbench"><summary>'
			+ html(row.displayName || ('사용자 #' + (row.userId || ''))) + ' · ' + html(attempt.attemptNo) + '차 제출</summary><div class="oj-ml-workbench-body">'
			+ '<ol class="oj-ml-progress-steps" aria-label="제출 확인 순서">' + steps.map(function (label, index) {
				return '<li' + (index === info.step ? ' aria-current="step"' : '') + '><span>' + (index + 1) + '</span>' + html(label) + '</li>';
			}).join('') + '</ol>' + banner(attempt, parts.canEvaluate)
			+ (info.tone === 'danger' && findings.length ? '<ul class="oj-ml-readable-findings">' + findings.slice(0, 20).map(function (finding) { return '<li>' + html(finding.message || '검사 기준을 확인하세요.') + '</li>'; }).join('') + '</ul>' : '')
			+ parts.reinspect + (parts.preview || parts.reviewForm ? '<div class="oj-ml-review-layout"><div>' + parts.preview + '</div><div class="oj-ml-grading-panel">' + parts.reviewForm + '</div></div>' : '')
			+ '<details class="oj-ml-technical"><summary>파일·검사 기술 정보</summary><dl><dt>접수 번호</dt><dd>' + html(attempt.id) + '</dd><dt>파일 크기</dt><dd>'
			+ (Number.isFinite(Number(artifact.bytes)) ? (Number(artifact.bytes) / 1048576).toFixed(2) + ' MiB' : '접수 파일 없음')
			+ '</dd><dt>SHA-256</dt><dd><code>' + html(artifact.sha256 || '-') + '</code></dd><dt>앱 / 형식 버전</dt><dd>'
			+ html(attempt.clientAppVersion || '검사 전 또는 확인 불가') + ' / ' + html(attempt.formatVersion || '확인 불가') + '</dd>'
			+ (attempt.failure ? '<dt>진단 코드</dt><dd><code>' + html(attempt.failure.code) + '</code></dd>' : '')
			+ '</dl>' + parts.findings + (info.tone === 'success' ? parts.facts : '<p>검사를 마치지 못해 알고리즘과 무결성 확인 결과를 표시하지 않습니다.</p>')
			+ parts.download + '</details></div></details></td></tr>';
	}
	function pageStats(rows) {
		var counts = { ready: 0, action: 0, waiting: 0, none: 0 };
		rows.forEach(function (row) {
			var status = row.attempt && row.attempt.status || 'not_submitted';
			if (['accepted', 'under_review', 'graded'].indexOf(status) !== -1) counts.ready++;
			else if (status === 'needs_resubmit') counts.action++;
			else if (['quarantined', 'system_pending', 'uploading'].indexOf(status) !== -1) counts.waiting++;
			else counts.none++;
		});
		return '<section aria-label="현재 페이지 요약"><p class="oj-ml-meta">현재 페이지 · ' + rows.length + '명</p><div class="oj-ml-stat-grid">'
			+ [['내용 확인 가능', counts.ready, 'success'], ['재제출 확인', counts.action, 'danger'], ['검사 대기', counts.waiting, 'warning'], ['미접수', counts.none, 'neutral']].map(function (item) {
				return '<div class="oj-ml-stat" data-tone="' + item[2] + '"><span>' + item[0] + '</span><strong>' + item[1] + '명</strong></div>';
			}).join('') + '</div></section>';
	}
	function tools(row, assignment, canManage, localDate) {
		var allowance = row.allowance || {}, maximum = Math.max(Number(assignment.maxAttempts || 1), Number(allowance.maxAttempts || 0));
		var used = Number(row.attemptsUsed || 0), remaining = Math.max(0, maximum - used);
		var form = canManage && assignment.status === 'published' && used < 100
			? '<form class="oj-ml-form" data-allowance-form><p>이 학생에게만 적용합니다. 다른 학생과 기존 제출 기록은 바뀌지 않습니다.</p>'
				+ '<div class="oj-ml-form__row"><label>총 허용 횟수<input type="number" name="maxAttempts" min="' + maximum + '" max="100" value="' + Math.min(100, Math.max(maximum, used + 1)) + '" required></label>'
				+ '<label>개인 마감 연장<input type="datetime-local" name="dueAt" value="' + html(localDate(allowance.dueAt)) + '"></label></div>'
				+ '<p class="oj-ml-meta">추가할 횟수가 아니라 총 횟수입니다. 기본 마감이 지났다면 개인 마감도 미래로 연장하세요. 기본 마감이 없는 과제에는 개인 마감 제한을 새로 만들지 않습니다.</p>'
				+ '<label>변경 사유<input name="reason" maxlength="300" required placeholder="예: 파일을 다시 제출할 기회 제공"></label><button type="submit">이 학생의 제출 허용 저장</button></form>'
			: '<p>' + (used >= 100 ? '학생이 최대 100회를 모두 사용했습니다. 이 과제에서 더 늘릴 수 없으므로 새 과제로 안내하세요.' : canManage ? '종료·보관된 과제에는 추가 허용할 수 없습니다. 과제 관리에서 다시 연 뒤 설정하세요.' : '학생별 제출 허용은 ML 과제 관리 권한이 필요합니다. 담당 관리자에게 권한을 확인하세요.') + '</p>';
		return '<div class="oj-ml-student-tools"><details data-history-panel><summary>전체 제출 이력</summary><button type="button" data-load-history>이력 불러오기</button><div data-attempt-history></div></details>'
			+ '<details data-allowance-panel><summary>학생별 재제출 설정 <span class="oj-ml-meta">남은 ' + remaining + '회</span></summary>'
			+ '<p>사용 ' + used + '회 / 총 ' + maximum + '회 · 현재 개인 마감: ' + html(allowance.dueAt ? new Date(allowance.dueAt).toLocaleString() : '과제 기본 마감 적용') + '</p>' + form + '</details></div>';
	}
	function navigation(root) {
		// OJ Platform 2.1+ draws the 과제 | 제출·평가 | 설정 local navigation itself
		// (교사 메뉴 › ML 실습 › 설정); keep a single navigation layer.
		if (root.dataset.platformLocalNav === '1') return '';
		var links = [];
		if (root.dataset.canManage === '1' && root.dataset.consoleAssignmentsUrl) links.push([root.dataset.consoleAssignmentsUrl, '과제·양식 설정']);
		if (root.dataset.canReport === '1' && root.dataset.consoleResultsUrl) links.push([root.dataset.consoleResultsUrl.replace('?assignment_id={assignment_id}', ''), '학생 제출·평가']);
		if (root.dataset.canSettings === '1' && root.dataset.consoleSettingsUrl) links.push([root.dataset.consoleSettingsUrl, '설정']);
		return '<nav class="oj-ml-workflow-nav" aria-label="ML 교사 작업">' + links.map(function (item) { return '<a class="ojp-button ojp-button--small" href="' + html(item[0]) + '">' + item[1] + '</a>'; }).join('') + '</nav>';
	}
	var preset = [
		['topic', '프로젝트 주제와 문제 정의', '어떤 문제를 해결하려고 하나요? 분류·회귀·군집화 중 어떤 유형인지 설명하세요.'],
		['motivation', '탐구 동기', '이 문제를 선택한 이유와 관심 분야와의 관련성을 적어 보세요.'],
		['data', '데이터 선정과 이해', '데이터 출처, 규모, 주요 변수와 편향·윤리적 문제를 설명하세요.'],
		['preprocessing', '전처리와 특성 선택', '결측치 처리, 선택한 특성과 학습·평가 데이터 분할 방법을 설명하세요.'],
		['model', '모델 선택과 실험 과정', '모델과 설정을 선택한 이유, 비교한 실험과 차이를 설명하세요.'],
		['result', '결과 평가와 오류 분석', '기록된 성능 지표, 잘된 사례와 실패한 사례를 비교하세요.'],
		['limitations', '한계와 개선 방향', '데이터와 모델의 한계, 다음에 개선할 점을 설명하세요.'],
		['reflection', '문제 해결과 성찰', '어려웠던 점, 해결 과정과 새롭게 배운 점을 적어 보세요.']
	];
	function markdown(questions) {
		return questions.map(function (q) { return '## ' + q.title.replace(/[\r\n]/g, ' ') + (q.id ? ' {#' + q.id + '}' : '') + '\n\n' + q.prompt.trim(); }).join('\n\n') + '\n';
	}
	function parse(markdownText) {
		var questions = [], current = null;
		String(markdownText || '').replace(/<!--[\s\S]*?-->/g, '').split(/\r?\n/).forEach(function (line) {
			var match = /^##(?!#)\s*(.*)$/.exec(line);
			if (match) {
				var marked = /\s*\{#([A-Za-z0-9_-]+)\}\s*$/.exec(match[1]);
				current = { id: marked ? marked[1] : undefined, title: (marked ? match[1].slice(0, marked.index) : match[1]).trim(), prompt: '' }; questions.push(current);
			}
			else if (current) current.prompt += line + '\n';
		});
		return questions.map(function (q) { q.prompt = q.prompt.trim(); return q; });
	}
	function download(template) {
		var blob = new Blob([template.markdown], { type: 'text/markdown;charset=utf-8' });
		var url = URL.createObjectURL(blob), anchor = document.createElement('a');
		anchor.href = url; anchor.download = 'portfolio-template.md'; document.body.appendChild(anchor); anchor.click(); anchor.remove();
		global.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}
	function templateEditor(form, api) {
		var panel = document.createElement('fieldset'); panel.className = 'oj-ml-template-editor';
		panel.innerHTML = '<legend>포트폴리오 양식</legend>'
			+ '<label><input type="checkbox" data-template-enabled> 이 과제에 양식 사용</label><div data-template-fields hidden><label>양식 이름<input data-template-title maxlength="120" value="탐구 포트폴리오"></label>'
			+ '<div class="oj-ml-actions"><button type="button" data-template-preset>기본 8문항</button><button type="button" data-template-add>문항 추가</button><button type="button" data-template-download>다운로드</button></div>'
			+ '<div data-template-questions></div><label>필수 문항 최소 글자 수<input data-template-min-answer type="number" min="1" max="1000" value="1"></label>'
			+ '<p class="oj-ml-meta">양식을 바꿔도 기존 학생 답변은 덮어쓰지 않습니다.</p></div>'
			+ '<details class="oj-ml-help"><summary>양식 안내</summary><p>문항과 작성 안내를 만들면 학생이 실습 화면에서 양식을 사용할 수 있습니다. 선택한 필수 문항만 제출 시 공백을 제외한 글자 수를 검사하며, 답안의 정답 여부는 교사가 평가합니다.</p><p>최대 30문항. 양식과 필수 문항은 게시 전까지만 변경할 수 있습니다.</p></details>'
			+ '<details><summary>내 양식 보관함</summary><p class="oj-ml-meta">최대 20개 · 복사한 양식은 이 과제에 따로 저장됩니다.</p><div class="oj-ml-actions"><button type="button" data-library-refresh>불러오기</button><button type="button" data-library-save>현재 양식 보관</button></div><label>보관한 양식<select data-library-list><option value="">보관함을 불러오세요</option></select></label><div class="oj-ml-actions"><button type="button" data-library-load>편집기에 복사</button><button type="button" data-library-delete data-variant="danger">보관함에서 삭제</button></div><p data-library-status role="status"></p></details>';
		form.querySelector('[data-oj-ml-assignment-form-note]').before(panel);
		var questions = [], library = [], locked = false, original = null, questionsTouched = false, enabled = panel.querySelector('[data-template-enabled]'), fields = panel.querySelector('[data-template-fields]'), title = panel.querySelector('[data-template-title]'), list = panel.querySelector('[data-template-questions]');
		function render() {
			fields.hidden = !enabled.checked;
			list.innerHTML = questions.map(function (q, index) {
				return '<section class="oj-ml-template-question"><div class="oj-ml-actions"><strong>문항 ' + (index + 1) + '</strong><button type="button" data-template-remove="' + index + '" data-variant="danger">삭제</button></div>'
					+ '<label>문항 제목<input data-question-title="' + index + '" maxlength="200" value="' + html(q.title) + '"></label><label>작성 안내<textarea data-question-prompt="' + index + '" rows="3" maxlength="32768">' + html(q.prompt) + '</textarea></label>'
					+ '<label><input type="checkbox" data-question-required="' + index + '"' + (q.required ? ' checked' : '') + '> 제출 시 필수 답변</label></section>';
			}).join('');
			panel.querySelectorAll('input, textarea, button, select').forEach(function (control) { control.disabled = locked; });
		}
		function value() {
			if (!enabled.checked) return null;
			if (!title.value.trim()) throw new Error('양식 이름을 입력하세요.');
			if (!questionsTouched && original) return { title: title.value.trim(), markdown: original.markdown };
			if (!questions.length || questions.some(function (q) { return !q.title.trim() || /[{}\r\n]/.test(q.title) || /^##(?!#)/m.test(q.prompt); })) throw new Error('문항 제목을 입력하세요. 안내문 안에는 별도 문항 제목(##)을 넣지 마세요.');
			var result = { title: title.value.trim(), markdown: markdown(questions) };
			if (new TextEncoder().encode(result.markdown).length > 32768) throw new Error('포트폴리오 양식은 32 KiB 이내로 줄여 주세요.');
			return result;
		}
		panel.addEventListener('input', function (event) {
			var target = event.target;
			if (target.hasAttribute('data-question-title') || target.hasAttribute('data-question-prompt')) questionsTouched = true;
			if (target.hasAttribute('data-question-title')) questions[Number(target.dataset.questionTitle)].title = target.value;
			if (target.hasAttribute('data-question-prompt')) questions[Number(target.dataset.questionPrompt)].prompt = target.value;
			if (target.hasAttribute('data-question-required')) {
				var question = questions[Number(target.dataset.questionRequired)];
				if (!question.id) { question.id = 'q-' + global.crypto.randomUUID(); questionsTouched = true; }
				question.required = target.checked;
			}
		});
		enabled.onchange = render;
		panel.addEventListener('click', async function (event) {
			var button = event.target.closest('button'); if (!button || locked) return;
			if (button.hasAttribute('data-template-preset')) {
				if (questions.length && !await ask({ title: '기본 8문항으로 바꿀까요?', message: '편집 중인 문항이 기본 8문항으로 바뀝니다. 과제를 저장하기 전까지 서버의 양식은 바뀌지 않습니다.', confirmLabel: '바꾸기' })) return;
				questions = preset.map(function (q) { return { id: q[0], title: q[1], prompt: q[2] }; }); questionsTouched = true; render();
			} else if (button.hasAttribute('data-template-add')) {
				if (questions.length >= 30) { notify('최대 30문항까지 제공할 수 있습니다.', 'warning'); return; }
				var id = 'q-' + global.crypto.randomUUID(); questions.push({ id: id, title: '', prompt: '' }); questionsTouched = true; render(); list.querySelector('[data-question-title="' + (questions.length - 1) + '"]').focus();
			} else if (button.hasAttribute('data-template-remove')) {
				var index = Number(button.dataset.templateRemove);
				if ((questions[index].title || questions[index].prompt) && !await ask({ title: '이 문항을 양식에서 삭제할까요?', confirmLabel: '삭제', destructive: true })) return;
				questions.splice(index, 1); questionsTouched = true; render();
			} else if (button.hasAttribute('data-template-download')) { try { var data = value(); if (data) download(data); } catch (error) { notify(error.message, 'danger'); } }
		});
		function requirements() {
			if (!enabled.checked) return [];
			var ids = questions.filter(function (q) { return q.required; }).map(function (q) { return q.id; });
			var minimum = Number(panel.querySelector('[data-template-min-answer]').value);
			if (!Number.isInteger(minimum) || minimum < 1 || minimum > 1000) throw new Error('필수 답변 길이는 1~1000자로 입력하세요.');
			return ids.length ? { requiredSectionIds: ids, minAnswerChars: minimum } : [];
		}
		function load(template, required) {
			original = template || null; questionsTouched = false; enabled.checked = !!template; title.value = template && template.title || '탐구 포트폴리오';
			questions = parse(template && template.markdown); var ids = required && required.requiredSectionIds || [];
			questions.forEach(function (q) { q.required = ids.indexOf(q.id) !== -1; });
			panel.querySelector('[data-template-min-answer]').value = String(required && required.minAnswerChars || 1); render();
		}
		panel.addEventListener('click', async function (event) {
			var button = event.target.closest('button'); if (!button || locked || !api || !/^library/.test(Object.keys(button.dataset)[0] || '')) return;
			var note = panel.querySelector('[data-library-status]'), select = panel.querySelector('[data-library-list]');
			var selected = library.find(function (item) { return item.id === select.value; });
			try {
				button.disabled = true;
				if (button.hasAttribute('data-library-load')) {
					if (!selected) throw new Error('먼저 보관한 양식을 선택하세요.');
					if (questions.length && !await ask({ title: '선택한 양식으로 바꿀까요?', message: '편집 중인 양식이 선택한 양식의 사본으로 바뀝니다.', confirmLabel: '바꾸기' })) return;
					load(selected.template, selected.requirements); note.textContent = '편집기에 복사했습니다. 과제 저장을 눌러 적용하세요.'; return;
				}
				var response;
				if (button.hasAttribute('data-library-save')) response = await api.post('console/template-library', { id: global.crypto.randomUUID(), template: value(), requirements: requirements() });
				else if (button.hasAttribute('data-library-delete')) {
					if (!selected) throw new Error('삭제할 양식을 선택하세요.');
					if (!await ask({ title: '보관함의 양식을 삭제할까요?', message: '이미 과제에 저장한 양식은 유지됩니다.', confirmLabel: '삭제', destructive: true })) return;
					response = await api.remove('console/template-library/' + encodeURIComponent(selected.id));
				} else response = await api.get('console/template-library');
				library = response.items || []; select.innerHTML = '<option value="">양식 선택</option>' + library.map(function (item) { return '<option value="' + html(item.id) + '">' + html(item.template.title) + '</option>'; }).join('');
				note.textContent = '내 보관함 ' + library.length + '개 · 과제 내용은 자동으로 바뀌지 않습니다.';
			} catch (error) { note.textContent = error.message || '양식 보관함 요청에 실패했습니다.'; }
			finally { button.disabled = locked; }
		});
		return { value: value, requirements: requirements, load: load, lock: function (state) { locked = state; render(); } };
	}
	function attachStudentTemplate(content, assignment) {
		var template = assignment.submissionPolicy && assignment.submissionPolicy.portfolioTemplate;
		if (!template || !template.markdown) return;
		var panel = document.createElement('section'); panel.className = 'oj-ml-panel oj-ml-student-template';
		panel.innerHTML = '<h3>교사가 제공한 포트폴리오 양식</h3><p><strong>' + html(template.title) + '</strong></p>'
			+ '<p>프로젝트를 열고 양식 적용을 누르세요. 없는 문항만 추가하며 기존 답변은 보존합니다.</p><div class="oj-ml-actions"><button type="button" data-student-template-apply data-variant="tinted" disabled>현재 프로젝트에 양식 적용</button><button type="button" data-student-template-download>양식 다운로드 (.md)</button></div><p data-template-bridge-status role="status">실습을 시작하고 프로젝트를 열면 적용할 수 있습니다.</p><details><summary>문항 미리보기</summary>'
			+ parse(template.markdown).map(function (q) { var required = assignment.submissionPolicy.portfolioRequirements; return '<section class="oj-ml-template-question"><h4>' + html(q.title) + (required && required.requiredSectionIds.indexOf(q.id) !== -1 ? ' · 필수 ' + html(required.minAnswerChars) + '자 이상' : '') + '</h4><p class="oj-ml-prewrap">' + html(q.prompt) + '</p></section>'; }).join('')
			+ '</details><p class="oj-ml-meta">필수 표시 문항만 제출 시 답변 길이를 확인합니다(공백 제외). 정답 여부는 교사가 평가합니다. 파일로 가져오려면 포트폴리오 → 양식 가져오기 → 파일을 사용하세요.</p>';
		content.querySelector('[data-oj-ml-workspace]').before(panel);
		panel.querySelector('[data-student-template-download]').onclick = function () { download(template); };
		if (global.OJMLPortfolioBridge) global.OJMLPortfolioBridge.bind(panel, content.closest('.oj-ml-app'), assignment, parse(template.markdown));
	}
	function assignmentSections(form, rubricPanel) {
		var groups = [
			['basic', '기본 정보', ['context', 'title', 'description'], '그룹·제목', ''],
			['practice', '실습·일정', ['taskType', 'opensAt', 'allowLateSubmission'], '유형·제출', '시각은 이 기기의 시간대 기준입니다. 시작·마감을 비워 두면 해당 시각 제한이 없습니다. 제출 횟수는 추가 횟수가 아닌 총 허용 횟수입니다.'],
			['limits', '파일·데이터 한도', ['maxRows', 'maxArtifactMiB'], '고급', '과제 한도와 서버 운영 한도 중 더 엄격한 값이 적용됩니다.']
		];
		var note = form.querySelector('[data-oj-ml-assignment-form-note]');
		var sections = {};
		function section(key, title, caption, nodes, help) {
			var details = document.createElement('details'); details.className = 'oj-ml-form-section';
			details.dataset.assignmentSection = key; details.open = key === 'basic';
			details.innerHTML = '<summary><span class="oj-ml-section-heading">' + html(title) + '</span><span class="oj-ml-section-caption">' + html(caption) + '</span></summary><div class="oj-ml-form-section__body"></div>';
			var body = details.querySelector('.oj-ml-form-section__body');
			nodes.filter(Boolean).forEach(function (node) { body.appendChild(node); });
			if (help) body.insertAdjacentHTML('beforeend', '<details class="oj-ml-help"><summary>설정 안내</summary><p>' + html(help) + '</p></details>');
			note.before(details); sections[key] = details;
		}
		groups.forEach(function (group) {
			var nodes = group[2].map(function (name) { var control = form.querySelector('[name="' + name + '"]'); return control && (control.closest('.oj-ml-form__row') || control.closest('.oj-ml-field')); });
			section(group[0], group[1], group[3], nodes, group[4]);
		});
		section('rubric', '평가 기준', '등급별 채점', [rubricPanel]);
		section('portfolio', '포트폴리오', '선택', [form.querySelector('.oj-ml-template-editor')]);
		var policy = form.querySelector('[data-algorithm-choices]');
		section('policy', '모델·자료', '선택', [policy && policy.closest('fieldset')]);
		note.before(sections.limits);
		// Hidden optional sections must open before native validation focuses a required field.
		form.addEventListener('invalid', function (event) {
			var parent = event.target.parentElement;
			while (parent && parent !== form) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
		}, true);
	}
	global.OJMLTeacherWorkflow = { ask: ask, notify: notify, html: html, guidance: guidance, banner: banner, detail: detail, pageStats: pageStats, tools: tools, navigation: navigation, templateEditor: templateEditor, attachStudentTemplate: attachStudentTemplate, assignmentSections: assignmentSections, markdown: markdown, parse: parse };
})(window);

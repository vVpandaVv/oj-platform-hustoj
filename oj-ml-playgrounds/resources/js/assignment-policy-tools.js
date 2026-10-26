(function (global) {
	'use strict';
	var choices = [['decision_tree', '의사결정나무'], ['random_forest', '랜덤 포레스트'], ['naive_bayes', '나이브 베이즈'], ['knn', 'K-최근접 이웃'], ['logistic_regression', '로지스틱 회귀'], ['linear_regression', '선형 회귀'], ['svm', '서포트 벡터 머신'], ['k_means', 'K-평균 군집화']];
	function html(value) { return global.OJMLTeacherWorkflow.html(value); }
	function editor(form, api) {
		var panel = document.createElement('fieldset'), locked = false, busy = false, library = [], current = null;
		panel.className = 'oj-ml-assignment-section';
		panel.innerHTML = '<legend>알고리즘·지정 데이터셋</legend><h4>허용 알고리즘</h4><p>선택하지 않으면 알고리즘을 제한하지 않습니다. 선택하면 제출된 모든 실행 기록의 알고리즘을 검사합니다. 실행 횟수나 성능 점수는 강제하지 않습니다.</p><div data-algorithm-choices class="oj-ml-algorithm-grid"></div>'
			+ '<h4>과제 데이터</h4><label>데이터 지정 방법<select data-dataset-mode><option value="none">자유 데이터 (해시 제한 없음)</option><option value="library">교사 CSV 배포 + 동일 데이터 검사</option><option value="manual">기존 SHA-256 직접 지정 (고급)</option></select></label>'
			+ '<div data-dataset-library hidden><p>UTF-8 쉼표 CSV · 첫 줄은 고유한 열 이름 · 최대 5 MiB / 20,000행 / 100열. 서버가 0.12.1 정본 CSV로 정규화하고 해시를 계산합니다.</p><label>내 CSV 자료<select data-dataset-select><option value="">자료를 불러오세요</option></select></label>'
			+ '<div class="oj-ml-actions"><button type="button" data-dataset-refresh>자료 목록 불러오기</button><button type="button" data-dataset-hide>선택 자료를 내 목록에서 숨기기</button></div><label>새 CSV 파일<input type="file" data-dataset-file accept=".csv,text/csv"></label><button type="button" data-dataset-upload>비공개 자료로 업로드</button><p class="oj-ml-meta">교사별 활성 자료 20개 / 전체 보존 자료 50 MiB, 서버 전체 200개 / 200 MiB. 숨기기는 원본 삭제가 아니며 기존 과제와 용량은 유지됩니다. 개인정보 없는 수업 자료를 사용하세요.</p></div>'
			+ '<div data-dataset-manual hidden><label>정본 dataset/data.csv SHA-256<input data-dataset-hash maxlength="64" pattern="[a-fA-F0-9]{64}"></label><label>MLPX 내부 경로<input data-dataset-path value="dataset/data.csv" maxlength="512"></label><p class="oj-ml-meta">다운로드한 CSV가 아니라 MLPX 안에 정규화된 데이터의 해시입니다. 직접 지정만 하면 파일은 배포되지 않습니다.</p></div><p data-dataset-status role="status"></p>';
		form.querySelector('[data-oj-ml-assignment-form-note]').before(panel);
		var algorithmHolder = panel.querySelector('[data-algorithm-choices]'), mode = panel.querySelector('[data-dataset-mode]'), select = panel.querySelector('[data-dataset-select]'), note = panel.querySelector('[data-dataset-status]');
		function renderLibrary() {
			var selected = select.value || current && current.id || '';
			var items = library.slice(); if (current && !items.some(function (item) { return item.id === current.id; })) items.unshift(current);
			select.innerHTML = '<option value="">자료 선택</option>' + items.map(function (item) { return '<option value="' + html(item.id) + '">' + html(item.name) + ' (' + html(item.rowCount || '?') + '행)</option>'; }).join('');
			select.value = selected;
		}
		function update() {
			panel.querySelector('[data-dataset-library]').hidden = mode.value !== 'library'; panel.querySelector('[data-dataset-manual]').hidden = mode.value !== 'manual';
			panel.querySelectorAll('input,select,button').forEach(function (control) { control.disabled = locked || busy; });
			panel.querySelector('[data-dataset-hash]').disabled = locked || busy || mode.value !== 'manual';
			panel.querySelector('[data-dataset-path]').disabled = locked || busy || mode.value !== 'manual';
		}
		mode.onchange = update;
		panel.addEventListener('click', async function (event) {
			var button = event.target.closest('button'); if (!button || locked || busy) return;
			try {
				busy = true; update(); var response;
				if (button.hasAttribute('data-dataset-upload')) {
					var file = panel.querySelector('[data-dataset-file]').files[0]; if (!file) throw new Error('CSV 파일을 선택하세요.');
					if (file.size > 5242880) throw new Error('CSV는 최대 5 MiB입니다.');
					var body = new FormData(); body.append('dataset', file); response = await api.upload('console/datasets', body);
					current = response.dataset; if (!current) throw new Error('업로드 결과를 확인하지 못했습니다. 목록을 다시 불러오세요.');
					library = (await api.get('console/datasets')).items || []; select.value = ''; renderLibrary(); select.value = current.id;
					panel.querySelector('[data-dataset-file]').value = ''; note.textContent = 'CSV를 저장했습니다. 과제 저장을 눌러 배포·해시 검사를 연결하세요.';
				} else if (button.hasAttribute('data-dataset-hide')) {
					if (!select.value) throw new Error('숨길 자료를 선택하세요.');
					if (!await global.OJMLTeacherWorkflow.ask({ title: '내 자료 목록에서 숨길까요?', message: '서버 원본, 기존 과제 연결, 사용 용량은 그대로 유지됩니다.', confirmLabel: '숨기기' })) return;
					await api.remove('console/datasets/' + encodeURIComponent(select.value)); library = (await api.get('console/datasets')).items || []; renderLibrary(); note.textContent = '내 목록에서 숨겼습니다. 현재 과제에 이미 연결된 자료는 유지됩니다.';
				} else { library = (await api.get('console/datasets')).items || []; renderLibrary(); note.textContent = '본인 자료 목록을 불러왔습니다.'; }
			} catch (error) { note.textContent = error.message || '자료 요청에 실패했습니다.'; }
			finally { busy = false; update(); }
		});
		function load(policy) {
			var allowed = policy.allowedAlgorithms || [], all = choices.slice(); allowed.forEach(function (id) { if (!all.some(function (entry) { return entry[0] === id; })) all.push([id, id + ' (기존 설정)']); });
			algorithmHolder.innerHTML = all.map(function (entry) { return '<label><input type="checkbox" data-allowed-algorithm value="' + html(entry[0]) + '"' + (allowed.indexOf(entry[0]) !== -1 ? ' checked' : '') + '> ' + html(entry[1]) + '</label>'; }).join('');
			current = policy.datasetResource || null; mode.value = current ? 'library' : policy.requiredDatasetSha256 ? 'manual' : 'none';
			panel.querySelector('[data-dataset-hash]').value = policy.requiredDatasetSha256 || ''; panel.querySelector('[data-dataset-path]').value = policy.requiredDatasetPath || 'dataset/data.csv';
			select.value = ''; renderLibrary(); note.textContent = ''; update();
		}
		function value() {
			if (busy) throw new Error('자료 업로드가 끝난 뒤 과제를 저장하세요.');
			var result = { allowedAlgorithms: Array.from(panel.querySelectorAll('[data-allowed-algorithm]:checked')).map(function (input) { return input.value; }), datasetResource: null, requiredDatasetSha256: '', requiredDatasetPath: '' };
			if (mode.value === 'library') { if (!select.value) throw new Error('배포할 CSV 자료를 선택하세요.'); result.datasetResource = { id: select.value }; }
			if (mode.value === 'manual') {
				result.requiredDatasetSha256 = panel.querySelector('[data-dataset-hash]').value.trim(); result.requiredDatasetPath = panel.querySelector('[data-dataset-path]').value.trim();
				if (!/^[a-fA-F0-9]{64}$/.test(result.requiredDatasetSha256)) throw new Error('정본 데이터 SHA-256 64자리를 입력하세요.');
			}
			return result;
		}
		load({}); return { load: load, value: value, lock: function (state) { locked = state; update(); } };
	}
	function student(content, root, assignment) {
		var policy = assignment.submissionPolicy || {}, dataset = policy.datasetResource;
		var taskLabels = { classification: '분류', regression: '회귀', clustering: '군집화' };
		var panel = document.createElement('section'); panel.className = 'oj-ml-panel';
		panel.innerHTML = (global.OJMLRubric ? global.OJMLRubric.criteriaHtml(assignment.rubric) : '')
			+ (Array.isArray(policy.allowedTaskTypes) && policy.allowedTaskTypes.length ? '<p><strong>허용 학습 유형:</strong> ' + policy.allowedTaskTypes.map(function (id) { return html(taskLabels[id] || id); }).join(', ') + '</p>' : '')
			+ (policy.allowedAlgorithms && policy.allowedAlgorithms.length ? '<p><strong>제출 허용 알고리즘:</strong> ' + policy.allowedAlgorithms.map(function (id) { var found = choices.find(function (item) { return item[0] === id; }); return html(found ? found[1] : id); }).join(', ') + '</p>' : '')
			+ '<p class="oj-ml-meta">실습 화면에서는 과제가 허용한 유형과 알고리즘만 새로 선택할 수 있습니다. 기존 프로젝트를 가져오면 현재 선택과 모든 과거 실험 기록을 제출 전에 확인합니다. 제한에 맞지 않는 기록은 자동 삭제하지 않습니다.</p>'
			+ (dataset ? '<h3>교사 지정 데이터</h3><p>' + html(dataset.name) + ' · ' + html(dataset.rowCount) + '행</p><button type="button" data-student-dataset-download data-variant="tinted">수업 CSV 다운로드</button><p>이 파일을 프로젝트 데이터 화면에서 가져오세요. 첫 줄은 열 이름입니다. 데이터 값을 바꾸면 제출 검사에서 반려됩니다. 서버에서 데이터나 모델을 실행하지 않습니다.</p><p data-dataset-download-status role="status"></p>' : policy.requiredDatasetSha256 ? '<p>이 과제는 지정 데이터 해시를 검사합니다. 교사가 안내한 데이터 파일을 사용하세요.</p>' : '');
		if (!panel.textContent.trim()) return;
		content.querySelector('[data-oj-ml-workspace]').before(panel);
		var button = panel.querySelector('button'); if (!button) return;
		button.onclick = async function () {
			var note = panel.querySelector('[data-dataset-download-status]'); button.disabled = true;
			try {
				if (root.dataset.publicPcLocked === '1' || root.dataset.publicPcTerminal === '1') throw new Error('로그인 상태를 다시 확인하세요.');
				var response = await global.fetch(String(root.dataset.restRoot).replace(/\/+$/, '') + '/assignments/' + encodeURIComponent(assignment.id) + '/dataset', { credentials: 'same-origin', headers: { 'X-WP-Nonce': root.dataset.restNonce || '' } });
				if (!response.ok) { var problem = await response.json().catch(function () { return {}; }); throw new Error(problem.message || '자료를 다운로드하지 못했습니다.'); }
				if (!/^text\/csv\b/i.test(response.headers.get('Content-Type') || '')) throw new Error('CSV 응답을 확인하지 못했습니다.');
				var blob = await response.blob(); if (blob.size !== Number(dataset.bytes) || blob.size > 5242880) throw new Error('자료 크기가 일치하지 않습니다.');
				var bytes = await blob.arrayBuffer(); var hash = Array.from(new Uint8Array(await global.crypto.subtle.digest('SHA-256', bytes))).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
				if (hash !== dataset.sha256 || root.dataset.publicPcLocked === '1' || root.dataset.publicPcTerminal === '1') throw new Error('자료 무결성 또는 로그인 상태를 확인하지 못했습니다.');
				var url = URL.createObjectURL(blob), anchor = document.createElement('a'); anchor.href = url; anchor.download = dataset.name || 'class-dataset.csv'; document.body.appendChild(anchor); anchor.click(); anchor.remove(); global.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
				note.textContent = 'CSV 무결성을 확인하고 다운로드했습니다. 사용 후 공용 PC에 다운로드한 파일도 삭제하세요.';
			} catch (error) { note.textContent = error.message; } finally { button.disabled = false; }
		};
	}
	global.OJMLAssignmentPolicy = { editor: editor, student: student };
})(window);

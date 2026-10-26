(function (global) {
    'use strict';
    function html(value) {
        return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function ask(options) {
        if (global.OJP && typeof global.OJP.confirm === 'function') return global.OJP.confirm(options);
        if (global.OJMLTeacherWorkflow && global.OJMLTeacherWorkflow.ask) return global.OJMLTeacherWorkflow.ask(options);
        return Promise.resolve(global.confirm(options.title));
    }
    function criteria(rubric) {
        return rubric && (rubric.version === 1 || rubric.version === 2) && Array.isArray(rubric.criteria) ? rubric.criteria.slice(0, 25) : [];
    }
    function legacy(rubric) {
        return rubric && typeof rubric === 'object' && Object.keys(rubric).length > 0
            && !Object.prototype.hasOwnProperty.call(rubric, 'version');
    }
    function points(value) {
        if (value === null || value === '' || typeof value === 'boolean' || typeof value === 'object') throw new Error('점수를 숫자로 입력하세요.');
        var number = Number(value);
        if (!Number.isFinite(number) || Math.abs(number * 100 - Math.round(number * 100)) > 0.000001) throw new Error('점수는 소수 둘째 자리까지 입력하세요.');
        return Math.round(number * 100);
    }
    function makeId(prefix) {
        return (prefix || 'criterion') + '_' + (global.crypto && global.crypto.randomUUID ? global.crypto.randomUUID() : Date.now().toString(36) + '_' + Math.random().toString(36).slice(2));
    }
    var presetLevelDescriptions = {
        problem: ['해결할 질문·대상·성공 조건을 구체화하고, 학습 유형과 타깃·특성 선택 이유를 프로젝트 내용에 근거해 설명했다.', '해결 질문과 학습 유형·타깃은 적절하나 성공 조건 또는 특성 선택 이유의 설명이 일부 부족하다.', '주제나 타깃은 제시했으나 해결 목표가 모호하거나 학습 유형·특성과 목표의 연결 근거가 부족하다.', '해결 목표와 타깃을 제시하지 않았거나 제출 내용에서 선택의 근거를 확인할 수 없다.'],
        data: ['출처·변수 의미·전처리 방법을 설명하고, 누락값·편향 등 데이터 한계와 개인정보 처리까지 근거 있게 검토했다.', '출처·변수·전처리를 설명했으나 데이터 한계 또는 개인정보 검토 중 일부가 부족하다.', '출처나 변수 일부만 설명했으며 전처리 이유와 데이터 한계에 대한 근거가 부족하다.', '데이터 출처·변수·전처리에 관한 설명과 근거를 확인할 수 없다.'],
        experiments: ['모델·매개변수·분할 설정의 이유를 설명하고 같은 조건의 실행 지표를 비교하여 선택 근거를 제시했다.', '설정과 실행 지표를 비교했으나 일부 설정 이유 또는 비교 조건의 일관성 설명이 부족하다.', '학습 실행이나 지표는 기록했으나 설정 선택 이유 또는 실험 간 비교 근거가 부족하다.', '실험 설정과 실행 결과에 대한 평가 근거를 확인할 수 없다.'],
        reflection: ['포트폴리오 설명이 실제 실험 결과와 일치하고 지표의 의미·한계·구체적인 후속 개선을 근거로 제시했다.', '결과와 지표를 적절히 해석했으나 한계 또는 후속 개선을 뒷받침하는 근거가 일부 부족하다.', '결과를 요약했으나 지표 해석이 단편적이거나 한계·개선 방향이 구체적이지 않다.', '제출 결과에 대한 해석·한계·개선 방향을 확인할 수 없다.'],
        question: ['해결 목표와 타깃·특성을 연결하고 바꿀 조건·유지할 조건·비교 지표를 구체적인 계획으로 제시했다.', '해결 목표와 비교할 조건은 정했으나 통제할 조건 또는 비교 지표의 이유가 일부 부족하다.', '탐구 주제만 제시하거나 타깃·특성과 비교 계획의 연결이 불명확하다.', '탐구 질문과 실험 비교 계획을 확인할 수 없다.'],
        setup: ['출처·전처리·분할·모델·매개변수 설정을 재현 가능하게 기록하고 각 설정을 선택한 이유를 설명했다.', '주요 설정은 재현할 수 있으나 일부 전처리·분할·매개변수의 선택 이유가 부족하다.', '일부 설정만 기록되어 비교 조건을 재현하기 어렵거나 목표와 설정의 관련 근거가 부족하다.', '데이터 처리와 실험 설정을 확인하거나 재현할 근거가 없다.'],
        runs: ['실행 상태와 지표를 확인하여 같은 조건의 성공 실험을 비교했고, 실패·취소 기록이 있다면 원인과 개선도 설명했다.', '성공 실행의 상태와 지표를 비교했으나 비교 조건이나 실패·취소가 있었을 때의 원인 분석이 일부 부족하다.', '실행 기록과 지표는 있으나 비교 근거가 단편적이거나 실행 상태를 구분한 설명이 부족하다.', '실행 상태·지표에 관한 비교 근거를 확인할 수 없다.'],
        conclusion: ['프로젝트·실험 기록과 포트폴리오가 일치하며 지표의 의미·한계와 검증 가능한 후속 개선을 제시했다.', '기록과 결론이 대체로 일치하나 한계 또는 후속 개선을 설명하는 근거가 일부 부족하다.', '결론은 있으나 기록과의 연결이 약하거나 지표의 의미·한계·개선이 구체적이지 않다.', '실험 기록에 근거한 결론과 개선 방향을 확인할 수 없다.']
    };
    function defaultLevels(criterion, usePreset) {
        var maximum = points(criterion.maxScore == null ? 25 : criterion.maxScore), seen = new Set();
        var descriptions = usePreset && presetLevelDescriptions[criterion.id];
        var reference = criterion.description || criterion.title || '이 항목의 평가 기준';
        var generic = [
            '다음 기준을 모두 충족하고 제출 근거로 설명했습니다: ',
            '다음 기준의 주요 내용을 충족했으나 일부 근거가 부족합니다: ',
            '다음 기준을 일부만 충족했으며 설명이나 근거가 부족합니다: ',
            '다음 기준을 충족한 제출 근거를 확인할 수 없습니다: '
        ];
        return [['excellent', '충분히 충족', 1], ['proficient', '대체로 충족', 0.8], ['developing', '일부 충족', 0.4], ['not_yet', '미충족', 0]].reduce(function (levels, entry, index) {
            var score = Math.round(maximum * entry[2]);
            if (index > 0 && index < 3 && (score === 0 || score === maximum)) return levels;
            if (seen.has(score)) return levels;
            seen.add(score);
            levels.push({ id: entry[0], label: entry[1], score: score / 100,
                description: descriptions ? descriptions[index] : Array.from(generic[index] + reference).slice(0, 2000).join('') });
            return levels;
        }, []);
    }
    function presetItems(name) {
        if (name === 'experiments') return [
            { id: 'question', title: '탐구 질문과 비교 계획', description: '프로젝트의 해결 목표와 타깃·특성을 설명하고, 어떤 조건을 바꾸어 비교할지 근거를 제시했는가?', maxScore: 20 },
            { id: 'setup', title: '데이터·실험 설정', description: '데이터 출처·전처리와 분할 방법, 알고리즘·매개변수 설정이 목표에 적절하고 재현할 수 있게 기록되었는가?', maxScore: 30 },
            { id: 'runs', title: '실행 기록과 비교 근거', description: '실행 상태와 평가 지표를 확인하고, 성공한 실험의 결과를 같은 조건에서 비교했는가? 실패·취소 기록은 원인과 개선을 설명했는가?', maxScore: 30 },
            { id: 'conclusion', title: '결론과 개선', description: '프로젝트 정보·실험 결과와 포트폴리오 설명이 일치하며, 지표의 의미·한계와 후속 개선을 근거 있게 제시했는가?', maxScore: 20 }
        ];
        return [
            { id: 'problem', title: '문제 정의·프로젝트 이해', description: '프로젝트의 탐구 질문과 해결 목표가 구체적인가? 선택한 학습 유형과 타깃·특성이 목표에 적절한 이유를 설명했는가?', maxScore: 25 },
            { id: 'data', title: '데이터 이해·전처리', description: '데이터 출처, 변수의 의미, 전처리 방법을 설명했는가? 누락값·편향 등 데이터의 한계와 개인정보를 고려했는가?', maxScore: 25 },
            { id: 'experiments', title: '실험 설계·실행 비교', description: '알고리즘·매개변수·데이터 분할 설정에 근거가 있는가? 실험과 실행 기록의 지표를 비교하여 선택 이유를 설명했는가?', maxScore: 25 },
            { id: 'reflection', title: '결과 해석·성찰', description: '포트폴리오 설명이 제출한 실험 결과와 일치하는가? 지표의 의미, 결과의 한계와 후속 개선 방향을 제시했는가?', maxScore: 25 }
        ];
    }
    function withLevels(items, usePreset) {
        return items.map(function (item) {
            var result = Object.assign({}, item, { levels: defaultLevels(item, usePreset) });
            if (usePreset) result.evidenceRequirements = ['datasetDescription', 'datasetStatistics'].concat(['experiments', 'runs', 'conclusion', 'reflection'].indexOf(item.id) !== -1 ? ['experimentComparison'] : []);
            return result;
        });
    }
    var evidenceLabels = { datasetDescription: '데이터 설명', datasetStatistics: '데이터 통계', experimentComparison: '동일 조건 실험 비교' };
    function evidenceFields(criterion) {
        var explicit = Array.isArray(criterion.evidenceRequirements);
        var selected = explicit ? criterion.evidenceRequirements : ['datasetDescription', 'datasetStatistics'];
        return '<details class="oj-ml-rubric-help oj-ml-evidence-requirements" data-rubric-evidence data-evidence-explicit="' + (explicit ? '1' : '0') + '"><summary>평가에 필요한 근거</summary>'
            + '<fieldset><legend class="ojp-visually-hidden">이 평가 항목의 필수 근거</legend>'
            + Object.keys(evidenceLabels).map(function (key) { return '<label><input type="checkbox" data-rubric-evidence-key="' + key + '"' + (selected.indexOf(key) !== -1 ? ' checked' : '') + '> ' + evidenceLabels[key] + '</label>'; }).join('') + '</fieldset>'
            + '<p>선택한 근거가 부족하면 AI 점수를 제안하지 않고 교사 확인 필요로 표시합니다. 모두 해제하면 보고서·기록 중심으로 평가합니다.</p></details>';
    }
    function levelRowHtml(level) {
        return '<div class="oj-ml-rubric-level" data-rubric-level-item data-level-id="' + html(level.id || makeId('level')) + '" role="group" aria-label="성취 등급">'
            + '<span class="ojp-visually-hidden" data-rubric-level-number>성취 등급</span>'
            + '<div class="oj-ml-rubric-level__fields"><label>등급<input data-rubric-level-label aria-label="등급 이름" maxlength="200" required value="' + html(level.label || '') + '" placeholder="충분히 충족"></label>'
            + '<label>점수<input type="number" data-rubric-level-score aria-label="이 등급의 점수" min="0" max="10000" step="0.01" required value="' + html(level.score == null ? '' : level.score) + '"></label>'
            + '<button type="button" data-variant="danger" data-rubric-level-remove aria-label="이 성취 등급 삭제">삭제</button></div>'
            + '<label class="oj-ml-rubric-level__criterion">판단 기준<textarea data-rubric-level-description aria-label="이 등급을 부여하는 판단 기준" maxlength="2000" rows="2" required placeholder="이 등급을 부여할 조건과 근거">' + html(level.description || '') + '</textarea></label></div>';
    }
    function rowHtml(criterion, version, open) {
        var graded = version === 2, levels = graded ? criterion.levels || defaultLevels(criterion, false) : [];
        return '<div class="oj-ml-rubric-row" data-rubric-row><details class="oj-ml-rubric-item" data-rubric-item data-criterion-id="' + html(criterion.id || makeId()) + '"' + (open ? ' open' : '') + '>'
            + '<summary class="oj-ml-rubric-item__summary"><span class="oj-ml-rubric-item__identity"><span class="oj-ml-rubric-item__number" data-rubric-item-number>평가 항목</span><strong data-rubric-summary-title>' + html(criterion.title || '새 평가 항목') + '</strong></span>'
            + '<span class="oj-ml-rubric-item__meta" data-rubric-summary-meta>' + html(criterion.maxScore == null ? 25 : criterion.maxScore) + '점 · ' + (graded ? levels.length + '등급' : '자유 점수') + '</span></summary>'
            + '<div class="oj-ml-rubric-item__body"><div class="oj-ml-form__row"><label>항목명<input data-rubric-title aria-label="평가 항목명" maxlength="200" required placeholder="실험 설계와 결과 해석" value="' + html(criterion.title || '') + '"></label>'
            + '<label>' + (graded ? '배점 (자동)' : '배점') + '<input type="number" data-rubric-max aria-label="' + (graded ? '항목 배점, 등급 최고점에서 자동 계산' : '항목 배점') + '" min="0.01" max="10000" step="0.01"' + (graded ? ' readonly' : ' required') + ' value="' + html(criterion.maxScore == null ? 25 : criterion.maxScore) + '"></label></div>'
            + (graded ? '<details class="oj-ml-rubric-help"><summary>항목 설명</summary>' : '')
            + '<label>' + (graded ? '설명 (선택)' : '평가 기준') + '<textarea data-rubric-description aria-label="평가 항목 설명과 기준" maxlength="2000" rows="2" placeholder="이 항목에서 확인할 내용">' + html(criterion.description || '') + '</textarea></label>'
            + (graded ? '</details>' : '') + evidenceFields(criterion)
            + (graded ? '<div class="oj-ml-rubric-levels" data-rubric-levels-editor>' + levels.map(levelRowHtml).join('') + '</div><button type="button" data-rubric-level-add>등급 추가</button>' : '')
            + '</div></details><button class="oj-ml-rubric-row__remove" type="button" data-variant="danger" data-rubric-remove aria-label="' + html(criterion.title || '새 평가 항목') + ' 평가 항목 삭제">삭제</button></div>';
    }
    function rowsHtml(items, version) { return items.map(function (item, index) { return rowHtml(item, version, index === 0); }).join(''); }
    function editorHtml(rubric, options) {
        options = options || {};
        rubric = rubric || [];
        var isLegacy = legacy(rubric), items = criteria(rubric), version = rubric.version === 2 ? 2 : 1;
        // Only a newly created assignment gets a starter. Saved total-score and legacy rubrics stay intact.
        if (options.isNew === true && !isLegacy && !items.length) { version = 2; items = withLevels(presetItems('basic'), true); }
        var mode = isLegacy ? 'legacy' : items.length ? 'criteria' : 'total';
        return '<fieldset class="oj-ml-rubric-editor" data-rubric-editor data-rubric-original="' + html(JSON.stringify(rubric)) + '"'
            + ' data-rubric-version="' + version + '" data-rubric-mode="' + mode + '"' + (isLegacy ? ' data-rubric-legacy="1"' : '') + (options.disabled ? ' disabled' : '') + '><legend>평가 기준</legend>'
            + '<div class="oj-ml-rubric-toolbar"><div class="oj-ml-actions oj-ml-rubric-modes" data-rubric-modes role="group" aria-label="평가 방식"' + (isLegacy ? ' hidden' : '') + '>'
            + '<button type="button" data-rubric-mode-choice="criteria" aria-label="항목별 평가" aria-pressed="' + (mode === 'criteria') + '">항목별</button>'
            + '<button type="button" data-rubric-mode-choice="total" aria-label="총점과 피드백만 평가" aria-pressed="' + (mode === 'total') + '">총점</button></div>'
            + '<button type="button" data-variant="tinted" data-rubric-add aria-label="평가 항목 추가"' + (isLegacy ? ' disabled' : '') + '>항목 추가</button>'
            + '<details class="oj-ml-rubric-help" data-rubric-help><summary>도움말</summary><p>항목별 등급·점수·판단 기준을 정하면 교사와 선택적인 AI 평가 보조가 같은 기준을 사용합니다. AI 평가 플러그인이 없어도 채점·저장·공개할 수 있습니다.</p>'
            + '<p>새 과제의 기본안은 4개 항목, 총 100점입니다. 항목은 최대 25개, 등급은 항목당 2~10개입니다. 점수는 소수 둘째 자리까지 입력하며, 같은 항목의 등급 점수는 서로 달라야 합니다. 등급 최고점이 항목 배점이 됩니다.</p>'
            + '<p>기준 변경은 과제를 저장해야 반영됩니다. 게시 후에는 기준을 변경할 수 없고, 이전 평가 기록은 보존됩니다.</p></details></div>'
            + (isLegacy ? '<p data-rubric-legacy-notice>기존 기준을 보존하고 있습니다. 변경하려면 명시적으로 교체하세요.</p><button type="button" data-rubric-replace>등급별 기본안으로 교체</button>' : '')
            + (!isLegacy && version === 1 ? '<details class="oj-ml-rubric-help" data-rubric-upgrade-notice><summary>기존 자유 점수 방식 · 전환 옵션</summary><p>항목명·배점·설명을 유지하고 등급 기준을 추가합니다. 게시된 과제와 이전 평가는 자동 변환하지 않습니다.</p><button type="button" data-rubric-upgrade>등급별 루브릭으로 전환</button></details>' : '')
            + '<p class="oj-ml-rubric-empty" data-rubric-total-notice' + (mode === 'total' ? '' : ' hidden') + '>총점과 종합 피드백으로 평가합니다.</p>'
            + '<fieldset class="oj-ml-rubric-workspace" data-rubric-workspace' + (mode === 'criteria' ? '' : ' hidden disabled') + '><legend class="ojp-visually-hidden">평가 항목 작성</legend>'
            + '<div class="oj-ml-rubric-presets oj-ml-actions"><label>기본안<select data-rubric-preset-name><option value="basic">ML 탐구 기본형 · 4항목 / 100점</option><option value="experiments">실험 비교 중심형 · 4항목 / 100점</option></select></label>'
            + '<button type="button" data-rubric-preset aria-label="선택한 평가 기준 기본안 적용">적용</button></div>'
            + '<div data-rubric-rows>' + rowsHtml(items, version) + '</div>'
            + '</fieldset>'
            + '<p class="oj-ml-rubric-total" data-rubric-total aria-live="polite"></p><p data-rubric-error role="alert"></p></fieldset>';
    }
    function editorElement(container) {
        return container && container.matches && container.matches('[data-rubric-editor]') ? container : container && container.querySelector('[data-rubric-editor]');
    }
    function revealAncestors(node) {
        while (node) { if (node.tagName === 'DETAILS') node.open = true; node = node.parentElement; }
    }
    function readLevels(row) {
        var nodes = Array.from(row.querySelectorAll('[data-rubric-level-item]')), ids = new Set(), scores = new Set();
        if (nodes.length < 2 || nodes.length > 10) throw new Error('각 항목의 성취 등급은 2~10개여야 합니다.');
        return nodes.map(function (node) {
            var id = node.dataset.levelId, label = node.querySelector('[data-rubric-level-label]').value.trim();
            var description = node.querySelector('[data-rubric-level-description]').value.trim(), score = points(node.querySelector('[data-rubric-level-score]').value);
            if (!/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(id || '') || ids.has(id)) throw new Error('같은 항목 안의 등급 식별자는 중복 없이 올바른 형식이어야 합니다.');
            if (!label || Array.from(label).length > 200 || !description || Array.from(description).length > 2000) throw new Error('등급 이름은 1~200자, 판단 기준은 1~2,000자로 입력하세요.');
            if (score < 0 || score > 1000000 || scores.has(score)) throw new Error('등급 점수는 0~10,000점이며 같은 항목 안에서 서로 달라야 합니다.');
            ids.add(id); scores.add(score);
            return { id: id, label: label, description: description, score: score / 100 };
        });
    }
    function readEditor(container, includeInactive) {
        var editor = editorElement(container);
        if (!editor) return undefined;
        if (editor.dataset.rubricLegacy === '1') return JSON.parse(editor.dataset.rubricOriginal || '[]');
        if (!includeInactive && editor.dataset.rubricMode === 'total') return { version: 1, criteria: [] };
        var version = editor.dataset.rubricVersion === '2' ? 2 : 1;
        var seen = new Set();
        var rows = Array.from(editor.querySelectorAll('[data-rubric-item]'));
        if (!rows.length && editor.dataset.rubricMode === 'criteria') throw new Error('항목별 평가에는 평가 항목이 필요합니다. 항목을 추가하거나 총점 평가로 바꿔 주세요.');
        if (rows.length > 25) throw new Error('평가 항목은 최대 25개입니다.');
        return { version: version, criteria: rows.map(function (row) {
            try {
            var id = row.dataset.criterionId, title = row.querySelector('[data-rubric-title]').value.trim();
            var description = row.querySelector('[data-rubric-description]').value.trim();
            var levels = version === 2 ? readLevels(row) : null;
            var max = levels ? Math.max.apply(null, levels.map(function (level) { return points(level.score); })) : points(row.querySelector('[data-rubric-max]').value);
            if (!/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(id || '') || seen.has(id)) throw new Error('평가 항목 식별자가 중복되거나 올바르지 않습니다.');
            seen.add(id);
            if (!title || Array.from(title).length > 200 || Array.from(description).length > 2000) throw new Error('평가 제목과 설명의 글자 수를 확인하세요.');
            if (max < 1 || max > 1000000) throw new Error('배점은 0.01~10,000점입니다.');
            var item = { id: id, title: title, description: description, maxScore: max / 100 };
            if (levels) item.levels = levels;
            var evidence = row.querySelector('[data-rubric-evidence]');
            if (evidence && evidence.dataset.evidenceExplicit === '1') item.evidenceRequirements = Array.from(evidence.querySelectorAll('[data-rubric-evidence-key]:checked')).map(function (control) { return control.dataset.rubricEvidenceKey; });
            return item;
            } catch (error) { revealAncestors(row); throw error; }
        }) };
    }
    function updateEditor(editor) {
        var target = editor.querySelector('[data-rubric-total]');
        Array.from(editor.querySelectorAll('[data-rubric-item]')).forEach(function (row, index) {
            row.querySelector('[data-rubric-item-number]').textContent = '평가 항목 ' + (index + 1);
            row.querySelector('[data-rubric-summary-title]').textContent = row.querySelector('[data-rubric-title]').value.trim() || '새 평가 항목';
            row.parentElement.querySelector('[data-rubric-remove]').setAttribute('aria-label', '평가 항목 ' + (index + 1) + ' ' + row.querySelector('[data-rubric-summary-title]').textContent + ' 삭제');
            if (editor.dataset.rubricVersion === '2') {
                var levelRows = Array.from(row.querySelectorAll('[data-rubric-level-item]'));
                levelRows.forEach(function (level, levelIndex) {
                    level.querySelector('[data-rubric-level-number]').textContent = '등급 ' + (levelIndex + 1);
                    level.setAttribute('aria-label', '성취 등급 ' + (levelIndex + 1));
                });
                try {
                    var maximum = Math.max.apply(null, levelRows.map(function (level) { return points(level.querySelector('[data-rubric-level-score]').value); }));
                    row.querySelector('[data-rubric-max]').value = Number.isFinite(maximum) ? String(maximum / 100) : '';
                } catch (ignored) { row.querySelector('[data-rubric-max]').value = ''; }
            }
            var score = row.querySelector('[data-rubric-max]').value;
            row.querySelector('[data-rubric-summary-meta]').textContent = (score === '' ? '배점 확인' : score + '점') + ' · '
                + (editor.dataset.rubricVersion === '2' ? row.querySelectorAll('[data-rubric-level-item]').length + '등급' : '자유 점수');
        });
        try {
            var rubric = readEditor(editor), items = criteria(rubric);
            target.textContent = items.length ? (rubric.version === 2 ? '등급별 평가' : '항목별 자유 점수 평가') + ' · ' + items.length + '개 항목 · 총 배점 ' + (items.reduce(function (sum, item) { return sum + points(item.maxScore); }, 0) / 100) + '점'
                : editor.dataset.rubricLegacy === '1' ? '기존 기준 보존 · 총점과 종합 피드백으로 직접 평가합니다.' : '총점 평가 · 총점과 종합 피드백을 직접 입력합니다.';
        } catch (error) { target.textContent = error.message; }
    }
    function setMode(editor, mode) {
        editor.dataset.rubricMode = mode;
        editor.querySelector('[data-rubric-modes]').hidden = false;
        editor.querySelectorAll('[data-rubric-mode-choice]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.dataset.rubricModeChoice === mode));
        });
        var workspace = editor.querySelector('[data-rubric-workspace]');
        workspace.hidden = mode !== 'criteria';
        workspace.disabled = mode !== 'criteria';
        editor.querySelector('[data-rubric-total-notice]').hidden = mode !== 'total';
        editor.querySelector('[data-rubric-add]').disabled = editor.dataset.rubricLegacy === '1';
    }
    function bindEditor(container) {
        var editor = editorElement(container);
        if (!editor || editor.dataset.rubricBound) return;
        editor.dataset.rubricBound = '1';
        editor.addEventListener('change', function (event) {
            if (event.target.hasAttribute('data-rubric-evidence-key')) event.target.closest('[data-rubric-evidence]').dataset.evidenceExplicit = '1';
        });
        editor.addEventListener('input', function () { updateEditor(editor); });
        editor.addEventListener('invalid', function (event) {
            // Keep native validation usable in standalone editors and nested console sections.
            revealAncestors(event.target);
        }, true);
        editor.addEventListener('click', async function (event) {
            var button = event.target.closest('button');
            if (!button || editor.disabled || button.matches(':disabled') || !editor.contains(button)) return;
            var rows = editor.querySelector('[data-rubric-rows]'), error = editor.querySelector('[data-rubric-error]');
            var version = editor.dataset.rubricVersion === '2' ? 2 : 1;
            error.textContent = '';
            try {
            if (button.hasAttribute('data-rubric-mode-choice')) {
                var mode = button.dataset.rubricModeChoice;
                if (mode === editor.dataset.rubricMode) return;
                if (mode === 'total' && rows.children.length && !await ask({ title: '총점·피드백 평가로 바꿀까요?', message: '저장하면 항목별 기준을 사용하지 않습니다. 저장 전에는 항목별 평가를 다시 선택하여 작성한 내용을 되돌릴 수 있습니다.', confirmLabel: '총점 평가로 바꾸기' })) return;
                if (mode === 'criteria' && !rows.children.length) rows.innerHTML = rowsHtml(version === 2 ? withLevels(presetItems('basic'), true) : presetItems('basic'), version);
                setMode(editor, mode);
            } else if (button.hasAttribute('data-rubric-upgrade')) {
                var original = readEditor(editor, true).criteria;
                if (!await ask({ title: '등급별 루브릭으로 전환할까요?', message: '기존 항목명·설명·배점을 유지하고 등급별 점수와 기준의 초안을 만듭니다. 저장 전에 각 등급을 확인하세요. 이전 평가 기록은 바뀌지 않습니다.', confirmLabel: '등급별로 전환' })) return;
                editor.dataset.rubricVersion = '2';
                rows.innerHTML = rowsHtml(original.length ? withLevels(original, false) : withLevels(presetItems('basic'), true), 2);
                var upgrade = editor.querySelector('[data-rubric-upgrade-notice]'); if (upgrade) upgrade.remove();
                setMode(editor, 'criteria');
            } else if (button.hasAttribute('data-rubric-replace')) {
                if (!await ask({ title: '기존 채점 기준을 등급별 기본안으로 바꿀까요?', message: '기존 형식은 새 기본안으로 교체됩니다. 과제를 저장하기 전까지 서버의 기존 기준은 바뀌지 않습니다.', confirmLabel: '바꾸기' })) return;
                delete editor.dataset.rubricLegacy;
                editor.dataset.rubricVersion = '2';
                button.remove();
                var notice = editor.querySelector('[data-rubric-legacy-notice]');
                if (notice) notice.remove();
                rows.innerHTML = rowsHtml(withLevels(presetItems('basic'), true), 2);
                setMode(editor, 'criteria');
            } else if (button.hasAttribute('data-rubric-add')) {
                if (editor.dataset.rubricLegacy === '1') return;
                if (rows.children.length >= 25) { error.textContent = '평가 항목은 최대 25개입니다.'; return; }
                setMode(editor, 'criteria');
                rows.insertAdjacentHTML('beforeend', rowHtml({}, version, true));
                rows.lastElementChild.querySelector('input').focus();
            } else if (button.hasAttribute('data-rubric-remove')) {
                var wrapper = button.closest('[data-rubric-row]');
                var title = wrapper.querySelector('[data-rubric-title]').value.trim() || '새 평가 항목';
                button.disabled = true;
                var confirmed;
                try { confirmed = await ask({ title: '이 평가 항목을 삭제할까요?', message: '“' + title + '”의 배점과 등급별 기준을 함께 삭제합니다. 과제를 저장하기 전까지 서버의 기존 기준은 바뀌지 않습니다.', confirmLabel: '항목 삭제' }); }
                finally { button.disabled = false; }
                if (editor.disabled || !editor.contains(wrapper)) return;
                if (!confirmed) { button.focus(); return; }
                var adjacent = wrapper.nextElementSibling || wrapper.previousElementSibling;
                wrapper.remove();
                (adjacent ? adjacent.querySelector('summary') : editor.querySelector('[data-rubric-add]')).focus();
            } else if (button.hasAttribute('data-rubric-level-add')) {
                var holder = button.closest('[data-rubric-item]').querySelector('[data-rubric-levels-editor]');
                if (holder.children.length >= 10) { error.textContent = '각 항목의 성취 등급은 최대 10개입니다.'; return; }
                holder.insertAdjacentHTML('beforeend', levelRowHtml({}));
                holder.lastElementChild.querySelector('input').focus();
            } else if (button.hasAttribute('data-rubric-level-remove')) {
                var level = button.closest('[data-rubric-level-item]');
                if (level.parentElement.children.length <= 2) { error.textContent = '각 항목의 성취 등급은 최소 2개를 유지해야 합니다.'; return; }
                level.remove();
            } else if (button.hasAttribute('data-rubric-preset')) {
                if (rows.children.length && !await ask({ title: '선택한 100점 기본안으로 바꿀까요?', message: '작성 중인 평가 항목과 배점·기준이 선택한 기본안으로 바뀝니다.', confirmLabel: '기본안 적용' })) return;
                var items = presetItems(editor.querySelector('[data-rubric-preset-name]').value);
                rows.innerHTML = rowsHtml(version === 2 ? withLevels(items, true) : items, version);
            }
            } catch (problem) { error.textContent = problem.message || '평가 기준을 확인하세요.'; }
            updateEditor(editor);
        });
        updateEditor(editor);
    }
    function criteriaHtml(rubric) {
        var items = criteria(rubric);
        if (!items.length) return '';
        return '<section class="oj-ml-rubric-summary"><h4>평가 기준</h4><ol>'
            + items.map(function (item) { return '<li><strong>' + html(item.title) + ' · ' + html(item.maxScore) + '점</strong>'
                + (rubric.version === 2 ? '' : '<p class="oj-ml-rubric-description">' + html(item.description || '') + '</p>')
                + (rubric.version === 2 ? levelsHtml(item, null) : '') + '</li>'; }).join('') + '</ol></section>';
    }
    function levelsHtml(criterion, selectedId) {
        return '<details class="oj-ml-rubric-level-guide"><summary>등급별 점수·판단 기준</summary>'
            + (criterion.description ? '<p class="oj-ml-rubric-description">' + html(criterion.description) + '</p>' : '') + '<ol>'
            + (Array.isArray(criterion.levels) ? criterion.levels.slice(0, 10) : []).map(function (level) {
                return '<li' + (level.id === selectedId ? ' data-selected-level="1"' : '') + '><strong>' + html(level.label) + ' · ' + html(level.score) + '점'
                    + '</strong><span data-rubric-level-selection' + (level.id === selectedId ? '' : ' hidden') + '> (선택한 등급)</span><p class="oj-ml-rubric-description">' + html(level.description) + '</p></li>';
            }).join('') + '</ol></details>';
    }
    function gradeAdjustmentHtml(criterion, previous, levels) {
        var panelId = makeId('grade_picker');
        return '<label class="oj-ml-grade-canonical" hidden aria-hidden="true">평가 등급<select data-rubric-level tabindex="-1" aria-label="' + html(criterion.title) + ' 평가 등급 선택"><option value="">등급 선택</option>'
            + levels.map(function (level) { return '<option value="' + html(level.id) + '"' + (previous.levelId === level.id ? ' selected' : '') + '>' + html(level.label) + ' · ' + html(level.score) + '점</option>'; }).join('')
            + '</select></label><input data-rubric-score type="hidden" value="' + html(previous.score == null ? '' : previous.score) + '">'
            + '<div class="oj-ml-grade-current"><p class="oj-ml-rubric-selected-level" data-rubric-selected-level aria-live="polite"></p>'
            + '<button type="button" class="oj-ml-grade-adjust" data-rubric-grade-adjust aria-expanded="false" aria-controls="' + panelId + '"><strong data-rubric-grade-score>등급 선택</strong><span data-rubric-grade-action>점수 조정</span></button></div>'
            + '<div class="oj-ml-grade-picker" data-rubric-grade-picker id="' + panelId + '" hidden><div class="oj-ml-grade-choices" role="group" aria-label="' + html(criterion.title) + ' 등급별 점수 조정">'
            + levels.map(function (level) { return '<button type="button" class="oj-ml-grade-choice" data-rubric-grade-choice="' + html(level.id) + '" aria-pressed="false"><span class="oj-ml-grade-choice__head"><strong>' + html(level.label) + ' · ' + html(level.score) + '점</strong><span data-rubric-grade-selected hidden>선택됨</span></span><span class="oj-ml-grade-choice__description">' + html(level.description) + '</span></button>'; }).join('')
            + '</div><div class="oj-ml-actions"><button type="button" data-rubric-grade-clear>선택 지우기</button><button type="button" data-rubric-grade-close>닫기</button></div><p class="oj-ml-meta">변경한 평가는 아래 저장 버튼을 눌러야 반영됩니다.</p></div>';
    }
    function reviewFields(rubric, review) {
        var details = review && review.details || null;
        var snapshot = details && details.rubricSnapshot || rubric;
        var items = criteria(snapshot);
        if (!items.length) return '';
        var scores = new Map((details && Array.isArray(details.criteriaScores) ? details.criteriaScores : []).map(function (entry) { return [entry.id, entry]; }));
        var graded = snapshot.version === 2;
        return '<fieldset class="oj-ml-rubric-review" data-rubric-review data-rubric-version="' + (graded ? 2 : 1) + '"><legend>항목별 평가</legend>'
            + '<details class="oj-ml-rubric-help" data-rubric-review-help><summary>채점 도움말</summary><p>'
            + (graded ? '판단 기준에 맞는 등급을 선택하면 해당 점수로 채점됩니다. ' : '기존 자유 점수 기준으로 채점합니다. ')
            + 'AI 플러그인 없이도 저장·공개할 수 있습니다. 점수는 자동 합산됩니다. 피드백만 저장하려면 모든 ' + (graded ? '등급 선택을 비우세요.' : '점수를 비우세요.') + '</p></details>'
            + items.map(function (item, index) {
                var previous = scores.get(item.id) || {};
                var levels = Array.isArray(item.levels) ? item.levels : [];
                var scoreControl = graded ? gradeAdjustmentHtml(item, previous, levels)
                    : '<label>항목 점수 (최대 ' + html(item.maxScore) + '점)<input data-rubric-score type="number" min="0" max="' + html(item.maxScore) + '" step="0.01" value="' + html(previous.score == null ? '' : previous.score) + '"></label>';
                return '<div class="oj-ml-preview-card oj-ml-rubric-item" data-rubric-score-item data-criterion-id="' + html(item.id) + '" data-max-score="' + html(item.maxScore) + '"'
                    + (graded ? ' data-rubric-levels="' + html(JSON.stringify(levels)) + '"' : '') + '><div class="oj-ml-rubric-item__head"><h5>' + (index + 1) + '. ' + html(item.title) + '</h5><span>' + html(item.maxScore) + '점</span></div>'
                    + (!graded && item.description ? '<p class="oj-ml-rubric-description">' + html(item.description) + '</p>' : '')
                    + scoreControl
                    + '<label>피드백<textarea data-rubric-feedback aria-label="' + html(item.title) + ' 항목 피드백, 선택 입력" maxlength="2000" rows="2" placeholder="필요한 피드백을 남기세요.">' + html(previous.feedback || '') + '</textarea></label></div>';
            }).join('') + '<p class="oj-ml-rubric-total" data-rubric-review-total aria-live="polite"></p></fieldset>';
    }
    function readReview(container) {
        var area = container && container.matches && container.matches('[data-rubric-review]') ? container : container && container.querySelector('[data-rubric-review]');
        if (!area) return null;
        var entries = Array.from(area.querySelectorAll('[data-rubric-score-item]')).map(function (row) {
            var graded = area.dataset.rubricVersion === '2', levelId = null, score = null;
            if (graded) {
                var selected = row.querySelector('[data-rubric-level]').value;
                var level = JSON.parse(row.dataset.rubricLevels || '[]').find(function (item) { return item.id === selected; });
                if (selected && !level) throw new Error('과제에 정의된 평가 등급을 선택하세요.');
                if (level) { levelId = level.id; score = points(level.score); }
            } else {
                var raw = row.querySelector('[data-rubric-score]').value.trim(); score = raw === '' ? null : points(raw);
            }
            var maximum = points(row.dataset.maxScore), feedback = row.querySelector('[data-rubric-feedback]').value.trim();
            if (score !== null && (score < 0 || score > maximum)) throw new Error('각 점수는 0점부터 해당 항목 배점 사이여야 합니다.');
            if (Array.from(feedback).length > 2000) throw new Error('항목 피드백은 2,000자 이내로 입력하세요.');
            var entry = { id: row.dataset.criterionId, score: score === null ? null : score / 100, feedback: feedback };
            if (graded) entry.levelId = levelId;
            return entry;
        });
        var scored = entries.filter(function (entry) { return entry.score !== null; }).length;
        if (scored && scored !== entries.length) throw new Error(area.dataset.rubricVersion === '2'
            ? '모든 항목의 등급을 선택하거나, 모든 등급 선택을 비워 피드백만 저장하세요.'
            : '모든 항목에 점수를 입력하거나, 모두 비워 피드백만 저장하세요.');
        return { criteriaScores: entries };
    }
    function bindReview(container) {
        var area = container && container.matches && container.matches('[data-rubric-review]') ? container : container && container.querySelector('[data-rubric-review]');
        if (!area || area.dataset.rubricBound) return;
        area.dataset.rubricBound = '1';
        function update() {
            var target = area.querySelector('[data-rubric-review-total]');
            try {
                if (area.dataset.rubricVersion === '2') area.querySelectorAll('[data-rubric-score-item]').forEach(function (row) {
                    var selected = row.querySelector('[data-rubric-level]').value;
                    var levels = JSON.parse(row.dataset.rubricLevels || '[]');
                    var level = levels.find(function (item) { return item.id === selected; });
                    row.querySelector('[data-rubric-score]').value = level ? String(level.score) : '';
                    row.querySelector('[data-rubric-selected-level]').textContent = level ? level.label + ' · ' + level.score + '점\n' + level.description : '';
                    row.querySelector('[data-rubric-selected-level]').hidden = !level;
                    row.querySelector('[data-rubric-grade-score]').textContent = level ? level.score + ' / ' + row.dataset.maxScore + '점' : '등급 선택';
                    row.querySelector('[data-rubric-grade-action]').textContent = level ? '점수 조정' : '기준 보고 채점';
                    row.querySelector('[data-rubric-grade-adjust]').setAttribute('aria-label', (level ? level.label + ' ' + level.score + '점, 점수 조정' : '평가 등급 선택'));
                    row.querySelectorAll('[data-rubric-grade-choice]').forEach(function (button) {
                        var active = button.dataset.rubricGradeChoice === selected;
                        button.setAttribute('aria-pressed', String(active));
                        button.toggleAttribute('data-selected-level', active);
                        button.querySelector('[data-rubric-grade-selected]').hidden = !active;
                    });
                });
                var values = readReview(area).criteriaScores;
                var max = Array.from(area.querySelectorAll('[data-rubric-score-item]')).reduce(function (sum, row) { return sum + points(row.dataset.maxScore); }, 0);
                target.textContent = values.some(function (entry) { return entry.score !== null; })
                    ? '합계 ' + (values.reduce(function (sum, entry) { return sum + points(entry.score); }, 0) / 100) + ' / ' + max / 100 + '점 · 저장 시 서버가 다시 합산합니다.'
                    : '점수 없이 피드백만 저장합니다.';
            } catch (error) { target.textContent = error.message; }
        }
        area.addEventListener('input', update);
        area.addEventListener('change', update);
        function togglePicker(row, open, focus) {
            var picker = row.querySelector('[data-rubric-grade-picker]');
            var trigger = row.querySelector('[data-rubric-grade-adjust]');
            if (!picker || !trigger) return;
            picker.hidden = !open; trigger.setAttribute('aria-expanded', String(open));
            if (focus) (open ? picker.querySelector('[aria-pressed="true"]') || picker.querySelector('[data-rubric-grade-choice]') || trigger : trigger).focus();
        }
        area.addEventListener('click', function (event) {
            var button = event.target.closest('button');
            var form = area.closest('form');
            if (!button || !area.contains(button) || button.matches(':disabled') || area.disabled || (form && form.dataset.reviewSaving === '1')) return;
            var row = button.closest('[data-rubric-score-item]');
            if (!row) return;
            if (button.hasAttribute('data-rubric-grade-adjust')) togglePicker(row, button.getAttribute('aria-expanded') !== 'true', true);
            else if (button.hasAttribute('data-rubric-grade-close')) togglePicker(row, false, true);
            else if (button.hasAttribute('data-rubric-grade-choice') || button.hasAttribute('data-rubric-grade-clear')) {
                var select = row.querySelector('[data-rubric-level]');
                if (!select || select.matches(':disabled')) return;
                select.value = button.hasAttribute('data-rubric-grade-choice') ? button.dataset.rubricGradeChoice : '';
                select.dispatchEvent(new global.Event('change', { bubbles: true }));
                togglePicker(row, false, true);
            }
        });
        area.addEventListener('keydown', function (event) {
            var form = area.closest('form');
            if (form && form.dataset.reviewSaving === '1') return;
            var picker = event.target.closest('[data-rubric-grade-picker]');
            if (event.key === 'Escape' && picker && !picker.hidden) { event.preventDefault(); togglePicker(picker.closest('[data-rubric-score-item]'), false, true); }
        });
        update();
    }
    function releasedHtml(review) {
        if (!review || review.status !== 'released' || !review.details) return '';
        var details = review.details, items = criteria(details.rubricSnapshot), graded = details.rubricSnapshot && details.rubricSnapshot.version === 2;
        if (!items.length) return '';
        var scores = new Map((Array.isArray(details.criteriaScores) ? details.criteriaScores : []).map(function (entry) { return [entry.id, entry]; }));
        return '<section class="oj-ml-rubric-results"><h4>공개된 항목별 평가</h4><ol>'
            + items.map(function (item) { var score = scores.get(item.id) || {};
                var selected = graded && Array.isArray(item.levels) ? item.levels.find(function (level) { return level.id === score.levelId; }) : null;
                return '<li><strong>' + html(item.title) + '</strong> · ' + (score.score == null ? '점수 없음' : html(score.score) + ' / ' + html(item.maxScore) + '점')
                    + (graded ? '<p><strong>선택 등급: ' + html(selected ? selected.label : '선택 안 함') + '</strong></p>'
                        + (selected ? '<p class="oj-ml-rubric-selected-level">' + html(selected.description) + '</p>' : '')
                        : '<p class="oj-ml-rubric-description">' + html(item.description) + '</p>')
                    + (score.feedback ? '<p class="oj-ml-preview-answer">' + html(score.feedback) + '</p>' : '')
                    + (graded ? levelsHtml(item, selected && selected.id) : '') + '</li>';
            }).join('') + '</ol></section>';
    }
    global.OJMLRubric = {
        editorHtml: editorHtml, bindEditor: bindEditor, readEditor: readEditor,
        criteriaHtml: criteriaHtml, reviewFields: reviewFields, readReview: readReview,
        bindReview: bindReview, releasedHtml: releasedHtml
    };
})(window);

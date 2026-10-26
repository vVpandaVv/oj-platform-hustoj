import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');

function extract(name) {
	const pattern = new RegExp(`\\tfunction ${name}\\([^\\n]*\\) \\{[\\s\\S]*?\\n\\t\\}`);
	const match = source.match(pattern);
	assert.ok(match, `${name} helper must exist.`);
	return match[0].trim();
}

const helpers = [
	'escapeHtml',
	'formatDate',
	'statusLabel',
	'submissionFactRow',
	'submissionBooleanLabel',
	'submissionNumberLabel',
	'submissionFactsHtml',
	'previewDisplay',
	'previewDlRow',
	'previewNameValueHtml',
	'previewStringListHtml',
	'previewPortfolioHtml',
	'previewParameterGroupsHtml',
	'previewSettingsHtml',
	'previewPerClassHtml',
	'previewConfusionMatrixHtml',
	'previewImportanceHtml',
	'previewRunHtml',
	'previewExperimentsHtml',
	'previewDatasetContextHtml',
	'previewDatasetProfilesHtml',
	'submissionPreviewHtml',
	'findingsHtml',
	'resultReviewForDisplay',
	'resultDetailsRowHtml',
].map(extract).join('\n');
const { submissionFactsHtml, submissionPreviewHtml, resultReviewForDisplay, resultDetailsRowHtml } = vm.runInNewContext(
	`(() => { ${helpers}; return { submissionFactsHtml, submissionPreviewHtml, resultReviewForDisplay, resultDetailsRowHtml }; })()`,
);

const html = submissionFactsHtml({
	facts: {
		projectName: '<img src=x onerror=alert(1)>',
		projectCreatedAt: '2026-08-31T00:00:00.000Z',
		projectUpdatedAt: '2026-09-01T00:00:00.000Z',
		taskType: 'classification',
		dataType: 'tabular',
		runCount: 2,
		algorithms: ['decision_tree', '<script>alert(1)</script>'],
		formatVersion: 2,
		appVersion: '0.12.1',
		entryCount: 7,
		totalUncompressedBytes: 12345,
		integrity: { present: true, verified: true, entryHashes: { 'secret.csv': 'do-not-render' } },
		tabular: {
			applicable: true,
			datasetPresent: true,
			datasetPath: 'dataset/private-name.csv',
			hasHeader: true,
			rows: 42,
			columns: 5,
			currentFeatureCount: 3,
			maxSelectedFeatureCount: 4,
		},
	},
});

assert.match(html, /제출 내용 요약/);
assert.match(html, /프로젝트 이름/);
assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
assert.doesNotMatch(html, /<img/);
assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
assert.match(html, /데이터 행/);
assert.match(html, /현재 선택 특성/);
assert.doesNotMatch(html, /do-not-render|dataset\/private-name\.csv|entryHashes|datasetPath/);

const oldFacts = submissionFactsHtml({ facts: { taskType: 'regression', dataType: 'tabular', runCount: 1 } });
assert.match(oldFacts, /회귀/);
assert.match(submissionFactsHtml(null), /서버 검사가 완료되면/);

const previewHtml = submissionPreviewHtml({
	project: {
		name: '<img src=x onerror=alert(1)>',
		appVersion: '0.12.1',
		formatVersion: 2,
		taskType: 'classification',
		dataType: 'tabular',
	},
	portfolio: {
		sections: [{
			title: '<script>alert(2)</script>',
			description: '<img src=x>',
			answered: true,
			answer: "<script>alert('answer')</script>\n둘째 줄",
			attachmentCount: 1,
		}],
	},
	settings: {
		runtime: 'browser',
		split: { method: 'holdout', testSize: 0.2, stratify: true, randomState: 42 },
		selectedAlgorithms: [{ algorithm: '<img src=x>', runtime: 'browser' }],
		hyperparameters: [{ algorithm: 'tree', runtime: 'browser', items: [{ name: '<script>x</script>', value: '5' }] }],
		data: {
			type: 'tabular',
			datasetConfigured: true,
			features: ['age', '<script>feature</script>'],
			target: 'label',
			preprocessing: { missing: 'drop', scaling: 'standard', categoricalEncoding: 'onehot' },
		},
	},
	experiments: [{
		startedAt: '2026-09-01T00:00:00Z',
		taskType: 'classification',
		runtime: 'browser',
		split: { method: 'holdout' },
		runs: [{
			algorithm: '<img src=x onerror=alert(3)>',
			status: 'done',
			metrics: [{ name: 'accuracy', value: 0.9 }],
			hyperparameters: [{ name: 'depth', value: '5' }],
			perClass: [{ label: '<script>class</script>', precision: 1, recall: 1, f1: 1, support: 2 }],
			confusionMatrix: { labels: ['<img src=x>', 'B'], matrix: [[1, 0], [0, 1]] },
			featureImportance: [{ feature: '<script>age</script>', importance: 0.8 }],
		}],
	}],
	notices: ['<script>notice</script>'],
});

assert.match(previewHtml, /읽기 전용 안전 보기/);
assert.match(previewHtml, /포트폴리오 답변/);
assert.match(previewHtml, /기록된 평가 지표/);
assert.match(previewHtml, /&lt;script&gt;alert\(&#039;answer&#039;\)&lt;\/script&gt;/);
assert.match(previewHtml, /&lt;img src=x onerror=alert\(1\)&gt;/);
assert.doesNotMatch(previewHtml, /<script|<img/);
const sensitiveAttempt = {
	id: 'attempt-sensitive',
	status: 'accepted',
	receivedAt: '2026-09-01T00:00:00Z',
	clientAppVersion: '0.12.1',
	formatVersion: 2,
	artifact: {
		originalName: 'ORIGINAL_NAME_SENTINEL.mlpx',
		bytes: 123,
		sha256: 'SHA_SENTINEL',
		download: {
			url: '/DOWNLOAD_URL_SENTINEL',
			action: 'DOWNLOAD_ACTION_SENTINEL',
			attempt: 'attempt-sensitive',
			nonce: 'DOWNLOAD_NONCE_SENTINEL',
		},
	},
	evaluation: null,
	review: {
		version: 4,
		status: 'draft',
		score: 9,
		maxScore: 10,
		feedback: 'DRAFT_FEEDBACK_SENTINEL',
		reviewerId: 77,
	},
};
const deniedHtml = resultDetailsRowHtml({ canEvaluate: false, attempt: sensitiveAttempt }, true);
for (const sentinel of ['ORIGINAL_NAME_SENTINEL', 'DOWNLOAD_URL_SENTINEL', 'DOWNLOAD_NONCE_SENTINEL', 'DRAFT_FEEDBACK_SENTINEL']) {
	assert.doesNotMatch(deniedHtml, new RegExp(sentinel));
}
assert.doesNotMatch(deniedHtml, /data-oj-ml-review-form|data-oj-ml-preview|oj-ml-download-form|data-reinspect-id/);
assert.match(deniedHtml, /123 bytes/);
assert.equal(resultReviewForDisplay({ canEvaluate: false, attempt: sensitiveAttempt }, true), null);
const releasedReview = resultReviewForDisplay({
	canEvaluate: false,
	attempt: { review: { version: 3, status: 'released', score: 8, maxScore: 10, feedback: '' } },
}, true);
assert.equal(releasedReview.status, 'released');
assert.equal(releasedReview.score, 8);
const fullHtml = resultDetailsRowHtml({ canEvaluate: true, attempt: sensitiveAttempt }, true);
for (const sentinel of ['ORIGINAL_NAME_SENTINEL', 'DOWNLOAD_URL_SENTINEL', 'DOWNLOAD_NONCE_SENTINEL', 'DRAFT_FEEDBACK_SENTINEL']) {
	assert.match(fullHtml, new RegExp(sentinel));
}
assert.match(fullHtml, /data-oj-ml-review-form/);
assert.match(fullHtml, /data-oj-ml-preview/);
assert.match(fullHtml, /oj-ml-download-form/);
const noGlobalHtml = resultDetailsRowHtml({ canEvaluate: true, attempt: sensitiveAttempt }, false);
const missingContextFlagHtml = resultDetailsRowHtml({ attempt: sensitiveAttempt }, true);
for (const restrictedHtml of [noGlobalHtml, missingContextFlagHtml]) {
	assert.doesNotMatch(restrictedHtml, /ORIGINAL_NAME_SENTINEL|DOWNLOAD_NONCE_SENTINEL|DRAFT_FEEDBACK_SENTINEL/);
	assert.doesNotMatch(restrictedHtml, /data-oj-ml-review-form|data-oj-ml-preview|oj-ml-download-form|data-reinspect-id/);
}
const pendingAttempt = { ...sensitiveAttempt, status: 'quarantined', artifact: null, review: null };
assert.match(resultDetailsRowHtml({ canEvaluate: true, attempt: pendingAttempt }, true), /data-reinspect-id/);
assert.doesNotMatch(resultDetailsRowHtml({ canEvaluate: false, attempt: pendingAttempt }, true), /data-reinspect-id/);
assert.match(source, /method=\"post\"/);
assert.match(source, /name=\"_wpnonce\"/);
assert.match(source, /var canEvaluate = mayReview && row\.canEvaluate === true/);
assert.match(source, /var downloadSpec = canEvaluate && artifact && artifact\.download/);
assert.doesNotMatch(source, /artifact\.downloadUrl/);
assert.match(source, /api\.post\('console\/attempts\/' \+ encodeURIComponent\(attemptId\) \+ '\/preview'/);
assert.match(source, /var canPreview = canEvaluate && artifact/);
assert.match(source, /canEvaluate && artifact\.originalName/);
assert.match(source, /data-oj-ml-result-details/);
assert.doesNotMatch(source, /api\.get\([^\n]*\/preview/);

console.log('OJ ML submission content UI regression passed.');

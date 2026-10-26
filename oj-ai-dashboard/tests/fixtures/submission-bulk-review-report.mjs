// In-memory server contract for browser checks. No real AI or student data.
export function installBulkFixture({ stage, mode = 'normal' }) {
  const hash = 'a'.repeat(64);
  const context = { type: 'group', id: 'group:10', revision: 'membership-4', label: '검증 1학년 1반', selectable: true };
  const assignment = { id: 'assignment-1', worksheet_id: 'worksheet-1', title: '디지털 사회 탐구', state: 'open', schedule_version: 1 };
  const details = new Map();
  const rows = [];
  const notes = new Set(['evaluation-3']);
  const reviewed = stage === 'report';
  for (let n = 1; n <= 6; n += 1) {
    const id = `evaluation-${n}`;
    const learner = { user_id: n, display_name: `학생 ${n}`, login: `1010${n}`, management_name: `1반 ${n}번` };
    const evaluation = { id, state: reviewed ? 'reviewed' : 'review_required', assignment, context, learner,
      submission: { id: `submission-${n}`, state: 'submitted', source_revision_id: `revision-${n}`, is_source_current: true, submitted_at_utc: '2026-09-21 01:00:00' },
      candidate: { id: `result-${n}`, score: '16', maximum_score: '20', confidence: '0.9', abstained: false, safety_flagged: false },
      priority: { review_needed_count: 0 }, feedback: { count: 0 }, release: {},
      review: { official_scope: reviewed ? 'this_evaluation' : 'none', official_id: reviewed ? `official-${n}` : null },
      latest_run: { id: `run-${n}`, run_no: 1, state: 'succeeded' } };
    const detail = { evaluation, selected_result: { id: `result-${n}`, abstained: false, safety_flagged: false, summary: '주어진 자료의 근거를 연결하여 설명함.',
      items: [{ binding_key: hash, criterion_id: 'criterion-1', label: '근거 설명', decision: 'score', candidate_points: '16', maximum_points: '20', flags: [], evidence: [], position: 0 }] },
      review_precondition: { expected_result_id: `result-${n}`, expected_result_hash: hash, expected_official_review_id: reviewed ? `official-${n}` : null, expected_official_review_hash: reviewed ? hash : null, expected_review_no: reviewed ? 1 : 0 },
      reviews: reviewed ? [{ id: `official-${n}`, is_official: true, review_no: 1, decision: 'accepted', official_score: '16' }] : [], feedback: [], runs: [],
      source: { responses: [], bindings: [{ binding_key: hash, label: '근거 설명', maximum_points: '20' }] } };
    if (reviewed) {
      if (n === 2) { detail.feedback = [{ id: 'feedback-2', state: 'approved', review_id: 'official-2', profile: { value: { source_hash: hash } } }]; evaluation.feedback.count = 1; }
      if (n === 4) detail.runs = [{ operation: 'feedback_draft', state: 'queued' }, { operation: 'special_note_draft', state: 'queued' }];
    } else {
      if (n === 3) { detail.selected_result.abstained = true; detail.selected_result.items[0].candidate_points = null; }
      if (n === 4) { evaluation.state = 'reviewed'; evaluation.review.official_id = 'already-reviewed'; }
    }
    if (n === 5) evaluation.submission.is_source_current = false;
    details.set(id, detail);
    rows.push({ id: `target-${n}`, learner, assigned: n !== 6, current_member: true, status: 'submitted',
      submission: { id: `submission-${n}`, official_revision_id: `revision-${n}` } });
  }
  const clone = value => JSON.parse(JSON.stringify(value));
  const envelope = (data, meta = {}) => ({ data: clone(data), meta });
  const response = (payload, status = 200) => ({ ok: status < 400, status, json: async () => clone(payload) });
  window.__bulkPosts = [];
  window.__bulkReads = [];
  window.__bulkMode = mode;
  window.__bulkMutate = id => { details.get(id).review_precondition.expected_result_hash = 'b'.repeat(64); };
  window.__bulkReadCount = 0;
  const receipts = new Map();
  window.fetch = async (url, options = {}) => {
    const value = new URL(url);
    const path = value.pathname.replace('/wp-json/oj-ai/v1', '');
    const query = value.searchParams;
    if (options.method === 'POST') {
      const body = JSON.parse(options.body);
      const id = path.split('/')[2];
      const d = details.get(id);
      window.__bulkPosts.push({ path, body, key: options.headers['Idempotency-Key'] });
      if (window.__bulkMode === 'denied') return response({ message: '권한이 변경되었습니다.', data: { status: 403 } }, 403);
      if (window.__bulkMode === 'conflict' && id === 'evaluation-1') return response({ message: '수정 충돌', data: { status: 409 } }, 409);
      if (receipts.has(body.command_id)) return response(receipts.get(body.command_id));
      if (window.__bulkMode === 'slow') await new Promise(resolve => { window.__bulkResolve = resolve; });
      let result;
      if (path.endsWith('/review')) {
        d.evaluation.state = 'reviewed';
        d.evaluation.review = { official_scope: 'this_evaluation', official_id: `official-${id}` };
        result = envelope({ evaluation: { id }, result: { id: body.expected.expected_result_id, hash: body.expected.expected_result_hash }, review: { id: `official-${id}`, decision: 'accepted' }, official_review: { id: `official-${id}` } });
      } else {
        const operation = path.endsWith('/generate-feedback') ? 'feedback_draft' : 'special_note_draft';
        d.runs.unshift({ id: `new-${id}`, state: 'queued', operation });
        result = envelope({ evaluation_id: id, official_review: { id: body.expected.expected_official_review_id, hash: body.expected.expected_official_review_hash }, run: { id: `new-${id}`, state: 'queued', operation } });
      }
      receipts.set(body.command_id, result);
      if (window.__bulkMode === 'lost') { window.__bulkMode = 'normal'; throw new Error('응답 연결 끊김'); }
      return response(result);
    }
    window.__bulkReads.push(path + value.search);
    if (path === '/contexts') return response(envelope({ items: [context] }));
    if (path === '/assignments') return response(envelope({ items: [assignment] }));
    if (path === '/submission-evaluation-roster') return response(envelope({ items: rows }, { server_time_utc: '2026-09-21 02:00:00' }));
    if (path === '/submission-evaluation-candidates') return response(envelope({ items: [] }));
    if (path === '/submission-evaluations') {
      const values = [...details.values()].map(d => d.evaluation);
      const second = query.get('cursor') === 'page2';
      return response(envelope({ items: second ? values.slice(2) : values.slice(0, 2) }, { next_cursor: second ? '' : 'page2' }));
    }
    if (path.startsWith('/submission-evaluations/')) {
      window.__bulkReadCount += 1;
      return response(envelope(details.get(path.split('/')[2])));
    }
    if (path === '/student-notes') return response(envelope({ items: [...notes].map(id => ({ id: `note-${id}`, evaluation: { id } })) }));
    if (path.startsWith('/student-notes/')) return response(envelope({ note: { id: path.split('/')[2] }, revisions: [], precondition: {} }));
    return response({ message: 'Fixture route missing: ' + path, data: { status: 404 } }, 404);
  };
}

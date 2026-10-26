(function (window, document) {
  'use strict';

  const shell = document.querySelector('[data-oj-ai-assessment-shell]');
  const root = document.querySelector('#oj-ai-submission-review-app');

  function resolveTimeZone(raw) {
    const candidate = raw || 'Asia/Seoul';
    try {
      new Intl.DateTimeFormat('en-US', { timeZone: candidate });
      return candidate;
    } catch (_error) {
      const match = candidate.match(/^([+-])(\d{2}):(\d{2})$/);
      if (match && match[3] === '00') {
        const etc = `Etc/GMT${match[1] === '-' ? '+' : '-'}${Number(match[2])}`;
        try {
          new Intl.DateTimeFormat('en-US', { timeZone: etc });
          return etc;
        } catch (_fallbackError) { /* fall through to default */ }
      }
      return 'Asia/Seoul';
    }
  }
  const TIME_ZONE = resolveTimeZone(root?.dataset.timezone);
  const LOCALE = String(root?.dataset.locale || 'ko-KR').replace(/_/g, '-');
  const STATE_LABELS = Object.freeze({
    not_evaluated: '평가 전',
    requested: '요청됨',
    queued: '대기',
    running: '진행 중',
    review_required: '검토 대기',
    reviewed: '검토 완료',
    failed: '평가 실패',
    cancelled: '취소',
  });
  const RUN_FAILURE_LABELS = Object.freeze({
    routing_stale: 'AI 연결 설정을 찾을 수 없습니다. 설정 → AI 연결에서 프로필을 확인해 주세요.',
    context_denied: '학생이 이 배포 대상에 더 이상 속하지 않아 평가를 진행할 수 없습니다.',
    source_stale: '평가 도중 제출 내용이 바뀌어 다시 시도해야 합니다.',
    external_processing_not_approved: '외부 AI 처리 동의가 필요합니다. 설정에서 동의 여부를 확인해 주세요.',
    queue_deadline_exceeded: '대기 시간이 너무 길어 평가가 취소되었습니다. 다시 시도해 주세요.',
    worker_lost: '평가 작업을 처리하지 못했습니다. 다시 시도해 주세요.',
    worker_exception: '내부 오류로 작업을 중단했습니다. 다시 시도하거나 관리자에게 작업 확인 ID를 전달해 주세요.',
  });
  const RELEASE_LABELS = Object.freeze({
    released: '공개',
    corrected: '정정 공개',
    withdrawn: '공개 취소',
    stale: '갱신 필요',
  });
  const FEEDBACK_LABELS = Object.freeze({
    ai_draft: 'AI 초안',
    teacher_draft: '검토 중',
    rejected: '다시 작성',
    approved: '승인됨',
    stale: '갱신 필요',
  });
  const STUDENT_NOTE_LABELS = Object.freeze({
    ai_draft: 'AI 초안',
    teacher_draft: '검토 중',
    rejected: '다시 작성',
    approved: '승인됨',
    superseded: '갱신 필요',
  });
  const FLAG_LABELS = Object.freeze({
    artifact_quality_issue: '첨부 확인',
    evidence_limited: '근거 부족',
    response_ambiguous: '응답 확인',
    safety_review_required: '안전 확인',
    teacher_review_required: '교사 확인',
  });
  const REASONS = Object.freeze([
    ['evidence_checked', '근거 확인'],
    ['rubric_interpretation', '기준 해석'],
    ['manual_assessment', '직접 평가'],
    ['data_correction', '데이터 정정'],
    ['other', '기타'],
  ]);
  const SCORE_VISIBILITIES = Object.freeze(['total', 'total_and_criteria', 'hidden']);
  const FEEDBACK_VISIBILITIES = Object.freeze(['shown', 'hidden']);
  const RELEASE_REASON_CODES = Object.freeze(['data_correction', 'publication_error', 'privacy_request', 'other']);

  function normalizeDecimal(value, maximum = null) {
    const text = String(value ?? '').trim();
    if (!/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/.test(text)) {
      throw new TypeError('점수는 소수 넷째 자리까지 입력해 주세요.');
    }
    const normalized = Number(text).toFixed(4);
    if (maximum !== null && Number(normalized) > Number(maximum)) {
      throw new RangeError('배점을 넘을 수 없습니다.');
    }
    return normalized;
  }

  function reviewCommand(commandId, precondition, decision, itemDecisions = [], reason = null) {
    if (typeof commandId !== 'string' || !commandId || !precondition || typeof precondition !== 'object') {
      throw new TypeError('검토 요청 정보가 없습니다.');
    }
    if (!['accepted', 'adjusted', 'rejected', 'withdrawn'].includes(decision)) {
      throw new TypeError('검토 결정을 확인해 주세요.');
    }
    return {
      command_id: commandId,
      expected: {
        expected_result_id: precondition.expected_result_id,
        expected_result_hash: precondition.expected_result_hash,
        expected_official_review_id: precondition.expected_official_review_id,
        expected_official_review_hash: precondition.expected_official_review_hash,
        expected_review_no: precondition.expected_review_no,
      },
      payload: {
        decision,
        item_decisions: itemDecisions,
        reason,
      },
    };
  }

  function officialTotalLabel(values) {
    if (!values.length) return '-';
    try {
      const units = values.map(value => Math.round(Number(normalizeDecimal(value)) * 10000));
      return `${(units.reduce((sum, value) => sum + value, 0) / 10000).toLocaleString(LOCALE, { maximumFractionDigits: 4 })}점`;
    } catch (_error) {
      return '미확정';
    }
  }

  function abstainMessage(item) {
    if (!item.abstain_reason) return null;
    const verificationMessages = {
      evidence_reference_unknown: 'AI가 존재하지 않는 답변 또는 근거를 참조하여 점수를 보류했습니다. AI 재채점 또는 교사 채점이 필요합니다.',
      evidence_scope_mismatch: 'AI가 다른 문항의 답변을 근거로 연결하여 점수를 보류했습니다. AI 재채점 또는 교사 채점이 필요합니다.',
      evidence_quote_mismatch: 'AI의 인용문을 학생 답변 원문에서 확인하지 못해 점수를 보류했습니다. 학생 답변의 내용이 부족하다는 뜻은 아닙니다.',
      evidence_multiple_invalid: 'AI의 근거 연결과 인용 검증에 여러 오류가 있어 점수를 보류했습니다. AI 재채점 또는 교사 채점이 필요합니다.',
    };
    if (verificationMessages[item.abstain_reason]) return verificationMessages[item.abstain_reason];
    if (item.abstain_reason === 'insufficient_evidence' && item.flags?.includes('evidence_limited') && item.flags?.includes('teacher_review_required')) {
      return '채점 근거를 충분히 확인하지 못해 이 항목의 AI 점수를 보류했습니다. 학생 답변과 평가 기준을 확인해 최종 점수를 정해 주세요.';
    }
    return item.abstain_reason === 'safety_review'
      ? '안전 관련 확인이 필요해 AI 점수를 보류했습니다.'
      : 'AI가 점수를 판단하지 못했습니다.';
  }

  function feedbackExpected(precondition, feedbackRevision = null) {
    const officialId = precondition?.expected_official_review_id;
    const officialHash = precondition?.expected_official_review_hash;
    if (typeof officialId !== 'string' || !officialId || !/^[a-f0-9]{64}$/.test(String(officialHash || ''))) {
      throw new TypeError('확정된 점수 정보가 없습니다.');
    }
    if (feedbackRevision === null || feedbackRevision === undefined) {
      return {
        expected_official_review_id: officialId,
        expected_official_review_hash: officialHash,
        expected_feedback_revision_id: null,
        expected_feedback_revision_hash: null,
        expected_feedback_revision_no: 0,
      };
    }
    if (
      typeof feedbackRevision.id !== 'string' || !feedbackRevision.id
      || !/^[a-f0-9]{64}$/.test(String(feedbackRevision.hash || ''))
      || !Number.isInteger(feedbackRevision.revision_no) || feedbackRevision.revision_no < 1
    ) {
      throw new TypeError('피드백 상태를 확인할 수 없습니다.');
    }
    return {
      expected_official_review_id: officialId,
      expected_official_review_hash: officialHash,
      expected_feedback_revision_id: feedbackRevision.id,
      expected_feedback_revision_hash: feedbackRevision.hash,
      expected_feedback_revision_no: feedbackRevision.revision_no,
    };
  }

  function generateFeedbackCommand(commandId, precondition) {
    if (typeof commandId !== 'string' || !commandId) throw new TypeError('피드백 요청 정보가 없습니다.');
    const expected = feedbackExpected(precondition);
    return {
      command_id: commandId,
      expected: {
        expected_official_review_id: expected.expected_official_review_id,
        expected_official_review_hash: expected.expected_official_review_hash,
      },
      payload: {},
    };
  }

  function reviewFeedbackCommand(commandId, precondition, feedbackRevision, decision, sourceMode, feedback, reason = null) {
    if (typeof commandId !== 'string' || !commandId) throw new TypeError('피드백 요청 정보가 없습니다.');
    if (!['teacher_draft', 'rejected'].includes(decision) || !['direct', 'edit'].includes(sourceMode)) {
      throw new TypeError('피드백 결정을 확인해 주세요.');
    }
    const expected = feedbackExpected(precondition, feedbackRevision);
    if (sourceMode === 'edit' && !feedbackRevision) throw new TypeError('수정할 피드백이 없습니다.');
    if (decision === 'teacher_draft') {
      if (!feedback || typeof feedback !== 'object' || Array.isArray(feedback) || reason !== null) {
        throw new TypeError('작성한 피드백을 확인해 주세요.');
      }
    } else if (sourceMode !== 'edit' || feedback !== null || typeof reason !== 'string' || !reason.trim()) {
      throw new TypeError('피드백을 다시 작성할 이유를 입력해 주세요.');
    }
    return {
      command_id: commandId,
      expected,
      payload: { decision, feedback, reason, source_mode: sourceMode },
    };
  }

  function approveFeedbackCommand(commandId, precondition, feedbackRevision) {
    if (typeof commandId !== 'string' || !commandId || !feedbackRevision) {
      throw new TypeError('승인할 피드백이 없습니다.');
    }
    return { command_id: commandId, expected: feedbackExpected(precondition, feedbackRevision), payload: {} };
  }

  function withdrawFeedbackApprovalCommand(commandId, precondition, feedbackRevision) {
    if (typeof commandId !== 'string' || !commandId || !feedbackRevision) {
      throw new TypeError('회수할 피드백이 없습니다.');
    }
    return { command_id: commandId, expected: feedbackExpected(precondition, feedbackRevision), payload: {} };
  }

  function releaseResultCommand(commandId, detail, scoreVisibility, feedbackVisibility, reasonCode = null, reasonNote = null) {
    if (typeof commandId !== 'string' || !commandId) throw new TypeError('공개 요청 정보가 없습니다.');
    const precondition = detail?.review_precondition;
    const officialId = precondition?.expected_official_review_id;
    const officialHash = precondition?.expected_official_review_hash;
    if (typeof officialId !== 'string' || !officialId || !/^[a-f0-9]{64}$/.test(String(officialHash || ''))) {
      throw new TypeError('확정된 점수 정보가 없습니다.');
    }
    if (!SCORE_VISIBILITIES.includes(scoreVisibility) || !FEEDBACK_VISIBILITIES.includes(feedbackVisibility)) {
      throw new TypeError('공개 범위를 확인해 주세요.');
    }
    if (scoreVisibility === 'hidden' && feedbackVisibility === 'hidden') {
      throw new TypeError('점수와 피드백을 모두 비공개로 할 수 없습니다.');
    }
    let feedbackRevisionId = null;
    let feedbackRevisionHash = null;
    if (feedbackVisibility === 'shown') {
      const latest = latestFeedback(detail);
      if (latest?.state !== 'approved' || typeof latest?.id !== 'string' || !latest.id || !/^[a-f0-9]{64}$/.test(String(latest?.hash || ''))) {
        throw new TypeError('승인된 피드백 정보를 확인할 수 없습니다.');
      }
      feedbackRevisionId = latest.id;
      feedbackRevisionHash = latest.hash;
    }
    const currentRelease = detail?.evaluation?.release || {};
    const mode = currentRelease.current_id ? 'correction' : 'release';
    const expectedCurrentReleaseId = mode === 'correction' ? currentRelease.current_id : null;
    const expectedCurrentReleaseHash = mode === 'correction' ? currentRelease.current_hash : null;
    if (mode === 'correction' && (typeof expectedCurrentReleaseId !== 'string' || !expectedCurrentReleaseId || !/^[a-f0-9]{64}$/.test(String(expectedCurrentReleaseHash || '')))) {
      throw new TypeError('현재 공개 상태를 확인할 수 없습니다.');
    }
    const payload = {
      feedback_visibility: feedbackVisibility,
      mode,
      score_visibility: scoreVisibility,
    };
    if (mode === 'correction') {
      if (!RELEASE_REASON_CODES.includes(reasonCode)) throw new TypeError('정정 사유를 선택해 주세요.');
      const note = typeof reasonNote === 'string' ? reasonNote.trim() : '';
      if (reasonCode === 'other' && !note) throw new TypeError('정정 사유를 자세히 입력해 주세요.');
      if (note.length > 2000) throw new TypeError('메모를 조금 줄여 주세요.');
      payload.reason_code = reasonCode;
      payload.reason_note = note || null;
    }
    return {
      command_id: commandId,
      expected: {
        expected_current_release_hash: expectedCurrentReleaseHash,
        expected_current_release_id: expectedCurrentReleaseId,
        expected_feedback_revision_hash: feedbackRevisionHash,
        expected_feedback_revision_id: feedbackRevisionId,
        expected_official_review_hash: officialHash,
        expected_official_review_id: officialId,
      },
      payload,
    };
  }

  function withdrawResultCommand(commandId, detail, reasonCode, reasonNote) {
    const release = detail?.evaluation?.release;
    if (!commandId || !['released', 'corrected'].includes(release?.state)
      || !release.current_id || !/^[a-f0-9]{64}$/.test(release.current_hash || '')) throw new TypeError('회수할 공개 결과를 확인해 주세요.');
    if (!RELEASE_REASON_CODES.includes(reasonCode)) throw new TypeError('공개 회수 사유를 선택해 주세요.');
    const note = typeof reasonNote === 'string' ? reasonNote.trim() : '';
    if ((reasonCode === 'other' && !note) || note.length > 2000) throw new TypeError('공개 회수 사유를 확인해 주세요.');
    return { command_id: commandId, expected: { expected_current_release_id: release.current_id, expected_current_release_hash: release.current_hash }, payload: { reason_code: reasonCode, reason_note: note || null } };
  }

  function studentNoteOfficialRef(officialReview) {
    const officialId = officialReview?.id;
    const officialHash = officialReview?.hash;
    if (typeof officialId !== 'string' || !officialId || !/^[a-f0-9]{64}$/.test(String(officialHash || ''))) {
      throw new TypeError('확정된 점수 정보가 없습니다.');
    }
    return { id: officialId, hash: officialHash };
  }

  function generateStudentNoteCommand(commandId, officialReview) {
    if (typeof commandId !== 'string' || !commandId) throw new TypeError('특기사항 요청 정보가 없습니다.');
    const official = studentNoteOfficialRef(officialReview);
    return {
      command_id: commandId,
      expected: { expected_official_review_id: official.id, expected_official_review_hash: official.hash },
      payload: {},
    };
  }

  function reviewStudentNoteCommand(commandId, officialReview, precondition, decision, sourceMode, note = null, reason = null) {
    if (typeof commandId !== 'string' || !commandId) throw new TypeError('특기사항 요청 정보가 없습니다.');
    const official = studentNoteOfficialRef(officialReview);
    if (!['teacher_draft', 'rejected'].includes(decision) || !['direct', 'edit'].includes(sourceMode)) {
      throw new TypeError('특기사항 결정을 확인해 주세요.');
    }
    const hasRevision = Boolean(precondition?.latest_revision_id);
    if (sourceMode === 'edit' && !hasRevision) throw new TypeError('수정할 특기사항이 없습니다.');
    if (decision === 'teacher_draft') {
      if (!note || typeof note !== 'object' || Array.isArray(note) || reason !== null) {
        throw new TypeError('작성한 특기사항을 확인해 주세요.');
      }
    } else if (sourceMode !== 'edit' || note !== null || typeof reason !== 'string' || !reason.trim()) {
      throw new TypeError('특기사항을 다시 작성할 이유를 입력해 주세요.');
    }
    return {
      command_id: commandId,
      expected: {
        expected_include_in_reports: hasRevision ? Boolean(precondition.include_in_reports) : false,
        expected_official_review_hash: official.hash,
        expected_official_review_id: official.id,
        expected_revision_hash: hasRevision ? precondition.latest_revision_hash : null,
        expected_revision_id: hasRevision ? precondition.latest_revision_id : null,
        expected_revision_no: hasRevision ? precondition.latest_revision_no : 0,
        expected_state: hasRevision ? precondition.latest_state : null,
      },
      payload: { decision, note, reason, source_mode: sourceMode },
    };
  }

  function approveStudentNoteCommand(commandId, officialReview, precondition) {
    if (typeof commandId !== 'string' || !commandId || !precondition?.latest_revision_id) {
      throw new TypeError('승인할 특기사항이 없습니다.');
    }
    const official = studentNoteOfficialRef(officialReview);
    return {
      command_id: commandId,
      expected: {
        expected_include_in_reports: false,
        expected_official_review_hash: official.hash,
        expected_official_review_id: official.id,
        expected_revision_hash: precondition.latest_revision_hash,
        expected_revision_id: precondition.latest_revision_id,
        expected_revision_no: precondition.latest_revision_no,
        expected_state: 'teacher_draft',
      },
      payload: {},
    };
  }

  function withdrawStudentNoteApprovalCommand(commandId, officialReview, precondition) {
    if (typeof commandId !== 'string' || !commandId || !precondition?.latest_revision_id) {
      throw new TypeError('회수할 특기사항이 없습니다.');
    }
    const official = studentNoteOfficialRef(officialReview);
    return {
      command_id: commandId,
      expected: {
        expected_official_review_hash: official.hash,
        expected_official_review_id: official.id,
        expected_revision_hash: precondition.latest_revision_hash,
        expected_revision_id: precondition.latest_revision_id,
        expected_revision_no: precondition.latest_revision_no,
      },
      payload: {},
    };
  }

  function changeStudentNoteReportInclusionCommand(commandId, officialReview, precondition, target) {
    if (typeof commandId !== 'string' || !commandId || !precondition?.latest_revision_id) {
      throw new TypeError('특기사항을 확인할 수 없습니다.');
    }
    const official = studentNoteOfficialRef(officialReview);
    if (typeof target !== 'boolean' || Boolean(precondition.include_in_reports) === target) {
      throw new TypeError('보고서 포함 상태를 확인해 주세요.');
    }
    return {
      command_id: commandId,
      expected: {
        expected_include_in_reports: Boolean(precondition.include_in_reports),
        expected_official_review_hash: official.hash,
        expected_official_review_id: official.id,
        expected_revision_hash: precondition.latest_revision_hash,
        expected_revision_id: precondition.latest_revision_id,
        expected_revision_no: precondition.latest_revision_no,
        expected_state: 'approved',
      },
      payload: {},
    };
  }

  function contextQuery(context, extra = {}) {
    if (!context) throw new TypeError('배포 대상 정보가 없습니다.');
    return new URLSearchParams({
      context_type: context.type,
      context_id: context.id,
      context_revision: context.revision,
      ...extra,
    }).toString();
  }

  async function loadAssignedContexts(contexts, worksheetId, request, allPages) {
    const selectable = contexts.filter(context => context.selectable);
    const matches = new Array(selectable.length);
    let next = 0;
    let failure = null;
    // Bound reads across groups; keep source order and never accept a partial page set.
    async function readNext() {
      while (!failure && next < selectable.length) {
        const index = next++;
        const context = selectable[index];
        try {
          const items = await allPages(request, `/assignments?${contextQuery(context, { limit: '100' })}`);
          const assignments = items.filter(item => !worksheetId || item.worksheet_id === worksheetId);
          if (assignments.length) matches[index] = { context, assignments };
        } catch (error) {
          if (error?.status !== 403) failure = error;
        }
      }
    }
    await Promise.all(Array.from({ length: Math.min(4, selectable.length) }, readNext));
    if (failure) throw failure;
    return matches.filter(Boolean);
  }

  function evaluateSubmissionCommand(commandId, submission) {
    const revision = submission?.official_revision;
    if (
      typeof commandId !== 'string' || !commandId
      || typeof submission?.id !== 'string' || !submission.id
      || typeof revision?.id !== 'string' || revision.id !== submission.official_revision_id
      || !/^[a-f0-9]{64}$/.test(String(revision?.content_hash || ''))
    ) {
      throw new TypeError('공식 제출본을 다시 불러와 주세요.');
    }
    return {
      command_id: commandId,
      expected: {
        expected_official_revision_id: revision.id,
        expected_official_revision_hash: revision.content_hash,
      },
      payload: {},
    };
  }

  function responseText(response) {
    const answer = response?.answer || {};
    if (Object.prototype.hasOwnProperty.call(answer, 'value') && answer.value === null) return '응답 없음';
    if (response?.kind === 'short_text' || response?.kind === 'long_text') return answer.text || '응답 없음';
    if (response?.kind === 'single_choice') return answer.option?.label || '응답 없음';
    if (response?.kind === 'multiple_choice') {
      const labels = Array.isArray(answer.options) ? answer.options.map((item) => item?.label).filter(Boolean) : [];
      return labels.length ? labels.join(', ') : '응답 없음';
    }
    if (response?.kind === 'checkbox') return answer.checked ? '예' : '아니요';
    if (response?.kind === 'number') return `${answer.decimal ?? '-'}${answer.constraints?.unit ? ` ${answer.constraints.unit}` : ''}`;
    if (response?.kind === 'image_upload' || response?.kind === 'file_upload') {
      return `첨부 ${Array.isArray(answer.artifacts) ? answer.artifacts.length : 0}개`;
    }
    if (response?.kind === 'drawing') return answer.preview_artifact ? '그림 제출' : '응답 없음';
    if (response?.kind === 'table') return `표 ${Array.isArray(answer.rows) ? answer.rows.length : 0}행`;
    return '응답 확인';
  }

  function rosterSubmissionState(row) {
    if (!row.assigned) return { category: 'not_assigned', label: row.target_state === 'revoked' ? '배포 대상 해제' : '배포 대상 아님' };
    if (row.submission?.official_revision_id) {
      const labels = { auto_submitted: '제출 · 자동', proxy_submitted: '제출 · 대리', reopened: '제출 · 재제출 중' };
      return { category: 'submitted', label: labels[row.status] || '제출' };
    }
    return { category: 'not_submitted', label: row.status === 'draft' ? '미제출 · 작성 중' : row.status === 'conflict' ? '미제출 · 저장 충돌' : '미제출' };
  }

  function buildRosterItems(rows, evaluations, candidates, assignment) {
    // Match the official answer revision, never an older evaluation or display name.
    const evaluated = new Map();
    [...evaluations].sort((a, b) => String(b.requested_at_utc || b.created_at_utc || b.updated_at_utc || '').localeCompare(String(a.requested_at_utc || a.created_at_utc || a.updated_at_utc || '')) || String(b.id).localeCompare(String(a.id)))
      .forEach(item => {
        if (item.assignment?.id !== assignment.id) return;
        const key = `${item.learner?.user_id}:${item.submission?.id}:${item.submission?.source_revision_id}`;
        if (!evaluated.has(key) || item.review?.official_scope === 'this_evaluation') evaluated.set(key, item);
      });
    const pending = new Map(candidates.filter(item => item.assignment?.id === assignment.id)
      .map(item => [`${item.learner?.user_id}:${item.submission?.id}:${item.submission?.official_revision_id}`, item]));
    return rows.map(row => {
      const submissionState = rosterSubmissionState(row);
      const key = `${row.learner?.user_id}:${row.submission?.id}:${row.submission?.official_revision_id}`;
      const evaluation = row.assigned && row.submission?.official_revision_id ? evaluated.get(key) : null;
      const candidate = row.assigned && row.submission?.official_revision_id ? pending.get(key) : null;
      return {
        ...(evaluation || candidate || { id: row.id, state: '', submission: row.submission }),
        kind: evaluation ? 'evaluation' : candidate ? 'candidate' : 'roster',
        learner: row.learner, assignment,
        roster: row, submissionStatus: submissionState.category, submissionLabel: submissionState.label,
      };
    }).sort((a, b) => Number(b.roster.current_member) - Number(a.roster.current_member)
      || String(a.learner.display_name).localeCompare(String(b.learner.display_name), 'ko')
      || a.learner.user_id - b.learner.user_id);
  }

  function rosterCounts(items) {
    return items.reduce((counts, item) => {
      counts.total += 1;
      counts[item.submissionStatus] += 1;
      return counts;
    }, { total: 0, submitted: 0, not_submitted: 0, not_assigned: 0 });
  }

  function scoreDownloadRow(item, detail, { context, assignment, onlyOfficial, label }) {
    const evaluation = detail?.evaluation;
    if (evaluation?.id !== item.id || evaluation.assignment?.id !== assignment.id
      || evaluation.context?.type !== context.type || evaluation.context?.id !== context.id || evaluation.context?.revision !== context.revision
      || evaluation.learner?.user_id !== item.learner?.user_id || evaluation.submission?.id !== item.submission?.id) {
      throw new Error('점수의 학생·배포 정보를 확인하지 못했습니다. 다시 시도해 주세요.');
    }
    if (!['review_required', 'reviewed'].includes(evaluation.state) || evaluation.submission.is_source_current !== true
      || evaluation.submission.source_revision_id !== item.roster?.submission?.official_revision_id
      || !['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(evaluation.submission.state)) return null;
    const candidate = evaluation.candidate;
    if (!candidate || detail.selected_result?.id !== candidate.id) throw new Error('최신 채점 결과를 확인하지 못했습니다. 다시 시도해 주세요.');
    const official = officialReview(detail);
    const hasOfficial = evaluation.state === 'reviewed' && official?.id === evaluation.review?.official_id
      && ['accepted', 'adjusted'].includes(official?.decision) && official?.official_score != null;
    const hasAi = candidate.score != null && !candidate.abstained && !candidate.safety_flagged
      && !detail.selected_result.abstained && !detail.selected_result.safety_flagged && !selectedScoreRejection(detail);
    if (onlyOfficial ? !hasOfficial : !hasOfficial && !hasAi) return null;
    const maximum = normalizeDecimal(candidate.maximum_score);
    const ai = hasAi ? normalizeDecimal(candidate.score, maximum) : '';
    const confirmed = hasOfficial ? normalizeDecimal(official.official_score, maximum) : '';
    const learner = item.learner;
    return [context.label, assignment.title, label(learner), learner.labels?.management_name || '',
      learner.labels?.login || '', learner.labels?.display_name || learner.display_name || '',
      ai, confirmed, maximum, hasOfficial ? '확정' : '검토 대기', evaluation.priority?.review_needed_count ?? 0,
      evaluation.submission.submitted_at_utc || ''];
  }

  function scoreDownloadCsv(rows) {
    const headers = ['그룹/수업', '배포', '학생', '관리용 이름', '아이디', '표시이름', 'AI 점수', '교사 확정 점수', '배점', '검토 상태', '확인 필요', '제출 일시(UTC)'];
    const cell = value => {
      let text = String(value ?? '');
      // Quoting alone does not prevent spreadsheet formulas, including full-width prefixes.
      if (/^[\s\p{Cf}]*[=+@\-＝＋－＠]|^[\u0000-\u001f\u007f]/u.test(text)) text = "'" + text;
      return '"' + text.replaceAll('"', '""') + '"';
    };
    if (!rows.length || rows.some(row => row.length !== headers.length)) throw new Error('다운로드할 채점 완료 점수가 없습니다.');
    return [headers, ...rows].map(row => row.map(cell).join(',')).join('\r\n') + '\r\n';
  }

  function scoreDownloadFilename(context, assignment, date = new Date()) {
    const name = `${context.label || 'AI평가'}_${assignment.title || '점수'}`
      .replace(/[<>:"/\\|?*\u0000-\u001f\u007f]/g, '_').replace(/^[.\s]+|[.\s]+$/g, '').slice(0, 100);
    return `점수_${name}_${date.toISOString().replace(/[-:]/g, '').slice(0, 15)}.csv`;
  }

  async function readScoreDownload({ context, assignment, request, allPages, onlyOfficial = false, label, isCurrent = () => true, onProgress = () => {} }) {
    const scope = contextQuery(context, { assignment_id: assignment.id });
    const [roster, evaluations] = await Promise.all([
      request(`/submission-evaluation-roster?${scope}`),
      allPages(request, `/submission-evaluations?${scope}&limit=100`),
    ]);
    if (!Array.isArray(roster?.data?.items)) throw new Error('학생 명단을 확인하지 못했습니다.');
    const items = buildRosterItems(roster.data.items, evaluations, [], assignment);
    const candidates = items.filter(item => item.kind === 'evaluation' && item.roster.assigned
      && ['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(item.roster.status)
      && ['review_required', 'reviewed'].includes(item.state));
    const rows = new Array(candidates.length);
    let next = 0;
    let completed = 0;
    let failure = null;
    async function readNext() {
      while (next < candidates.length && !failure && isCurrent()) {
        const index = next++;
        try {
          const item = candidates[index];
          const result = await request(`/submission-evaluations/${encodeURIComponent(item.id)}?${contextQuery(context)}`);
          if (!isCurrent()) return;
          rows[index] = scoreDownloadRow(item, result.data, { context, assignment, onlyOfficial, label });
          onProgress(++completed, candidates.length);
        } catch (error) { failure = error; }
      }
    }
    await Promise.all(Array.from({ length: Math.min(4, candidates.length) }, readNext));
    if (!isCurrent()) throw new Error('점수 다운로드가 취소되었습니다.');
    if (failure) throw failure;
    const included = rows.filter(Boolean);
    return { rows: included, excluded: items.length - included.length };
  }

  function bulkCandidateCount(items) {
    return items.filter(item => (item.kind === 'candidate' || (item.kind === 'evaluation' && item.state === 'failed')) && item.submissionStatus === 'submitted'
      && item.roster?.assigned && ['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(item.roster.status)).length;
  }

  function assignmentBatchCommand(commandId, assignment, cutoff, cursor = '') {
    const version = Number(assignment?.schedule_version || assignment?.schedule?.version || 0);
    if (!assignment?.id || !['open', 'closed'].includes(assignment.state) || !Number.isInteger(version) || version < 1
      || !commandId || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(cutoff || '')) {
      throw new Error('배포 정보를 새로고침한 뒤 다시 시도해 주세요.');
    }
    return {
      command_id: commandId,
      expected: { expected_schedule_version: version, expected_state: assignment.state },
      payload: { source_cutoff_utc: cutoff, item_cursor: cursor, limit: 20 },
    };
  }

  function createAssignmentBatch({ assignment, context, cutoff, send, makeId, onProgress }) {
    const path = `/assignments/${encodeURIComponent(assignment.id)}/commands/evaluate-submissions?${contextQuery(context)}`;
    let cursor = '';
    let pending = null;
    let running = false;
    let stopped = false;
    let done = false;
    const seen = new Set(['']);
    const counts = { created: 0, existing: 0, skipped: 0, failed: 0, total: 0 };
    const failures = [];
    const snapshot = () => ({ running, done, counts: { ...counts }, failures: [...failures] });
    async function run() {
      if (running || done) return;
      running = true;
      stopped = false;
      onProgress(snapshot());
      try {
        // Keep the identical page command after uncertain delivery; C30 replays its durable receipt.
        for (let page = 0; page < 100 && !done && !stopped; page += 1) {
          pending ||= assignmentBatchCommand(makeId(), assignment, cutoff, cursor);
          const envelope = await send(path, { method: 'POST', idempotencyKey: pending.command_id, body: pending });
          const meta = envelope?.meta;
          const items = envelope?.data?.items;
          const next = meta?.next_item_cursor;
          if (envelope?.data?.assignment_id !== assignment.id || !Array.isArray(items) || items.length > 20
            || meta?.source_cutoff_utc !== cutoff || meta?.item_cursor !== cursor
            || typeof next !== 'string' || (next && (next.length > 191 || seen.has(next)))
            || Object.keys(counts).some(key => !Number.isInteger(meta?.counts?.[key]) || meta.counts[key] < 0)
            || meta.counts.total !== items.length
            || ['created', 'existing', 'failed', 'skipped'].some(key => meta.counts[key] !== items.filter(item => item.status === key).length)
            || items.some(item => !['created', 'existing', 'failed', 'skipped'].includes(item.status))) {
            throw new Error('일괄 등록 응답을 확인하지 못했습니다. 같은 요청으로 다시 확인해 주세요.');
          }
          Object.keys(counts).forEach(key => { counts[key] += meta.counts[key]; });
          failures.push(...items.filter(item => item.status === 'failed'));
          cursor = next;
          seen.add(next);
          done = !next;
          pending = null;
          onProgress(snapshot());
        }
      } finally {
        running = false;
        onProgress(snapshot());
      }
    }
    return Object.freeze({ run, snapshot, stop: () => { stopped = true; } });
  }

  const WORKFLOW_BULK = Object.freeze({
    accept: { label: 'AI 점수 일괄 승인', endpoint: 'review', operation: null },
    feedback: { label: '피드백 일괄 요청', endpoint: 'generate-feedback', operation: 'feedback_draft' },
    note: { label: '특기사항 일괄 요청', endpoint: 'generate-student-note', operation: 'special_note_draft' },
  });

  function workflowBulkReason(action, detail, hasNote = false) {
    const evaluation = detail?.evaluation;
    const expected = detail?.review_precondition;
    if (!WORKFLOW_BULK[action] || !evaluation || !expected) return '평가 정보 확인 필요';
    if (evaluation.submission?.is_source_current !== true
      || !['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(evaluation.submission?.state)) return '제출 변경 또는 재제출 중';
    if (action === 'accept') {
      if (evaluation.state !== 'review_required') return '검토 대기 아님';
      if (expected.expected_official_review_id || evaluation.review?.official_id) return '기존 확정 점수 있음';
      if (selectedScoreRejection(detail)) return '반려된 점수';
      const result = detail.selected_result;
      if (!result?.items?.length || result.id !== expected.expected_result_id || result.id !== evaluation.candidate?.id) return '현재 AI 결과 확인 필요';
      if (result.abstained || result.safety_flagged || evaluation.priority?.review_needed_count > 0
        || result.items.some(item => item.decision !== 'score' || item.abstain_reason || item.flags?.length)) return '판단 보류 또는 개별 확인 필요';
      try { result.items.forEach(item => normalizeDecimal(item.candidate_points, item.maximum_points)); }
      catch (_error) { return '점수 확인 필요'; }
    } else {
      if (evaluation.state !== 'reviewed' || !officialReview(detail)) return '점수 미확정';
      if ((detail.runs || []).some(run => run.operation === WORKFLOW_BULK[action].operation
        && ['requested', 'queued', 'leased', 'running', 'retry_wait'].includes(run.state))) return '이미 생성 대기 또는 진행 중';
      if (action === 'feedback' && (detail.feedback?.length || evaluation.feedback?.count > 0)) return '기존 피드백 있음';
      if (action === 'note' && hasNote) return '기존 특기사항 있음';
    }
    return '';
  }

  function workflowBulkCommand(action, detail, commandId) {
    if (action === 'accept') return reviewCommand(commandId, detail.review_precondition, 'accepted');
    if (action === 'feedback') return generateFeedbackCommand(commandId, detail.review_precondition);
    if (action === 'note') return generateStudentNoteCommand(commandId, {
      id: detail.review_precondition.expected_official_review_id,
      hash: detail.review_precondition.expected_official_review_hash,
    });
    throw new TypeError('일괄 작업 종류를 확인해 주세요.');
  }

  function workflowBulkReceipt(action, id, body, envelope) {
    const data = envelope?.data;
    if (action === 'accept') {
      return data?.evaluation?.id === id && data?.result?.id === body.expected.expected_result_id
        && data?.result?.hash === body.expected.expected_result_hash && data?.review?.decision === 'accepted'
        && Boolean(data?.review?.id) && data?.official_review?.id === data.review.id;
    }
    return data?.evaluation_id === id && data?.official_review?.id === body.expected.expected_official_review_id
      && data?.official_review?.hash === body.expected.expected_official_review_hash
      && Boolean(data?.run?.id) && data.run.operation === WORKFLOW_BULK[action].operation
      && ['queued', 'leased', 'running', 'retry_wait', 'succeeded', 'failed', 'cancelled'].includes(data.run.state);
  }

  function createWorkflowBulk({ action, targets, context, read, send, onProgress }) {
    let index = 0;
    let running = false;
    let stopped = false;
    let pending = null;
    const results = [];
    const snapshot = () => ({ running, done: index === targets.length, total: targets.length, results: [...results] });
    async function run() {
      if (running || index === targets.length) return;
      running = true;
      stopped = false;
      onProgress();
      try {
        while (index < targets.length && !stopped) {
          const target = targets[index];
          try {
            if (!pending) {
              const { detail, hasNote } = await read(target);
              if (stopped) break;
              const reason = workflowBulkReason(action, detail, hasNote);
              const currentBody = reason ? null : workflowBulkCommand(action, detail, target.body.command_id);
              if (detail?.evaluation?.id !== target.id || detail?.evaluation?.assignment?.id !== target.assignmentId
                || detail?.evaluation?.submission?.source_revision_id !== target.revisionId
                || reason || JSON.stringify(currentBody) !== JSON.stringify(target.body)) {
                results.push({ ...target, status: 'skipped', reason: reason || '확인 후 평가 정보가 변경됨' });
                index += 1;
                onProgress();
                continue;
              }
              pending = { path: `/submission-evaluations/${encodeURIComponent(target.id)}/commands/${WORKFLOW_BULK[action].endpoint}?${contextQuery(context)}`, body: target.body };
            }
            // A lost response must replay the original command, not generate a second request.
            const response = await send(pending.path, { method: 'POST', idempotencyKey: pending.body.command_id, body: pending.body });
            if (!workflowBulkReceipt(action, target.id, pending.body, response)) throw new Error('처리 응답을 확인하지 못했습니다. 이어하기로 확인해 주세요.');
            const failedRun = action !== 'accept' && ['failed', 'cancelled'].includes(response.data.run.state);
            results.push({ ...target, status: failedRun ? 'failed' : 'succeeded', reason: failedRun ? '생성 작업 실패 또는 취소 · 개별 확인 필요' : action === 'accept' ? '승인 완료' : '생성 요청 등록' });
          } catch (error) {
            if (![404, 409, 422].includes(error?.status)) throw error;
            results.push({ ...target, status: 'failed', reason: error.status === 409 ? '변경 충돌 · 개별 확인 필요' : error.status === 404 ? '접근 대상 없음' : '입력·처리 조건 확인 필요' });
          }
          pending = null;
          index += 1;
          onProgress();
        }
      } finally {
        running = false;
        onProgress();
      }
    }
    return Object.freeze({ run, snapshot, stop: () => { stopped = true; } });
  }

  function scorePending(detail) {
    return ['requested', 'queued', 'running'].includes(detail?.evaluation?.state);
  }

  function preserveScrollPosition(element, render) {
    const scrollTop = element.scrollTop;
    const scrollLeft = element.scrollLeft;
    render();
    element.scrollTop = scrollTop;
    element.scrollLeft = scrollLeft;
  }

  function selectedScoreRejection(detail) {
    const resultId = detail?.selected_result?.id;
    if (!resultId) return null;
    const reviews = Array.isArray(detail.reviews) ? detail.reviews : [];
    const latest = reviews.filter(review => review.result_id === resultId)
      .reduce((previous, review) => !previous || review.review_no > previous.review_no ? review : previous, null);
    return latest?.decision === 'rejected' ? latest : null;
  }

  function canRetryScore(detail) {
    const evaluation = detail?.evaluation;
    const run = evaluation?.latest_run;
    if (scoreApproved(detail)) return false;
    if (!evaluation?.id || !run?.id || !Number.isInteger(run.run_no) || run.run_no < 1
      || !evaluation.submission?.is_source_current) return false;
    return (['review_required', 'reviewed'].includes(evaluation.state) && run.state === 'succeeded')
      || (evaluation.state === 'failed' && run.state === 'failed')
      || (evaluation.state === 'cancelled' && run.state === 'cancelled');
  }

  function retryScoreCommand(detail, commandId) {
    if (!canRetryScore(detail) || typeof commandId !== 'string' || !commandId) {
      throw new TypeError('현재 평가 상태를 확인한 뒤 다시 시도해 주세요.');
    }
    const evaluation = detail.evaluation;
    return {
      command_id: commandId,
      expected: {
        expected_state: evaluation.state,
        expected_run_id: evaluation.latest_run.id,
        expected_run_no: evaluation.latest_run.run_no,
        expected_latest_result_id: evaluation.candidate?.id ?? null,
      },
      payload: { reason: selectedScoreRejection(detail) ? '교사가 반려한 AI 점수의 재채점을 요청했습니다.' : '교사가 평가를 다시 시작했습니다.' },
    };
  }

  function createScoreStatusPoller({ read, apply, canPoll, onError, schedule, cancel }) {
    let generation = 0;
    let timer = null;
    function stop() {
      generation += 1;
      if (timer !== null) cancel(timer);
      timer = null;
    }
    function start() {
      stop();
      const current = generation;
      let failures = 0;
      async function tick() {
        timer = null;
        if (current !== generation || !canPoll()) return;
        try {
          const detail = await read();
          if (current !== generation || !canPoll()) return;
          apply(detail);
          failures = 0;
        } catch (error) {
          if (current !== generation || !canPoll()) return;
          failures += 1;
          onError(error);
          if ([401, 403, 404].includes(error?.status)) return;
        }
        if (current === generation && canPoll()) timer = schedule(tick, Math.min(30000, 5000 * (failures + 1)));
      }
      if (canPoll()) timer = schedule(tick, 1500);
    }
    return Object.freeze({ start, stop });
  }

  function reportWritingIssues(values, schoolRecord = false) {
    const issues = new Set();
    values.forEach((value) => {
      // Check prose only; never rewrite the teacher's text or quoted source evidence.
      const prose = String(value || '').replace(/“[^”]*”|‘[^’]*’|"[^"]*"/gu, '');
      prose.split(/[.!?。](?!\d)|\n/u).forEach((sentence) => {
        const text = sentence.trim().replace(/[\s)\]»]+$/u, '');
        if (!/[가-힣]/u.test(text)) return;
        const last = text.charCodeAt(text.length - 1);
        if (last < 0xac00 || last > 0xd7a3 || (last - 0xac00) % 28 !== 16) {
          issues.add('문장 끝을 명사형(~함·~음·~됨·~임)으로 확인하세요.');
        }
      });
      if (schoolRecord && /(?:^|\s)(?:나는|저는|내가|제가|나의|저의)\s/u.test(prose)) {
        issues.add('학생의 1인칭 대신 교사가 관찰한 수행을 서술하세요.');
      }
      if (schoolRecord && /깨달았|깨달음|느꼈|느낌|다짐했|다짐함/u.test(prose)) {
        issues.add('내면의 변화는 추정하지 말고 제출물에서 확인되는 표현과 수행으로 바꾸세요.');
      }
    });
    return [...issues];
  }

  const adapter = Object.freeze({
    scoreDownloadRow,
    scoreDownloadCsv,
    scoreDownloadFilename,
    readScoreDownload,
    loadAssignedContexts,
    workflowBulkReason,
    workflowBulkCommand,
    createWorkflowBulk,
    reportWritingIssues,
    bulkCandidateCount,
    assignmentBatchCommand,
    createAssignmentBatch,
    scorePending,
    preserveScrollPosition,
    selectedScoreRejection,
    canRetryScore,
    scoreApproved,
    retryScoreCommand,
    createScoreStatusPoller,
    runFailureMessage,
    scoreInputRefreshCommand,
    rosterSubmissionState,
    buildRosterItems,
    rosterCounts,
    normalizeDecimal,
    officialTotalLabel,
    abstainMessage,
    reviewCommand,
    feedbackExpected,
    generateFeedbackCommand,
    reviewFeedbackCommand,
    approveFeedbackCommand,
    releaseResultCommand,
    withdrawResultCommand,
    latestFeedback,
    generateStudentNoteCommand,
    reviewStudentNoteCommand,
    approveStudentNoteCommand,
    changeStudentNoteReportInclusionCommand,
    contextQuery,
    evaluateSubmissionCommand,
    responseText,
  });
  window.OJAISubmissionEvaluationReviewWorkbench = adapter;

  function activateTab(name, focus = false) {
    if (!shell) return;
    const tabs = [...shell.querySelectorAll('[data-assessment-tab]')];
    const panels = [...shell.querySelectorAll('[data-assessment-panel]')];
    const target = tabs.find((item) => item.dataset.assessmentTab === name) || tabs[0];
    if (!target) return;
    tabs.forEach((item) => {
      const active = item === target;
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      item.tabIndex = active ? 0 : -1;
    });
    panels.forEach((item) => {
      const hidden = item.dataset.assessmentPanel !== target.dataset.assessmentTab;
      if (hidden) item.querySelectorAll('dialog[open]').forEach((dialog) => {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
      });
      item.hidden = hidden;
    });
    document.dispatchEvent(new CustomEvent('ojai:assessment-tab-change', { detail: { tab: target.dataset.assessmentTab } }));
    if (focus) target.focus();
  }

  if (shell) {
    shell.addEventListener('click', (event) => {
      const tab = event.target.closest('[data-assessment-tab]');
      if (tab && shell.contains(tab)) activateTab(tab.dataset.assessmentTab);
    });
    shell.addEventListener('keydown', (event) => {
      const tab = event.target.closest('[data-assessment-tab]');
      if (!tab || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
      const tabs = [...shell.querySelectorAll('[data-assessment-tab]')];
      const direction = event.key === 'ArrowRight' ? 1 : -1;
      const index = (tabs.indexOf(tab) + direction + tabs.length) % tabs.length;
      event.preventDefault();
      activateTab(tabs[index].dataset.assessmentTab, true);
    });
    activateTab(shell.dataset.defaultTab || 'submission');
  }

  if (!root) return;

  const learnerLabels = window.OJAILearnerLabels.create(root);
  const learnerNameNode = (tag, identity, suffix = '') => learnerLabels.bind(node(tag), identity, suffix);

  const fields = {
    context: root.querySelector('[data-review-field="context"]'),
    assignment: root.querySelector('[data-review-field="assignment"]'),
    state: root.querySelector('[data-review-field="state"]'),
    submissionStatus: root.querySelector('[data-review-field="submission-status"]'),
    search: root.querySelector('[data-review-field="search"]'),
    rejectReason: root.querySelector('[data-review-field="reject-reason"]'),
    feedbackRejectReason: root.querySelector('[data-feedback-field="reject-reason"]'),
    studentNoteRejectReason: root.querySelector('[data-student-note-field="reject-reason"]'),
    releaseScoreVisibility: root.querySelector('[data-release-field="score-visibility"]'),
    releaseFeedbackVisibility: root.querySelector('[data-release-field="feedback-visibility"]'),
    releaseReasonCode: root.querySelector('[data-release-field="reason-code"]'),
    releaseReasonNote: root.querySelector('[data-release-field="reason-note"]'),
  };
  const elements = {
    summary: root.querySelector('[data-role="submission-summary"]'),
    notice: root.querySelector('[data-review-role="notice"]'),
    content: root.querySelector('[data-review-role="content"]'),
    list: root.querySelector('[data-review-role="list"]'),
    detail: root.querySelector('[data-review-role="detail"]'),
    loadMore: root.querySelector('[data-review-action="load-more"]'),
    rejectDialog: root.querySelector('[data-review-role="reject-dialog"]'),
    rejectStatus: root.querySelector('[data-review-role="reject-status"]'),
    feedbackRejectDialog: root.querySelector('[data-feedback-role="reject-dialog"]'),
    feedbackRejectStatus: root.querySelector('[data-feedback-role="reject-status"]'),
    studentNoteRejectDialog: root.querySelector('[data-student-note-role="reject-dialog"]'),
    studentNoteRejectStatus: root.querySelector('[data-student-note-role="reject-status"]'),
    releaseDialog: root.querySelector('[data-release-role="dialog"]'),
    releaseTitle: root.querySelector('[data-release-role="title"]'),
    releaseReasonSection: root.querySelector('[data-release-role="reason-section"]'),
    releaseStatus: root.querySelector('[data-release-role="status"]'),
    batchButton: root.querySelector('[data-review-action="bulk-grade"]'),
    batch: root.querySelector('[data-review-role="batch"]'),
    batchStatus: root.querySelector('[data-review-role="batch-status"]'),
    batchFailures: root.querySelector('[data-review-role="batch-failures"]'),
    batchDialog: root.querySelector('[data-review-role="batch-dialog"]'),
    batchScope: root.querySelector('[data-review-role="batch-scope"]'),
    batchTargets: root.querySelector('[data-review-role="batch-targets"]'),
    batchResume: root.querySelector('[data-review-action="bulk-resume"]'),
    batchStop: root.querySelector('[data-review-action="bulk-stop"]'),
    batchDismiss: root.querySelector('[data-review-action="bulk-dismiss"]'),
    workflowActions: root.querySelector('[data-review-role="workflow-actions"]'),
    workflowDialog: root.querySelector('[data-review-role="workflow-dialog"]'),
    workflowProgress: root.querySelector('[data-review-role="workflow-progress"]'),
  };
  const state = {
    contexts: [],
    assignments: [],
    items: [],
    nextEvaluationCursor: '',
    nextCandidateCursor: '',
    selectedId: '',
    selectedType: '',
    detail: null,
    candidateDetail: null,
    listRequest: 0,
    detailRequest: 0,
    submitting: false,
    canAssess: root.dataset.canAssess === '1',
    canReview: root.dataset.canReview === '1',
    canManageStudentNotes: root.dataset.canManageStudentNotes === '1',
    studentNote: null,
    studentNoteLoading: false,
    studentNoteRequest: 0,
    draftPollToken: 0,
    pollingOperation: null,
    rosterReady: false,
    rosterCutoff: '',
    batch: null,
    batchConfirmation: null,
    workflowPlan: null,
    workflowJob: null,
    workflowPreparing: false,
    workflowRequest: 0,
    scoreDownloadRequest: 0,
    scoreDownloading: false,
  };

  const entryParams = new URLSearchParams(window.location?.search || '');
  const workflowStage = ['report', 'release'].includes(entryParams.get('stage')) ? entryParams.get('stage') : 'assessment';
  if (fields.submissionStatus) fields.submissionStatus.closest('label').hidden = workflowStage !== 'assessment';
  const requestedWorksheetId = window.OJAIWorkflow?.read().worksheetId || '';
  let assignmentsUnavailable = false;
  let assignmentRequest = 0;
  let contextsLoading = false;
  let contextSelection = null;
  const requestedContextType = String(entryParams.get('context_type') || '');
  const requestedContextId = String(entryParams.get('context_id') || '');
  const requestedContextRevision = String(entryParams.get('context_revision') || '');
  const requestedEvaluationValue = String(entryParams.get('evaluation_id') || '');
  const requestedEvaluationId = /^[0-9A-HJKMNP-TV-Z]{26}$/.test(requestedEvaluationValue) ? requestedEvaluationValue : '';
  const requestedAssignmentValue = String(entryParams.get('assignment_id') || '');
  const requestedAssignmentId = /^[0-9A-HJKMNP-TV-Z]{26}$/.test(requestedAssignmentValue) ? requestedAssignmentValue : '';

  function node(tag, className = '', text = null) {
    const value = document.createElement(tag);
    if (className) value.className = className;
    if (text !== null && text !== undefined) value.textContent = String(text);
    return value;
  }

  function append(parent, ...children) {
    children.filter(Boolean).forEach((child) => parent.appendChild(child));
    return parent;
  }

  function button(label, action, className = '') {
    const value = node('button', className, label);
    value.type = 'button';
    value.dataset.reviewAction = action;
    return value;
  }

  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'oj-ai-icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${root.dataset.iconSprite}#${name}`);
    svg.appendChild(use);
    return svg;
  }

  function iconButton(name, action, className, label) {
    const control = button('', action, className);
    control.appendChild(icon(name));
    control.setAttribute('aria-label', label);
    control.title = label;
    return control;
  }

  function feedbackButton(label, action, className = '') {
    const value = node('button', className, label);
    value.type = 'button';
    value.dataset.feedbackAction = action;
    value.disabled = state.submitting;
    return value;
  }

  function studentNoteButton(label, action, className = '') {
    const value = node('button', className, label);
    value.type = 'button';
    value.dataset.studentNoteAction = action;
    value.disabled = state.submitting;
    return value;
  }

  function releaseButton(label, action, className = '') {
    const value = node('button', className, label);
    value.type = 'button';
    value.dataset.releaseAction = action;
    value.disabled = state.submitting;
    return value;
  }

  function wait(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
  }

  function option(value, label, selected = false) {
    const item = node('option', '', label);
    item.value = value;
    item.selected = selected;
    return item;
  }

  function badge(label, tone = 'neutral') {
    const value = node('span', 'oj-ai-submission-review__badge', label);
    value.dataset.tone = tone;
    return value;
  }

  function labelFor(map, value, fallback = '확인 중') {
    return map[value] || fallback;
  }

  function toneForState(value) {
    if (value === 'failed' || value === 'cancelled') return 'danger';
    if (value === 'review_required' || value === 'queued' || value === 'running') return 'attention';
    if (value === 'reviewed') return 'success';
    return 'neutral';
  }

  function dateLabel(value) {
    if (typeof value !== 'string' || !value) return '-';
    const parsed = new Date(`${value.replace(' ', 'T')}Z`);
    if (Number.isNaN(parsed.getTime())) return value;
    try {
      return new Intl.DateTimeFormat(LOCALE, {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE,
      }).format(parsed);
    } catch (_error) {
      return value;
    }
  }

  function scoreLabel(score, maximum) {
    if (score === null || score === undefined) return '판단 보류';
    return `${Number(score).toLocaleString()} / ${Number(maximum).toLocaleString()}`;
  }

  function token(prefix) {
    const random = window.crypto?.randomUUID
      ? window.crypto.randomUUID().replaceAll('-', '')
      : `${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
    return `${prefix}:${Date.now().toString(36)}:${random}`;
  }

  function currentContext() {
    if (fields.context.value === '') return null;
    const index = Number(fields.context.value);
    return Number.isInteger(index) && index >= 0 ? state.contexts[index] || null : null;
  }

  function currentAssignment() {
    const raw = String(fields.assignment.value || '');
    if (!raw) return null;
    const index = Number(raw);
    return Number.isInteger(index) && index >= 0 ? state.assignments[index] || null : null;
  }

  function showNotice(message, tone = 'neutral') {
    elements.notice.textContent = message;
    elements.notice.dataset.tone = tone;
    elements.notice.hidden = !message;
  }

  function problemMessage(error, fallback) {
    if (error?.status === 409) return '평가가 바뀌었습니다. 최신 내용을 다시 불러왔습니다.';
    if (error?.status === 403) return '이 작업을 수행할 권한이 없습니다.';
    if (error?.status === 404) return '평가를 찾을 수 없습니다.';
    if (error?.status === 422 || error?.status === 503) {
      const base = error.message || (error.status === 422 ? '점수와 사유를 확인해 주세요.' : fallback);
      const stage = typeof error?.safeData?.projection_stage === 'string' ? error.safeData.projection_stage : '';
      const path = typeof error?.safeData?.path === 'string' ? error.safeData.path : '';
      const detail = typeof error?.safeData?.detail === 'string' ? error.safeData.detail : '';
      const suffix = [stage, path, detail].filter(Boolean).join(': ');
      return suffix ? `${base} (${suffix})` : base;
    }
    return error?.message || fallback;
  }

  function feedbackProblemMessage(error, fallback) {
    if (error?.status === 409) return '피드백이 변경되었습니다. 다시 확인해 주세요.';
    if (error?.status === 403) return '피드백을 작성할 권한이 없습니다.';
    if (error?.status === 404) return '평가를 찾을 수 없습니다.';
    if (error?.status === 422) return error.message || '피드백 내용을 확인해 주세요.';
    return error?.message || fallback;
  }

  function studentNoteProblemMessage(error, fallback) {
    if (error?.status === 409) return '특기사항이 변경되었습니다. 다시 확인해 주세요.';
    if (error?.status === 403) return '특기사항을 관리할 권한이 없습니다.';
    if (error?.status === 404) return '학생 특기사항을 찾을 수 없습니다.';
    if (error?.status === 422) return error.message || '특기사항 내용을 확인해 주세요.';
    return error?.message || fallback;
  }

  function releaseProblemMessage(error, fallback) {
    if (error?.status === 409) return '공개 상태가 변경되었습니다. 최신 내용을 다시 불러왔습니다.';
    if (error?.status === 403) return '결과를 공개할 권한이 없습니다.';
    if (error?.status === 404) return '평가를 찾을 수 없습니다.';
    if (error?.status === 422) return error.message || '공개 설정을 확인해 주세요.';
    return error?.message || fallback;
  }

  async function request(path, options = {}) {
    const method = options.method || 'GET';
    const headers = {
      Accept: 'application/json',
      'X-WP-Nonce': root.dataset.restNonce || '',
    };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers['Idempotency-Key'] = options.idempotencyKey || token('idem');
    }
    const response = await window.fetch(`${root.dataset.restRoot}${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: method === 'GET' ? undefined : JSON.stringify(options.body || {}),
    });
    let payload = null;
    try { payload = await response.json(); } catch (_error) { payload = null; }
    if (!response.ok) {
      const error = new Error(payload?.message || '요청을 처리하지 못했습니다.');
      error.status = Number(payload?.data?.status || response.status || 500);
      error.code = payload?.code || '';
      error.safeData = payload?.data || {};
      throw error;
    }
    if (!payload || typeof payload !== 'object' || !Object.prototype.hasOwnProperty.call(payload, 'data')) {
      const error = new Error('응답을 확인할 수 없습니다.');
      error.status = 503;
      throw error;
    }
    return payload;
  }

  function visibleItems() {
    const term = String(fields.search.value || '').trim().toLocaleLowerCase();
    return state.items.filter((item) => {
      if (item.roster) {
        if (fields.submissionStatus?.value && item.submissionStatus !== fields.submissionStatus.value) return false;
        const evaluationState = itemType(item) === 'candidate' ? 'not_evaluated' : item.state;
        if (fields.state.value && (itemType(item) === 'roster' || evaluationState !== fields.state.value)) return false;
      }
      const value = `${item?.assignment?.title || ''} ${learnerLabels.searchText(item?.learner)}`.toLocaleLowerCase();
      return value.includes(term);
    });
  }

  function updateBatchButton() {
    const download = root.querySelector('[data-review-action="download-scores"]');
    if (download) {
      download.hidden = workflowStage !== 'assessment' || !(state.canAssess || state.canReview);
      download.disabled = download.hidden || contextsLoading || assignmentsUnavailable || !state.rosterReady
        || !currentContext() || !currentAssignment() || state.submitting || state.scoreDownloading || workflowBusy()
        || Boolean(state.batch && !state.batch.controller.snapshot().done);
    }
    if (elements.workflowActions) updateWorkflowBulkButtons();
    if (!elements.batchButton) return;
    elements.batchButton.hidden = workflowStage !== 'assessment' || !state.canAssess;
    elements.batchButton.disabled = elements.batchButton.hidden || state.submitting || !state.rosterReady || !currentContext()
      || !['open', 'closed'].includes(currentAssignment()?.state) || !bulkCandidateCount(state.items)
      || Boolean(state.batch && !state.batch.controller.snapshot().done) || Boolean(state.workflowPreparing || state.workflowJob && !state.workflowJob.controller.snapshot().done);
  }

  function resetBatch() {
    state.batch?.controller.stop();
    state.batch = null;
    state.batchConfirmation = null;
    if (elements.batchDialog?.open) elements.batchDialog.close();
    if (elements.batch) elements.batch.hidden = true;
    updateBatchButton();
  }

  function openScoreDownload() {
    const control = root.querySelector('[data-review-action="download-scores"]');
    if (!control || control.disabled || !canLeaveDetail()) return;
    const dialog = root.querySelector('[data-review-role="score-download-dialog"]');
    dialog.querySelector('[data-review-role="score-download-scope"]').textContent = `${currentContext().label} · ${currentAssignment().title}`;
    dialog.querySelector('[data-review-role="score-download-status"]').textContent = '';
    dialog.showModal();
  }

  async function downloadScores() {
    const dialog = root.querySelector('[data-review-role="score-download-dialog"]');
    if (!dialog?.open || state.scoreDownloading || workflowStage !== 'assessment' || !(state.canAssess || state.canReview)
      || !currentContext() || !currentAssignment() || state.submitting || workflowBusy()) return;
    const context = currentContext();
    const assignment = currentAssignment();
    const requestId = ++state.scoreDownloadRequest;
    const status = dialog.querySelector('[data-review-role="score-download-status"]');
    const mode = dialog.querySelector('[data-review-field="score-download-mode"]');
    const confirm = dialog.querySelector('[data-review-action="confirm-score-download"]');
    const labelMode = root.querySelector('[data-learner-label-mode]')?.value || 'display_name';
    const isCurrent = () => requestId === state.scoreDownloadRequest && dialog.open && currentContext() === context
      && currentAssignment() === assignment && !root.closest('[hidden]');
    state.scoreDownloading = true;
    confirm.disabled = true; mode.disabled = true;
    updateBatchButton();
    status.textContent = '배포 전체의 최신 점수를 확인하는 중입니다.';
    try {
      const result = await readScoreDownload({ context, assignment, request, allPages: window.OJAIWorkflow.allPages,
        onlyOfficial: mode.value === 'official', isCurrent,
        label: learner => window.OJAILearnerLabels?.label(learner, labelMode) || learner.display_name || '',
        onProgress: (done, total) => { status.textContent = `점수 확인 ${done} / ${total}명`; },
      });
      if (!isCurrent()) return;
      const csv = scoreDownloadCsv(result.rows);
      const url = window.URL.createObjectURL(new window.Blob([csv], { type: 'text/csv;charset=utf-8' }));
      const link = node('a');
      try {
        link.href = url; link.download = scoreDownloadFilename(context, assignment); link.hidden = true;
        document.body.appendChild(link); link.click();
      } finally {
        link.remove();
        window.setTimeout(() => window.URL.revokeObjectURL(url), 1000);
      }
      dialog.close();
      showNotice(`${context.label} · ${assignment.title} · 점수 ${result.rows.length}명 다운로드 · 제외 ${result.excluded}명`);
    } catch (error) {
      if (isCurrent()) status.textContent = problemMessage(error, '점수를 다운로드하지 못했습니다. 다시 시도해 주세요.');
    } finally {
      if (requestId === state.scoreDownloadRequest) {
        state.scoreDownloading = false;
        confirm.disabled = false; mode.disabled = false;
        updateBatchButton();
      }
    }
  }

  function renderBatch(batch) {
    if (state.batch !== batch || !elements.batch) return;
    const progress = batch.controller.snapshot();
    const counts = progress.counts;
    elements.batch.hidden = false;
    const status = progress.done ? '등록 완료' : progress.running ? '등록 중' : '등록 중단';
    elements.batchStatus.textContent = `${status} · 채점 대기 등록 ${counts.created}명 · 기존 작업 ${counts.existing}명 · 제외 ${counts.skipped}명 · 등록 실패 ${counts.failed}명${batch.error ? ` · ${batch.error}` : ''}`;
    elements.batchResume.hidden = progress.running || progress.done || batch.blocked;
    elements.batchStop.hidden = !progress.running;
    elements.batchStop.disabled = false;
    elements.batchDismiss.hidden = progress.running;
    elements.batchFailures.hidden = !progress.failures.length;
    const list = elements.batchFailures.querySelector('ul');
    list.replaceChildren();
    const reasons = {
      artifact_not_ready: '첨부파일 처리 확인 필요',
      submission_evaluation_source_stale: '제출 내용 변경',
      submission_evaluation_configuration_changed: 'AI 운영 설정 변경',
      submission_evaluation_not_routable: 'AI 연결 확인 필요',
      submission_evaluation_input_too_large: '제출 입력 크기 확인 필요',
    };
    progress.failures.forEach(item => list.appendChild(learnerNameNode('li', batch.names.get(item.submission_id), ` · ${reasons[item.reason] || '등록 실패, 개별 평가에서 확인 필요'}`)));
    updateBatchButton();
  }

  function openBatchDialog() {
    updateBatchButton();
    if (!elements.batchButton || elements.batchButton.disabled || !canLeaveDetail()) return;
    const assignment = currentAssignment();
    const context = currentContext();
    try { assignmentBatchCommand('batch-preflight', assignment, state.rosterCutoff); }
    catch (error) { showNotice(error.message, 'error'); return; }
    state.batchConfirmation = { assignment, context, cutoff: state.rosterCutoff };
    elements.batchScope.textContent = `${context.label} · ${assignment.title}`;
    const failedCount = bulkCandidateCount(state.items.filter(item => item.kind === 'evaluation'));
    elements.batchTargets.textContent = `미채점 제출 ${bulkCandidateCount(state.items) - failedCount}명 · 실패 재채점 ${failedCount}명`;
    elements.batchDialog.showModal();
  }

  async function runBatch(batch) {
    if (state.submitting || state.batch !== batch || !state.canAssess || batch.blocked || batch.controller.snapshot().running) return;
    batch.error = '';
    scorePoller.stop();
    try {
      await batch.controller.run();
    } catch (error) {
      batch.blocked = [401, 403, 404, 409, 422].includes(error?.status);
      batch.refreshAssignment = error?.status === 409;
      batch.error = error?.status === 409 ? '배포가 변경되었습니다. 새로고침해 주세요.'
        : problemMessage(error, '등록하지 못한 작업이 있습니다. 연결을 확인해 주세요.');
      if (error?.status === 403) state.canAssess = false;
    } finally {
      if (state.batch === batch) {
        if (batch.refreshAssignment) await loadAssignmentsForContext(batch.context, batch.assignment.id);
        if (state.batch !== batch) return;
        if (!hasDetailChanges()) await loadEvaluations(false, state.selectedId, state.selectedType || 'evaluation');
        renderBatch(batch);
        scorePoller.start();
      }
    }
  }

  function startBatch() {
    const confirmed = state.batchConfirmation;
    if (!confirmed || confirmed.assignment !== currentAssignment() || confirmed.context !== currentContext()
      || state.submitting || !state.canAssess || !canLeaveDetail()) return;
    elements.batchDialog.close();
    state.batchConfirmation = null;
    const batch = { ...confirmed, error: '', blocked: false, names: new Map(state.items.map(item => [item.submission?.id, item.learner])), controller: null };
    batch.controller = createAssignmentBatch({ ...confirmed, send: request, makeId: () => token('batch'), onProgress: () => renderBatch(batch) });
    state.batch = batch;
    runBatch(batch);
  }

  function priorityLabel(item) {
    if (item?.kind === 'candidate') return '';
    if (item?.latest_run?.operation === 'manual_prepare') return item.review?.official_scope === 'this_evaluation' ? '교사 채점 완료' : '교사 채점 대기';
    if (item?.candidate?.safety_flagged) return '안전 확인';
    if (item?.candidate?.abstained) return '판단 보류';
    if (item?.priority?.review_needed_count > 0) return `${item.priority.review_needed_count}개 확인`;
    return '';
  }

  function workflowNode(role) {
    return root.querySelector(`[data-review-role="workflow-${role}"]`);
  }

  function workflowBusy() {
    return state.workflowPreparing || Boolean(state.workflowJob && !state.workflowJob.controller.snapshot().done);
  }

  function workflowPermission(action) {
    return action === 'note' ? state.canManageStudentNotes : state.canReview;
  }

  const workflowDisabled = new Map();
  function updateWorkflowBulkButtons() {
    root.querySelectorAll('[data-workflow-bulk]').forEach(control => {
      const action = control.dataset.workflowBulk;
      control.hidden = !workflowPermission(action) || (action === 'accept' ? workflowStage !== 'assessment' : workflowStage !== 'report');
      control.disabled = control.hidden || !currentAssignment() || assignmentsUnavailable || !currentContext()
        || state.submitting || workflowBusy() || Boolean(state.batch && !state.batch.controller.snapshot().done);
      control.title = !currentAssignment() ? '배포를 선택하세요.' : WORKFLOW_BULK[action].label;
    });
    const locked = workflowBusy();
    root.querySelectorAll('.oj-ai-submission-review__filters input,.oj-ai-submission-review__filters select').forEach(control => {
      if (locked) {
        if (!workflowDisabled.has(control)) workflowDisabled.set(control, control.disabled);
        control.disabled = true;
      } else if (workflowDisabled.has(control)) {
        control.disabled = workflowDisabled.get(control);
        workflowDisabled.delete(control);
      }
    });
    elements.detail.inert = locked;
  }

  async function readWorkflowTarget(action, target, context) {
    const envelope = await request(`/submission-evaluations/${encodeURIComponent(target.id)}?${contextQuery(context)}`);
    const detail = envelope.data;
    if (detail?.evaluation?.id !== target.id) throw new Error('평가 응답을 확인할 수 없습니다.');
    const hasNote = action === 'note' && Boolean(await findStudentNoteSummary(context, target.assignmentId, target.id));
    return { detail, hasNote };
  }

  function updateWorkflowSelection() {
    const boxes = [...workflowNode('targets').querySelectorAll('input[type="checkbox"]')];
    const count = boxes.filter(box => box.checked).length;
    const all = workflowNode('select-all');
    all.checked = Boolean(boxes.length) && count === boxes.length;
    all.indeterminate = count > 0 && count < boxes.length;
    all.disabled = state.workflowPreparing || !boxes.length;
    const confirm = root.querySelector('[data-workflow-control="confirm"]');
    confirm.disabled = state.workflowPreparing || !count || !state.workflowPlan
      || (state.workflowPlan.action === 'accept' && !workflowNode('reviewed').checked);
    confirm.textContent = state.workflowPlan?.action === 'accept' ? `${count}명 승인` : `${count}명 요청`;
  }

  async function openWorkflowBulk(action) {
    if (!WORKFLOW_BULK[action] || !workflowPermission(action) || workflowBusy() || state.submitting
      || !currentAssignment() || !currentContext() || state.batch && !state.batch.controller.snapshot().done) return;
    if (hasDetailChanges()) { showNotice('변경한 점수 또는 보고서를 먼저 저장해 주세요.', 'error'); return; }
    const assignment = currentAssignment();
    const context = currentContext();
    const plan = { action, assignment, context, targets: [], excluded: [] };
    const requestId = ++state.workflowRequest;
    state.workflowPlan = plan;
    state.workflowPreparing = true;
    workflowNode('title').textContent = WORKFLOW_BULK[action].label;
    workflowNode('scope').textContent = `${context.label} · ${assignment.title} · 배포 전체 (검색·상태 필터와 무관)`;
    workflowNode('policy').textContent = action === 'accept'
      ? '기존 확정 점수·반려·판단 보류·개별 확인 대상 제외. 점수만 확정하며 학생에게 공개하지 않습니다.'
      : '점수 확정·초안 없는 학생 대상. 기존 초안·승인 기록·생성 중인 작업 제외. 생성 후 개별 검토가 필요하며 자동 승인·공개하지 않습니다.';
    workflowNode('attestation').hidden = action !== 'accept';
    workflowNode('reviewed').checked = false;
    workflowNode('targets').replaceChildren();
    workflowNode('exclusions').replaceChildren();
    workflowNode('preview-status').textContent = '대상을 확인하는 중입니다.';
    updateWorkflowSelection();
    updateBatchButton();
    elements.workflowDialog.showModal();
    try {
      const scope = contextQuery(context, { assignment_id: assignment.id });
      const roster = await request(`/submission-evaluation-roster?${scope}`);
      const evaluations = await window.OJAIWorkflow.allPages(request, `/submission-evaluations?${scope}&limit=100`);
      if (!Array.isArray(roster?.data?.items)) throw new Error('학생 명단을 확인할 수 없습니다.');
      const items = buildRosterItems(roster.data.items, evaluations, [], assignment);
      for (const item of items) {
        if (requestId !== state.workflowRequest || !elements.workflowDialog.open) return;
        const target = { id: item.id, learner: item.learner, assignmentId: assignment.id, revisionId: item.submission?.source_revision_id };
        let reason = item.kind !== 'evaluation' ? '평가 결과 없음'
          : !item.roster.assigned || item.submissionStatus !== 'submitted' ? '공식 제출 대상 아님'
            : !['submitted', 'auto_submitted', 'proxy_submitted', 'locked'].includes(item.roster.status) ? '재제출 작성 중' : '';
        if (!reason) {
          try {
            const { detail, hasNote } = await readWorkflowTarget(action, target, context);
            reason = workflowBulkReason(action, detail, hasNote);
            if (detail.evaluation.assignment?.id !== assignment.id || detail.evaluation.submission?.source_revision_id !== target.revisionId) reason = '제출 또는 배포 변경';
            if (!reason) target.body = workflowBulkCommand(action, detail, token(`bulk-${action}`));
          } catch (error) {
            if (![404, 409, 422].includes(error?.status)) throw error;
            reason = '평가 조회 불가 · 개별 확인 필요';
          }
        }
        if (reason) plan.excluded.push({ ...target, reason });
        else plan.targets.push(target);
        workflowNode('preview-status').textContent = `${plan.targets.length + plan.excluded.length} / ${items.length}명 확인 중`;
      }
      if (requestId !== state.workflowRequest || !elements.workflowDialog.open) return;
      plan.targets.forEach((target, index) => {
        const label = node('label', 'oj-ai-submission-review__bulk-check');
        const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.checked = true; checkbox.value = String(index);
        append(label, checkbox, learnerNameNode('span', target.learner));
        workflowNode('targets').appendChild(label);
      });
      plan.excluded.forEach(item => workflowNode('exclusions').appendChild(learnerNameNode('li', item.learner, ` · ${item.reason}`)));
      workflowNode('preview-status').textContent = `대상 ${plan.targets.length}명 · 제외 ${plan.excluded.length}명`;
    } catch (error) {
      if (requestId !== state.workflowRequest) return;
      state.workflowPlan = null;
      workflowNode('preview-status').textContent = problemMessage(error, '대상을 확인하지 못했습니다. 다시 시도해 주세요.');
    } finally {
      if (requestId === state.workflowRequest) {
        state.workflowPreparing = false;
        updateWorkflowSelection();
        updateBatchButton();
      }
    }
  }

  function renderWorkflowProgress(job) {
    if (state.workflowJob !== job) return;
    const progress = job.controller.snapshot();
    elements.workflowProgress.hidden = false;
    const count = status => progress.results.filter(item => item.status === status).length;
    workflowNode('progress-status').textContent = `${WORKFLOW_BULK[job.action].label} · ${progress.done ? '처리 완료' : progress.running ? '처리 중' : '중지됨'} · ${progress.results.length}/${progress.total}명 · ${job.action === 'accept' ? '승인' : '요청 등록'} ${count('succeeded')}명 · 제외 ${count('skipped')}명 · 실패 ${count('failed')}명${job.error ? ` · ${job.error}` : ''}`;
    workflowNode('results').replaceChildren(...progress.results.map(item => learnerNameNode('li', item.learner, ` · ${item.reason}`)));
    root.querySelector('[data-workflow-control="resume"]').hidden = progress.running || progress.done || job.blocked;
    root.querySelector('[data-workflow-control="stop"]').hidden = !progress.running;
    root.querySelector('[data-workflow-control="stop"]').disabled = false;
    root.querySelector('[data-workflow-control="dismiss"]').hidden = progress.running;
    updateBatchButton();
  }

  async function runWorkflowJob(job) {
    if (state.workflowJob !== job || state.submitting || !workflowPermission(job.action) || job.blocked) return;
    state.submitting = true;
    job.error = '';
    scorePoller.stop();
    state.draftPollToken += 1;
    state.pollingOperation = null;
    try { await job.controller.run(); }
    catch (error) {
      job.blocked = [401, 403].includes(error?.status);
      job.error = problemMessage(error, '연결을 확인한 뒤 이어서 처리해 주세요.');
      if (error?.status === 403) {
        if (job.action === 'note') revokeStudentNotePermission(); else revokeReviewPermission();
      }
    } finally {
      state.submitting = false;
      if (state.workflowJob === job) {
        await loadEvaluations(false, state.selectedId, state.selectedType || 'evaluation');
        renderWorkflowProgress(job);
        scorePoller.start();
      }
    }
  }

  function startWorkflowJob() {
    const plan = state.workflowPlan;
    if (!plan || state.workflowPreparing || state.submitting || workflowBusy() || !workflowPermission(plan.action)
      || plan.assignment !== currentAssignment() || plan.context !== currentContext() || hasDetailChanges()
      || plan.action === 'accept' && !workflowNode('reviewed').checked) return;
    const targets = [...workflowNode('targets').querySelectorAll('input:checked')].map(box => plan.targets[Number(box.value)]).filter(Boolean);
    if (!targets.length) return;
    const job = { ...plan, targets, error: '', blocked: false, controller: null };
    job.controller = createWorkflowBulk({ action: job.action, targets, context: job.context, send: request,
      read: target => readWorkflowTarget(job.action, target, job.context), onProgress: () => renderWorkflowProgress(job) });
    state.workflowJob = job;
    elements.workflowDialog.close();
    runWorkflowJob(job);
  }

  function itemType(item) {
    return ['candidate', 'roster'].includes(item?.kind) ? item.kind : 'evaluation';
  }

  function itemTimestamp(item) {
    return String(item?.submission?.submitted_at_utc || item?.updated_at_utc || '');
  }

  function mergeSubmissionItems(existing, evaluations, candidates) {
    const combined = [
      ...existing,
      ...evaluations.map((item) => ({ ...item, kind: 'evaluation' })),
      ...candidates.map((item) => ({ ...item, kind: 'candidate' })),
    ];
    const evaluatedSources = new Set(combined
      .filter((item) => itemType(item) === 'evaluation')
      .map((item) => `${item.submission?.id || ''}:${item.submission?.source_revision_id || ''}`));
    const seen = new Set();
    return combined
      .filter((item) => {
        const type = itemType(item);
        if (
          type === 'candidate'
          && evaluatedSources.has(`${item.submission?.id || ''}:${item.submission?.official_revision_id || ''}`)
        ) {
          return false;
        }
        const key = `${type}:${item.id || ''}`;
        if (!item.id || seen.has(key)) return false;
        seen.add(key);
        return true;
      })
      .sort((left, right) => {
        const time = itemTimestamp(right).localeCompare(itemTimestamp(left));
        return time || String(left.id).localeCompare(String(right.id));
      });
  }

  function renderList() {
    preserveScrollPosition(elements.list.parentElement, renderListEntries);
  }

  function renderListEntries() {
    elements.list.replaceChildren();
    const items = visibleItems();
    if (!items.length) {
      elements.list.appendChild(node('div', 'oj-ai-submission-review__empty', state.items.length ? '검색 결과가 없습니다.' : '평가할 제출이 없습니다.'));
      return;
    }
    items.forEach((item) => {
      const type = itemType(item);
      const entry = button('', 'select', 'oj-ai-submission-review__list-item');
      entry.dataset.itemId = item.id;
      entry.dataset.itemType = type;
      if (type === 'evaluation') entry.dataset.evaluationId = item.id;
      if (type === 'candidate') entry.dataset.submissionId = item.id;
      entry.setAttribute('aria-current', item.id === state.selectedId && type === state.selectedType ? 'true' : 'false');
      const title = node('div', 'oj-ai-submission-review__list-title');
      append(title, learnerNameNode('strong', item.learner), item.roster
        ? badge(item.submissionLabel, item.submissionStatus === 'submitted' ? 'success' : item.submissionStatus === 'not_submitted' ? 'attention' : 'neutral')
        : badge(labelFor(STATE_LABELS, item.state), toneForState(item.state)));
      const score = type === 'candidate'
        ? '채점 전'
        : type === 'roster' ? (item.submissionStatus === 'submitted' ? '평가 가능 상태 확인 필요' : '채점 대상 아님') : item.latest_run?.operation === 'manual_prepare' ? '교사 직접 채점' : item.candidate
          ? scoreLabel(item.candidate.score, item.candidate.maximum_score)
          : '결과 대기';
      const meta = node('div', 'oj-ai-submission-review__list-meta');
      append(meta, node('span', '', score), node('span', '', dateLabel(item.submission?.submitted_at_utc)));
      const attention = priorityLabel(item);
      append(entry, title, node('span', 'oj-ai-submission-review__assignment', item.assignment?.title || '활동지'), meta);
      if (item.roster && type === 'evaluation') entry.appendChild(badge(labelFor(STATE_LABELS, item.state), toneForState(item.state)));
      if (item.roster && !item.roster.current_member) entry.appendChild(node('span', 'oj-ai-submission-review__assignment', '현재 그룹·수업 명단에서 제외됨'));
      if (attention) entry.appendChild(node('span', 'oj-ai-submission-review__priority', attention));
      elements.list.appendChild(entry);
    });
  }

  function renderEmptyDetail(title, message) {
    const empty = node('div', 'oj-ai-submission-review__empty');
    append(empty, node('strong', '', title), node('span', '', message));
    replaceDetail(empty);
  }

  function selectRosterItem(item) {
    scorePoller.stop();
    ++state.detailRequest;
    ++state.draftPollToken;
    state.selectedId = item.id;
    state.selectedType = 'roster';
    state.detail = null;
    state.candidateDetail = null;
    const messages = {
      not_submitted: item.roster.status === 'draft' ? '답안을 작성 중이며 아직 제출하지 않았습니다.' : '아직 제출한 답안이 없습니다.',
      not_assigned: item.roster.target_state === 'revoked' ? '선택한 배포의 대상에서 해제되었습니다.' : '선택한 배포에 포함되지 않은 학생입니다.',
      submitted: '제출 기록은 있으나 현재 평가 가능한 항목이 없습니다. 배포 및 제출 상태를 확인해 주세요.',
    };
    renderEmptyDetail('', messages[item.submissionStatus]);
    learnerLabels.bind(elements.detail.querySelector('strong'), item.learner, ` · ${item.submissionLabel}`);
    renderList();
  }

  function section(title, count = null) {
    const value = node('section', 'oj-ai-submission-review__section');
    const heading = node('h3', '', title);
    if (count !== null) heading.appendChild(node('span', 'oj-ai-submission-review__count', count));
    value.appendChild(heading);
    return value;
  }

  function detailHeader(detail) {
    const evaluation = detail.evaluation;
    const header = node('header', 'oj-ai-submission-review__detail-header');
    const identity = node('div');
    append(
      identity,
      node('h2', '', evaluation.assignment?.title || '학생 제출'),
      learnerNameNode('p', evaluation.learner, ` · ${dateLabel(evaluation.submission?.submitted_at_utc)}`)
    );
    const badges = node('div', 'oj-ai-submission-review__detail-badges');
    const rejected = ['review_required', 'reviewed'].includes(evaluation.state) && selectedScoreRejection(detail);
    const approved = scoreApproved(detail);
    badges.appendChild(badge(approved ? '점수 승인 완료' : rejected ? 'AI 점수 반려' : labelFor(STATE_LABELS, evaluation.state), approved ? 'success' : rejected ? 'danger' : toneForState(evaluation.state)));
    if (!evaluation.submission?.is_source_current) badges.appendChild(badge('새 제출 있음', 'attention'));
    if (workflowStage === 'release' && root.dataset.canPublish === '1' && officialReview(detail)) {
      const releaseLabel = evaluation.release?.current_id ? '정정 공개' : '결과 공개';
      append(badges, releaseButton(releaseLabel, 'open', 'oj-ai-submission-review__secondary'));
      if (['released', 'corrected'].includes(evaluation.release?.state)) {
        append(badges, releaseButton('공개 회수', 'withdraw', 'oj-ai-submission-review__secondary'));
      }
    }
    const refresh = iconButton('refresh-cw', 'detail-refresh', 'oj-ai-submission-review__icon-button', '평가 새로고침');
    const viewer = window.OJAISubmissionDocument?.viewButton(root, evaluation.submission?.source_revision_id, currentContext(), evaluation.submission?.id);
    if (viewer) append(badges, viewer);
    append(badges, refresh);
    append(header, identity, badges);
    return header;
  }

  function metrics(detail) {
    const evaluation = detail.evaluation;
    const result = detail.selected_result;
    const release = evaluation.release?.state ? labelFor(RELEASE_LABELS, evaluation.release.state) : '미공개';
    const official = scoreApproved(detail) ? officialReview(detail) : null;
    const values = [
      [official ? '확정 점수' : manualScore(detail) ? '교사 채점' : selectedScoreRejection(detail) ? 'AI 점수 (반려)' : 'AI 점수', official ? scoreLabel(official.official_score, result?.maximum_score) : manualScore(detail) ? '채점 전' : result ? scoreLabel(result.candidate_score, result.maximum_score) : '-'],
      ['AI 신뢰도', result && !manualScore(detail) ? `${Math.round(Number(result.overall_confidence) * 100)}%` : '-'],
      [official ? '검토 상태' : '확인 필요', official ? '승인 완료' : evaluation.priority?.review_needed_count ?? 0],
      ['학생 공개', release],
    ];
    const container = node('div', 'oj-ai-submission-review__metrics');
    values.forEach(([label, value]) => {
      const metric = node('div', 'oj-ai-submission-review__metric');
      append(metric, node('span', '', label), node('strong', '', value));
      container.appendChild(metric);
    });
    return container;
  }

  function renderTableAnswer(answer) {
    const wrapper = node('div', 'oj-ai-submission-review__answer-table-wrap');
    const table = node('table', 'oj-ai-submission-review__answer-table');
    const rows = Array.isArray(answer?.rows) ? answer.rows : [];
    rows.forEach((row) => {
      const tr = node('tr');
      tr.appendChild(node('th', '', row.label || '행'));
      (Array.isArray(row.cells) ? row.cells : []).forEach((cell) => {
        const value = typeof cell.value === 'boolean' ? (cell.value ? '예' : '아니요') : String(cell.value ?? '-');
        const td = node('td');
        append(td, node('span', 'oj-ai-submission-review__cell-label', cell.label || ''), node('span', '', value));
        tr.appendChild(td);
      });
      table.appendChild(tr);
    });
    wrapper.appendChild(table);
    return wrapper;
  }

  function renderResponses(detail) {
    const responses = Array.isArray(detail.source?.responses) ? detail.source.responses : [];
    const value = node('div', 'oj-ai-submission-review__responses');
    responses.forEach((response) => {
      const article = node('article', 'oj-ai-submission-review__response');
      article.dataset.responseId = response.response_id || '';
      article.appendChild(node('h4', '', response.label || `문항 ${Number(response.position || 0) + 1}`));
      if (response.kind === 'table' && Array.isArray(response.answer?.rows)) {
        article.appendChild(renderTableAnswer(response.answer));
      } else {
        article.appendChild(node('p', 'oj-ai-submission-review__answer', responseText(response)));
      }
      value.appendChild(article);
    });
    return value;
  }

  function submissionAnswerText(value, attachments) {
    if (value === null || value === undefined || value === '') return '미작성';
    if (typeof value === 'boolean') return value ? '선택함' : '선택하지 않음';
    if (typeof value === 'string') return attachments.get(value) ? `첨부: ${attachments.get(value)}` : value;
    if (Array.isArray(value)) {
      return value.length ? value.map((item) => submissionAnswerText(item, attachments)).join(', ') : '미작성';
    }
    if (typeof value === 'object') {
      const values = Object.values(value).map((item) => submissionAnswerText(item, attachments)).filter(Boolean);
      return values.length ? values.join(' · ') : '미작성';
    }
    return String(value);
  }

  function candidateDetailHeader(submission) {
    const header = node('header', 'oj-ai-submission-review__detail-header');
    const identity = node('div');
    append(
      identity,
      node('h2', '', submission.assignment_title || '학생 제출'),
      learnerNameNode('p', submission.target, ` · ${dateLabel(submission.submitted_at_utc)}`)
    );
    const badges = node('div', 'oj-ai-submission-review__detail-badges');
    badges.appendChild(badge('평가 전'));
    const viewer = window.OJAISubmissionDocument?.viewButton(root, submission.official_revision?.id, currentContext(), submission.id);
    if (viewer) badges.appendChild(viewer);
    badges.appendChild(iconButton('refresh-cw', 'detail-refresh', 'oj-ai-submission-review__icon-button', '제출 새로고침'));
    append(header, identity, badges);
    return header;
  }

  function renderCandidateDetail() {
    const submission = state.candidateDetail?.submission;
    if (!submission?.official_revision) {
      renderEmptyDetail('제출을 불러오지 못했습니다.', '공식 제출본을 다시 확인해 주세요.');
      return;
    }
    const fragment = document.createDocumentFragment();
    fragment.appendChild(candidateDetailHeader(submission));

    const answers = submission.official_revision.response?.answers || {};
    const entries = Object.values(answers);
    const attachments = new Map((Array.isArray(submission.attachments) ? submission.attachments : [])
      .map((item) => [String(item.id || ''), String(item.name || '첨부 파일')]));
    const responses = section('학생 답변', entries.length);
    const list = node('div', 'oj-ai-submission-review__responses');
    if (!entries.length) {
      list.appendChild(node('p', 'oj-ai-submission-review__summary-text', '작성 내용이 없습니다.'));
    } else {
      entries.forEach((value, index) => {
        const article = node('article', 'oj-ai-submission-review__response');
        append(
          article,
          node('h4', '', `문항 ${index + 1}`),
          node('p', 'oj-ai-submission-review__answer', submissionAnswerText(value, attachments))
        );
        list.appendChild(article);
      });
    }
    responses.appendChild(list);
    fragment.appendChild(responses);

    const actions = node('div', 'oj-ai-submission-review__review-actions');
    const status = node('div');
    append(status, node('strong', '', 'AI 평가 전'), node('span', '', '공식 제출본'));
    const controls = node('div');
    if (state.canReview) {
      const manual = button('교사 직접 채점', 'manual-assessment', 'oj-ai-submission-review__primary');
      manual.disabled = state.submitting;
      controls.appendChild(manual);
    }
    if (state.canAssess) {
      const evaluate = button('AI 평가 시작', 'evaluate-submission', 'oj-ai-submission-review__primary');
      evaluate.disabled = state.submitting;
      controls.appendChild(evaluate);
    }
    append(actions, status, controls);
    fragment.appendChild(actions);
    replaceDetail(fragment);
  }

  function latestReviewItems(detail) {
    const reviews = Array.isArray(detail.reviews) ? detail.reviews : [];
    const latest = officialReview(detail) || (reviews.at(-1)?.decision === 'withdrawn'
      ? [...reviews].reverse().find(item => ['accepted', 'adjusted'].includes(item.decision) && item.result_id === detail.selected_result?.id)
      : reviews.at(-1));
    if (!latest || latest.result_id !== detail.selected_result?.id || latest.decision === 'rejected') return new Map();
    const items = latest.score_decisions?.value?.items;
    return new Map((Array.isArray(items) ? items : []).map((item) => [item.binding_key, item]));
  }

  function officialReview(detail) {
    if (detail.evaluation?.review?.official_scope !== 'this_evaluation') return null;
    return (Array.isArray(detail.reviews) ? detail.reviews : []).find((item) => item?.is_official) || null;
  }

  function scoreApproved(detail) {
    return detail?.evaluation?.state === 'reviewed' && Boolean(detail.review_precondition?.expected_official_review_id
      || detail.evaluation.review?.official_id);
  }

  function manualScore(detail) {
    return detail?.selected_result?.provider_status === 'not_requested';
  }

  function foreignApproval(detail) {
    return detail?.evaluation?.review?.official_scope === 'other_evaluation';
  }

  function latestFeedback(detail) {
    const items = Array.isArray(detail.feedback) ? detail.feedback : [];
    const latest = items[items.length - 1] || null;
    const current = detail.review_precondition?.expected_official_review_hash;
    return latest && current && (latest.review_id !== detail.review_precondition.expected_official_review_id || feedbackProfile(latest)?.source_hash !== current)
      ? { ...latest, state: 'stale' } : latest;
  }

  function feedbackProfile(item) {
    const value = item?.profile?.value;
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
  }

  function officialFeedbackItems(detail) {
    const items = officialReview(detail)?.score_decisions?.value?.items;
    return (Array.isArray(items) ? [...items] : []).sort((left, right) => Number(left.position || 0) - Number(right.position || 0));
  }

  function bindingLabel(detail, item) {
    const binding = (detail.source?.bindings || []).find((value) => value.binding_key === item.binding_key);
    const criterion = (detail.source?.rubric?.criteria || []).find((value) => value.criterion_id === item.criterion_id);
    return binding?.label || criterion?.label || `평가 기준 ${Number(item.position || 0) + 1}`;
  }

  function responseLabel(detail, item) {
    if (!item.response_id) return '전체 제출';
    return (detail.source?.responses || []).find((value) => value.response_id === item.response_id)?.label || '연결 문항';
  }

  function reasonControls(item, previous, manual = false) {
    const controls = node('div', 'oj-ai-submission-review__reason');
    controls.dataset.reviewRole = 'reason-controls';
    const reasonLabel = node('label');
    reasonLabel.appendChild(node('span', '', manual ? '채점 사유' : '조정 사유'));
    const select = node('select');
    select.dataset.reviewField = 'reason-code';
    select.appendChild(option('', '사유 선택'));
    REASONS.forEach(([value, label]) => select.appendChild(option(value, label, previous?.reason_code === value)));
    reasonLabel.appendChild(select);
    const noteLabel = node('label');
    noteLabel.appendChild(node('span', '', '메모'));
    const textarea = node('textarea');
    textarea.dataset.reviewField = 'reason-note';
    textarea.rows = 2;
    textarea.maxLength = 2000;
    textarea.placeholder = item.flags?.includes('safety_review_required') ? '확인한 내용을 입력하세요.' : '필요할 때 입력하세요.';
    textarea.value = previous?.reason_note || '';
    noteLabel.appendChild(textarea);
    append(controls, reasonLabel, noteLabel);
    return controls;
  }

  function itemNeedsTeacher(article) {
    const candidate = article.dataset.candidatePoints;
    const points = article.querySelector('[data-review-field="official-points"]')?.value.trim() || '';
    return article.dataset.safety === '1' || !candidate || Number(points) !== Number(candidate);
  }

  function updateCriterion(article) {
    const controls = article.querySelector('[data-review-role="reason-controls"]');
    if (controls) controls.hidden = !itemNeedsTeacher(article);
    updateOfficialTotal();
  }

  function rubricChoices(item, selectedPoints, editable) {
    const group = node('fieldset', 'oj-ai-submission-review__rubric');
    group.appendChild(node('legend', '', editable ? '평가 루브릭 · 최종 선택' : '평가 루브릭 · 확정 결과'));
    [...item.levels].sort((a, b) => Number(b.points) - Number(a.points)).forEach(level => {
      const row = node('label', 'oj-ai-submission-review__rubric-level');
      const radio = node('input');
      radio.type = 'radio';
      radio.name = `rubric-${item.binding_key}`;
      radio.value = String(Number(level.points));
      radio.dataset.reviewField = 'rubric-level';
      radio.checked = selectedPoints != null && Number(level.points) === Number(selectedPoints);
      radio.disabled = !editable || state.submitting;
      const text = node('span', 'oj-ai-submission-review__rubric-description', level.label);
      const score = node('span', 'oj-ai-submission-review__rubric-score', `${Number(level.points)}점`);
      if (item.candidate_points != null && Number(item.candidate_points) === Number(level.points)) score.appendChild(badge('AI 선택', 'neutral'));
      append(row, radio, text, score);
      group.appendChild(row);
    });
    return group;
  }

  function renderCriterion(detail, item, previous) {
    const article = node('article', 'oj-ai-submission-review__criterion');
    article.dataset.bindingKey = item.binding_key;
    article.dataset.candidatePoints = item.candidate_points ?? '';
    article.dataset.maximumPoints = item.maximum_points;
    article.dataset.safety = item.flags?.includes('safety_review_required') ? '1' : '0';
    const header = node('header', 'oj-ai-submission-review__criterion-header');
    const title = node('div');
    append(title, node('h4', '', bindingLabel(detail, item)), node('span', '', responseLabel(detail, item)));
    const score = manualScore(detail) ? '교사 채점' : item.candidate_points === null ? '판단 보류' : `${Number(item.candidate_points)} / ${Number(item.maximum_points)}점`;
    append(header, title, badge(score, item.candidate_points === null ? 'attention' : 'neutral'));
    article.appendChild(header);

    if (item.rationale) article.appendChild(node('p', 'oj-ai-submission-review__rationale', item.rationale));
    const abstention = manualScore(detail) ? '' : abstainMessage(item);
    if (abstention) article.appendChild(node('p', 'oj-ai-submission-review__rationale', abstention));
    if (item.evidence?.length) {
      const evidence = node('div', 'oj-ai-submission-review__evidence');
      evidence.appendChild(node('strong', '', '근거'));
      item.evidence.forEach((entry) => evidence.appendChild(node('p', '', entry.quote || '연결된 응답을 확인해 주세요.')));
      article.appendChild(evidence);
    }
    if (item.flags?.length) {
      const flags = node('div', 'oj-ai-submission-review__flags');
      item.flags.forEach((flag) => {
        const label = flag === 'evidence_limited' && /^evidence_/.test(item.abstain_reason || '')
          ? '근거 검증 실패' : labelFor(FLAG_LABELS, flag, '확인 필요');
        flags.appendChild(badge(label, flag === 'safety_review_required' ? 'danger' : 'attention'));
      });
      article.appendChild(flags);
    }

    const editable = state.canReview && !scoreApproved(detail) && !foreignApproval(detail);
    const selectedPoints = previous?.official_points ?? item.candidate_points ?? null;
    const hasLevels = Array.isArray(item.levels) && item.levels.length;
    if (hasLevels) article.appendChild(rubricChoices(item, selectedPoints, editable));
    if (editable) {
      const controls = node('div', 'oj-ai-submission-review__score-control');
      const label = node('label');
      label.appendChild(node('span', '', '최종 점수'));
      let input;
      if (hasLevels) {
        input = node('input');
        input.type = 'hidden';
        input.value = selectedPoints == null ? '' : String(Number(selectedPoints));
        label.hidden = true;
      } else {
        input = node('input');
        input.type = 'number';
        input.min = '0';
        input.max = String(Number(item.maximum_points));
        input.step = '0.0001';
        input.inputMode = 'decimal';
        input.value = selectedPoints ?? '';
      }
      input.dataset.reviewField = 'official-points';
      label.appendChild(input);
      controls.appendChild(label);
      article.appendChild(controls);
      article.appendChild(reasonControls(item, previous || (manualScore(detail) ? { reason_code: 'manual_assessment' } : null), manualScore(detail)));
      updateCriterion(article);
    } else if (previous?.official_points !== undefined) {
      article.appendChild(node('p', 'oj-ai-submission-review__official-score', `확정 ${Number(previous.official_points)}점`));
    }
    return article;
  }

  function updateOfficialTotal() {
    const target = elements.detail.querySelector('[data-review-role="official-total"]');
    if (!target) return;
    const inputs = [...elements.detail.querySelectorAll('[data-review-field="official-points"]')];
    target.textContent = officialTotalLabel(inputs.map(input => input.value));
    const accept = elements.detail.querySelector('[data-review-action="accept"]');
    if (accept) accept.disabled = state.submitting || !canAccept(state.detail) || [...elements.detail.querySelectorAll('[data-binding-key]')].some(itemNeedsTeacher);
  }

  function canAccept(detail) {
    return state.canReview
      && ['review_required', 'reviewed'].includes(detail.evaluation?.state)
      && !scoreApproved(detail)
      && !foreignApproval(detail)
      && !selectedScoreRejection(detail)
      && detail.selected_result?.items?.length > 0
      && !detail.selected_result.abstained
      && !detail.selected_result.safety_flagged
      && detail.selected_result.items.every((item) => item.candidate_points !== null && !item.flags?.includes('safety_review_required'));
  }

  function reviewActions(detail) {
    if (!state.canReview || !detail.review_precondition || !['review_required', 'reviewed'].includes(detail.evaluation?.state)) return null;
    if (foreignApproval(detail)) return node('p', 'oj-ai-submission-review__official-score', '다른 채점 이력에서 점수가 확정되어 있습니다. 확정된 채점의 승인을 먼저 회수해 주세요.');
    const value = node('div', 'oj-ai-submission-review__review-actions');
    if (scoreApproved(detail)) {
      const official = officialReview(detail);
      const status = node('div', 'oj-ai-submission-review__official-total');
      append(status, node('strong', '', manualScore(detail) ? '교사 채점 확정 완료' : official?.decision === 'adjusted' ? '조정 점수 확정 완료' : 'AI 점수 승인 완료'),
        node('span', '', `확정 점수 ${scoreLabel(official?.official_score, detail.selected_result?.maximum_score)}`));
      const withdraw = button('점수 승인 회수', 'withdraw-score', 'oj-ai-submission-review__secondary');
      withdraw.disabled = state.submitting;
      append(value, status, withdraw);
      return value;
    }
    const total = node('div', 'oj-ai-submission-review__official-total');
    append(total, node('span', '', '최종 합계'), node('strong', '', '-'));
    total.querySelector('strong').dataset.reviewRole = 'official-total';
    const actions = node('div');
    const accept = button('AI 점수 승인', 'accept', 'oj-ai-submission-review__secondary');
    accept.disabled = !canAccept(detail);
    const reject = button('반려', 'reject', 'oj-ai-submission-review__danger-link');
    reject.disabled = state.submitting || Boolean(selectedScoreRejection(detail));
    if (!manualScore(detail)) actions.appendChild(accept);
    actions.appendChild(button(manualScore(detail) ? '채점 점수 확정' : '선택 점수 확정', 'adjust', 'oj-ai-submission-review__primary'));
    if (!manualScore(detail)) actions.appendChild(reject);
    append(value, total, actions);
    return value;
  }

  function renderReviewHistory(detail) {
    const reviews = Array.isArray(detail.reviews) ? detail.reviews : [];
    if (!reviews.length) return null;
    const value = section('검토 이력', reviews.length);
    const list = node('ul', 'oj-ai-submission-review__timeline');
    [...reviews].reverse().forEach((review) => {
      const item = node('li');
      append(
        item,
        node('strong', '', `검토 ${review.review_no}`),
        node('span', '', review.decision === 'accepted' ? 'AI 점수 승인' : review.decision === 'adjusted' ? '점수 조정' : review.decision === 'withdrawn' ? '점수 승인 회수' : '반려'),
        node('span', '', dateLabel(review.created_at_utc))
      );
      if (review.reason) item.appendChild(node('p', '', review.reason));
      list.appendChild(item);
    });
    value.appendChild(list);
    return value;
  }

  function latestRunForOperation(detail, operation) {
    const runs = Array.isArray(detail?.runs) ? detail.runs : [];
    return runs.find((run) => run.operation === operation) || null;
  }

  function runFailureMessage(run, fallbackVerb) {
    if (!run || run.state !== 'failed') return null;
    const code = run.last_error_code;
    const detailMessage = run.last_error_message;
    if (code === 'student_note_schema_invalid') {
      const cause = detailMessage || 'AI 특기사항 응답의 형식·근거·기재 내용 검증을 통과하지 못했습니다. 이 실행에는 세부 원인이 기록되지 않았습니다.';
      return `${cause} 이번 초안은 저장하지 않았습니다. AI 특기사항을 다시 생성해 주세요. 기존 점수와 승인 기록은 변경되지 않았습니다.`;
    }
    if (code === 'output_schema_invalid'
      && /\$\.(?:source_hash\b|evaluation_ref\b|schema_id\|schema_version\|evaluation_ref\|source_hash)/.test(detailMessage || '')) {
      return 'AI 응답의 원본 제출 확인 정보가 올바르지 않아 이번 결과를 저장하지 않았습니다. AI 평가를 다시 시작해 주세요. 학생 제출 내용은 변경되지 않았습니다.';
    }
    if (code === 'output_domain_invalid' && /\$\.items\[\d+\]\.binding_key\b/.test(detailMessage || '')) {
      return 'AI가 평가 항목을 정확히 연결하지 못해 이번 결과를 저장하지 않았습니다. AI 평가를 다시 시작해 주세요. 학생 제출 내용은 변경되지 않았습니다.';
    }
    if (['output_domain_invalid', 'output_schema_invalid'].includes(code)
      && /\$\.items\[\d+\]\.evidence\[\d+\]/.test(detailMessage || '')) {
      const cause = /\.quote \(not found in (response|artifact)\)/.test(detailMessage)
        ? 'AI가 인용한 문장이 해당 학생 답변 또는 첨부자료에서 확인되지 않았습니다.'
        : /scope mismatch/.test(detailMessage) && !/or quote not found/.test(detailMessage)
          ? 'AI가 이 평가 항목의 대상이 아닌 다른 응답을 근거로 연결했습니다.'
          : 'AI가 제시한 근거를 해당 학생 답변 또는 첨부자료와 연결하지 못했습니다.';
      return `${cause} 이번 점수는 저장하지 않았습니다. AI 평가를 다시 시작해 주세요. 학생 제출 내용은 변경되지 않았습니다.`;
    }
    const base = RUN_FAILURE_LABELS[code] || `${fallbackVerb}에 실패했습니다.${code ? ` (${code})` : ''} 다시 시도하거나 관리자에게 문의해 주세요.`;
    return base + (detailMessage ? ` [${detailMessage}]` : '');
  }

  function scoreInputRefreshCommand(detail, commandId) {
    const evaluation = detail?.evaluation;
    const latestScore = (detail?.runs || []).find((run) => run.operation === 'score');
    const bindings = detail?.source?.bindings;
    const error = latestScore?.last_error_message || '';
    const legacyBindingFailure = /\$\.items\[\d+\]\.binding_key\b/.test(error)
      && Array.isArray(bindings) && bindings.length
      && !bindings.some((binding) => Object.hasOwn(binding, 'binding_ref'));
    const promptVersion = /^student_submission_assessment_prompt\.v([1-9]\d*)$/.exec(detail?.configuration?.prompt_contract_version || '');
    const legacyEvidenceFailure = /\$\.items\[\d+\]\.evidence\[\d+\]/.test(error)
      && promptVersion && Number(promptVersion[1]) < 9;
    const legacyAbstention = promptVersion && Number(promptVersion[1]) < 9
      && detail.selected_result?.abstained && canRetryScore(detail);
    if (!legacyAbstention && (evaluation?.state !== 'failed' || latestScore?.state !== 'failed'
      || !['output_domain_invalid', 'output_schema_invalid'].includes(latestScore.last_error_code)
      || !(legacyBindingFailure || legacyEvidenceFailure))) return null;
    const revision = detail?.source?.submission_revision;
    if (!evaluation.submission?.is_source_current || !revision?.is_official
      || evaluation.submission.source_revision_id !== revision.id) {
      throw new Error('제출 내용이 바뀌었습니다. 최신 제출을 확인한 뒤 평가를 시작해 주세요.');
    }
    return {
      submissionId: evaluation.submission.id,
      body: evaluateSubmissionCommand(commandId, {
        id: evaluation.submission.id,
        official_revision_id: revision.id,
        official_revision: revision,
      }),
    };
  }

  function runPendingMessage(run) {
    if (!run || !['queued', 'leased', 'running', 'retry_wait'].includes(run.state)) return null;
    return 'AI가 생성하고 있습니다. 완료되면 자동으로 반영됩니다.';
  }

  function feedbackTone(stateValue) {
    if (stateValue === 'approved') return 'success';
    if (stateValue === 'rejected') return 'danger';
    if (stateValue === 'ai_draft' || stateValue === 'teacher_draft' || stateValue === 'stale') return 'attention';
    return 'neutral';
  }

  function renderFeedbackContent(detail, feedback) {
    const profile = feedbackProfile(feedback);
    if (!profile) return null;
    const value = node('div', 'oj-ai-submission-review__feedback-readonly');
    if (profile.overall_feedback) {
      const overall = node('div');
      append(overall, node('strong', '', '종합 의견'), node('p', '', profile.overall_feedback));
      value.appendChild(overall);
    }
    const criterionItems = Array.isArray(profile.criterion_feedback) ? profile.criterion_feedback : [];
    if (criterionItems.length) {
      const criteria = node('div', 'oj-ai-submission-review__feedback-readonly-criteria');
      criterionItems.forEach((item) => {
        const article = node('article');
        append(
          article,
          node('h4', '', bindingLabel(detail, item)),
          node('strong', '', '잘한 점'),
          node('p', '', item.strength || '-'),
          node('strong', '', '다음에 해볼 것'),
          node('p', '', item.next_step || '-')
        );
        criteria.appendChild(article);
      });
      value.appendChild(criteria);
    }
    const nextSteps = Array.isArray(profile.next_steps) ? profile.next_steps.filter(Boolean) : [];
    if (nextSteps.length) {
      const block = node('div');
      block.appendChild(node('strong', '', '다음 단계'));
      const list = node('ul', 'oj-ai-submission-review__feedback-next-list');
      nextSteps.forEach((item) => list.appendChild(node('li', '', item)));
      block.appendChild(list);
      value.appendChild(block);
    }
    return value;
  }

  function feedbackEditor(detail, feedback = null) {
    const profile = feedbackProfile(feedback) || {};
    const previous = new Map((Array.isArray(profile.criterion_feedback) ? profile.criterion_feedback : []).map((item) => [item.binding_key, item]));
    const editor = node('div', 'oj-ai-submission-review__feedback-editor');
    const criteria = node('div', 'oj-ai-submission-review__feedback-criteria');
    officialFeedbackItems(detail).forEach((item) => {
      const saved = previous.get(item.binding_key) || {};
      const article = node('article', 'oj-ai-submission-review__feedback-criterion');
      article.dataset.feedbackBindingKey = item.binding_key;
      article.appendChild(node('h4', '', bindingLabel(detail, item)));
      const strengthLabel = node('label');
      strengthLabel.appendChild(node('span', '', '잘한 점'));
      const strength = node('textarea');
      strength.rows = 3;
      strength.maxLength = 600;
      strength.dataset.feedbackField = 'strength';
      strength.value = saved.strength || '';
      strengthLabel.appendChild(strength);
      const nextLabel = node('label');
      nextLabel.appendChild(node('span', '', '다음에 해볼 것'));
      const next = node('textarea');
      next.rows = 3;
      next.maxLength = 600;
      next.dataset.feedbackField = 'criterion-next-step';
      next.value = saved.next_step || '';
      nextLabel.appendChild(next);
      append(article, strengthLabel, nextLabel);
      criteria.appendChild(article);
    });
    editor.appendChild(criteria);
    const overallLabel = node('label', 'oj-ai-submission-review__feedback-field');
    overallLabel.appendChild(node('span', '', '종합 의견'));
    const overall = node('textarea');
    overall.rows = 4;
    overall.maxLength = 2000;
    overall.dataset.feedbackField = 'overall';
    overall.value = profile.overall_feedback || '';
    overallLabel.appendChild(overall);
    const nextStepsLabel = node('label', 'oj-ai-submission-review__feedback-field');
    nextStepsLabel.appendChild(node('span', '', '다음 단계'));
    const nextSteps = node('textarea');
    nextSteps.rows = 3;
    nextSteps.maxLength = 902;
    nextSteps.placeholder = '한 줄에 하나, 최대 3개';
    nextSteps.dataset.feedbackField = 'next-steps';
    nextSteps.value = (Array.isArray(profile.next_steps) ? profile.next_steps : []).join('\n');
    nextStepsLabel.appendChild(nextSteps);
    append(editor, overallLabel, nextStepsLabel);
    appendWritingCheck(editor);
    return editor;
  }

  function renderFeedbackHistory(detail) {
    const items = Array.isArray(detail.feedback) ? detail.feedback : [];
    if (!items.length) return null;
    const value = node('details', 'oj-ai-submission-review__feedback-history');
    value.appendChild(node('summary', '', `작성 이력 ${items.length}`));
    const list = node('ul', 'oj-ai-submission-review__timeline');
    [...items].reverse().forEach((item) => {
      const entry = node('li');
      append(
        entry,
        node('strong', '', `기록 ${item.revision_no}`),
        node('span', '', FEEDBACK_LABELS[item.state] || '확인'),
        node('span', '', dateLabel(item.created_at_utc))
      );
      list.appendChild(entry);
    });
    value.appendChild(list);
    return value;
  }

  function renderFeedback(detail) {
    const feedbackItems = Array.isArray(detail.feedback) ? detail.feedback : [];
    const latest = latestFeedback(detail);
    const value = section('학생 피드백', feedbackItems.length);
    const official = officialReview(detail);
    if (!official || !detail.review_precondition?.expected_official_review_id) {
      value.appendChild(node('p', 'oj-ai-submission-review__feedback-empty', '점수를 먼저 확정하세요.'));
      return value;
    }
    const header = node('div', 'oj-ai-submission-review__feedback-header');
    append(
      header,
      node('p', '', latest ? '학생에게 전할 내용을 확인하세요.' : '학생에게 전할 내용을 작성하세요.'),
      badge(latest ? (FEEDBACK_LABELS[latest.state] || '확인') : '작성 전', feedbackTone(latest?.state))
    );
    value.appendChild(header);

    if (latest?.state === 'approved') {
      const approved = node('div', 'oj-ai-submission-review__feedback-approved');
      append(approved, node('strong', '', '승인됨'), node('span', '', '공개 상태: ' + (RELEASE_LABELS[detail.evaluation.release?.state] || '미공개')));
      value.appendChild(approved);
      value.appendChild(renderFeedbackContent(detail, latest));
      if (state.canReview) {
        const approvedActions = node('div', 'oj-ai-submission-review__feedback-actions');
        approvedActions.appendChild(feedbackButton('승인 회수', 'withdraw-approval', 'oj-ai-submission-review__secondary'));
        value.appendChild(approvedActions);
      }
      append(value, renderFeedbackHistory(detail));
      return value;
    }

    if (latest?.state === 'rejected' || latest?.state === 'stale') {
      const profile = feedbackProfile(latest);
      const message = latest.state === 'stale'
        ? '점수가 바뀌었습니다. 새 피드백을 준비하세요.'
        : profile?.reason || '새 피드백을 작성하세요.';
      const warning = node('p', 'oj-ai-submission-review__feedback-warning', message);
      warning.dataset.tone = latest.state === 'rejected' ? 'danger' : 'attention';
      value.appendChild(warning);
    }
    if (latest?.state === 'ai_draft' && feedbackProfile(latest)?.safety_flags?.length) {
      value.appendChild(node('p', 'oj-ai-submission-review__feedback-warning', 'AI 초안을 직접 확인해 주세요.'));
    }

    if (!latest || ['rejected', 'stale'].includes(latest.state)) {
      const run = latestRunForOperation(detail, 'feedback_draft');
      const failure = runFailureMessage(run, 'AI 피드백 생성');
      const pending = runPendingMessage(run);
      if (failure) {
        const warning = node('p', 'oj-ai-submission-review__feedback-warning', failure);
        warning.dataset.tone = 'danger';
        value.appendChild(warning);
      } else if (pending) {
        value.appendChild(node('p', 'oj-ai-submission-review__feedback-empty', pending));
      }
    }

    const editable = ['ai_draft', 'teacher_draft'].includes(latest?.state);
    if (state.canReview) {
      value.appendChild(feedbackEditor(detail, editable ? latest : null));
      const actions = node('div', 'oj-ai-submission-review__feedback-actions');
      const feedbackGenerating = 'feedback_draft' === state.pollingOperation;
      if (!latest || ['rejected', 'stale'].includes(latest.state)) {
        const generateButton = feedbackButton(feedbackGenerating ? 'AI 초안 생성 중...' : 'AI 초안', 'generate', 'oj-ai-submission-review__secondary');
        if (feedbackGenerating) generateButton.disabled = true;
        actions.appendChild(generateButton);
      }
      actions.appendChild(feedbackButton(editable ? '수정 저장' : '초안 저장', 'save', 'oj-ai-submission-review__secondary'));
      if (editable) actions.appendChild(feedbackButton('기각', 'reject', 'oj-ai-submission-review__danger-link'));
      if (latest?.state === 'teacher_draft') actions.appendChild(feedbackButton('승인', 'approve', 'oj-ai-submission-review__primary'));
      value.appendChild(actions);
    } else if (latest) {
      value.appendChild(renderFeedbackContent(detail, latest));
    } else {
      value.appendChild(node('p', 'oj-ai-submission-review__feedback-empty', '작성된 피드백이 없습니다.'));
    }
    append(value, renderFeedbackHistory(detail));
    return value;
  }

  function studentNoteTone(stateValue) {
    if (stateValue === 'approved') return 'success';
    if (stateValue === 'rejected') return 'danger';
    if (stateValue === 'ai_draft' || stateValue === 'teacher_draft' || stateValue === 'superseded') return 'attention';
    return 'neutral';
  }

  function studentNoteLatestRevision(noteDetail) {
    const revisions = Array.isArray(noteDetail?.revisions) ? noteDetail.revisions : [];
    return revisions.length ? revisions[revisions.length - 1] : null;
  }

  function studentNoteEvidenceItems(noteDetail) {
    const evidence = studentNoteLatestRevision(noteDetail)?.content?.evidence;
    return Array.isArray(evidence) ? evidence : [];
  }

  function studentNoteEditor(noteDetail) {
    const latest = studentNoteLatestRevision(noteDetail);
    const content = latest.content;
    const usedKeys = new Set([
      ...(content.summary?.evidence_keys || []),
      ...content.strengths.flatMap((item) => item.evidence_keys || []),
      ...content.support_suggestions.flatMap((item) => item.evidence_keys || []),
    ]);
    const editor = node('div', 'oj-ai-submission-review__student-note-editor');
    const evidenceBlock = node('div', 'oj-ai-submission-review__student-note-evidence');
    evidenceBlock.appendChild(node('strong', '', '사용할 근거 선택'));
    studentNoteEvidenceItems(noteDetail).forEach((item) => {
      const label = node('label', 'oj-ai-submission-review__student-note-evidence-item');
      const checkbox = node('input');
      checkbox.type = 'checkbox';
      checkbox.dataset.studentNoteField = 'evidence';
      checkbox.value = item.evidence_key;
      checkbox.checked = usedKeys.has(item.evidence_key);
      append(label, checkbox, node('span', '', item.observation));
      evidenceBlock.appendChild(label);
    });
    editor.appendChild(evidenceBlock);

    const summaryLabel = node('label', 'oj-ai-submission-review__feedback-field');
    summaryLabel.appendChild(node('span', '', '학생부 기록 초안'));
    const summary = node('textarea');
    summary.rows = 4;
    summary.maxLength = 2000;
    summary.dataset.studentNoteField = 'summary';
    summary.value = content.summary?.text || '';
    summaryLabel.appendChild(summary);

    const strengthsLabel = node('label', 'oj-ai-submission-review__feedback-field');
    strengthsLabel.appendChild(node('span', '', '강점 (선택, 최대 3개)'));
    const strengths = node('textarea');
    strengths.rows = 3;
    strengths.dataset.studentNoteField = 'strengths';
    strengths.value = content.strengths.map((item) => item.text).join('\n');
    strengthsLabel.appendChild(strengths);

    const suggestionsLabel = node('label', 'oj-ai-submission-review__feedback-field');
    suggestionsLabel.appendChild(node('span', '', '지도 참고사항 (학생부 기록과 별도, 최대 3개)'));
    const suggestions = node('textarea');
    suggestions.rows = 3;
    suggestions.dataset.studentNoteField = 'support-suggestions';
    suggestions.value = content.support_suggestions.map((item) => item.text).join('\n');
    suggestionsLabel.appendChild(suggestions);

    append(editor, summaryLabel, strengthsLabel, suggestionsLabel);
    appendWritingCheck(editor, true);
    return editor;
  }

  function appendWritingCheck(editor, schoolRecord = false) {
    editor.dataset.reportWriting = schoolRecord ? 'school-record' : 'feedback';
    const warning = node('p', 'oj-ai-submission-review__feedback-warning');
    warning.dataset.writingCheck = '';
    warning.setAttribute('role', 'status');
    editor.appendChild(warning);
    updateWritingCheck(editor);
  }

  function updateWritingCheck(editor) {
    const warning = editor.querySelector('[data-writing-check]');
    const values = [...editor.querySelectorAll('textarea')].map((control) => control.value);
    const issues = reportWritingIssues(values, editor.dataset.reportWriting === 'school-record');
    warning.textContent = issues.join(' ');
    warning.hidden = issues.length === 0;
  }

  function studentNoteWritingGuidance() {
    const guidance = node('details', 'oj-ai-submission-review__feedback-history');
    guidance.appendChild(node('summary', '', '학생부 특기사항 작성요령'));
    const list = node('ul', 'oj-ai-submission-review__feedback-next-list');
    [
      '교사가 수업에서 확인한 근거에 맞춰 활동, 수행 과정, 결과를 연결하고 명사형(~함·~음)으로 종결함.',
      '분석·비교·근거 선택 등 실제 제출물에서 확인되는 수행을 기술함. 확인되지 않은 발표·협력·성장·태도·내면을 만들지 않음.',
      '교외 실적, 외부 시험·자격, 논문·특허, 해외 활동, 장학금, 가정 배경 등 기재 제한 사항을 제외함. 특정 기관명 등의 예외와 학교급·항목별 분량은 교사가 확인함.',
      '지도 참고사항은 학생부 기록과 분리함. AI 문장을 그대로 입력하지 않고 교사가 실제 관찰 근거와 기재요령에 맞게 검토·수정함.',
    ].forEach((text) => list.appendChild(node('li', '', text)));
    guidance.appendChild(list);
    return guidance;
  }

  function renderStudentNoteContent(noteDetail) {
    const content = studentNoteLatestRevision(noteDetail).content;
    const value = node('div', 'oj-ai-submission-review__feedback-readonly');
    const summary = node('div');
    append(summary, node('strong', '', '학생부 기록 초안'), node('p', '', content.summary?.text || '-'));
    value.appendChild(summary);
    if (content.strengths.length) {
      const block = node('div');
      block.appendChild(node('strong', '', '강점'));
      const list = node('ul', 'oj-ai-submission-review__feedback-next-list');
      content.strengths.forEach((item) => list.appendChild(node('li', '', item.text)));
      block.appendChild(list);
      value.appendChild(block);
    }
    if (content.support_suggestions.length) {
      const block = node('div');
      block.appendChild(node('strong', '', '지도 참고사항 (학생부 기록과 별도)'));
      const list = node('ul', 'oj-ai-submission-review__feedback-next-list');
      content.support_suggestions.forEach((item) => list.appendChild(node('li', '', item.text)));
      block.appendChild(list);
      value.appendChild(block);
    }
    return value;
  }

  function renderStudentNoteHistory(noteDetail) {
    const items = Array.isArray(noteDetail?.revisions) ? noteDetail.revisions : [];
    if (!items.length) return null;
    const value = node('details', 'oj-ai-submission-review__feedback-history');
    value.appendChild(node('summary', '', `작성 이력 ${items.length}`));
    const list = node('ul', 'oj-ai-submission-review__timeline');
    [...items].reverse().forEach((item) => {
      const entry = node('li');
      append(
        entry,
        node('strong', '', `기록 ${item.revision_no}`),
        node('span', '', STUDENT_NOTE_LABELS[item.state] || '확인'),
        node('span', '', dateLabel(item.created_at_utc))
      );
      list.appendChild(entry);
    });
    value.appendChild(list);
    return value;
  }

  function renderStudentNote(detail) {
    if (!state.canManageStudentNotes) return null;
    const official = officialReview(detail);
    const value = section('학생 특기사항');
    value.appendChild(studentNoteWritingGuidance());
    if (!official || !detail.review_precondition?.expected_official_review_id) {
      value.appendChild(node('p', 'oj-ai-submission-review__feedback-empty', '점수를 먼저 확정하세요.'));
      return value;
    }
    if (state.studentNoteLoading) {
      value.appendChild(node('p', 'oj-ai-submission-review__feedback-empty', '특기사항을 불러오는 중입니다.'));
      return value;
    }
    const noteDetail = state.studentNote;
    const latest = studentNoteLatestRevision(noteDetail);
    const header = node('div', 'oj-ai-submission-review__feedback-header');
    append(
      header,
      node('p', '', latest ? '학생 특기사항을 확인하세요.' : '학생 특기사항을 작성하세요.'),
      badge(latest ? (STUDENT_NOTE_LABELS[latest.state] || '확인') : '작성 전', studentNoteTone(latest?.state))
    );
    value.appendChild(header);

    if (latest?.state === 'approved') {
      const approved = node('div', 'oj-ai-submission-review__feedback-approved');
      append(approved, node('strong', '', '승인됨'), node('span', '', latest.include_in_reports ? '보고서에 포함됨' : '보고서 미포함'));
      value.appendChild(approved);
      value.appendChild(renderStudentNoteContent(noteDetail));
      const inclusionActions = node('div', 'oj-ai-submission-review__feedback-actions');
      inclusionActions.appendChild(studentNoteButton(
        latest.include_in_reports ? '보고서에서 제외' : '보고서에 포함',
        latest.include_in_reports ? 'exclude' : 'include',
        'oj-ai-submission-review__secondary'
      ));
      if (latest.include_in_reports) {
        inclusionActions.appendChild(node('span', 'oj-ai-submission-review__feedback-warning', '보고서 포함을 해제하면 승인을 회수할 수 있습니다.'));
      } else {
        inclusionActions.appendChild(studentNoteButton('승인 회수', 'withdraw-approval', 'oj-ai-submission-review__secondary'));
      }
      value.appendChild(inclusionActions);
      append(value, renderStudentNoteHistory(noteDetail));
      return value;
    }

    if (latest?.state === 'rejected' || latest?.state === 'superseded') {
      const message = latest.state === 'superseded'
        ? '평가가 갱신되어 특기사항을 다시 작성해야 합니다.'
        : '새 특기사항을 작성하세요.';
      const warning = node('p', 'oj-ai-submission-review__feedback-warning', message);
      warning.dataset.tone = latest.state === 'rejected' ? 'danger' : 'attention';
      value.appendChild(warning);
    }

    if (!latest || ['rejected', 'superseded'].includes(latest.state)) {
      const run = latestRunForOperation(detail, 'special_note_draft');
      const failure = runFailureMessage(run, 'AI 특기사항 생성');
      const pending = runPendingMessage(run);
      if (failure) {
        const warning = node('p', 'oj-ai-submission-review__feedback-warning', failure);
        warning.dataset.tone = 'danger';
        value.appendChild(warning);
      } else if (pending) {
        value.appendChild(node('p', 'oj-ai-submission-review__feedback-empty', pending));
      }
    }

    const editable = ['ai_draft', 'teacher_draft'].includes(latest?.state);
    if (editable) {
      value.appendChild(studentNoteEditor(noteDetail));
    }
    const actions = node('div', 'oj-ai-submission-review__feedback-actions');
    const noteGenerating = 'special_note_draft' === state.pollingOperation;
    if (!latest || ['rejected', 'superseded'].includes(latest.state)) {
      const generateButton = studentNoteButton(noteGenerating ? 'AI 특기사항 생성 중...' : 'AI 특기사항 생성', 'generate', 'oj-ai-submission-review__secondary');
      if (noteGenerating) generateButton.disabled = true;
      actions.appendChild(generateButton);
    }
    if (editable) {
      actions.appendChild(studentNoteButton('수정 저장', 'save', 'oj-ai-submission-review__secondary'));
      actions.appendChild(studentNoteButton('기각', 'reject', 'oj-ai-submission-review__danger-link'));
    }
    if (latest?.state === 'teacher_draft') {
      actions.appendChild(studentNoteButton('승인', 'approve', 'oj-ai-submission-review__primary'));
    }
    value.appendChild(actions);
    append(value, renderStudentNoteHistory(noteDetail));
    return value;
  }

  let detailBaseline = null;
  let detailScrollSelection = '';
  const detailInputs = () => JSON.stringify([...elements.detail.querySelectorAll('input,textarea,select')].map(control => [control.name, control.type, control.value, control.checked]));
  const hasDetailChanges = () => detailBaseline !== null && detailInputs() !== detailBaseline;
  const canLeaveDetail = () => !hasDetailChanges() || window.confirm('저장하지 않은 점수 또는 보고서 변경이 있습니다. 변경 내용을 버리고 이동할까요?');
  function replaceDetail(content) {
    const selection = `${state.selectedType}:${state.selectedId}`;
    if (selection !== detailScrollSelection) {
      elements.detail.scrollTop = 0;
      elements.detail.scrollLeft = 0;
      detailScrollSelection = selection;
    }
    preserveScrollPosition(elements.detail, () => elements.detail.replaceChildren(content));
    detailBaseline = detailInputs();
  }
  document.addEventListener('ojai:before-navigation', event => {
    if (workflowBusy()) { event.preventDefault(); showNotice('일괄 작업을 중지하고 처리 현황을 닫은 뒤 이동해 주세요.'); return; }
    if (!canLeaveDetail()) event.preventDefault();
  });
  window.addEventListener('beforeunload', event => { if(hasDetailChanges() || state.batch?.controller.snapshot().running || workflowBusy()){event.preventDefault();event.returnValue='';} });
  root.addEventListener('click', event => {
    if (contextsLoading) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    if (state.scoreDownloading && !event.target.closest('[data-review-role="score-download-dialog"]')) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    if (workflowBusy() && !event.target.closest('[data-workflow-control]') && !elements.workflowDialog?.contains(event.target)) {
      event.preventDefault(); event.stopImmediatePropagation(); return;
    }
    const action=event.target.closest('[data-review-action]')?.dataset.reviewAction;
    if(['select','refresh','detail-refresh'].includes(action) && !canLeaveDetail()){event.preventDefault();event.stopImmediatePropagation();}
  }, true);
  root.addEventListener('focusin', event => { if([fields.context,fields.assignment,fields.state,fields.submissionStatus].includes(event.target))event.target.dataset.navigationPreviousValue=event.target.value; });
  root.addEventListener('change', event => {
    if (contextsLoading) { event.stopImmediatePropagation(); return; }
    if(![fields.context,fields.assignment,fields.state,fields.submissionStatus].includes(event.target))return;
    if(!canLeaveDetail()){event.target.value=event.target.dataset.navigationPreviousValue || '';event.stopImmediatePropagation();}
  }, true);

  function workflowLink(stage, label) {
    if(window.OJAIWorkflow.canNavigate && !window.OJAIWorkflow.canNavigate(stage))return document.createDocumentFragment();
    const link = node('a', 'oj-ai-submission-review__secondary', label);
    link.href = window.OJAIWorkflow.url(stage, { evaluation_id: state.detail?.evaluation?.id });
    link.dataset.workflowStep = stage;
    return link;
  }

  function renderScoreStatus(detail) {
    if (scoreApproved(detail)) return null;
    if (manualScore(detail)) return null;
    const evaluation = detail.evaluation;
    const rejection = selectedScoreRejection(detail);
    const failed = evaluation.state === 'failed';
    const cancelled = evaluation.state === 'cancelled';
    const pending = scorePending(detail);
    const abstained = Boolean(detail.selected_result?.abstained);
    if (!pending && !failed && !cancelled && !rejection && !abstained && detail.selected_result) return null;
    const title = pending ? 'AI 평가 진행 상태' : failed ? '평가 실패' : cancelled ? '평가 취소' : rejection ? 'AI 점수 반려' : abstained ? 'AI 판단 보류' : '평가 상태';
    const status = section(title);
    const latestRun = latestRunForOperation(detail, 'score');
    const message = pending
      ? evaluation.state === 'running' ? '서버에서 AI 평가를 진행하고 있습니다.' : '평가 작업이 서버 대기열에 등록되어 있습니다.'
      : failed ? (runFailureMessage(latestRun, 'AI 평가') || 'AI 평가에 실패했습니다. 다시 시도해 주세요.')
        : cancelled ? 'AI 평가가 취소되었습니다.' : rejection ? '이 AI 점수는 반려되었습니다.' : abstained ? '항목별 보류 사유를 확인한 뒤 재채점하거나 직접 점수를 입력할 수 있습니다.' : '표시할 AI 평가 결과가 없습니다.';
    status.appendChild(node('p', 'oj-ai-submission-review__summary-text', message));
    if (rejection?.reason) status.appendChild(node('p', '', `반려 사유: ${rejection.reason}`));
    if (state.canAssess && canRetryScore(detail)) {
      const retry = button(rejection || abstained ? 'AI 재채점' : 'AI 평가 다시 시작', 'retry-evaluation', 'oj-ai-submission-review__primary');
      retry.disabled = state.submitting;
      status.appendChild(retry);
    }
    if (state.canReview && !detail.selected_result && !pending && evaluation.submission?.is_source_current) {
      const manual = button('교사 직접 채점', 'manual-assessment', 'oj-ai-submission-review__secondary');
      manual.disabled = state.submitting;
      status.appendChild(manual);
    }
    return status;
  }

  function renderDetail() {
    const detail = state.detail;
    if (!detail?.evaluation) {
      renderEmptyDetail('결과가 없습니다.', '제출을 다시 선택해 주세요.');
      return;
    }
    const fragment = document.createDocumentFragment();
    fragment.appendChild(detailHeader(detail));
    fragment.appendChild(metrics(detail));
    if (workflowStage !== 'assessment') {
      if (!officialReview(detail)) {
        fragment.appendChild(node('p', '', 'AI 평가에서 점수를 먼저 확정해 주세요.'));
        fragment.appendChild(workflowLink('assessment', '점수 검토'));
      } else if (workflowStage === 'report') {
        fragment.appendChild(renderFeedback(detail));
        const note = renderStudentNote(detail); if (note) fragment.appendChild(note);
        const actions = node('div', 'oj-ai-submission-review__feedback-actions');
        append(actions, workflowLink('summary', '보고서'), workflowLink('release', '학생 공개 준비'));
        fragment.appendChild(actions);
      } else {
      const preview = section('검토·승인한 내용');
        const official = officialReview(detail);
        preview.appendChild(node('p', '', '확정 점수: ' + scoreLabel(official.official_score, detail.selected_result?.maximum_score)));
        const feedback = latestFeedback(detail);
        if (feedback?.state === 'approved') append(preview, renderFeedbackContent(detail, feedback));
        else preview.appendChild(node('p', '', '승인된 학생 피드백이 없습니다. 보고서 작성에서 먼저 승인해 주세요.'));
        preview.appendChild(workflowLink('report', '보고서 확인'));
        fragment.appendChild(preview);
      }
      replaceDetail(fragment);
      return;
    }
    const result = detail.selected_result;
    if (scoreApproved(detail)) {
      fragment.appendChild(reviewActions(detail) || node('p', 'oj-ai-submission-review__official-score', '점수 승인 완료'));
    }
    const scoreStatus = renderScoreStatus(detail);
    if (scoreStatus) fragment.appendChild(scoreStatus);
    if (!result) {
      replaceDetail(fragment);
      return;
    }
    if (!scoreApproved(detail) && !manualScore(detail) && (result.safety_flagged || result.abstained)) {
      const warning = node('div', 'oj-ai-submission-review__warning');
      warning.dataset.tone = result.safety_flagged ? 'danger' : 'attention';
      append(warning, node('strong', '', result.safety_flagged ? '직접 확인이 필요합니다.' : 'AI가 일부 점수를 판단하지 못했습니다.'), node('span', '', '학생 답변과 근거를 확인한 뒤 점수를 입력하세요.'));
      fragment.appendChild(warning);
    }
    if (!manualScore(detail)) {
      const summary = section('AI 평가 요약');
      summary.appendChild(node('p', 'oj-ai-submission-review__summary-text', result.summary));
      fragment.appendChild(summary);
    }
    const responses = section('학생 답변', detail.source?.responses?.length || 0);
    responses.appendChild(renderResponses(detail));
    fragment.appendChild(responses);
    const criteria = section('점수와 근거', result.items?.length || 0);
    const previous = latestReviewItems(detail);
    const list = node('div', 'oj-ai-submission-review__criteria');
    (result.items || []).forEach((item) => list.appendChild(renderCriterion(detail, item, previous.get(item.binding_key))));
    criteria.appendChild(list);
    const actions = scoreApproved(detail) ? null : reviewActions(detail);
    if (actions) criteria.appendChild(actions);
    fragment.appendChild(criteria);
    if (result.limitations?.length) {
      const limitations = section('확인 사항', result.limitations.length);
      const listNode = node('ul', 'oj-ai-submission-review__limitations');
      result.limitations.forEach((item) => listNode.appendChild(node('li', '', item)));
      limitations.appendChild(listNode);
      fragment.appendChild(limitations);
    }
    const history = renderReviewHistory(detail);
    if (history) fragment.appendChild(history);
    if (officialReview(detail)) fragment.appendChild(workflowLink('report', '보고서 작성'));
    replaceDetail(fragment);
    updateOfficialTotal();
  }

  async function loadDetail(evaluationId) {
    scorePoller.stop();
    const context = currentContext();
    if (!context || !evaluationId) return;
    const scrollTop = state.selectedType === 'evaluation' && state.selectedId === evaluationId ? elements.detail.scrollTop : 0;
    if (state.detail?.evaluation?.id !== evaluationId) {
      state.draftPollToken += 1;
      state.pollingOperation = null;
    }
    state.selectedId = evaluationId;
    state.selectedType = 'evaluation';
    state.candidateDetail = null;
    renderList();
    const requestId = ++state.detailRequest;
    renderEmptyDetail('불러오는 중', '학생 답변과 평가 근거를 확인하고 있습니다.');
    const item = state.items.find((value) => value.id === evaluationId);
    const extra = item?.candidate?.id ? { result_id: item.candidate.id } : {};
    try {
      const envelope = await request(`/submission-evaluations/${encodeURIComponent(evaluationId)}?${contextQuery(context, extra)}`);
      if (requestId !== state.detailRequest) return;
      state.detail = envelope.data;
      state.studentNote = null;
      const detailAssignment = state.detail?.evaluation?.assignment;
      const assignment = state.assignments.find((value) => value.id === detailAssignment?.id);
      if (detailAssignment?.id) window.OJAIWorkflow?.setSelection({
        assignmentId: detailAssignment.id,
        assignmentTitle: detailAssignment.title,
        context,
        ...(assignment ? {
          worksheetId: assignment.worksheet_id,
          publicationId: assignment.worksheet_publication_id,
          publicationHash: assignment.publication_hash,
        } : {}),
      });
      renderDetail();
      elements.detail.scrollTop = scrollTop;
      scorePoller.start();
      refreshStudentNote();
    } catch (error) {
      if (requestId !== state.detailRequest) return;
      state.detail = null;
      renderEmptyDetail('평가를 불러오지 못했습니다.', problemMessage(error, '잠시 후 다시 시도해 주세요.'));
    }
  }

  async function loadCandidateDetail(submissionId) {
    scorePoller.stop();
    const context = currentContext();
    if (!context || !submissionId) return;
    const scrollTop = state.selectedType === 'candidate' && state.selectedId === submissionId ? elements.detail.scrollTop : 0;
    state.selectedId = submissionId;
    state.selectedType = 'candidate';
    state.detail = null;
    state.studentNote = null;
    state.draftPollToken += 1;
    state.pollingOperation = null;
    renderList();
    const requestId = ++state.detailRequest;
    renderEmptyDetail('불러오는 중', '학생의 공식 제출본을 확인하고 있습니다.');
    try {
      const envelope = await request(`/submission-evaluation-candidates/${encodeURIComponent(submissionId)}?${contextQuery(context)}`);
      if (requestId !== state.detailRequest) return;
      if (envelope.data?.submission?.id !== submissionId) throw new Error('제출 응답을 확인할 수 없습니다.');
      state.candidateDetail = envelope.data;
      renderCandidateDetail();
      elements.detail.scrollTop = scrollTop;
    } catch (error) {
      if (requestId !== state.detailRequest) return;
      state.candidateDetail = null;
      renderEmptyDetail('제출을 불러오지 못했습니다.', problemMessage(error, '잠시 후 다시 시도해 주세요.'));
    }
  }

  async function findStudentNoteSummary(context, assignmentId, evaluationId) {
    const params = contextQuery(context, { assignment_id: assignmentId, limit: '50' });
    const items = await window.OJAIWorkflow.allPages(request, `/student-notes?${params}`);
    return items.find((item) => item?.evaluation?.id === evaluationId) || null;
  }

  async function loadStudentNoteDetail(noteId, context) {
    const envelope = await request(`/student-notes/${encodeURIComponent(noteId)}?${contextQuery(context)}`);
    return envelope.data;
  }

  async function refreshStudentNote() {
    if (workflowStage !== 'report') return;
    const context = currentContext();
    const detail = state.detail;
    if (!state.canManageStudentNotes || !context || !detail?.evaluation || !officialReview(detail)) {
      state.studentNote = null;
      state.studentNoteLoading = false;
      if (state.detail === detail) renderDetail();
      return;
    }
    const requestId = ++state.studentNoteRequest;
    state.studentNoteLoading = true;
    renderDetail();
    try {
      const summary = await findStudentNoteSummary(context, detail.evaluation.assignment.id, detail.evaluation.id);
      if (requestId !== state.studentNoteRequest) return;
      state.studentNote = summary ? await loadStudentNoteDetail(summary.id, context) : null;
      if (requestId !== state.studentNoteRequest) return;
    } catch (_error) {
      if (requestId !== state.studentNoteRequest) return;
      state.studentNote = null;
    } finally {
      if (requestId === state.studentNoteRequest) {
        state.studentNoteLoading = false;
        renderDetail();
      }
    }
  }

  const SETTLED_RUN_STATES = Object.freeze(['succeeded', 'failed', 'cancelled']);

  /**
   * Poll the evaluation detail (which already carries every operation's
   * latest run) until the given operation's run leaves a pending state, so
   * the teacher sees a clear "generating" indicator the whole time instead
   * of a one-shot toast that never updates. Stops as soon as the run
   * settles -- renderFeedback()/renderStudentNote() already know how to
   * show a settled success/failure from detail.runs.
   */
  async function pollDraftGeneration(evaluationId, context, operation) {
    const pollToken = ++state.draftPollToken;
    state.pollingOperation = operation;
    renderDetail();
    for (let attempt = 0; attempt < 12; attempt += 1) {
      await wait(attempt === 0 ? 900 : 1800);
      if (pollToken !== state.draftPollToken || state.detail?.evaluation?.id !== evaluationId) return;
      try {
        const envelope = await request(`/submission-evaluations/${encodeURIComponent(evaluationId)}?${contextQuery(context)}`);
        if (pollToken !== state.draftPollToken || state.detail?.evaluation?.id !== evaluationId) return;
        state.detail = envelope.data;
        const run = latestRunForOperation(state.detail, operation);
        if (run && SETTLED_RUN_STATES.includes(run.state)) {
          state.pollingOperation = null;
          if ('special_note_draft' === operation) { await refreshStudentNote(); } else { renderDetail(); }
          return;
        }
        renderDetail();
      } catch (_error) {
        // transient errors are tolerated; polling continues until the attempt budget is used.
      }
    }
    if (pollToken === state.draftPollToken) {
      state.pollingOperation = null;
      showNotice('아직 생성이 끝나지 않았습니다. 잠시 후 새로고침해 주세요.', 'error');
      renderDetail();
    }
  }

  async function loadRosterEvaluations(context, assignment, preferredId, preferredType) {
    state.rosterReady = false;
    state.rosterCutoff = '';
    updateBatchButton();
    scorePoller.stop();
    const requestId = ++state.listRequest;
    ++state.detailRequest;
    state.detail = null;
    state.candidateDetail = null;
    elements.content.hidden = true;
    elements.loadMore.hidden = true;
    elements.summary.textContent = `${context.label} · 제출 현황 확인 중`;
    showNotice('학생 명단과 제출 상태를 불러오는 중입니다.');
    window.OJAIWorkflow?.setSelection({ worksheetId: assignment.worksheet_id || requestedWorksheetId, assignmentId: assignment.id, publicationId: assignment.worksheet_publication_id, publicationHash: assignment.publication_hash, assignmentTitle: assignment.title, context });
    const scope = contextQuery(context, { assignment_id: assignment.id });
    try {
      const [rosterEnvelope, evaluations, candidates] = await Promise.all([
        request(`/submission-evaluation-roster?${scope}`),
        window.OJAIWorkflow.allPages(request, `/submission-evaluations?${scope}&limit=100`),
        (state.canAssess || state.canReview) ? window.OJAIWorkflow.allPages(request, `/submission-evaluation-candidates?${scope}&limit=100`) : Promise.resolve([]),
      ]);
      if (requestId !== state.listRequest) return;
      if (!Array.isArray(rosterEnvelope?.data?.items)) throw new Error('학생 명단 응답을 확인하지 못했습니다.');
      state.items = buildRosterItems(rosterEnvelope.data.items, evaluations, candidates, assignment);
      state.rosterCutoff = rosterEnvelope.meta?.server_time_utc || '';
      state.rosterReady = true;
      updateBatchButton();
      state.nextEvaluationCursor = '';
      state.nextCandidateCursor = '';
      const counts = rosterCounts(state.items);
      elements.summary.textContent = `${context.label} · 전체 ${counts.total}명 · 제출 ${counts.submitted}명 · 미제출 ${counts.not_submitted}명${counts.not_assigned ? ` · 배포 대상 아님 ${counts.not_assigned}명` : ''}`;
      elements.content.hidden = false;
      showNotice(state.items.length ? '' : '학생 명단이 없습니다.');
      renderList();
      const items = visibleItems();
      const next = items.find(item => item.id === preferredId && itemType(item) === preferredType)
        || items.find(item => item.id === state.selectedId && itemType(item) === state.selectedType) || items[0];
      if (next?.kind === 'roster') selectRosterItem(next);
      else if (next?.kind === 'candidate') await loadCandidateDetail(next.id);
      else if (next) await loadDetail(next.id);
      else {
        state.selectedId = ''; state.selectedType = '';
        renderEmptyDetail('조건에 맞는 학생이 없습니다.', '제출 상태와 평가 상태를 확인해 주세요.');
      }
    } catch (error) {
      if (requestId !== state.listRequest) return;
      state.items = [];
      state.selectedId = ''; state.selectedType = '';
      elements.content.hidden = true;
      elements.summary.textContent = `${context.label} · 제출 현황 확인 불가`;
      showNotice(problemMessage(error, '학생 명단과 제출 상태를 불러오지 못했습니다. 새로고침해 주세요.'), 'error');
    } finally {
      if (requestId === state.listRequest) updateBatchButton();
    }
  }

  async function loadEvaluations(appendItems = false, preferredId = '', preferredType = 'evaluation') {
    state.rosterReady = false;
    updateBatchButton();
    scorePoller.stop();
    const context = currentContext();
    if (assignmentsUnavailable || (context && (requestedWorksheetId || workflowStage === 'assessment') && !currentAssignment())) {
      ++state.listRequest; ++state.detailRequest;
      state.items = []; state.selectedId = ''; state.detail = null; state.candidateDetail = null;
      elements.content.hidden = true; elements.loadMore.hidden = true;
      elements.summary.textContent = '배포 선택 필요';
      showNotice(assignmentsUnavailable ? '배포를 불러오지 못했습니다. 다시 불러와 주세요.' : state.assignments.length ? '평가할 배포를 선택해 주세요.' : '선택한 대상의 배포가 없습니다.');
      return;
    }
    if (!context) {
      ++state.listRequest; ++state.detailRequest;
      state.items = [];
      state.nextEvaluationCursor = '';
      state.nextCandidateCursor = '';
      state.selectedId = '';
      state.selectedType = '';
      state.detail = null;
      state.candidateDetail = null;
      elements.content.hidden = true;
      elements.summary.textContent = '대상을 선택하세요.';
      showNotice('대상을 선택하세요.');
      return;
    }

    if (workflowStage === 'assessment') return loadRosterEvaluations(context, currentAssignment(), preferredId, preferredType);

    const requestId = ++state.listRequest;
    const selectedState = String(fields.state.value || '');
    const includeEvaluations = selectedState !== 'not_evaluated';
    const includeCandidates = (state.canAssess || state.canReview) && (!selectedState || selectedState === 'not_evaluated');
    const loadEvaluationsPage = includeEvaluations && (!appendItems || Boolean(state.nextEvaluationCursor));
    const loadCandidatesPage = includeCandidates && (!appendItems || Boolean(state.nextCandidateCursor));
    showNotice(appendItems ? '제출을 더 불러오는 중입니다.' : '학생 제출을 불러오는 중입니다.');

    const evaluationExtra = { limit: '40' };
    if (selectedState && selectedState !== 'not_evaluated') evaluationExtra.state = selectedState;
    if (appendItems && state.nextEvaluationCursor) evaluationExtra.cursor = state.nextEvaluationCursor;
    const candidateExtra = { limit: '40' };
    if (appendItems && state.nextCandidateCursor) candidateExtra.cursor = state.nextCandidateCursor;
    const assignment = currentAssignment();
    if (assignment) {
      window.OJAIWorkflow?.setSelection({ worksheetId: assignment.worksheet_id || requestedWorksheetId, assignmentId: assignment.id, publicationId: assignment.worksheet_publication_id, publicationHash: assignment.publication_hash, assignmentTitle: assignment.title, context });
      evaluationExtra.assignment_id = assignment.id;
      candidateExtra.assignment_id = assignment.id;
    }

    try {
      const [evaluationEnvelope, candidateEnvelope] = await Promise.all([
        loadEvaluationsPage
          ? request(`/submission-evaluations?${contextQuery(context, evaluationExtra)}`)
          : Promise.resolve(null),
        loadCandidatesPage
          ? request(`/submission-evaluation-candidates?${contextQuery(context, candidateExtra)}`)
          : Promise.resolve(null),
      ]);
      if (requestId !== state.listRequest) return;

      const evaluations = Array.isArray(evaluationEnvelope?.data?.items) ? evaluationEnvelope.data.items : [];
      const candidates = Array.isArray(candidateEnvelope?.data?.items) ? candidateEnvelope.data.items : [];
      state.items = mergeSubmissionItems(appendItems ? state.items : [], evaluations, candidates);
      if (loadEvaluationsPage) state.nextEvaluationCursor = evaluationEnvelope?.meta?.next_cursor || '';
      else if (!appendItems || !includeEvaluations) state.nextEvaluationCursor = '';
      if (loadCandidatesPage) state.nextCandidateCursor = candidateEnvelope?.meta?.next_cursor || '';
      else if (!appendItems || !includeCandidates) state.nextCandidateCursor = '';

      elements.loadMore.hidden = !(state.nextEvaluationCursor || state.nextCandidateCursor);
      elements.content.hidden = false;
      elements.summary.textContent = `${context.label} · ${state.items.length}건`;
      showNotice(state.items.length ? '' : '이 조건에 맞는 제출이 없습니다.');
      renderList();

      const preferred = preferredId
        ? state.items.find((item) => item.id === preferredId && (!preferredType || itemType(item) === preferredType))
        : null;
      const current = state.selectedId
        ? state.items.find((item) => item.id === state.selectedId && itemType(item) === state.selectedType)
        : null;
      const next = preferred || current || state.items[0] || null;
      if (next && itemType(next) === 'candidate') await loadCandidateDetail(next.id);
      else if (next) await loadDetail(next.id);
      else {
        state.selectedId = '';
        state.selectedType = '';
        state.detail = null;
        state.candidateDetail = null;
        renderEmptyDetail('평가할 제출이 없습니다.', '다른 상태나 대상을 선택해 주세요.');
      }
    } catch (error) {
      if (requestId !== state.listRequest) return;
      elements.content.hidden = true;
      showNotice(problemMessage(error, '학생 제출을 불러오지 못했습니다.'), 'error');
    }
  }
  async function loadAssignmentsForContext(context, preferredAssignmentId = '', prefetched = null) {
    const requestId = ++assignmentRequest;
    assignmentsUnavailable = false;
    state.assignments = [];
    fields.assignment.replaceChildren(option('', workflowStage === 'assessment' ? '배포 선택' : '전체 활동지'));
    fields.assignment.value = '';
    fields.assignment.disabled = true;
    if (!context) return;
    try {
      const items = prefetched ?? await window.OJAIWorkflow.allPages(request, `/assignments?${contextQuery(context, { limit: '100' })}`);
      if (requestId !== assignmentRequest) return;
      state.assignments = items.filter(item => !requestedWorksheetId || item.worksheet_id === requestedWorksheetId);
      fields.assignment.replaceChildren(option('', requestedWorksheetId || workflowStage === 'assessment' ? '배포 선택' : '전체 활동지'));
      state.assignments.forEach((assignment, index) => fields.assignment.appendChild(option(String(index), assignment.title || '활동지')));
      const requestedIndex = preferredAssignmentId
        ? state.assignments.findIndex((assignment) => assignment.id === preferredAssignmentId)
        : -1;
      if (requestedIndex >= 0) fields.assignment.value = String(requestedIndex);
      else if (!preferredAssignmentId && (requestedWorksheetId || workflowStage === 'assessment') && state.assignments.length === 1) fields.assignment.value = '0';
      else if (preferredAssignmentId) assignmentsUnavailable = true;
    } catch (_error) {
      if (requestId !== assignmentRequest) return;
      assignmentsUnavailable = _error?.status !== 403 || Boolean(requestedWorksheetId || preferredAssignmentId);
      state.assignments = [];
      fields.assignment.replaceChildren(option('', '전체 활동지'));
    } finally {
      if (requestId === assignmentRequest) fields.assignment.disabled = false;
    }
  }

  async function loadContexts(selection = {
    context: { type: requestedContextType, id: requestedContextId, revision: requestedContextRevision },
    assignmentId: requestedAssignmentId, evaluationId: requestedEvaluationId, evaluationType: 'evaluation',
  }) {
    if (contextsLoading) return;
    contextSelection = selection;
    contextsLoading = true;
    scorePoller.stop();
    ++assignmentRequest; ++state.listRequest; ++state.detailRequest;
    state.rosterReady = false;
    state.contexts = []; state.assignments = []; state.items = [];
    state.detail = null; state.candidateDetail = null;
    state.selectedId = ''; state.selectedType = '';
    elements.content.hidden = true; elements.loadMore.hidden = true;
    window.OJAIWorkflow.contextPicker(fields.context, []);
    fields.assignment.replaceChildren(option('', workflowStage === 'assessment' ? '배포 선택' : '전체 활동지'));
    const typePicker = root.querySelector('[data-context-type]');
    const refresh = root.querySelector('[data-review-action="refresh"]');
    fields.context.disabled = true; fields.assignment.disabled = true;
    if (typePicker) typePicker.disabled = true;
    if (refresh) refresh.disabled = true;
    updateBatchButton();
    showNotice('배포 대상을 불러오는 중입니다.');
    try {
      const contexts = await window.OJAIWorkflow.allPages(request, '/contexts?limit=100');
      const deployed = workflowStage === 'assessment'
        ? await loadAssignedContexts(contexts, requestedWorksheetId, request, window.OJAIWorkflow.allPages) : null;
      state.contexts = deployed ? deployed.map(item => item.context) : contexts.filter((item) => item.selectable);
      if (!state.contexts.length) {
        window.OJAIWorkflow.contextPicker(fields.context, []);
        elements.content.hidden = true;
        elements.summary.textContent = workflowStage === 'assessment' ? '배포된 대상이 없습니다.' : '등록된 대상이 없습니다.';
        showNotice(workflowStage === 'assessment'
          ? requestedWorksheetId ? '이 활동지의 배포된 대상이 없습니다.' : '배포된 대상이 없습니다.'
          : '평가할 대상이 없습니다.');
        return;
      }
      const selectedContext = selection.context;
      const requestedIndex = state.contexts.findIndex((context) => (
        context.id === selectedContext?.id
        && (!selectedContext.type || context.type === selectedContext.type)
        && (!selectedContext.revision || context.revision === selectedContext.revision)
      ));
      const initialIndex = requestedIndex >= 0 ? requestedIndex : !selectedContext?.id ? window.OJAIWorkflow.preferredContextIndex(state.contexts, Boolean(requestedWorksheetId)) : -1;
      window.OJAIWorkflow.contextPicker(fields.context, state.contexts, initialIndex);
      const prefetched = deployed?.find(item => item.context === currentContext())?.assignments;
      await loadAssignmentsForContext(currentContext(), selection.assignmentId, prefetched);
      await loadEvaluations(false, selection.evaluationId, selection.evaluationType);
      if (selectedContext?.id && requestedIndex < 0) showNotice('선택한 대상의 배포를 확인할 수 없습니다. 배포 대상을 다시 선택해 주세요.');
    } catch (error) {
      state.contexts = []; state.assignments = [];
      window.OJAIWorkflow.contextPicker(fields.context, []);
      elements.content.hidden = true;
      elements.summary.textContent = '배포 대상을 불러오지 못했습니다.';
      showNotice(problemMessage(error, '배포 대상을 불러오지 못했습니다.'), 'error');
    } finally {
      contextsLoading = false;
      fields.context.disabled = false;
      fields.assignment.disabled = !currentContext();
      if (typePicker) typePicker.disabled = false;
      if (refresh) refresh.disabled = false;
      updateBatchButton();
    }
  }

  function collectAdjustedItems() {
    const articles = [...elements.detail.querySelectorAll('[data-binding-key]')];
    if (!articles.length) throw new Error('확정할 점수 항목이 없습니다.');
    const items = articles.map((article) => {
      const input = article.querySelector('[data-review-field="official-points"]');
      if (!input?.value.trim()) throw new Error('모든 평가 항목의 루브릭을 선택하거나 점수를 입력해 주세요.');
      const points = normalizeDecimal(input?.value, article.dataset.maximumPoints);
      const teacher = itemNeedsTeacher(article);
      if (!teacher) {
        return { binding_key: article.dataset.bindingKey, source: 'ai_accepted', official_points: points, reason_code: null, reason_note: null };
      }
      const reason = article.querySelector('[data-review-field="reason-code"]')?.value || '';
      const note = article.querySelector('[data-review-field="reason-note"]')?.value.trim() || '';
      if (!reason) throw new Error('조정한 점수의 사유를 선택해 주세요.');
      if ((reason === 'other' || article.dataset.safety === '1') && !note) throw new Error('확인한 내용을 메모에 입력해 주세요.');
      return {
        binding_key: article.dataset.bindingKey,
        source: 'teacher_assigned',
        official_points: points,
        reason_code: reason,
        reason_note: note || null,
      };
    });
    return items;
  }

  function setReviewButtons(disabled) {
    elements.detail.querySelectorAll('[data-review-action="accept"], [data-review-action="adjust"], [data-review-action="reject"], [data-review-action="withdraw-score"]').forEach((item) => {
      item.disabled = disabled || (item.dataset.reviewAction === 'accept' && !canAccept(state.detail))
        || (item.dataset.reviewAction === 'accept' && [...elements.detail.querySelectorAll('[data-binding-key]')].some(itemNeedsTeacher))
        || (item.dataset.reviewAction === 'reject' && Boolean(selectedScoreRejection(state.detail)));
    });
  }

  function collectFeedback() {
    const criterionFeedback = [...elements.detail.querySelectorAll('[data-feedback-binding-key]')].map((article) => {
      const strength = article.querySelector('[data-feedback-field="strength"]')?.value.trim() || '';
      const nextStep = article.querySelector('[data-feedback-field="criterion-next-step"]')?.value.trim() || '';
      if (!strength || !nextStep) throw new Error('평가 기준마다 잘한 점과 다음에 해볼 것을 입력해 주세요.');
      if (strength.length + nextStep.length > 600) throw new Error('평가 기준별 피드백을 조금 줄여 주세요.');
      return { binding_key: article.dataset.feedbackBindingKey, strength, next_step: nextStep };
    });
    if (!criterionFeedback.length) throw new Error('피드백 기준을 불러오지 못했습니다.');
    const overall = elements.detail.querySelector('[data-feedback-field="overall"]')?.value.trim() || '';
    if (!overall) throw new Error('종합 의견을 입력해 주세요.');
    if (overall.length > 2000) throw new Error('종합 의견을 조금 줄여 주세요.');
    const nextSteps = (elements.detail.querySelector('[data-feedback-field="next-steps"]')?.value || '')
      .split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
    if (nextSteps.length > 3 || nextSteps.some((item) => item.length > 300)) {
      throw new Error('다음 단계는 3개까지 짧게 입력해 주세요.');
    }
    return { criterion_feedback: criterionFeedback, next_steps: nextSteps, overall_feedback: overall };
  }

  function collectStudentNote() {
    const checked = [...elements.detail.querySelectorAll('[data-student-note-field="evidence"]:checked')];
    if (!checked.length) throw new Error('사용할 근거를 한 개 이상 선택해 주세요.');
    const known = new Map(studentNoteEvidenceItems(state.studentNote).map((item) => [item.evidence_key, item]));
    const evidence = checked.map((input) => {
      const item = known.get(input.value);
      if (!item) throw new Error('근거 정보를 확인할 수 없습니다.');
      return { evidence_key: item.evidence_key, observation: item.observation };
    });
    const evidenceKeys = evidence.map((item) => item.evidence_key);
    const summaryText = elements.detail.querySelector('[data-student-note-field="summary"]')?.value.trim() || '';
    if (!summaryText) throw new Error('학생부 기록 초안을 입력해 주세요.');
    if (summaryText.length > 2000) throw new Error('학생부 기록 초안을 조금 줄여 주세요.');
    const strengths = (elements.detail.querySelector('[data-student-note-field="strengths"]')?.value || '')
      .split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
    if (strengths.length > 3 || strengths.some((item) => item.length > 500)) {
      throw new Error('강점은 최대 3개, 각각 500자 이내로 입력해 주세요.');
    }
    const suggestions = (elements.detail.querySelector('[data-student-note-field="support-suggestions"]')?.value || '')
      .split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
    if (suggestions.length > 3 || suggestions.some((item) => item.length > 500)) {
      throw new Error('지도 참고사항은 최대 3개, 각각 500자 이내로 입력해 주세요.');
    }
    return {
      evidence,
      strengths: strengths.map((text) => ({ evidence_keys: evidenceKeys, text })),
      summary: { evidence_keys: evidenceKeys, text: summaryText },
      support_suggestions: suggestions.map((text) => ({ evidence_keys: evidenceKeys, text })),
    };
  }

  function setFeedbackButtons(disabled) {
    elements.detail.querySelectorAll('[data-feedback-action]').forEach((item) => { item.disabled = disabled; });
  }

  function setStudentNoteButtons(disabled) {
    elements.detail.querySelectorAll('[data-student-note-action]').forEach((item) => { item.disabled = disabled; });
  }

  function setReleaseButtons(disabled) {
    elements.detail.querySelectorAll('[data-release-action]').forEach((item) => { item.disabled = disabled; });
  }

  function revokeAssessPermission() {
    state.canAssess = false;
    root.dataset.canAssess = '0';
    state.items = state.items.filter((item) => itemType(item) !== 'candidate');
    state.nextCandidateCursor = '';
    if (state.selectedType === 'candidate') {
      state.selectedId = '';
      state.selectedType = '';
      state.candidateDetail = null;
      renderEmptyDetail('평가 권한이 없습니다.', '권한을 확인한 뒤 새로고침해 주세요.');
    }
    renderList();
  }

  async function submitCandidateEvaluation(manual = false) {
    const context = currentContext();
    const submission = state.candidateDetail?.submission || (manual && state.detail?.evaluation?.submission.is_source_current ? {
      id: state.detail.evaluation.submission.id,
      official_revision_id: state.detail.evaluation.submission.source_revision_id,
      official_revision: { content_hash: state.detail.source.submission_revision.content_hash },
    } : null);
    if (state.submitting || !(manual ? state.canReview : state.canAssess) || !context || !submission?.id || !canLeaveDetail()) return;
    state.submitting = true;
    if (state.candidateDetail) renderCandidateDetail();
    showNotice(manual ? '교사 채점표를 여는 중입니다.' : 'AI 평가 작업을 만드는 중입니다.');
    try {
      const commandId = token('evaluate');
      const endpoint = manual ? 'start-manual-assessment' : 'evaluate';
      const envelope = await request(`/submissions/${encodeURIComponent(submission.id)}/commands/${endpoint}?${contextQuery(context)}`, {
        method: 'POST',
        idempotencyKey: commandId,
        body: evaluateSubmissionCommand(commandId, submission),
      });
      const evaluationId = String(envelope.data?.evaluation?.id || '');
      fields.state.value = '';
      await loadEvaluations(false, evaluationId, 'evaluation');
      showNotice(manual ? '교사 직접 채점을 시작합니다.' : 'AI 평가를 시작했습니다.', 'success');
    } catch (error) {
      const message = problemMessage(error, manual ? '교사 채점을 시작하지 못했습니다.' : 'AI 평가를 시작하지 못했습니다.');
      if (error?.status === 403) { if (manual) revokeReviewPermission(); else revokeAssessPermission(); }
      if (error?.status === 409) {
        fields.state.value = '';
        await loadEvaluations();
      }
      showNotice(message, 'error');
    } finally {
      state.submitting = false;
      updateBatchButton();
      if (state.selectedType === 'candidate' && state.candidateDetail) renderCandidateDetail();
      else if (state.detail) renderDetail();
      scorePoller.start();
    }
  }

  async function retryEvaluation() {
    const context = currentContext();
    const evaluation = state.detail?.evaluation;
    if (state.submitting || !state.canAssess || !context || !canRetryScore(state.detail) || !canLeaveDetail()) return;
    state.submitting = true;
    renderDetail();
    showNotice('AI 평가를 다시 시작하는 중입니다.');
    try {
      const commandId = token('retry');
      const refreshed = scoreInputRefreshCommand(state.detail, commandId);
      const endpoint = refreshed
        ? `/submissions/${encodeURIComponent(refreshed.submissionId)}/commands/evaluate`
        : `/submission-evaluations/${encodeURIComponent(evaluation.id)}/commands/retry`;
      const envelope = await request(`${endpoint}?${contextQuery(context)}`, {
        method: 'POST',
        idempotencyKey: commandId,
        body: refreshed?.body || retryScoreCommand(state.detail, commandId),
      });
      fields.state.value = '';
      await loadEvaluations(false, String(envelope.data?.evaluation?.id || evaluation.id), 'evaluation');
      showNotice('AI 평가를 다시 시작했습니다.', 'success');
    } catch (error) {
      const message = problemMessage(error, 'AI 평가를 다시 시작하지 못했습니다.');
      if (error?.status === 403) revokeAssessPermission();
      if (error?.status === 409) await loadDetail(evaluation.id);
      showNotice(message, 'error');
    } finally {
      state.submitting = false;
      updateBatchButton();
      if (state.selectedType === 'evaluation' && state.detail) renderDetail();
      scorePoller.start();
    }
  }

  function revokeReviewPermission() {
    state.canReview = false;
    root.dataset.canReview = '0';
    closeRejectDialog();
    closeFeedbackRejectDialog();
    closeReleaseDialog();
    if (state.detail) renderDetail();
  }

  function revokeStudentNotePermission() {
    state.canManageStudentNotes = false;
    root.dataset.canManageStudentNotes = '0';
    closeStudentNoteRejectDialog();
    if (state.detail) renderDetail();
  }

  async function submitReview(decision, items = [], reason = null) {
    const context = currentContext();
    const detail = state.detail;
    if (state.submitting || !state.canReview || !context || !detail?.review_precondition) return;
    if (foreignApproval(detail)) return;
    if (decision === 'accepted' && (!canAccept(detail) || [...elements.detail.querySelectorAll('[data-binding-key]')].some(itemNeedsTeacher))) return;
    if ((decision === 'withdrawn') !== scoreApproved(detail)) return;
    if (decision === 'rejected' && selectedScoreRejection(detail)) return;
    state.submitting = true;
    setReviewButtons(true);
    showNotice('검토를 저장하는 중입니다.');
    try {
      const commandId = token('review');
      await request(`/submission-evaluations/${encodeURIComponent(detail.evaluation.id)}/commands/review?${contextQuery(context)}`, {
        method: 'POST',
        idempotencyKey: commandId,
        body: reviewCommand(commandId, detail.review_precondition, decision, items, reason),
      });
      if (decision !== 'rejected') fields.state.value = decision === 'withdrawn' ? 'review_required' : 'reviewed';
      await loadEvaluations(false, detail.evaluation.id);
      showNotice(decision === 'withdrawn' ? '점수 승인을 회수했습니다. 검토 대기 상태입니다.' : decision === 'rejected' ? '평가를 반려했습니다.' : '점수를 확정했습니다.', 'success');
    } catch (error) {
      const message = problemMessage(error, '검토를 저장하지 못했습니다.');
      if (error?.status === 403) {
        revokeReviewPermission();
      }
      if (error?.status === 409) {
        await loadEvaluations(false, detail.evaluation.id);
        showNotice(message, 'error');
      } else {
        showNotice(message, 'error');
        if (state.detail) renderDetail();
      }
    } finally {
      state.submitting = false;
      updateBatchButton();
      setReviewButtons(false);
      if (state.detail) renderDetail();
      scorePoller.start();
    }
  }

  function openRejectDialog(withdraw = false) {
    if (state.submitting || !state.canReview || (withdraw !== scoreApproved(state.detail))
      || (!withdraw && selectedScoreRejection(state.detail))) return;
    elements.rejectDialog.dataset.decision = withdraw ? 'withdrawn' : 'rejected';
    elements.rejectDialog.querySelector('h2').textContent = withdraw ? '점수 승인 회수' : '평가 반려';
    elements.rejectDialog.querySelector('label > span').textContent = withdraw ? '회수 사유' : '반려 사유';
    elements.rejectDialog.querySelector('[data-review-action="confirm-reject"]').textContent = withdraw ? '승인 회수' : '반려';
    fields.rejectReason.placeholder = withdraw ? '점수 승인을 회수하는 이유를 입력하세요.' : '다시 평가해야 하는 이유를 입력하세요.';
    fields.rejectReason.value = '';
    elements.rejectStatus.textContent = withdraw ? '확정 점수를 해제하고 검토 대기로 돌아갑니다. 공개된 결과는 재공개 전까지 숨겨집니다.' : '';
    if (typeof elements.rejectDialog.showModal === 'function') elements.rejectDialog.showModal();
    else elements.rejectDialog.setAttribute('open', '');
    fields.rejectReason.focus();
  }

  function closeRejectDialog() {
    if (typeof elements.rejectDialog.close === 'function') elements.rejectDialog.close();
    else elements.rejectDialog.removeAttribute('open');
  }

  function openFeedbackRejectDialog() {
    if (!fields.feedbackRejectReason || !elements.feedbackRejectDialog) return;
    fields.feedbackRejectReason.value = '';
    elements.feedbackRejectStatus.textContent = '';
    if (typeof elements.feedbackRejectDialog.showModal === 'function') elements.feedbackRejectDialog.showModal();
    else elements.feedbackRejectDialog.setAttribute('open', '');
    fields.feedbackRejectReason.focus();
  }

  function closeFeedbackRejectDialog() {
    if (!elements.feedbackRejectDialog) return;
    if (typeof elements.feedbackRejectDialog.close === 'function') elements.feedbackRejectDialog.close();
    else elements.feedbackRejectDialog.removeAttribute('open');
  }

  function openStudentNoteRejectDialog() {
    if (!fields.studentNoteRejectReason || !elements.studentNoteRejectDialog) return;
    fields.studentNoteRejectReason.value = '';
    elements.studentNoteRejectStatus.textContent = '';
    if (typeof elements.studentNoteRejectDialog.showModal === 'function') elements.studentNoteRejectDialog.showModal();
    else elements.studentNoteRejectDialog.setAttribute('open', '');
    fields.studentNoteRejectReason.focus();
  }

  function closeStudentNoteRejectDialog() {
    if (!elements.studentNoteRejectDialog) return;
    if (typeof elements.studentNoteRejectDialog.close === 'function') elements.studentNoteRejectDialog.close();
    else elements.studentNoteRejectDialog.removeAttribute('open');
  }

  function openReleaseDialog(withdraw = false) {
    if (root.dataset.canPublish !== '1') return;
    const detail = state.detail;
    if (!elements.releaseDialog || !detail?.evaluation) return;
    const mode = detail.evaluation.release?.current_id ? 'correction' : 'release';
    if (withdraw && !['released', 'corrected'].includes(detail.evaluation.release?.state)) return;
    elements.releaseDialog.dataset.mode = withdraw ? 'withdraw' : mode;
    const feedbackApproved = latestFeedback(detail)?.state === 'approved';
    if (elements.releaseTitle) elements.releaseTitle.textContent = mode === 'correction' ? '정정 공개' : '결과 공개';
    const confirmButton = elements.releaseDialog.querySelector('[data-release-action="confirm"]');
    if (confirmButton) confirmButton.textContent = mode === 'correction' ? '정정 공개' : '공개';
    if (withdraw) {
      if (elements.releaseTitle) elements.releaseTitle.textContent = '학생 공개 회수';
      if (confirmButton) confirmButton.textContent = '공개 회수';
    }
    [fields.releaseScoreVisibility, fields.releaseFeedbackVisibility].forEach((field) => {
      if (field) field.disabled = withdraw;
    });
    if (fields.releaseScoreVisibility) fields.releaseScoreVisibility.value = 'total_and_criteria';
    if (fields.releaseFeedbackVisibility) {
      const shownOption = fields.releaseFeedbackVisibility.querySelector('option[value="shown"]');
      if (shownOption) shownOption.disabled = !feedbackApproved;
      fields.releaseFeedbackVisibility.value = feedbackApproved ? 'shown' : 'hidden';
    }
    if (fields.releaseReasonCode) fields.releaseReasonCode.value = '';
    if (fields.releaseReasonNote) fields.releaseReasonNote.value = '';
    if (elements.releaseReasonSection) elements.releaseReasonSection.hidden = !withdraw && mode !== 'correction';
    if (elements.releaseStatus) elements.releaseStatus.textContent = '';
    if (typeof elements.releaseDialog.showModal === 'function') elements.releaseDialog.showModal();
    else elements.releaseDialog.setAttribute('open', '');
    (withdraw ? fields.releaseReasonCode : fields.releaseScoreVisibility)?.focus();
  }

  function closeReleaseDialog() {
    if (!elements.releaseDialog) return;
    if (typeof elements.releaseDialog.close === 'function') elements.releaseDialog.close();
    else elements.releaseDialog.removeAttribute('open');
  }

  async function submitRelease(scoreVisibility, feedbackVisibility, reasonCode, reasonNote, withdraw = false) {
    const context = currentContext();
    const detail = state.detail;
    if (state.submitting || root.dataset.canPublish !== '1' || !context || !officialReview(detail)) return;
    const commandId = token('release');
    let body;
    try {
      body = withdraw ? withdrawResultCommand(commandId, detail, reasonCode, reasonNote)
        : releaseResultCommand(commandId, detail, scoreVisibility, feedbackVisibility, reasonCode, reasonNote);
    } catch (error) {
      showNotice(error.message, 'error');
      return;
    }
    state.submitting = true;
    setReviewButtons(true);
    setFeedbackButtons(true);
    setStudentNoteButtons(true);
    setReleaseButtons(true);
    showNotice('결과 공개를 저장하는 중입니다.');
    try {
      await request(`/submission-evaluations/${encodeURIComponent(detail.evaluation.id)}/commands/${withdraw ? 'withdraw-release' : 'release'}?${contextQuery(context)}`, {
        method: 'POST',
        idempotencyKey: commandId,
        body,
      });
      await loadDetail(detail.evaluation.id);
      showNotice(withdraw ? '학생 공개를 회수했습니다.' : body.payload.mode === 'correction' ? '결과를 정정 공개했습니다.' : '결과를 공개했습니다.', 'success');
    } catch (error) {
      const message = releaseProblemMessage(error, '결과를 공개하지 못했습니다.');
      if (error?.status === 403) root.dataset.canPublish = '0';
      if (error?.status === 409) {
        await loadDetail(detail.evaluation.id);
        showNotice(message, 'error');
      } else {
        showNotice(message, 'error');
        if (state.detail) renderDetail();
      }
    } finally {
      state.submitting = false;
      updateBatchButton();
      setReviewButtons(false);
      setFeedbackButtons(false);
      setStudentNoteButtons(false);
      setReleaseButtons(false);
    }
  }

  async function submitFeedback(action, feedback = null, reason = null) {
    const context = currentContext();
    const detail = state.detail;
    if (state.submitting || !state.canReview || !context || !officialReview(detail)) return;
    const latest = latestFeedback(detail);
    const commandId = token(`feedback-${action}`);
    let path = '';
    let body = null;
    let success = '';
    if (action === 'generate') {
      path = 'generate-feedback';
      body = generateFeedbackCommand(commandId, detail.review_precondition);
      success = 'AI 초안을 요청했습니다.';
    } else if (action === 'save') {
      const sourceMode = ['ai_draft', 'teacher_draft'].includes(latest?.state) ? 'edit' : 'direct';
      path = 'review-feedback';
      body = reviewFeedbackCommand(commandId, detail.review_precondition, latest, 'teacher_draft', sourceMode, feedback, null);
      success = '피드백 초안을 저장했습니다.';
    } else if (action === 'reject') {
      path = 'review-feedback';
      body = reviewFeedbackCommand(commandId, detail.review_precondition, latest, 'rejected', 'edit', null, reason);
      success = '피드백을 기각했습니다.';
    } else if (action === 'approve') {
      path = 'approve-feedback';
      body = approveFeedbackCommand(commandId, detail.review_precondition, latest);
      success = '새 피드백을 승인했습니다. 학생 공개는 공개 화면에서 확인하세요.';
    } else if (action === 'withdraw-approval') {
      path = 'withdraw-feedback-approval';
      body = withdrawFeedbackApprovalCommand(commandId, detail.review_precondition, latest);
      success = '승인을 회수했습니다. 다시 수정할 수 있습니다.';
    } else {
      return;
    }

    state.submitting = true;
    setReviewButtons(true);
    setFeedbackButtons(true);
    showNotice('피드백을 저장하는 중입니다.');
    try {
      await request(`/submission-evaluations/${encodeURIComponent(detail.evaluation.id)}/commands/${path}?${contextQuery(context)}`, {
        method: 'POST',
        idempotencyKey: commandId,
        body,
      });
      await loadDetail(detail.evaluation.id);
      showNotice(success, 'success');
      if (action === 'generate') pollDraftGeneration(detail.evaluation.id, context, 'feedback_draft');
    } catch (error) {
      const message = feedbackProblemMessage(error, '피드백을 저장하지 못했습니다.');
      if (error?.status === 403) revokeReviewPermission();
      if (error?.status === 409) {
        await loadDetail(detail.evaluation.id);
        showNotice(message, 'error');
      } else {
        showNotice(message, 'error');
        if (state.detail) renderDetail();
      }
    } finally {
      state.submitting = false;
      updateBatchButton();
      setReviewButtons(false);
      if ('feedback_draft' !== state.pollingOperation) setFeedbackButtons(false);
    }
  }

  async function submitStudentNote(action, note = null, reason = null) {
    const context = currentContext();
    const detail = state.detail;
    if (state.submitting || !state.canManageStudentNotes || !context || !officialReview(detail) || !detail.review_precondition) return;
    const officialRef = {
      id: detail.review_precondition.expected_official_review_id,
      hash: detail.review_precondition.expected_official_review_hash,
    };
    const noteDetail = state.studentNote;
    const precondition = noteDetail ? noteDetail.precondition : null;
    const commandId = token(`student-note-${action}`);
    let path = '';
    let body = null;
    let success = '';
    if (action === 'generate') {
      path = `submission-evaluations/${encodeURIComponent(detail.evaluation.id)}/commands/generate-student-note`;
      body = generateStudentNoteCommand(commandId, officialRef);
      success = 'AI 특기사항 초안을 요청했습니다.';
    } else if (action === 'save') {
      if (!noteDetail) return;
      path = `student-notes/${encodeURIComponent(noteDetail.note.id)}/commands/review`;
      body = reviewStudentNoteCommand(commandId, officialRef, precondition, 'teacher_draft', 'edit', note, null);
      success = '특기사항 초안을 저장했습니다.';
    } else if (action === 'reject') {
      if (!noteDetail) return;
      path = `student-notes/${encodeURIComponent(noteDetail.note.id)}/commands/review`;
      body = reviewStudentNoteCommand(commandId, officialRef, precondition, 'rejected', 'edit', null, reason);
      success = '특기사항을 기각했습니다.';
    } else if (action === 'approve') {
      if (!noteDetail) return;
      if (hasDetailChanges()) {
        showNotice('변경한 내용을 먼저 저장한 뒤 승인하세요.', 'error');
        return;
      }
      if (!(await (window.OJP && typeof window.OJP.confirm === 'function' ? window.OJP.confirm({title:'특기사항을 승인할까요?',message:'실제 관찰 근거, 수행 과정, 명사형 문체와 기재 제한 사항을 검토했는지 확인하세요. 이 승인은 학교생활기록부 최종 입력을 대신하지 않습니다.',confirmLabel:'승인'}) : Promise.resolve(window.confirm('실제 관찰 근거, 수행 과정, 명사형 문체와 기재 제한 사항을 검토·수정했나요? 이 승인은 학교생활기록부 최종 입력을 대신하지 않습니다.'))))) return;
      path = `student-notes/${encodeURIComponent(noteDetail.note.id)}/commands/approve`;
      body = approveStudentNoteCommand(commandId, officialRef, precondition);
      success = '특기사항을 승인했습니다.';
    } else if (action === 'withdraw-approval') {
      if (!noteDetail) return;
      path = `student-notes/${encodeURIComponent(noteDetail.note.id)}/commands/withdraw-approval`;
      body = withdrawStudentNoteApprovalCommand(commandId, officialRef, precondition);
      success = '승인을 회수했습니다. 다시 수정할 수 있습니다.';
    } else if (action === 'include' || action === 'exclude') {
      if (!noteDetail) return;
      const target = action === 'include';
      path = `student-notes/${encodeURIComponent(noteDetail.note.id)}/commands/${target ? 'include-in-reports' : 'exclude-from-reports'}`;
      body = changeStudentNoteReportInclusionCommand(commandId, officialRef, precondition, target);
      success = target ? '보고서에 포함했습니다.' : '보고서에서 제외했습니다.';
    } else {
      return;
    }

    state.submitting = true;
    setReviewButtons(true);
    setFeedbackButtons(true);
    setStudentNoteButtons(true);
    showNotice('특기사항을 저장하는 중입니다.');
    try {
      await request(`/${path}?${contextQuery(context)}`, {
        method: 'POST',
        idempotencyKey: commandId,
        body,
      });
      if (action === 'generate') {
        showNotice(success, 'success');
        pollDraftGeneration(detail.evaluation.id, context, 'special_note_draft');
      } else {
        await refreshStudentNote();
        showNotice(success, 'success');
      }
    } catch (error) {
      const message = studentNoteProblemMessage(error, '특기사항을 저장하지 못했습니다.');
      if (error?.status === 403) revokeStudentNotePermission();
      if (error?.status === 409) {
        await refreshStudentNote();
        showNotice(message, 'error');
      } else {
        showNotice(message, 'error');
        if (state.detail) renderDetail();
      }
    } finally {
      state.submitting = false;
      updateBatchButton();
      setReviewButtons(false);
      setFeedbackButtons(false);
      if ('special_note_draft' !== state.pollingOperation) setStudentNoteButtons(false);
    }
  }

  root.addEventListener('click', (event) => {
    const workflowAction = event.target.closest('[data-workflow-bulk]');
    if (workflowAction) { if (!workflowAction.disabled) openWorkflowBulk(workflowAction.dataset.workflowBulk); return; }
    const workflowControl = event.target.closest('[data-workflow-control]');
    if (workflowControl) {
      const action = workflowControl.dataset.workflowControl;
      if (action === 'confirm') startWorkflowJob();
      if (action === 'resume' && state.workflowJob) runWorkflowJob(state.workflowJob);
      if (action === 'stop' && state.workflowJob) {
        state.workflowJob.controller.stop();
        workflowControl.disabled = true;
        workflowNode('progress-status').textContent += ' · 현재 요청 완료 후 중지';
      }
      if (action === 'dismiss' && !state.workflowJob?.controller.snapshot().running) {
        state.workflowJob = null;
        elements.workflowProgress.hidden = true;
        updateBatchButton();
      }
      return;
    }
    const feedbackTarget = event.target.closest('[data-feedback-action]');
    if (feedbackTarget && root.contains(feedbackTarget)) {
      const feedbackAction = feedbackTarget.dataset.feedbackAction;
      if (feedbackAction === 'generate') submitFeedback('generate');
      if (feedbackAction === 'save') {
        try { submitFeedback('save', collectFeedback()); }
        catch (error) { showNotice(error.message, 'error'); }
      }
      if (feedbackAction === 'approve') submitFeedback('approve');
      if (feedbackAction === 'withdraw-approval') submitFeedback('withdraw-approval');
      if (feedbackAction === 'reject') openFeedbackRejectDialog();
      if (feedbackAction === 'confirm-reject') {
        const reason = fields.feedbackRejectReason?.value.trim() || '';
        if (!reason) {
          elements.feedbackRejectStatus.textContent = '기각 사유를 입력해 주세요.';
          return;
        }
        closeFeedbackRejectDialog();
        submitFeedback('reject', null, reason);
      }
      return;
    }
    const studentNoteTarget = event.target.closest('[data-student-note-action]');
    if (studentNoteTarget && root.contains(studentNoteTarget)) {
      const studentNoteAction = studentNoteTarget.dataset.studentNoteAction;
      if (studentNoteAction === 'generate') submitStudentNote('generate');
      if (studentNoteAction === 'save') {
        try { submitStudentNote('save', collectStudentNote()); }
        catch (error) { showNotice(error.message, 'error'); }
      }
      if (studentNoteAction === 'approve') submitStudentNote('approve');
      if (studentNoteAction === 'withdraw-approval') submitStudentNote('withdraw-approval');
      if (studentNoteAction === 'include') submitStudentNote('include');
      if (studentNoteAction === 'exclude') submitStudentNote('exclude');
      if (studentNoteAction === 'reject') openStudentNoteRejectDialog();
      if (studentNoteAction === 'confirm-reject') {
        const reason = fields.studentNoteRejectReason?.value.trim() || '';
        if (!reason) {
          elements.studentNoteRejectStatus.textContent = '기각 사유를 입력해 주세요.';
          return;
        }
        closeStudentNoteRejectDialog();
        submitStudentNote('reject', null, reason);
      }
      return;
    }
    const releaseTarget = event.target.closest('[data-release-action]');
    if (releaseTarget && root.contains(releaseTarget)) {
      const releaseAction = releaseTarget.dataset.releaseAction;
      if (releaseAction === 'open') openReleaseDialog();
      if (releaseAction === 'withdraw') openReleaseDialog(true);
      if (releaseAction === 'confirm') {
        const scoreVisibility = fields.releaseScoreVisibility?.value || '';
        const feedbackVisibility = fields.releaseFeedbackVisibility?.value || '';
        const reasonCode = fields.releaseReasonCode?.value || '';
        const reasonNote = fields.releaseReasonNote?.value || '';
        const mode = state.detail?.evaluation?.release?.current_id ? 'correction' : 'release';
        const withdraw = elements.releaseDialog?.dataset.mode === 'withdraw';
        if (!withdraw && scoreVisibility === 'hidden' && feedbackVisibility === 'hidden') {
          if (elements.releaseStatus) elements.releaseStatus.textContent = '점수와 피드백을 모두 비공개로 할 수 없습니다.';
          return;
        }
        if (mode === 'correction' && !reasonCode) {
          if (elements.releaseStatus) elements.releaseStatus.textContent = '정정 사유를 선택해 주세요.';
          return;
        }
        if (mode === 'correction' && reasonCode === 'other' && !reasonNote.trim()) {
          if (elements.releaseStatus) elements.releaseStatus.textContent = '정정 사유를 자세히 입력해 주세요.';
          return;
        }
        closeReleaseDialog();
        submitRelease(scoreVisibility, feedbackVisibility, mode === 'correction' ? reasonCode : null, mode === 'correction' ? (reasonNote.trim() || null) : null, withdraw);
      }
      return;
    }
    const target = event.target.closest('[data-review-action]');
    if (!target || !root.contains(target)) return;
    const action = target.dataset.reviewAction;
    if (action === 'download-scores') openScoreDownload();
    if (action === 'confirm-score-download') downloadScores();
    if (action === 'bulk-grade') openBatchDialog();
    if (action === 'bulk-confirm') startBatch();
    if (action === 'bulk-resume' && state.batch) runBatch(state.batch);
    if (action === 'bulk-stop' && state.batch) {
      state.batch.controller.stop();
      elements.batchStop.disabled = true;
      elements.batchStatus.textContent += ' · 현재 등록 요청 완료 후 중지';
    }
    if (action === 'bulk-dismiss' && !state.batch?.controller.snapshot().running) resetBatch();
    if (action === 'refresh') {
      if (workflowStage === 'assessment' && !state.batch?.controller.snapshot().running) loadContexts({
        context: currentContext() || contextSelection?.context,
        assignmentId: currentContext() ? currentAssignment()?.id || '' : contextSelection?.assignmentId || '',
        evaluationId: state.selectedId, evaluationType: state.selectedType || 'evaluation',
      });
      else loadEvaluations(false, state.selectedId, state.selectedType || 'evaluation');
    }
    if (action === 'load-more') loadEvaluations(true);
    if (action === 'select' && target.dataset.itemType === 'candidate') loadCandidateDetail(target.dataset.itemId);
    if (action === 'select' && target.dataset.itemType === 'roster') {
      const item = state.items.find(row => row.id === target.dataset.itemId && row.kind === 'roster');
      if (item) selectRosterItem(item);
    }
    if (action === 'select' && !['candidate', 'roster'].includes(target.dataset.itemType)) loadDetail(target.dataset.itemId);
    if (action === 'detail-refresh' && state.selectedType === 'candidate') loadCandidateDetail(state.selectedId);
    if (action === 'detail-refresh' && state.selectedType === 'evaluation') loadDetail(state.selectedId);
    if (action === 'evaluate-submission') submitCandidateEvaluation();
    if (action === 'manual-assessment') submitCandidateEvaluation(true);
    if (action === 'retry-evaluation') retryEvaluation();
    if (action === 'accept') submitReview('accepted');
    if (action === 'adjust') {
      try {
        const items = collectAdjustedItems();
        const adjusted = items.some(item => item.source === 'teacher_assigned');
        submitReview(adjusted ? 'adjusted' : 'accepted', adjusted ? items : []);
      }
      catch (error) { showNotice(error.message, 'error'); }
    }
    if (action === 'reject') openRejectDialog();
    if (action === 'withdraw-score') openRejectDialog(true);
    if (action === 'confirm-reject') {
      const decision = elements.rejectDialog.dataset.decision || 'rejected';
      const reason = fields.rejectReason.value.trim();
      if (!reason) {
        elements.rejectStatus.textContent = decision === 'withdrawn' ? '회수 사유를 입력해 주세요.' : '반려 사유를 입력해 주세요.';
        return;
      }
      closeRejectDialog();
      submitReview(decision, [], reason);
    }
  });

  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-review-field="rubric-level"]')) {
      const article = event.target.closest('[data-binding-key]');
      const points = article?.querySelector('[data-review-field="official-points"]');
      if (points && event.target.checked) { points.value = event.target.value; updateCriterion(article); }
      return;
    }
    if (elements.workflowDialog?.contains(event.target)) {
      if (event.target === workflowNode('select-all')) workflowNode('targets').querySelectorAll('input').forEach(box => { box.checked = event.target.checked; });
      updateWorkflowSelection();
      return;
    }
    if (event.target === fields.context) {
      resetBatch();
      scorePoller.stop();
      const context = currentContext();
      contextSelection = { context, assignmentId: '' };
      ++state.listRequest; ++state.detailRequest;
      state.items = []; state.detail = null; state.candidateDetail = null;
      elements.content.hidden = true;
      renderEmptyDetail('대상 변경', '배포 대상을 선택해 주세요.');
      window.OJAIWorkflow.setSelection({context,assignmentId:'',assignmentTitle:'',publicationId:'',publicationHash:''});
      loadAssignmentsForContext(context).then(() => { if (currentContext() === context) loadEvaluations(); });
    } else if (event.target === fields.assignment || event.target === fields.state || event.target === fields.submissionStatus) {
      if (event.target === fields.assignment) resetBatch();
      if (event.target === fields.assignment && currentAssignment()) assignmentsUnavailable = false;
      loadEvaluations();
    }
    if (event.target.matches('[data-review-field="reason-code"]')) {
      const article = event.target.closest('[data-binding-key]');
      if (article) updateCriterion(article);
    }
  });

  root.addEventListener('input', (event) => {
    const writingEditor = event.target.closest('[data-report-writing]');
    if (writingEditor) updateWritingCheck(writingEditor);
    if (event.target === fields.search) renderList();
    if (event.target.matches('[data-review-field="official-points"]')) {
      const article = event.target.closest('[data-binding-key]');
      if (article) updateCriterion(article);
    }
  });

  if (workflowStage !== 'assessment') {
    const heading = root.querySelector('.oj-ai-submission-review__header h1');
    if (heading) heading.textContent = workflowStage === 'report' ? '보고서 작성' : '학생 공개';
    fields.state.value = 'reviewed';
  }
  const scorePoller = createScoreStatusPoller({
    schedule: (callback, delay) => window.setTimeout(callback, delay),
    cancel: timer => window.clearTimeout(timer),
    canPoll: () => workflowStage === 'assessment'
      && document.visibilityState !== 'hidden' && !root.closest('[hidden]')
      && !state.submitting && !hasDetailChanges() && !root.querySelector('dialog[open]')
      && state.selectedType === 'evaluation' && state.selectedId === state.detail?.evaluation?.id
      && scorePending(state.detail),
    read: async () => {
      const envelope = await request(`/submission-evaluations/${encodeURIComponent(state.selectedId)}?${contextQuery(currentContext())}`);
      return envelope.data;
    },
    apply: detail => {
      if (detail?.evaluation?.id !== state.selectedId) throw new Error('평가 상태 응답을 확인할 수 없습니다.');
      state.detail = detail;
      state.items = state.items.map(item => item.kind === 'evaluation' && item.id === detail.evaluation.id
        ? { ...item, ...detail.evaluation } : item);
      renderList();
      renderDetail();
    },
    onError: error => showNotice(problemMessage(error, '평가 상태를 확인하지 못했습니다. 연결 후 다시 확인합니다.'), 'error'),
  });
  document.addEventListener('visibilitychange', () => scorePoller.start());
  document.addEventListener('ojai:assessment-tab-change', () => scorePoller.start());
  window.addEventListener('pageshow', () => scorePoller.start());
  window.addEventListener('pagehide', () => scorePoller.stop());
  window.addEventListener('pagehide', () => state.batch?.controller.stop());
  window.addEventListener('pagehide', () => state.workflowJob?.controller.stop());
  function resetScoreDownload() {
    state.scoreDownloadRequest += 1;
    state.scoreDownloading = false;
    const dialog = root.querySelector('[data-review-role="score-download-dialog"]');
    if (dialog) {
      dialog.querySelector('[data-review-action="confirm-score-download"]').disabled = false;
      dialog.querySelector('[data-review-field="score-download-mode"]').disabled = false;
    }
    updateBatchButton();
  }
  window.addEventListener('pagehide', () => {
    resetScoreDownload();
    root.querySelector('[data-review-role="score-download-dialog"][open]')?.close();
  });
  root.querySelector('[data-review-role="score-download-dialog"]')?.addEventListener('close', resetScoreDownload);
  elements.workflowDialog?.addEventListener('close', () => {
    state.workflowRequest += 1;
    state.workflowPreparing = false;
    state.workflowPlan = null;
    updateBatchButton();
  });
  updateBatchButton();
  loadContexts();
})(window, document);

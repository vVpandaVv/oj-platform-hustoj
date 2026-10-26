import { access, readFile } from 'node:fs/promises';
import path from 'node:path';
import { Script } from 'node:vm';

const voidElements = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta',
  'param', 'source', 'track', 'wbr',
]);

function checkBalancedTags(content, file, errors) {
  const stack = [];
  const source = content.replace(/<!--[\s\S]*?-->/g, '');
  const tagPattern = /<\/?([a-z][a-z0-9-]*)\b[^>]*>/gi;

  for (const match of source.matchAll(tagPattern)) {
    const token = match[0];
    const name = match[1].toLowerCase();
    if (voidElements.has(name) || token.endsWith('/>')) continue;
    if (!token.startsWith('</')) {
      stack.push(name);
      continue;
    }
    const opened = stack.pop();
    if (opened !== name) {
      errors.push(`${file}: closing </${name}> does not match <${opened ?? 'none'}>`);
      return;
    }
  }

  if (stack.length > 0) errors.push(`${file}: unclosed tags: ${stack.join(', ')}`);
}

async function readUtf8(file, errors) {
  const bytes = await readFile(file);
  if (bytes.length >= 3 && bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
    errors.push(`${path.basename(file)}: UTF-8 BOM is not allowed`);
  }
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch (error) {
    errors.push(`${path.basename(file)}: invalid UTF-8 (${error.message})`);
    return bytes.toString('utf8');
  }
}

function countButtons(block) {
  return (block.match(/<button\b/gi) ?? []).length;
}

export async function validateUiPrototype(docsRoot) {
  const errors = [];
  const htmlPath = path.join(docsRoot, 'a-ds-01-ui-prototype.html');
  const cssPath = path.join(docsRoot, 'ui-prototype.css');
  const jsPath = path.join(docsRoot, 'ui-prototype.js');
  const [html, css, script] = await Promise.all([
    readUtf8(htmlPath, errors),
    readUtf8(cssPath, errors),
    readUtf8(jsPath, errors),
  ]);

  if (!/^<!doctype html>/i.test(html)) errors.push('a-ds-01-ui-prototype.html: missing HTML doctype');
  if (!/<html\b[^>]*\blang=['"]ko['"]/i.test(html)) errors.push('a-ds-01-ui-prototype.html: missing lang="ko"');
  if (!/<meta\b[^>]*\bcharset=['"]?utf-8['"]?/i.test(html)) errors.push('a-ds-01-ui-prototype.html: missing UTF-8 charset');
  if (!/href=['"]ui-prototype\.css['"]/i.test(html)) errors.push('a-ds-01-ui-prototype.html: missing prototype CSS');
  if (!/src=['"]ui-prototype\.js['"]/i.test(html)) errors.push('a-ds-01-ui-prototype.html: missing prototype JavaScript');
  checkBalancedTags(html, 'a-ds-01-ui-prototype.html', errors);

  const requiredFiles = [cssPath, jsPath, path.join(docsRoot, 'a-ds-01-ui-system.html')];
  for (const file of requiredFiles) {
    await access(file).catch(() => errors.push(`a-ds-01-ui-prototype.html: missing local target ${path.basename(file)}`));
  }

  const navContracts = [
    ['teacher work navigation', /<nav class="work-nav"[\s\S]*?<\/nav>/i, 5],
    ['worksheet phase navigation', /<nav class="phase-nav"[\s\S]*?<\/nav>/i, 4],
    ['student state navigation', /<nav class="student-tabs"[\s\S]*?<\/nav>/i, 3],
    ['distribution dialog progress', /<nav class="dialog-progress"[\s\S]*?<\/nav>/i, 4],
    ['settings navigation', /<nav class="settings-nav"[\s\S]*?<\/nav>/i, 5],
  ];
  for (const [label, pattern, expected] of navContracts) {
    const block = html.match(pattern)?.[0] ?? '';
    const actual = countButtons(block);
    if (actual !== expected) errors.push(`a-ds-01-ui-prototype.html: ${label} expected ${expected} buttons, found ${actual}`);
  }

  for (const marker of [
    '>활동지</button>',
    '>배포·제출</button>',
    '>평가</button>',
    '>보고서</button>',
    '>평가 설정</button>',
    '>만들기</button>',
    '>배포</button>',
    '>공개</button>',
    'data-student-tab="todo">할 일',
    'data-student-tab="submitted">제출함',
    'data-student-tab="results">결과',
    'data-run-check',
    'data-block-style',
    'data-menu-trigger="insert-menu"',
    'data-menu-trigger="response-menu"',
    'id="response-kind-select"',
    'id="response-preview"',
    'data-add-prototype-question',
    'data-prototype-question',
    'data-prototype-response',
    'data-add-prototype-response',
    'id="response-label-input"',
    'id="response-required-toggle"',
    'data-delete-prototype-response',
    '<strong>응답 설정</strong>',
    'data-open-submit',
    'data-confirm-submit',
    'data-open-distribution',
    'data-open-submission-import',
    'id="submission-import-dialog"',
    'data-confirm-submission-import',
    'id="submission-import-status"',
    'id="distribution-dialog"',
    'data-distribution-step="3"',
    'data-open-review',
    'id="review-dialog"',
    'data-confirm-review',
    'review-total-note',
    '>결과 확정</button>',
    'id="feedback-dialog"',
    'data-generate-feedback',
    'data-approve-feedback',
    'id="release-dialog"',
    'data-open-release',
    'data-confirm-release',
    '>학생에게 공개</button>',
    'result-notice',
    'AI 제안',
    '직접 확인 1',
    'data-report-lens="worksheet"',
    'data-report-lens="group"',
    'data-report-lens="student"',
    'id="report-baseline-time"',
    'data-refresh-report',
    'data-report-drilldown',
    'id="report-drilldown"',
    'data-open-report-export',
    'id="report-export-dialog"',
    'data-export-format="pdf"',
    'data-export-format="csv"',
    'data-export-format="json"',
    'data-export-format="zip"',
    'data-start-report-export',
    'data-download-report-export',
    'data-open-student-note',
    'id="student-note-dialog"',
    'data-generate-student-note',
    'data-approve-student-note',
    'id="worksheet-source-chart"',
    'id="ai-check-result"',
    'data-check-filter="all"',
    'data-check-filter="edit"',
    'data-check-filter="review"',
    'data-evidence-target="worksheet-source-chart"',
    'data-finding-decision="resolve"',
    'data-finding-decision="defer"',
    'data-finding-decision="dismiss"',
    'data-return-to-check',
    'data-download-worksheet',
    'data-download-submission',
    'id="student-conflict-alert"',
    'data-conflict-choice="mine"',
    'data-question-target="student-question-2"',
    'id="student-attachment-status"',
    'data-select-attachment',
    'data-remove-attachment',
    '>작성 결과물</button>',
    '접수 2',
    'data-settings-tab="connection" aria-selected="false">AI 연결</button>',
    'data-settings-tab="system" aria-selected="false">시스템 상태</button>',
    'data-settings-tab="system"',
    'data-settings-panel="system"',
    'data-refresh-system',
    'id="system-summary-status"',
    'id="system-checked-time"',
    '<strong>애드온 실행</strong>',
    '<strong>데이터 구조</strong>',
    '<strong>작업 처리</strong>',
    '<strong>파일 보관</strong>',
    '<strong>AI 작업 배정</strong>',
    'data-open-legacy-migration',
    'id="legacy-migration-dialog"',
    'data-migration-ack',
    'data-confirm-legacy-migration',
    'data-open-purge',
    'id="purge-dialog"',
    'data-purge-backup',
    'data-purge-phrase',
    'data-confirm-purge',
    '>영구 삭제</strong>',
  ]) {
    if (!html.includes(marker)) errors.push(`a-ds-01-ui-prototype.html: missing UI marker ${marker}`);
  }
  const systemStatusSection = html.slice(html.indexOf('id="system-status-list"'), html.indexOf('<section class="system-action-row"'));
  const systemStatusRowCount = (systemStatusSection.match(/data-system-state="ready"/g) ?? []).length;
  if (systemStatusRowCount !== 5) errors.push(`a-ds-01-ui-prototype.html: expected five ready system status rows, found ${systemStatusRowCount}`);
  if (systemStatusSection.includes('<strong>플랫폼 등록</strong>')) errors.push('a-ds-01-ui-prototype.html: obsolete platform registration row remains');
  if ((systemStatusSection.match(/>사용 준비됨<\/span>/g) ?? []).length !== 5) errors.push('a-ds-01-ui-prototype.html: every system status row must use the user-facing ready label');

  const responseKinds = [
    'short_text', 'long_text', 'single_choice', 'multiple_choice', 'checkbox',
    'number', 'table', 'image_upload', 'file_upload', 'drawing',
  ];
  for (const kind of responseKinds) {
    const optionPattern = new RegExp('<option\\s+value=[\"\']' + kind + '[\"\']', 'i');
    if (!optionPattern.test(html)) errors.push('a-ds-01-ui-prototype.html: missing response option ' + kind);
    if (!html.includes('data-response-kind="' + kind + '"')) errors.push('a-ds-01-ui-prototype.html: missing response menu item ' + kind);
  }

  for (const forbidden of [
    'DTO', 'schema', 'projection', 'handler', 'runtime', 'manifest', 'revision',
    'provider', 'idempotency', 'stack trace', '>개요<', '>작업함<',
  ]) {
    if (html.includes(forbidden)) errors.push(`a-ds-01-ui-prototype.html: developer-facing copy found: ${forbidden}`);
  }

  const cssWithoutComments = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const openBraces = (cssWithoutComments.match(/\{/g) ?? []).length;
  const closeBraces = (cssWithoutComments.match(/\}/g) ?? []).length;
  if (openBraces !== closeBraces) errors.push(`ui-prototype.css: unbalanced braces ${openBraces}/${closeBraces}`);
  if (/gradient\s*\(/i.test(css)) errors.push('ui-prototype.css: decorative gradient is not allowed');
  if (/font-size\s*:[^;]*(?:vw|clamp\s*\()/i.test(css)) errors.push('ui-prototype.css: viewport-scaled font size is not allowed');
  if (!/@media\s*\(max-width:\s*560px\)/i.test(css)) errors.push('ui-prototype.css: compact viewport contract is missing');
  for (const marker of ['.prototype-question', '.response-answer-label', '.response-placeholder.is-active', '.ai-finding', '.ai-check-filters', '.evidence-highlight', '.student-save-alert', '.question-progress', '.attachment-row', '.submission-import-table', '.heading-actions', '.rubric-review', '.review-total-note.is-error', '.feedback-field', '.release-options', '.result-notice', '.report-baseline', '.report-drilldown', '.export-format', '.student-note-source', '.system-management', '.system-status-list', '.system-action-row', '.migration-dialog', '.purge-dialog', '.purge-warning']) {
    if (!css.includes(marker)) errors.push('ui-prototype.css: missing worksheet evaluation style ' + marker);
  }

  for (const marker of ['selectResponse(element)', 'updateAnswerCount(question)', 'responsePreview.dataset.kind = kind', 'addPrototypeQuestionButton.addEventListener', "reviewDialog.close('confirm')", 'feedbackDialog.showModal()', 'releaseDialog.showModal()', "releaseDialog.close('confirm')", 'input.checkValidity()', "setAttribute('aria-invalid', 'true')", 'reportExportDialog.showModal()', 'studentNoteDialog.showModal()', 'exportDownload.hidden = false', 'reportDrilldown.hidden = false', "setSettingsPanel('policy')", "name === 'connection' || name === 'system'", "const tabBar = activeButton?.closest('.settings-nav')", 'buttonRect.right > tabRect.right', 'tabBar.scrollLeft += buttonRect.right - tabRect.right', 'legacyMigrationDialog.showModal()', 'confirmLegacyMigration.disabled = !migrationAck.checked', 'purgeDialog.showModal()', "purgePhrase.value.trim() !== '영구 삭제'", 'confirmPurge.disabled = !purgeBackup.checked']) {
    if (!script.includes(marker)) errors.push('ui-prototype.js: missing review guard ' + marker);
  }

  try {
    new Script(script, { filename: 'ui-prototype.js' });
  } catch (error) {
    errors.push(`ui-prototype.js: syntax error (${error.message})`);
  }

  return errors;
}

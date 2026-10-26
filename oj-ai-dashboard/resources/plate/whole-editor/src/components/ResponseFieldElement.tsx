import * as React from 'react';
import { createPortal } from 'react-dom';
import { PlateElement, useEditorRef, useElement, usePath, useReadOnly } from 'platejs/react';

type StudentRubricCriterion = {
  criterion_id: string;
  description?: string;
  label: string;
  maximum_points: string;
  scope: 'response' | 'submission';
};

declare global {
  interface Window {
    ojAiDashboardWorksheetPlatePreviewResponses?: Record<string, unknown>;
    ojAiDashboardStudentRubricCriteria?: StudentRubricCriterion[];
  }
}

let responseInspectorInstanceSequence = 0;

function nextResponseInspectorInstanceId(): string {
  responseInspectorInstanceSequence += 1;
  return `hws-response-inspector-${responseInspectorInstanceSequence}`;
}

function sanitizeFieldName(value: string): string {
  return String(value || '').replace(/[^A-Za-z0-9_-]/g, '_') || 'field';
}

function linesToItems(value: string): string[] {
  return String(value || '')
    .split(/\r\n|\r|\n|,/)
    .map((item) => item.trim())
    .filter(Boolean);
}

function canonicalKind(kind: string): string {
  const aliases: Record<string, string> = {
    checklist: 'multiple_choice',
    multi_select: 'multiple_choice',
    radio: 'single_choice',
    select: 'single_choice',
  };
  return aliases[kind] || kind || 'long_text';
}

function responseKindLabel(kind: string): string {
  const labels: Record<string, string> = {
    short_text: '단답형',
    long_text: '서술형',
    single_choice: '단일 선택',
    multiple_choice: '복수 선택',
    checkbox: '확인 체크',
    number: '숫자',
    table: '표 입력',
    image_upload: '이미지 첨부',
    file_upload: '파일 첨부',
    drawing: '그림 응답',
  };
  return labels[canonicalKind(kind)] || '서술형';
}

function responseDefaultLabel(kind: string): string {
  const labels: Record<string, string> = {
    short_text: '학생 단답칸',
    long_text: '학생 긴 응답칸',
    single_choice: '학생 단일 선택칸',
    multiple_choice: '학생 복수 선택칸',
    checkbox: '학생 체크칸',
    number: '학생 숫자 입력칸',
    table: '학생 응답표',
    image_upload: '학생 이미지 첨부칸',
    file_upload: '학생 파일 첨부칸',
    drawing: '학생 그림 응답칸',
  };

  return labels[canonicalKind(kind)] || '학생 응답';
}

function rubricCriteria(value: unknown): StudentRubricCriterion[] {
  if (!Array.isArray(value)) return [];
  return value.filter((criterion: any) => (
    typeof criterion?.criterion_id === 'string'
    && typeof criterion?.label === 'string'
    && typeof criterion?.maximum_points === 'string'
    && (criterion?.scope === 'response' || criterion?.scope === 'submission')
  ));
}

function decimalUnits(value: string): number {
  const match = String(value || '').match(/^(\d{1,8})(?:\.(\d{1,4}))?$/);
  if (!match) return 0;
  return (Number(match[1]) * 10000) + Number((match[2] || '').padEnd(4, '0'));
}

function decimalFromUnits(value: number): string {
  const whole = Math.floor(value / 10000);
  const fraction = String(value % 10000).padStart(4, '0').replace(/0+$/, '');
  return fraction ? `${whole}.${fraction}` : String(whole);
}

function boundCriteriaOutside(nodes: unknown, currentResponseKey: string): Set<string> {
  const result = new Set<string>();
  const visit = (value: unknown) => {
    if (!Array.isArray(value)) return;
    value.forEach((node: any) => {
      if (!node || typeof node !== 'object') return;
      const nodeResponseKey = String(node.name || node.response_id || node.id || '');
      if (node.type === 'hws_response' && nodeResponseKey !== currentResponseKey) {
        const ids = Array.isArray(node.grading?.rubric_criterion_ids) ? node.grading.rubric_criterion_ids : [];
        ids.forEach((id: unknown) => { if (typeof id === 'string') result.add(id); });
      }
      visit(node.children);
    });
  };
  visit(nodes);
  return result;
}

function canonicalCellType(type: string): string {
  const aliases: Record<string, string> = {
    check: 'checkbox',
    choice: 'single_choice',
    multiple: 'multiple_choice',
    numeric: 'number',
    select: 'single_choice',
    single: 'single_choice',
  };
  const normalized = aliases[type] || type || 'text';
  return ['text', 'number', 'checkbox', 'single_choice', 'multiple_choice'].includes(normalized)
    ? normalized
    : 'text';
}

function columnsToText(columns: unknown): string {
  if (!Array.isArray(columns)) {
    return '내용|text\n정리|text';
  }

  return columns
    .map((column: any, index) => {
      const label = String(column?.label || `열 ${index + 1}`);
      const inputType = canonicalCellType(String(column?.input_type || 'text'));
      const options = Array.isArray(column?.options) ? column.options.map(String).filter(Boolean).join(',') : '';
      return [label, inputType, options].filter(Boolean).join('|');
    })
    .filter(Boolean)
    .join('\n');
}

function textToColumns(value: string): Array<{ input_type: string; key: string; label: string; options?: string[] }> {
  return String(value || '')
    .split(/\r\n|\r|\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line, index) => {
      const parts = line.split('|').map((item) => item.trim());
      const options = parts.slice(2).join('|');
      return {
        key: sanitizeFieldName(`col_${index + 1}`),
        label: parts[0] || `열 ${index + 1}`,
        input_type: canonicalCellType(parts[1] || 'text'),
        ...(options ? { options: linesToItems(options) } : {}),
      };
    });
}

function normalizeColumns(columns: unknown): Array<{ input_type: string; key: string; label: string; options?: string[] }> {
  if (!Array.isArray(columns) || columns.length === 0) {
    return [
      { key: 'col_1', label: '내용', input_type: 'text' },
      { key: 'col_2', label: '정리', input_type: 'text' },
    ];
  }

  return columns.map((column: any, index) => ({
    key: sanitizeFieldName(String(column?.key || `col_${index + 1}`)),
    label: String(column?.label || `열 ${index + 1}`),
    input_type: canonicalCellType(String(column?.input_type || 'text')),
    ...(Array.isArray(column?.options) ? { options: column.options.map(String).filter(Boolean) } : {}),
  }));
}

function notifyResponseGradingChange(responseId: string, criterionIds: string[]) {
  if (typeof window === 'undefined') {
    return;
  }
  window.dispatchEvent(new CustomEvent('ojai-plate-response-grading-change', {
    detail: {
      response_id: responseId,
      rubric_criterion_ids: criterionIds,
    },
  }));
}
function notifyResponseFieldChange() {
  if (typeof window === 'undefined') {
    return;
  }

  const notify = () => {
    window.dispatchEvent(new CustomEvent('hws-plate-response-field-change'));
  };

  if (typeof window.queueMicrotask === 'function') {
    window.queueMicrotask(notify);
    return;
  }

  window.setTimeout(notify, 0);
}

function setElementProps(editor: any, element: any, path: any, props: Record<string, unknown>) {
  const nodePath = path || editor?.api?.findPath?.(element);
  const setNodes = editor?.tf?.setNodes;

  if (nodePath && typeof setNodes === 'function') {
    try {
      setNodes(props, { at: nodePath, voids: true });
    } catch {
      Object.assign(element, props);
    }
  } else {
    try {
      Object.assign(element, props);
    } catch {
      // The editor node is immutable and no transform is available.
    }
  }
  notifyResponseFieldChange();
}

function responseValueElement(kind: string, value: unknown): React.ReactNode {
  if (value === undefined || value === null || value === '') {
    return <span className="hws-response-node__empty">응답 없음</span>;
  }

  if (kind === 'drawing' && typeof value === 'object' && value !== null) {
    const image = (value as any).image;
    const dataUrl = image?.data_url;
    if (typeof dataUrl === 'string' && dataUrl) {
      return <img alt="drawing response" className="hws-response-node__image" src={dataUrl} />;
    }
  }

  if (Array.isArray(value)) {
    return (
      <ul className="hws-response-node__list">
        {value.map((item, index) => (
          <li key={`${String(item)}-${index}`}>{String(item)}</li>
        ))}
      </ul>
    );
  }

  if (typeof value === 'object') {
    return <pre className="hws-response-node__pre">{JSON.stringify(value, null, 2)}</pre>;
  }

  if (typeof value === 'boolean') {
    return value ? '예' : '아니오';
  }

  return <span className="hws-response-node__answer">{String(value)}</span>;
}

function studentPreviewControl(kind: string, element: any, responseKey: string): React.ReactNode {
  if (kind === 'short_text') {
    return (
      <input
        aria-label={String(element.label || responseKey || '학생 응답')}
        className="hws-response-node__student-control"
        disabled
        type="text"
      />
    );
  }

  if (kind === 'number') {
    return (
      <input
        aria-label={String(element.label || responseKey || '학생 응답')}
        className="hws-response-node__student-control"
        disabled
        type="number"
      />
    );
  }

  if (kind === 'checkbox') {
    return (
      <label className="hws-response-node__student-checkbox">
        <input disabled type="checkbox" />
        <span>{String(element.label || responseKey || '체크')}</span>
      </label>
    );
  }

  if (kind === 'single_choice') {
    const options = Array.isArray(element.options) ? element.options.map(String).filter(Boolean) : [];
    return (
      <select
        aria-label={String(element.label || responseKey || '학생 응답')}
        className="hws-response-node__student-control"
        disabled
      >
        <option value="">선택하세요</option>
        {options.map((option) => (
          <option key={option} value={option}>{option}</option>
        ))}
      </select>
    );
  }

  if (kind === 'multiple_choice') {
    const options = Array.isArray(element.options) ? element.options.map(String).filter(Boolean) : [];
    return (
      <div className="hws-response-node__student-checklist" aria-label={String(element.label || responseKey || '학생 응답')}>
        {options.map((option) => (
          <label key={option}>
            <input disabled type="checkbox" />
            <span>{option}</span>
          </label>
        ))}
      </div>
    );
  }

  if (kind === 'table') {
    const columns = normalizeColumns(element.columns);
    const rowCount = Math.max(1, Math.min(30, Number(element.row_count || 3)));
    return (
      <table className="hws-response-node__student-table" aria-label={String(element.label || responseKey || '학생 응답')}>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key}>{column.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {Array.from({ length: rowCount }).map((_, rowIndex) => (
            <tr key={rowIndex}>
              {columns.map((column) => (
                <td key={column.key}>{tableCellPreview(column)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    );
  }

  if (kind === 'drawing') {
    return <div className="hws-response-node__student-drawing" aria-label={String(element.label || responseKey || '학생 그림 응답')} />;
  }

  if (kind === 'image_upload') {
    return <input aria-label={String(element.label || responseKey || '이미지 첨부')} className="hws-response-node__student-control" disabled type="file" accept="image/*" />;
  }

  if (kind === 'file_upload') {
    return <input aria-label={String(element.label || responseKey || '파일 첨부')} className="hws-response-node__student-control" disabled type="file" />;
  }

  return (
    <textarea
      aria-label={String(element.label || responseKey || '학생 응답')}
      className="hws-response-node__student-control hws-response-node__student-control--textarea"
      disabled
      rows={5}
    />
  );
}

function tableCellPreview(column: { input_type?: string; label: string; options?: string[] }): React.ReactNode {
  const inputType = canonicalCellType(String(column.input_type || 'text'));
  if (inputType === 'number') {
    return <input aria-label={column.label} disabled type="number" />;
  }
  if (inputType === 'checkbox') {
    return <input aria-label={column.label} disabled type="checkbox" />;
  }
  if (inputType === 'single_choice') {
    return (
      <select aria-label={column.label} disabled>
        <option value="">선택</option>
        {(column.options || []).map((option) => (
          <option key={option} value={option}>{option}</option>
        ))}
      </select>
    );
  }
  if (inputType === 'multiple_choice') {
    return (
      <div className="hws-response-node__table-checks">
        {(column.options && column.options.length > 0 ? column.options : ['선택']).map((option) => (
          <label key={option}>
            <input disabled type="checkbox" />
            <span>{option}</span>
          </label>
        ))}
      </div>
    );
  }
  return <input aria-label={column.label} disabled type="text" />;
}

function questionIdAtPath(editor: any, path: number[]): string {
  if (!Array.isArray(editor?.children) || !Array.isArray(path)) return '';
  for (let depth = path.length - 1; depth > 0; depth -= 1) {
    let node: any = { children: editor.children };
    const ancestorPath = path.slice(0, depth);
    for (const index of ancestorPath) {
      node = node?.children?.[index];
    }
    if (node?.type === 'hws_question') {
      return String(node.question_id || node.node_id || node.id || '');
    }
  }
  return '';
}

export function ResponseFieldElement(props: any) {
  const element = useElement<any>() || props.element || {};
  const editor = useEditorRef();
  const path = usePath();
  const readOnly = useReadOnly();
  const [kindDraft, setKindDraft] = React.useState(() =>
    canonicalKind(String(element.kind || 'long_text'))
  );
  const [nameDraft, setNameDraft] = React.useState(() =>
    String(element.name || element.id || '')
  );
  const [labelDraft, setLabelDraft] = React.useState(() =>
    String(element.label || '')
  );
  const [requiredDraft, setRequiredDraft] = React.useState(() =>
    Boolean(element.required)
  );
  const [rowCountDraft, setRowCountDraft] = React.useState(() =>
    Math.max(1, Number(element.row_count || 3))
  );
  const [optionsText, setOptionsText] = React.useState(() =>
    Array.isArray(element.options) ? element.options.join('\n') : '선택지 1\n선택지 2'
  );
  const [columnsText, setColumnsText] = React.useState(() =>
    columnsToText(element.columns)
  );
  const [minCharsDraft, setMinCharsDraft] = React.useState(() =>
    Number.isInteger(element.constraints?.min_chars) ? String(element.constraints.min_chars) : ''
  );
  const [maxCharsDraft, setMaxCharsDraft] = React.useState(() =>
    Number.isInteger(element.constraints?.max_chars) ? String(element.constraints.max_chars) : ''
  );
  const [studentCriteria, setStudentCriteria] = React.useState<StudentRubricCriterion[]>(() =>
    rubricCriteria(typeof window !== 'undefined' ? window.ojAiDashboardStudentRubricCriteria : [])
  );
  const [rubricUnavailable, setRubricUnavailable] = React.useState(false);
  const [selectedCriterionIds, setSelectedCriterionIds] = React.useState<string[]>(() =>
    Array.isArray(element.grading?.rubric_criterion_ids) ? element.grading.rubric_criterion_ids.map(String) : []
  );
  const [teacherNoteDraft, setTeacherNoteDraft] = React.useState(() => String(element.grading?.teacher_note || ''));
  const [criteriaRevision, setCriteriaRevision] = React.useState(0);
  const elementOptionsKey = JSON.stringify(Array.isArray(element.options) ? element.options : []);
  const elementColumnsKey = JSON.stringify(Array.isArray(element.columns) ? element.columns : []);
  const elementConstraintsKey = JSON.stringify(element.constraints || {});
  const elementGradingKey = JSON.stringify(element.grading || {});
  const responseKey = String(nameDraft || element.response_id || element.id || 'response');
  const nodeKey = String(element.node_id || element.response_id || responseKey);
  const questionKey = questionIdAtPath(editor, path);
  const [inspectorRoot, setInspectorRoot] = React.useState<HTMLElement | null>(null);
  const [active, setActive] = React.useState(false);
  const [inspectorInstanceId] = React.useState(nextResponseInspectorInstanceId);

  React.useEffect(() => {
    setKindDraft(canonicalKind(String(element.kind || 'long_text')));
    setNameDraft(String(element.name || element.id || ''));
    setLabelDraft(String(element.label || ''));
    setRequiredDraft(Boolean(element.required));
    setRowCountDraft(Math.max(1, Number(element.row_count || 3)));
    setOptionsText(
      Array.isArray(element.options)
        ? element.options.join('\n')
        : '선택지 1\n선택지 2'
    );
    setColumnsText(columnsToText(element.columns));
    setMinCharsDraft(Number.isInteger(element.constraints?.min_chars) ? String(element.constraints.min_chars) : '');
    setMaxCharsDraft(Number.isInteger(element.constraints?.max_chars) ? String(element.constraints.max_chars) : '');
  }, [
    element.kind,
    element.name,
    element.id,
    element.label,
    element.required,
    element.row_count,
    elementOptionsKey,
    elementColumnsKey,
    elementConstraintsKey,
  ]);

  React.useEffect(() => {
    setSelectedCriterionIds(
      Array.isArray(element.grading?.rubric_criterion_ids) ? element.grading.rubric_criterion_ids.map(String) : []
    );
    setTeacherNoteDraft(String(element.grading?.teacher_note || ''));
  }, [elementGradingKey]);

  React.useEffect(() => {
    const updateRubric = (event: Event) => {
      const detail = (event as CustomEvent).detail || {};
      setStudentCriteria(rubricCriteria(detail.criteria));
      setRubricUnavailable(Boolean(detail.unavailable));
    };
    const refreshAssignments = () => setCriteriaRevision((value) => value + 1);
    window.addEventListener('ojai-worksheet-rubric-change', updateRubric);
    window.addEventListener('hws-plate-response-field-change', refreshAssignments);
    return () => {
      window.removeEventListener('ojai-worksheet-rubric-change', updateRubric);
      window.removeEventListener('hws-plate-response-field-change', refreshAssignments);
    };
  }, []);

  React.useEffect(() => {
    const root = document.getElementById('ws-response-inspector-root');
    setInspectorRoot(root);
    if (!root) {
      return undefined;
    }

    const selectResponse = (event: Event) => {
      const selected = String((event as CustomEvent).detail?.id || '');
      const requestedInstance = String((event as CustomEvent).detail?.instance || '');
      if (selected !== responseKey) {
        setActive(false);
        return;
      }

      if (requestedInstance) {
        const isRequestedInstance = requestedInstance === inspectorInstanceId;
        setActive(isRequestedInstance);
        if (isRequestedInstance) {
          root.dataset.activeResponse = responseKey;
          root.dataset.activeResponseInstance = inspectorInstanceId;
        }
          if (questionKey) root.dataset.activeQuestion = questionKey;
        return;
      }

      const currentInstance = root.dataset.activeResponse === responseKey
        ? String(root.dataset.activeResponseInstance || '')
        : '';
      const shouldClaimSelection = !currentInstance || currentInstance === inspectorInstanceId;
      setActive(shouldClaimSelection);
      if (shouldClaimSelection) {
        root.dataset.activeResponse = responseKey;
        root.dataset.activeResponseInstance = inspectorInstanceId;
      }
        if (questionKey) root.dataset.activeQuestion = questionKey;
    };

    window.addEventListener('ojai-plate-response-select', selectResponse);
    if (!root.dataset.activeResponse) {
        if (questionKey) root.dataset.activeQuestion = questionKey;
      window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
        detail: { id: responseKey, instance: inspectorInstanceId, questionId: questionKey },
      }));
    } else if (root.dataset.activeResponse === responseKey) {
      window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
        detail: { id: responseKey, questionId: questionKey },
      }));
    } else {
      setActive(false);
    }

    return () => {
      window.removeEventListener('ojai-plate-response-select', selectResponse);
      if (root.dataset.activeResponseInstance !== inspectorInstanceId) {
        return;
      }

      const selected = String(root.dataset.activeResponse || '');
      delete root.dataset.activeResponseInstance;
      window.setTimeout(() => {
        if (!root.isConnected || !selected || root.dataset.activeResponseInstance) {
          return;
        }
        window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
          detail: { id: selected, questionId: questionKey },
        }));
      }, 0);
    };
  }, [inspectorInstanceId, questionKey, responseKey]);

  const activate = React.useCallback(() => {
    const root = inspectorRoot || document.getElementById('ws-response-inspector-root');
    if (root) {
      root.dataset.activeResponse = responseKey;
      root.dataset.activeResponseInstance = inspectorInstanceId;
      if (questionKey) root.dataset.activeQuestion = questionKey;
    }
    setActive(true);
    window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
      detail: { id: responseKey, instance: inspectorInstanceId, questionId: questionKey, showInspector: true },
    }));
  }, [inspectorInstanceId, inspectorRoot, questionKey, responseKey]);

  const kind = kindDraft;
  const showTextConstraints = kind === 'short_text' || kind === 'long_text';
  const showOptions = kind === 'single_choice' || kind === 'multiple_choice';
  const showTable = kind === 'table';
  const update = (propsToSet: Record<string, unknown>) =>
    setElementProps(editor, element, path, propsToSet);
  const stopEditorEvent = (event: React.SyntheticEvent) => {
    event.stopPropagation();
  };
  const responseCriteria = studentCriteria.filter((criterion) => criterion.scope === 'response');
  const submissionCriteria = studentCriteria.filter((criterion) => criterion.scope === 'submission');
  const criteriaBoundElsewhere = React.useMemo(
    () => boundCriteriaOutside(editor?.children || [], responseKey),
    [criteriaRevision, editor, responseKey]
  );
  const selectedMaximumUnits = responseCriteria
    .filter((criterion) => selectedCriterionIds.includes(criterion.criterion_id))
    .reduce((total, criterion) => total + decimalUnits(criterion.maximum_points), 0);
  const selectedMaximumPoints = decimalFromUnits(selectedMaximumUnits);

  const updateGrading = (criterionIds: string[], teacherNote: string) => {
    const selected = responseCriteria.filter((criterion) => criterionIds.includes(criterion.criterion_id));
    const maximumUnits = selected.reduce(
      (total, criterion) => total + decimalUnits(criterion.maximum_points),
      0
    );
    const previous = element.grading && typeof element.grading === 'object' ? element.grading : {};
    const preserved: Record<string, unknown> = {};
    ['correct_answer', 'example_answer'].forEach((property) => {
      if (Object.prototype.hasOwnProperty.call(previous, property)) preserved[property] = previous[property];
    });
    const selectedIds = selected.map((criterion) => criterion.criterion_id);
    update({
      grading: {
        ...preserved,
        grading_enabled: selected.length > 0,
        max_points: decimalFromUnits(maximumUnits),
        rubric_criterion_ids: selectedIds,
        ...(teacherNote.trim() ? { teacher_note: teacherNote.trim() } : {}),
      },
    });
    notifyResponseGradingChange(responseKey, selectedIds);
  };

  const toggleCriterion = (criterionId: string, checked: boolean) => {
    const nextSet = new Set(selectedCriterionIds);
    if (checked) nextSet.add(criterionId);
    else nextSet.delete(criterionId);
    const next = responseCriteria.map((criterion) => criterion.criterion_id).filter((id) => nextSet.has(id));
    setSelectedCriterionIds(next);
    updateGrading(next, teacherNoteDraft);
  };

  const updateKind = (nextKind: string) => {
    const previousKind = kind;
    nextKind = canonicalKind(nextKind);
    const currentLabel = labelDraft.trim();
    const nextLabel = !currentLabel || currentLabel === responseDefaultLabel(previousKind)
      ? responseDefaultLabel(nextKind)
      : labelDraft;
    setKindDraft(nextKind);
    setLabelDraft(nextLabel);
    update({
      kind: nextKind,
      label: nextLabel,
      ...(nextKind === 'single_choice' || nextKind === 'multiple_choice'
        ? { options: linesToItems(optionsText) }
        : {}),
      ...(nextKind === 'table'
        ? { row_count: rowCountDraft, columns: textToColumns(columnsText) }
        : {}),
    });
  };


  const updateLabel = (nextLabel: string) => {
    setLabelDraft(nextLabel);
    update({ label: nextLabel });
  };

  const updateRequired = (nextRequired: boolean) => {
    setRequiredDraft(nextRequired);
    update({ required: nextRequired });
  };

  const updateTextConstraints = (nextMin: string, nextMax: string) => {
    const constraints: Record<string, number> = {};
    if (/^\d+$/.test(nextMin)) {
      constraints.min_chars = Math.min(100000, Number(nextMin));
    }
    if (/^\d+$/.test(nextMax)) {
      constraints.max_chars = Math.min(100000, Number(nextMax));
    }
    update({ constraints });
  };

  const removeResponse = () => {
    editor?.tf?.removeNodes?.({ at: path, voids: true });
    if (inspectorRoot?.dataset.activeResponse === responseKey) {
      delete inspectorRoot.dataset.activeResponse;
    }
    if (inspectorRoot?.dataset.activeResponseInstance === inspectorInstanceId) {
      delete inspectorRoot.dataset.activeResponseInstance;
    }
    window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
      detail: { id: '', questionId: questionKey },
    }));
    notifyResponseFieldChange();
    window.setTimeout(() => {
      const next = document.querySelector<HTMLElement>('.hws-response-node__preview');
      next?.click();
      next?.focus({ preventScroll: true });
    }, 0);
  };

  if (readOnly) {
    const responses = typeof window !== 'undefined'
      ? window.ojAiDashboardWorksheetPlatePreviewResponses || {}
      : {};
    const responseKey = nameDraft || String(element.id || '');
    const hasResponse = Object.prototype.hasOwnProperty.call(responses, responseKey);
    const responseValue = responses[responseKey];

    if (!hasResponse) {
      return (
        <PlateElement {...props} as="div" className="hws-response-node hws-response-node--student-preview">
          <div contentEditable={false} className="hws-response-node__student-panel">
            {studentPreviewControl(kind, element, responseKey)}
          </div>
          <span className="hws-plate-void-children">{props.children}</span>
        </PlateElement>
      );
    }

    return (
      <PlateElement {...props} as="div" className="hws-response-node hws-response-node--readonly">
        <div contentEditable={false} className="hws-response-node__panel">
          <div className="hws-response-node__header">
            <span className="hws-response-node__badge">학생 응답</span>
            <strong>{labelDraft || '학생 응답'}</strong>
          </div>
          <div className="hws-response-node__readonly-value">
            {responseValueElement(kind, responseValue)}
          </div>
        </div>
        <span className="hws-plate-void-children">{props.children}</span>
      </PlateElement>
    );
  }

  const inspector = (
    <div
      className="hws-response-inspector"
      data-hws-response-editor="1"
      data-hws-response-id={responseKey}
      data-hws-question-id={questionKey}
      hidden={!active}
      onClick={stopEditorEvent}
      onKeyDown={stopEditorEvent}
      onMouseDown={stopEditorEvent}
      onPointerDown={stopEditorEvent}
    >
      <input type="hidden" data-hws-response-prop="name" value={nameDraft} readOnly />
      <div className="hws-response-node__grid">
        <label className="hws-response-node__wide">
          <span>응답 형식</span>
          <select aria-label="응답 형식" data-hws-response-prop="kind" value={kind} onChange={(event) => updateKind(event.target.value)}>
            <option value="short_text">단답형</option>
            <option value="long_text">서술형</option>
            <option value="single_choice">단일 선택</option>
            <option value="multiple_choice">복수 선택</option>
            <option value="checkbox">확인 체크</option>
            <option value="number">숫자</option>
            <option value="table">표 입력</option>
            <option value="image_upload">이미지 첨부</option>
            <option value="file_upload">파일 첨부</option>
            <option value="drawing">그림 응답</option>
          </select>
        </label>
        <label className="hws-response-node__wide">
          <span>응답 안내</span>
          <input
            aria-label="응답 안내"
            data-hws-response-prop="label"
            value={labelDraft}
            onChange={(event) => updateLabel(event.target.value)}
            placeholder="학생에게 보여 줄 응답 안내"
          />
        </label>
        <label className="hws-response-node__check hws-response-node__wide">
          <input
            data-hws-response-prop="required"
            type="checkbox"
            checked={requiredDraft}
            onChange={(event) => updateRequired(event.target.checked)}
          />
          <span>필수 응답</span>
        </label>
        <fieldset className="hws-response-inspector__criteria hws-response-node__wide">
          <legend>평가 기준</legend>
          {rubricUnavailable && <p className="hws-response-inspector__criteria-empty">평가 기준을 불러오지 못했습니다.</p>}
          {!rubricUnavailable && responseCriteria.length === 0 && (
            <p className="hws-response-inspector__criteria-empty">개별 응답 기준 없음</p>
          )}
          {responseCriteria.map((criterion) => {
            const selected = selectedCriterionIds.includes(criterion.criterion_id);
            const usedElsewhere = criteriaBoundElsewhere.has(criterion.criterion_id) && !selected;
            return (
              <label className="hws-response-inspector__criterion" key={criterion.criterion_id}>
                <input
                  type="checkbox"
                  checked={selected}
                  disabled={usedElsewhere}
                  data-hws-response-prop="rubric_criterion_id"
                  value={criterion.criterion_id}
                  onChange={(event) => toggleCriterion(criterion.criterion_id, event.target.checked)}
                />
                <span className="hws-response-inspector__criterion-copy">
                  <strong>{criterion.label}</strong>
                  {criterion.description && <small>{criterion.description}</small>}
                </span>
                <small className="hws-response-inspector__criterion-score">{criterion.maximum_points}점{usedElsewhere ? ' · 다른 응답' : ''}</small>
              </label>
            );
          })}
          <output className="hws-response-inspector__criteria-total">배점 {selectedMaximumPoints}점</output>
          {submissionCriteria.length > 0 && (
            <p className="hws-response-inspector__global-criteria">
              전체 결과 · {submissionCriteria.map((criterion) => criterion.label).join(', ')}
            </p>
          )}
        </fieldset>
        <label className="hws-response-node__wide">
          <span>교사용 평가 메모</span>
          <textarea
            data-hws-response-prop="teacher_note"
            value={teacherNoteDraft}
            rows={3}
            onChange={(event) => {
              setTeacherNoteDraft(event.target.value);
              updateGrading(selectedCriterionIds, event.target.value);
            }}
          />
        </label>
        {showTextConstraints && (
          <>
            <label>
              <span>최소 글자 수</span>
              <input
                data-hws-response-prop="min_chars"
                type="number"
                min={0}
                max={100000}
                value={minCharsDraft}
                placeholder="제한 없음"
                onChange={(event) => {
                  const nextValue = event.target.value;
                  setMinCharsDraft(nextValue);
                  updateTextConstraints(nextValue, maxCharsDraft);
                }}
              />
            </label>
            <label>
              <span>최대 글자 수</span>
              <input
                data-hws-response-prop="max_chars"
                type="number"
                min={0}
                max={100000}
                value={maxCharsDraft}
                placeholder="제한 없음"
                onChange={(event) => {
                  const nextValue = event.target.value;
                  setMaxCharsDraft(nextValue);
                  updateTextConstraints(minCharsDraft, nextValue);
                }}
              />
            </label>
          </>
        )}
        {showOptions && (
          <label className="hws-response-node__wide">
            <span>선택지</span>
            <textarea
              data-hws-response-prop="options"
              value={optionsText}
              onChange={(event) => {
                setOptionsText(event.target.value);
                update({ options: linesToItems(event.target.value) });
              }}
              rows={4}
              placeholder={'선택지 1\n선택지 2'}
            />
            <small>한 줄에 하나씩 입력</small>
          </label>
        )}
        {showTable && (
          <>
            <label className="hws-response-node__wide">
              <span>행 수</span>
              <input
                data-hws-response-prop="row_count"
                type="number"
                min={1}
                max={30}
                value={rowCountDraft}
                onChange={(event) => {
                  const nextCount = Math.max(1, Math.min(30, Number(event.target.value || 3)));
                  setRowCountDraft(nextCount);
                  update({ row_count: nextCount });
                }}
              />
            </label>
            <label className="hws-response-node__wide">
              <span>열 설정</span>
              <textarea
                data-hws-response-prop="columns"
                value={columnsText}
                onChange={(event) => {
                  setColumnsText(event.target.value);
                  update({ columns: textToColumns(event.target.value) });
                }}
                rows={4}
                placeholder={'내용|text\n정리|text'}
              />
              <small>열 이름과 입력 형식을 한 줄에 하나씩 입력</small>
            </label>
          </>
        )}
      </div>
      <button type="button" className="hws-response-inspector__delete" onClick={removeResponse}>
        응답 삭제
      </button>
    </div>
  );

  return (
    <PlateElement
      {...props}
      as="div"
      className={`hws-response-node${active ? ' is-selected' : ''}`}
      data-hws-node-id={nodeKey}
      data-hws-response-id={responseKey}
      data-hws-question-id={questionKey}
    >
      <div
        contentEditable={false}
        className="hws-response-node__preview"
        data-hws-node-id={nodeKey}
        data-hws-response-id={responseKey}
        role="button"
        tabIndex={0}
        data-hws-question-id={questionKey}
        aria-label={`${labelDraft || '학생 응답'} 응답 설정`}
        onClick={(event) => {
          stopEditorEvent(event);
          activate();
        }}
        onKeyDown={(event) => {
          stopEditorEvent(event);
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            activate();
          }
        }}
        onMouseDown={stopEditorEvent}
        onPointerDown={(event) => {
          stopEditorEvent(event);
          activate();
        }}
      >
        <div className="hws-response-node__header">
          <span className="hws-response-node__badge">{responseKindLabel(kind)}</span>
          <strong>{labelDraft || '학생 응답'}</strong>
          <small>{requiredDraft ? '필수' : '선택'}{selectedCriterionIds.length ? ` · 평가 ${selectedCriterionIds.length}` : ''}</small>
        </div>
        <div className="hws-response-node__student-panel">
          {studentPreviewControl(kind, {
            ...element,
            label: labelDraft,
            options: linesToItems(optionsText),
            columns: textToColumns(columnsText),
            row_count: rowCountDraft,
          }, responseKey)}
        </div>
      </div>
      <span className="hws-plate-void-children">{props.children}</span>
      {inspectorRoot ? createPortal(inspector, inspectorRoot) : null}
    </PlateElement>
  );
}

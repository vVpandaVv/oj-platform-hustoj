(function (window, document) {
  'use strict';

  const RESPONSE_KINDS = [
    'short_text',
    'long_text',
    'single_choice',
    'multiple_choice',
    'checkbox',
    'number',
    'table',
    'image_upload',
    'file_upload',
    'drawing',
  ];
  const PUBLIC_ID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/;
  const TEMP_ID_PATTERN = /^tmp_[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  const TEXT_MARKS = ['bold', 'italic', 'underline', 'strikethrough'];
  const ROOT_TYPES = [
    'p', 'h1', 'h2', 'h3', 'blockquote', 'ul', 'ol', 'table', 'img', 'audio', 'video',
    'file', 'equation', 'code_block', 'callout', 'column_group', 'hr', 'page_break',
    'oj_ai_drawing', 'oj_ai_response',
  ];
  const STRUCTURAL_TYPES = ['tr', 'td', 'th', 'li', 'column', 'code_line', 'caption', 'a'];
  const STATE_LABELS = {
    active: '사용 중',
    archived: '보관',
    draft: '작성 중',
  };

  const EXPORT_KINDS = Object.freeze({
    pdf: Object.freeze({
      action: 'export-pdf',
      artifactKind: 'worksheet_pdf',
      label: 'PDF',
      mimeType: 'application/pdf',
      suffix: '.pdf',
      maxBytes: 100 * 1024 * 1024,
    }),
    package: Object.freeze({
      action: 'export-package',
      artifactKind: 'worksheet_package',
      label: '활동지 파일',
      mimeType: 'application/vnd.oj.ai-worksheet+zip',
      suffix: '.ojws',
      maxBytes: 220 * 1024 * 1024,
    }),
  });

  class WorkbenchError extends Error {
    constructor(message, code) {
      super(message);
      this.name = 'WorkbenchError';
      this.code = code || 'workbench_error';
    }
  }

  class ApiError extends Error {
    constructor(status, payload) {
      super(readProblemMessage(payload, status));
      this.name = 'ApiError';
      this.status = status;
      this.payload = payload || {};
      this.data = isObject(payload && payload.data) ? payload.data : {};
    }
  }

  function isObject(value) {
    return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
  }

  function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
  }

  function randomUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID().toLowerCase();
    }

    const bytes = new Uint8Array(16);
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      window.crypto.getRandomValues(bytes);
    } else {
      for (let index = 0; index < bytes.length; index += 1) {
        bytes[index] = Math.floor(Math.random() * 256);
      }
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  function createTemporaryId() {
    return `tmp_${randomUuid()}`;
  }

  function isDocumentId(value) {
    return typeof value === 'string' && (PUBLIC_ID_PATTERN.test(value) || TEMP_ID_PATTERN.test(value));
  }

  function createIdContext(documentId) {
    const used = new Set();
    const initial = isDocumentId(documentId) ? documentId : createTemporaryId();
    used.add(initial);

    return {
      documentId: initial,
      claim(preferred) {
        if (isDocumentId(preferred) && !used.has(preferred)) {
          used.add(preferred);
          return preferred;
        }
        let next = createTemporaryId();
        while (used.has(next)) {
          next = createTemporaryId();
        }
        used.add(next);
        return next;
      },
    };
  }

  function canonicalTextToPlate(node) {
    const result = { text: typeof node.text === 'string' ? node.text : '' };
    TEXT_MARKS.forEach((mark) => {
      if (node[mark] === true) {
        result[mark] = true;
      }
    });
    return result;
  }

  function canonicalResponseToPlate(node) {
    const constraints = isObject(node.constraints) ? clone(node.constraints) : {};
    const options = Array.isArray(constraints.options)
      ? constraints.options.map((option) => String(option.label || option.value || '')).filter(Boolean)
      : [];
    const columns = Array.isArray(constraints.columns)
      ? constraints.columns.map((column, index) => ({
        key: String(column.column_id || `column_${index + 1}`),
        label: String(column.label || `열 ${index + 1}`),
        input_type: String(column.cell_type || 'short_text'),
      }))
      : [];

    return {
      type: 'hws_response',
      node_id: node.node_id,
      response_id: node.response_id,
      name: node.response_id,
      kind: node.kind,
      canonical_kind: node.kind,
      label: node.label,
      required: node.required === true,
      ...(typeof node.help_text === 'string' ? { help_text: node.help_text } : {}),
      ...(isObject(node.constraints) ? { constraints } : {}),
      ...(isObject(node.grading) ? { grading: clone(node.grading) } : {}),
      options,
      option_ids: Array.isArray(constraints.options)
        ? constraints.options.map((option) => option.option_id)
        : [],
      columns,
      column_ids: Array.isArray(constraints.columns)
        ? constraints.columns.map((column) => column.column_id)
        : [],
      row_ids: Array.isArray(constraints.rows)
        ? constraints.rows.map((row) => row.row_id)
        : [],
      row_count: Math.max(1, Array.isArray(constraints.rows) ? constraints.rows.length : 3),
      children: [{ text: '' }],
    };
  }
  function isCanonicalQuestionNode(node) {
    if (!isObject(node) || node.type !== 'callout' || node.tone !== 'notice' || node.icon !== 'idea') {
      return false;
    }
    const containsResponse = (candidate) => isObject(candidate) && (
      candidate.type === 'oj_ai_response'
      || (Array.isArray(candidate.children) && candidate.children.some(containsResponse))
    );
    return Array.isArray(node.children) && node.children.some(containsResponse);
  }

  function canonicalQuestionToPlate(node) {
    return {
      type: 'hws_question',
      node_id: node.node_id,
      question_id: node.node_id,
      children: Array.isArray(node.children)
        ? node.children.map(canonicalNodeToPlate).filter(Boolean)
        : [],
    };
  }

  function legacyResponseToQuestion(node, index) {
    const response = canonicalResponseToPlate(node);
    const key = `legacy_question_${String(node.response_id || node.node_id || index)}`;
    return {
      type: 'hws_question',
      question_id: key,
      legacy_flat: true,
      legacy_heading: String(node.label || `문항 ${index + 1}`),
      children: [{ type: 'h2', children: [{ text: String(node.label || `문항 ${index + 1}`) }] }, response],
    };
  }

  function canonicalNodeToPlate(node) {
    if (!isObject(node)) {
      return null;
    }
    if (isCanonicalQuestionNode(node)) {
      return canonicalQuestionToPlate(node);
    }
    if (typeof node.text === 'string') {
      return canonicalTextToPlate(node);
    }
    if (node.type === 'oj_ai_response') {
      return canonicalResponseToPlate(node);
    }

    const result = {
      type: node.type === 'oj_ai_drawing' ? 'excalidraw' : node.type,
      node_id: node.node_id,
      children: Array.isArray(node.children)
        ? node.children.map(canonicalNodeToPlate).filter(Boolean)
        : [{ text: '' }],
    };
    const directProperties = [
      'align', 'start', 'url', 'label', 'header', 'row_id', 'column_id', 'asset_id', 'alt',
      'width', 'transcript', 'poster_asset_id', 'description', 'language', 'tone', 'icon',
      'aspect_ratio', 'stroke_schema_version',
    ];
    if (node.type === 'table' && Array.isArray(node.column_widths)) {
      result.colSizes = node.column_widths.slice();
    }
    if (node.type === 'table' && Number.isInteger(node.width_percent) && Array.isArray(node.column_ratios)) {
      result.widthPercent = node.width_percent;
      result.colSizes = node.column_ratios.slice();
    }
    directProperties.forEach((property) => {
      if (Object.prototype.hasOwnProperty.call(node, property)) {
        result[property] = clone(node[property]);
      }
    });
    if (node.type === 'equation') {
      result.texExpression = String(node.tex || '');
      result.display = node.display === true;
    }
    if (node.type === 'oj_ai_drawing') {
      result.canonical_type = 'oj_ai_drawing';
    }
    return result;
  }

  function teacherDocumentToPlateSchema(documentValue) {
    if (!isObject(documentValue) || !Array.isArray(documentValue.nodes)) {
      throw new WorkbenchError('활동지 내용을 불러올 수 없습니다.', 'document_invalid');
    }

    const plateValue = documentValue.nodes.map((node, index) => (
      isObject(node) && node.type === 'oj_ai_response'
        ? legacyResponseToQuestion(node, index)
        : canonicalNodeToPlate(node)
    )).filter(Boolean);
    if (plateValue.length === 0) {
      plateValue.push({ type: 'p', children: [{ text: '' }] });
    }

    return {
      version: 1,
      engine: 'plate',
      title: String(documentValue.title || ''),
      plate_value: plateValue,
      document_html: '',
      response_fields: [],
      blocks: [],
    };
  }

  function plateTextToCanonical(node) {
    const result = { text: typeof node.text === 'string' ? node.text.replace(/\r\n?/g, '\n') : '' };
    const allowed = new Set(['text', ...TEXT_MARKS]);
    Object.keys(node).forEach((key) => {
      if (!allowed.has(key) && node[key] !== false && node[key] !== null && node[key] !== undefined && node[key] !== '') {
        throw new WorkbenchError('지원하지 않는 글자 서식이 있습니다.', 'text_mark_unsupported');
      }
    });
    TEXT_MARKS.forEach((mark) => {
      if (node[mark] === true) {
        result[mark] = true;
      }
    });
    return result;
  }

  function normalizePlateType(node) {
    const type = String(node.type || 'p');
    const aliases = {
      paragraph: 'p',
      blockquote: 'blockquote',
      bulleted_list: 'ul',
      numbered_list: 'ol',
      list_item: 'li',
      // @platejs/list-classic wraps a list item's own text content in a
      // "lic" (list-item-content) node, e.g. ul > li > lic > text. The
      // canonical schema has no separate concept for this wrapper, so it is
      // treated as an ordinary paragraph; loading it back is safe because
      // the classic list plugin's own normalizer auto-converts a bare "p"
      // first child of "li" into "lic" on mount.
      lic: 'p',
      inline_equation: 'equation',
      hws_response: 'oj_ai_response',
      hws_question: 'callout',
      excalidraw: 'oj_ai_drawing',
    };
    return aliases[type] || type;
  }

  function canonicalEmptyChildren() {
    return [{ text: '' }];
  }

  function convertInlineChildren(children, context, parentType) {
    const converted = (Array.isArray(children) ? children : [])
      .map((child) => plateNodeToCanonical(child, context, parentType))
      .filter(Boolean);
    return converted.length > 0 ? converted : canonicalEmptyChildren();
  }

  function convertBlockChildren(children, context, parentType) {
    const source = Array.isArray(children) ? children : [];
    const result = [];
    let inline = [];

    const flushInline = () => {
      if (inline.length === 0) {
        return;
      }
      result.push({
        type: 'p',
        node_id: context.claim(null),
        children: inline,
      });
      inline = [];
    };

    source.forEach((child) => {
      const converted = plateNodeToCanonical(child, context, parentType);
      if (!converted) {
        return;
      }
      if (Object.prototype.hasOwnProperty.call(converted, 'text') || converted.type === 'a' || converted.type === 'equation') {
        inline.push(converted);
      } else {
        flushInline();
        result.push(converted);
      }
    });
    flushInline();

    if (result.length === 0) {
      result.push({ type: 'p', node_id: context.claim(null), children: canonicalEmptyChildren() });
    }
    return result;
  }

  function normalizeChoiceLabels(value) {
    const labels = Array.isArray(value)
      ? value.map((item) => String(item || '').trim()).filter(Boolean)
      : [];
    while (labels.length < 2) {
      labels.push(`선택 ${labels.length + 1}`);
    }
    return labels.slice(0, 100);
  }

  function responseConstraints(node, kind, context) {
    const previous = isObject(node.constraints) ? node.constraints : {};

    if (kind === 'short_text' || kind === 'long_text') {
      const result = {};
      ['min_chars', 'max_chars'].forEach((property) => {
        if (Number.isInteger(previous[property])) {
          result[property] = previous[property];
        }
      });
      if (kind === 'short_text' && typeof previous.trim === 'boolean') {
        result.trim = previous.trim;
      }
      return Object.keys(result).length > 0 ? result : null;
    }

    if (kind === 'single_choice' || kind === 'multiple_choice') {
      const labels = normalizeChoiceLabels(node.options);
      const oldOptions = Array.isArray(previous.options) ? previous.options : [];
      const optionIds = Array.isArray(node.option_ids) ? node.option_ids : [];
      const options = labels.map((label, index) => ({
        option_id: context.claim(optionIds[index] || (oldOptions[index] && oldOptions[index].option_id)),
        value: `choice_${index + 1}`,
        label,
      }));
      const result = { options };
      if (kind === 'multiple_choice') {
        if (Number.isInteger(previous.min_selected)) {
          result.min_selected = Math.max(0, Math.min(previous.min_selected, options.length));
        }
        if (Number.isInteger(previous.max_selected)) {
          result.max_selected = Math.max(1, Math.min(previous.max_selected, options.length));
        }
        if (result.min_selected !== undefined && result.max_selected !== undefined && result.min_selected > result.max_selected) {
          result.min_selected = result.max_selected;
        }
      }
      return result;
    }

    if (kind === 'number') {
      const result = {};
      ['min', 'max', 'step', 'unit', 'decimal_places'].forEach((property) => {
        if (Object.prototype.hasOwnProperty.call(previous, property)) {
          result[property] = clone(previous[property]);
        }
      });
      return Object.keys(result).length > 0 ? result : null;
    }

    if (kind === 'table') {
      const sourceColumns = Array.isArray(node.columns) && node.columns.length > 0
        ? node.columns.slice(0, 20)
        : [{ key: 'column_1', label: '응답', input_type: 'short_text' }];
      const oldColumns = Array.isArray(previous.columns) ? previous.columns : [];
      const columnIds = Array.isArray(node.column_ids) ? node.column_ids : [];
      const columns = sourceColumns.map((column, index) => {
        const requestedType = String(column.input_type || 'short_text');
        const cellType = ['short_text', 'text', 'number', 'checkbox'].includes(requestedType)
          ? (requestedType === 'text' ? 'short_text' : requestedType)
          : 'short_text';
        return {
          column_id: context.claim(columnIds[index] || (oldColumns[index] && oldColumns[index].column_id)),
          label: String(column.label || `열 ${index + 1}`).trim() || `열 ${index + 1}`,
          cell_type: cellType,
        };
      });
      const count = Math.max(1, Math.min(25, Number.parseInt(node.row_count, 10) || 3));
      const oldRows = Array.isArray(previous.rows) ? previous.rows : [];
      const rowIds = Array.isArray(node.row_ids) ? node.row_ids : [];
      const rows = Array.from({ length: count }, (_, index) => ({
        row_id: context.claim(rowIds[index] || (oldRows[index] && oldRows[index].row_id)),
        label: String((oldRows[index] && oldRows[index].label) || `행 ${index + 1}`),
      }));
      return { row_mode: 'fixed', rows, columns };
    }

    if (kind === 'image_upload') {
      return {
        mime_types: Array.isArray(previous.mime_types) && previous.mime_types.length > 0
          ? previous.mime_types
          : ['image/jpeg', 'image/png', 'image/webp'],
        max_files: Number.isInteger(previous.max_files) ? previous.max_files : 1,
        max_bytes: Number.isInteger(previous.max_bytes) ? previous.max_bytes : 10485760,
        alt_required: typeof previous.alt_required === 'boolean' ? previous.alt_required : true,
      };
    }

    if (kind === 'file_upload') {
      return {
        mime_types: Array.isArray(previous.mime_types) && previous.mime_types.length > 0
          ? previous.mime_types
          : ['application/pdf', 'image/jpeg', 'image/png'],
        max_files: Number.isInteger(previous.max_files) ? previous.max_files : 1,
        max_bytes: Number.isInteger(previous.max_bytes) ? previous.max_bytes : 10485760,
      };
    }

    if (kind === 'drawing') {
      return {
        stroke_schema_version: String(previous.stroke_schema_version || 'excalidraw_v1'),
        preview_mime_type: 'image/png',
        aspect_ratio: String(previous.aspect_ratio || '1.414'),
        max_strokes: Number.isInteger(previous.max_strokes) ? previous.max_strokes : 5000,
      };
    }

    return null;
  }

  function sanitizeGrading(node, kind) {
    if (!isObject(node.grading)) {
      return null;
    }
    const result = {};
    ['grading_enabled', 'max_points', 'rubric_criterion_ids', 'teacher_note'].forEach((property) => {
      if (Object.prototype.hasOwnProperty.call(node.grading, property)) {
        result[property] = clone(node.grading[property]);
      }
    });
    if (node.canonical_kind === kind) {
      ['correct_answer', 'example_answer'].forEach((property) => {
        if (Object.prototype.hasOwnProperty.call(node.grading, property)) {
          result[property] = clone(node.grading[property]);
        }
      });
    }
    return Object.keys(result).length > 0 ? result : null;
  }

  function plateResponseToCanonical(node, context) {
    const kind = RESPONSE_KINDS.includes(node.kind) ? node.kind : 'long_text';
    const constraints = responseConstraints(node, kind, context);
    const grading = sanitizeGrading(node, kind);
    const label = String(node.label || '학생 응답').trim() || '학생 응답';
    const result = {
      type: 'oj_ai_response',
      node_id: context.claim(node.node_id),
      response_id: context.claim(node.response_id),
      kind,
      label,
      required: node.required === true,
      children: canonicalEmptyChildren(),
    };
    if (typeof node.help_text === 'string' && node.help_text.trim() !== '') {
      result.help_text = node.help_text.trim();
    }
    if (constraints) {
      result.constraints = constraints;
    }
    if (grading) {
      result.grading = grading;
    }
    return result;
  }

  function plateTableToCanonical(node, context) {
    const rows = Array.isArray(node.children) ? node.children.filter((child) => normalizePlateType(child) === 'tr') : [];
    if (rows.length === 0) {
      throw new WorkbenchError('표에 행이 필요합니다.', 'content_table_invalid');
    }
    const firstCells = Array.isArray(rows[0].children) ? rows[0].children : [];
    if (firstCells.length === 0 || firstCells.length > 20) {
      throw new WorkbenchError('표의 열 수는 1~20개여야 합니다.', 'content_table_invalid');
    }
    const columnIds = firstCells.map((cell) => context.claim(cell.column_id));
    const relative = node.widthPercent !== undefined;
    if (relative && (!Number.isInteger(node.widthPercent) || node.widthPercent < 1 || node.widthPercent > 100)) {
      throw new WorkbenchError('표 너비는 1~100%로 입력해 주세요.', 'content_table_invalid');
    }
    const columnWidths = Array.isArray(node.colSizes) && node.colSizes.length > 0
      ? columnIds.map((_, index) => {
        const width = node.colSizes[index];
        if (width == null || width === 0) return 120;
        if (typeof width !== 'number' || !Number.isFinite(width)) {
          throw new WorkbenchError('표의 열 너비를 확인해 주세요.', 'content_table_invalid');
        }
        return relative ? Math.max(1, Math.min(10000, Math.round(width))) : Math.max(40, Math.min(1600, Math.round(width)));
      }) : null;
    let columnRatios = null;
    if (relative) {
      const weights = columnWidths || columnIds.map(() => 1);
      const total = weights.reduce((sum, width) => sum + width, 0);
      columnRatios = weights.map(width => Math.max(1, Math.round(width / total * 10000)));
      let remainder = 10000 - columnRatios.reduce((sum, width) => sum + width, 0);
      while (remainder !== 0) {
        for (let index = 0; index < columnRatios.length && remainder !== 0; index++) {
          const step = Math.sign(remainder);
          if (columnRatios[index] + step > 0) { columnRatios[index] += step; remainder -= step; }
        }
      }
    }
    const convertedRows = rows.map((row, rowIndex) => {
      const cells = Array.isArray(row.children) ? row.children : [];
      if (cells.length !== columnIds.length) {
        throw new WorkbenchError('표의 열 수를 맞춰 주세요.', 'content_table_invalid');
      }
      return {
        type: 'tr',
        node_id: context.claim(row.node_id),
        row_id: context.claim(row.row_id),
        children: cells.map((cell, columnIndex) => {
          const cellType = normalizePlateType(cell) === 'th' ? 'th' : 'td';
          return {
            type: cellType,
            node_id: context.claim(cell.node_id),
            column_id: columnIds[columnIndex],
            children: convertBlockChildren(cell.children, context, cellType),
          };
        }),
      };
    });
    return {
      type: 'table',
      node_id: context.claim(node.node_id),
      ...(typeof node.header === 'boolean' ? { header: node.header } : {}),
      ...(relative ? { width_percent: node.widthPercent, column_ratios: columnRatios } : (columnWidths ? { column_widths: columnWidths } : {})),
      children: convertedRows,
    };
  }

  function plateNodeToCanonical(node, context, parentType) {
    if (!isObject(node)) {
      return null;
    }
    if (typeof node.text === 'string') {
      return plateTextToCanonical(node);
    }
    const isQuestion = String(node.type || '') === 'hws_question';

    const type = normalizePlateType(node);
    if (type === 'oj_ai_response') {
      return plateResponseToCanonical(node, context);
    }
    if (type === 'table') {
      return plateTableToCanonical(node, context);
    }
    if (!ROOT_TYPES.includes(type) && !STRUCTURAL_TYPES.includes(type)) {
      throw new WorkbenchError(`지원하지 않는 블록이 있습니다: ${String(node.type || 'unknown')}`, 'block_unsupported');
    }
    // The editor's list toolbar/markdown shortcuts now build classic ul/ol/li
    // block-tree lists (via @platejs/list-classic), which never set
    // listStyleType, so this should no longer fire for anything the editor
    // itself produces. It is kept only as a defensive guard against
    // unexpected pasted/imported content still using the older flat
    // "paragraph + listStyleType" list representation.
    if (node.listStyleType) {
      throw new WorkbenchError('목록은 목록 블록으로 다시 넣어 주세요.', 'list_profile_unsupported');
    }

    const result = {
      type,
      node_id: context.claim(node.node_id || node.question_id),
    };
    if (['p', 'h1', 'h2', 'h3'].includes(type) && ['left', 'center', 'right', 'justify'].includes(node.align)) {
      result.align = node.align;
    }
    if (type === 'ol' && Number.isInteger(node.start) && node.start > 0) {
      result.start = node.start;
    }
    if (type === 'a') {
      const url = String(node.url || node.href || '');
      if (!/^https:\/\//i.test(url)) {
        throw new WorkbenchError('링크 주소는 https로 입력해 주세요.', 'link_invalid');
      }
      result.url = url;
      if (typeof node.label === 'string' && node.label.trim() !== '') {
        result.label = node.label.trim();
      }
    }
    if (type === 'equation') {
      result.tex = String(node.texExpression || node.tex || '').trim();
      result.display = parentType === 'p' || parentType === 'h1' || parentType === 'h2' || parentType === 'h3'
        ? false
        : node.display !== false;
      if (!result.tex) {
        throw new WorkbenchError('수식을 입력해 주세요.', 'equation_invalid');
      }
    }
    if (type === 'code_block') {
      result.language = String(node.language || 'plain_text');
    }
    if (type === 'callout') {
      const toneAliases = { info: 'info', notice: 'notice', success: 'notice', warning: 'warning', danger: 'warning' };
      result.tone = isQuestion ? 'notice' : (toneAliases[node.tone] || 'info');
      if (isQuestion) {
        result.icon = 'idea';
      } else if (['info', 'idea', 'notice', 'warning'].includes(node.icon)) {
        result.icon = node.icon;
      }
    }
    if (['img', 'audio', 'video', 'file', 'oj_ai_drawing'].includes(type)) {
      if (!PUBLIC_ID_PATTERN.test(String(node.asset_id || ''))) {
        throw new WorkbenchError('먼저 파일 업로드를 완료해 주세요.', 'asset_not_staged');
      }
      result.asset_id = node.asset_id;
    }
    if (type === 'img') {
      result.alt = String(node.alt || '').trim();
      if (!result.alt) {
        throw new WorkbenchError('이미지 설명을 입력해 주세요.', 'image_alt_required');
      }
      if (Number.isInteger(node.width)) {
        result.width = node.width;
      }
    }
    if (type === 'audio' || type === 'video') {
      result.transcript = String(node.transcript || '').trim();
      if (!result.transcript) {
        throw new WorkbenchError('미디어 대체 내용을 입력해 주세요.', 'transcript_required');
      }
      if (type === 'video' && PUBLIC_ID_PATTERN.test(String(node.poster_asset_id || ''))) {
        result.poster_asset_id = node.poster_asset_id;
      }
    }
    if (type === 'file') {
      result.label = String(node.label || '').trim();
      if (!result.label) {
        throw new WorkbenchError('파일 이름을 입력해 주세요.', 'file_label_required');
      }
      if (typeof node.description === 'string' && node.description.trim() !== '') {
        result.description = node.description.trim();
      }
    }
    if (type === 'oj_ai_drawing') {
      result.alt = String(node.alt || '').trim();
      result.aspect_ratio = String(node.aspect_ratio || '1.414');
      result.stroke_schema_version = String(node.stroke_schema_version || 'excalidraw_v1');
    }

    if (['file', 'equation', 'hr', 'page_break', 'oj_ai_drawing'].includes(type)) {
      result.children = canonicalEmptyChildren();
    } else if (['img', 'audio', 'video'].includes(type)) {
      result.children = canonicalEmptyChildren();
    } else if (['p', 'h1', 'h2', 'h3', 'a', 'caption', 'code_line'].includes(type)) {
      result.children = convertInlineChildren(node.children, context, type);
    } else if (['blockquote', 'li', 'td', 'th', 'column', 'callout'].includes(type)) {
      result.children = convertBlockChildren(node.children, context, type);
    } else {
      result.children = (Array.isArray(node.children) ? node.children : [])
        .map((child) => plateNodeToCanonical(child, context, type))
        .filter(Boolean);
      if (result.children.length === 0) {
        throw new WorkbenchError('빈 구조 블록을 정리해 주세요.', 'block_empty');
      }
    }

    if (type === 'column_group' && (result.children.length !== 2 || result.children.some((child) => child.type !== 'column'))) {
      throw new WorkbenchError('열 레이아웃은 두 칸으로 구성해 주세요.', 'column_group_invalid');
    }
    return result;
  }

  function legacyQuestionResponse(node) {
    if (!isObject(node) || node.type !== 'hws_question' || node.legacy_flat !== true) return null;
    const responses = [];
    const visit = (candidate) => {
      if (!isObject(candidate)) return;
      if (candidate.type === 'hws_response') responses.push(candidate);
      if (Array.isArray(candidate.children)) candidate.children.forEach(visit);
    };
    visit(node);
    if (responses.length !== 1) return null;
    const heading = (Array.isArray(node.children) ? node.children : []).find((child) => (
      isObject(child) && ['h2', 'h3'].includes(String(child.type || ''))
    ));
    const headingText = (candidate) => (Array.isArray(candidate?.children) ? candidate.children : [])
      .map((child) => typeof child?.text === 'string' ? child.text : headingText(child))
      .join('')
      .trim();
    return headingText(heading) === String(node.legacy_heading || '') ? responses[0] : null;
  }

  function plateSchemaToTeacherDocument(schemaValue, baseDocument, titleValue, localeValue, assessmentBindings, assessmentContext = {}, learningGoals) {
    const schema = Array.isArray(schemaValue) ? { plate_value: schemaValue } : schemaValue;
    if (!isObject(schema) || !Array.isArray(schema.plate_value)) {
      throw new WorkbenchError('편집기 내용을 읽을 수 없습니다.', 'plate_schema_invalid');
    }
    const base = isObject(baseDocument) ? baseDocument : {};
    const title = String(titleValue || schema.title || base.title || '').trim().slice(0, 200);
    if (!title) {
      throw new WorkbenchError('활동지 제목을 입력해 주세요.', 'title_required');
    }

    const context = createIdContext(base.document_id);
    const nodes = schema.plate_value.flatMap((node) => {
      const legacyResponse = legacyQuestionResponse(node);
      if (legacyResponse) {
        return [plateResponseToCanonical(legacyResponse, context)];
      }
      const converted = plateNodeToCanonical(node, context, 'root');
      return converted ? [converted] : [];
    });
    const h1Indexes = [];
    nodes.forEach((node, index) => {
      if (node.type === 'h1') {
        h1Indexes.push(index);
      }
    });
    if (h1Indexes.length === 0) {
      nodes.unshift({
        type: 'h1',
        node_id: context.claim(null),
        children: [{ text: title }],
      });
    } else if (h1Indexes.length > 1) {
      h1Indexes.slice(1).forEach((index) => {
        nodes[index].type = 'h2';
      });
    }
    if (nodes.length === 1) {
      nodes.push({ type: 'p', node_id: context.claim(null), children: canonicalEmptyChildren() });
    }

    const settings = {
      student_navigation: 'document',
      print_profile: 'worksheet_a4@1',
      learning_goals: normalizeLearningGoals(
        learningGoals === undefined ? learningGoalsFromDocument(base) : learningGoals
      ),
    };
    const assessmentCriteriaReady = assessmentContext.criteria_ready === true;
    if (isObject(base.settings?.preparation)) settings.preparation = clone(base.settings.preparation);
	if (isObject(base.settings?.assessment_project)) settings.assessment_project = clone(base.settings.assessment_project);
    const assessmentUnavailable = assessmentContext.unavailable === true;
    const assessmentCriteria = Array.isArray(assessmentContext.criteria) ? assessmentContext.criteria : [];
    if (hasExplicitAssessmentBindings(base) || (assessmentCriteriaReady && !assessmentUnavailable)) {
      settings.assessment_bindings = normalizeAssessmentBindings(assessmentBindings);
    }
    const teacherDocument = {
      schema_id: 'oj_ai.worksheet_document',
      schema_version: '1.0.0',
      editor_profile: 'worksheet_plate@1',
      document_id: context.documentId,
      locale: String(localeValue || base.locale || 'ko_KR'),
      title,
      nodes,
      settings,
    };
    if (hasExplicitAssessmentBindings(teacherDocument)) {
      synchronizeAssessmentBindings(teacherDocument, teacherDocument.settings.assessment_bindings, assessmentCriteria);
    }
    return teacherDocument;
  }

  function applyTemporaryIdMap(value, temporaryIdMap) {
    if (!isObject(temporaryIdMap)) {
      return clone(value);
    }
    if (typeof value === 'string') {
      return Object.prototype.hasOwnProperty.call(temporaryIdMap, value) ? temporaryIdMap[value] : value;
    }
    if (Array.isArray(value)) {
      return value.map((entry) => applyTemporaryIdMap(entry, temporaryIdMap));
    }
    if (isObject(value)) {
      const result = {};
      Object.entries(value).forEach(([key, entry]) => {
        result[key] = applyTemporaryIdMap(entry, temporaryIdMap);
      });
      return result;
    }
    return value;
  }
  function assetNodeFromReceipt(asset) {
    if (!isObject(asset)) {
      throw new WorkbenchError('파일 정보를 읽을 수 없습니다.', 'asset_receipt_invalid');
    }
    const assetId = String(asset.id || '');
    const name = String(asset.original_name || '').trim();
    const mimeType = String(asset.mime_type || '').trim().toLowerCase();
    if (!PUBLIC_ID_PATTERN.test(assetId) || !name || !mimeType) {
      throw new WorkbenchError('파일 정보를 읽을 수 없습니다.', 'asset_receipt_invalid');
    }

    const common = {
      asset_id: assetId,
      children: [{ text: '' }],
      ...(typeof asset.url === 'string' && asset.url ? { url: asset.url } : {}),
    };
    if (mimeType.startsWith('image/')) {
      return { ...common, type: 'img', alt: name };
    }
    if (mimeType.startsWith('audio/')) {
      return { ...common, type: 'audio', transcript: name };
    }
    if (mimeType.startsWith('video/')) {
      return { ...common, type: 'video', transcript: name };
    }
    return { ...common, type: 'file', label: name, name };
  }

  function collectDocumentAssetIds(value, result) {
    const ids = result instanceof Set ? result : new Set();
    if (Array.isArray(value)) {
      value.forEach((item) => collectDocumentAssetIds(item, ids));
      return ids;
    }
    if (!isObject(value)) {
      return ids;
    }
    ['asset_id', 'poster_asset_id'].forEach((key) => {
      if (PUBLIC_ID_PATTERN.test(String(value[key] || ''))) {
        ids.add(String(value[key]));
      }
    });
    Object.values(value).forEach((item) => collectDocumentAssetIds(item, ids));
    return ids;
  }

  function stripAssetPreviewUrls(value) {
    if (Array.isArray(value)) {
      return value.map(stripAssetPreviewUrls);
    }
    if (!isObject(value)) {
      return value;
    }
    const result = {};
    Object.keys(value).forEach((key) => {
      if (key === 'url' && PUBLIC_ID_PATTERN.test(String(value.asset_id || ''))) {
        return;
      }
      result[key] = stripAssetPreviewUrls(value[key]);
    });
    return result;
  }

  function duplicateCommandBody(commandId, expectedState, expectedRevisionId) {
    return {
      command_id: commandId,
      expected: {
        expected_state: expectedState,
        expected_revision_id: expectedRevisionId,
      },
      payload: {},
    };
  }

  function validationCommandBody(commandId, expectedState, expectedRevisionId) {
    return {
      command_id: commandId,
      expected: {
        expected_state: expectedState,
        expected_revision_id: expectedRevisionId,
      },
      payload: {},
    };
  }

  function approvalCommandBody(commandId, revisionId, documentHash, validationHash, decisionHash) {
    return {
      command_id: commandId,
      expected: {
        expected_state: 'draft',
        expected_revision_id: revisionId,
        expected_document_hash: documentHash,
        expected_validation_projection_hash: validationHash,
        expected_approval_decision_hash: decisionHash,
      },
      payload: {},
    };
  }
  function worksheetExportCommandBody(kind, commandId, revisionId, sourceHash, sourceState) {
    if (!Object.prototype.hasOwnProperty.call(EXPORT_KINDS, kind)) {
      throw new WorkbenchError('내보내기 형식을 확인해 주세요.', 'export_kind_invalid');
    }
    const common = {
      command_id: commandId,
      expected: {
        expected_revision_id: revisionId,
        expected_source_hash: sourceHash,
        expected_state: sourceState,
      },
    };
    return kind === 'pdf'
      ? { ...common, payload: { profile: 'student_blank' } }
      : { ...common, payload: { format_version: 1 } };
  }

  function worksheetExportRequestPath(revisionId, kind) {
    if (!PUBLIC_ID_PATTERN.test(String(revisionId || ''))) {
      throw new WorkbenchError('활동지를 다시 불러와 주세요.', 'export_revision_invalid');
    }
    const profile = EXPORT_KINDS[kind];
    if (!profile) {
      throw new WorkbenchError('내보내기 형식을 확인해 주세요.', 'export_kind_invalid');
    }
    return `/worksheet-revisions/${encodeURIComponent(revisionId)}/commands/${profile.action}`;
  }

  function exportDownloadName(kind, revisionId) {
    const profile = EXPORT_KINDS[kind];
    if (!profile || !PUBLIC_ID_PATTERN.test(String(revisionId || ''))) {
      throw new WorkbenchError('파일 이름을 만들 수 없습니다.', 'export_name_invalid');
    }
    return `worksheet-${String(revisionId).toLowerCase()}${profile.suffix}`;
  }

  function publicationCommandBody(commandId, revisionId, documentHash, decisionHash) {
    return {
      command_id: commandId,
      expected: {
        expected_state: 'approved',
        expected_revision_id: revisionId,
        expected_document_hash: documentHash,
        expected_approval_decision_hash: decisionHash,
      },
      payload: {},
    };
  }

  function lifecycleCommandBody(commandId, expectedState, revisionId, lockVersion) {
    return {
      command_id: commandId,
      expected: {
        expected_state: expectedState,
        expected_revision_id: revisionId,
        expected_lock_version: lockVersion,
      },
      payload: {},
    };
  }

  function previewRequestPath(revisionId, kind) {
    if (!PUBLIC_ID_PATTERN.test(String(revisionId || ''))) {
      throw new WorkbenchError('활동지를 다시 불러와 주세요.', 'preview_revision_invalid');
    }
    if (kind === 'student') {
      return `/worksheet-revisions/${encodeURIComponent(revisionId)}/preview?projection=student`;
    }
    if (kind === 'print') {
      return `/worksheet-revisions/${encodeURIComponent(revisionId)}/preview?projection=print&profile=student_blank`;
    }
    throw new WorkbenchError('미리보기 종류를 확인해 주세요.', 'preview_kind_invalid');
  }

  function validationSummary(validation) {
    if (!isObject(validation)) {
      throw new WorkbenchError('검증 결과를 읽을 수 없습니다.', 'validation_result_invalid');
    }
    if (validation.release_ready === true && validation.status === 'ready') {
      return {
        ready: true,
        title: '검증 완료',
        message: '내용을 확인했습니다.',
        tone: 'ready',
      };
    }
    const blockerMessages = {
      worksheet_asset_not_ready: '첨부 파일을 확인해 주세요.',
      worksheet_asset_missing: '첨부 파일을 다시 추가해 주세요.',
      worksheet_projection_invalid: '미리보기를 다시 확인해 주세요.',
      worksheet_renderer_parity_failed: '미리보기 내용을 확인해 주세요.',
      worksheet_private_projection_leak: '공개할 수 없는 정보가 있습니다.',
    };
    const blockers = Array.isArray(validation.blockers) ? validation.blockers : [];
    const message = blockers
      .map((blocker) => blockerMessages[String(blocker?.code || '')])
      .find(Boolean) || '활동지 내용을 확인해 주세요.';
    return {
      ready: false,
      title: '확인 필요',
      message,
      tone: 'blocked',
    };
  }


  window.OJAIWorksheetAuthoringWorkbench = Object.freeze({
    RESPONSE_KINDS: Object.freeze([...RESPONSE_KINDS]),
    WorkbenchError,
    approvalCommandBody,
    applyTemporaryIdMap,
    createTemporaryId,
    worksheetExportCommandBody,
    worksheetExportRequestPath,
    exportDownloadName,
    plateSchemaToTeacherDocument,
    assetNodeFromReceipt,
    collectDocumentAssetIds,
    duplicateCommandBody,
    lifecycleCommandBody,
    previewRequestPath,
    publicationCommandBody,
    stripAssetPreviewUrls,
    teacherDocumentToPlateSchema,
    validationCommandBody,
    validationSummary,
  });

  const root = document.getElementById('oj-ai-worksheet-authoring-app');
  if (!root) {
    return;
  }
  const entryContent = root.closest('[data-worksheet-entry-content]');
  const entryStatus = entryContent?.previousElementSibling?.matches('[data-worksheet-entry]')
    ? entryContent.previousElementSibling : null;

  const learningGoalLimit = Number.parseInt(root.dataset.maxLearningGoals || '', 10);
  const learningGoalCharacterLimit = Number.parseInt(root.dataset.maxLearningGoalCharacters || '', 10);
  const catalogPageLimit = Number.parseInt(root.dataset.catalogPageLimit || '', 10);
  if (!Number.isInteger(learningGoalLimit) || learningGoalLimit < 1
    || !Number.isInteger(learningGoalCharacterLimit) || learningGoalCharacterLimit < 1
    || !Number.isInteger(catalogPageLimit) || catalogPageLimit < 1) {
    (entryStatus?.querySelector('[data-worksheet-entry-message]') || root).textContent = '활동지 편집 설정을 불러오지 못했습니다.';
    return;
  }

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

  const state = {
    approval: null,
    curriculums: [],
    selectedCurriculumId: '',
    learningGoals: [],
    achievementLinks: [],
    achievementSnapshots: [],
    achievementStandards: [],
    achievementUnavailable: false,
    assessmentCriteria: [],
    assessmentBindings: [],
    assessmentCriteriaReady: false,
    assessmentUnavailable: false,
    activeInspectorTab: 'question',
    workflowEnabled: root.hasAttribute('data-workflow-stage'),
    preparation: { state: 'draft', rubric_revision_id: null, rubric_content_hash: null },
    preparationBaseline: '',
    rubricPin: null,
    initialWorkflowResolved: false,
    currentPublicationId: '',
    canManage: root.dataset.canManage === '1',
    canExport: root.dataset.canExport === '1',
    assessmentsUrl: root.dataset.assessmentsUrl || '/oj-console/addons/oj-ai-dashboard/assessments',
    conflict: null,
    currentDocument: null,
    currentDocumentHash: '',
    currentLockVersion: 0,
    currentRevisionId: '',
    currentRevisionState: 'draft',
    exportBusy: false,
    exportPollToken: 0,
    actionBusy: false,
    currentState: 'draft',
    editUrlTemplate: root.dataset.editUrlTemplate || '',
    loaded: false,
    editorBound: false,
    entryBusy: false,
    listNavigationSequence: 0,
    listItems: [],
    latestPublicationId: '',
    locale: root.dataset.locale || 'ko_KR',
    timeZone: resolveTimeZone(root.dataset.timezone),
    recoveryTimer: null,
    remoteFingerprint: '',
    restNonce: root.dataset.restNonce || '',
    restRoot: String(root.dataset.restRoot || '').replace(/\/$/, ''),
    previewUrls: new Set(),
    projectionPreviewUrls: new Set(),
    previewKind: 'student',
    selectedQuestionId: '',
    suppressRecovery: false,
    validation: null,
    pendingReviewRevisionId: '',
    view: root.dataset.view || 'list',
    worksheetId: root.dataset.worksheetId || '',
    stagedAssets: new Map(),
  };

  function readProblemMessage(payload, status) {
    if (isObject(payload) && typeof payload.message === 'string' && payload.message.trim() !== '') {
      return payload.message;
    }
    const messages = {
      403: '이 작업을 할 수 없습니다.',
      404: '활동지를 찾을 수 없습니다.',
      409: '다른 변경이 먼저 저장되었습니다.',
      422: '입력한 내용을 점검해 주세요.',
      503: '잠시 후 다시 시도해 주세요.',
    };
    return messages[status] || '요청을 완료할 수 없습니다.';
  }

  function commandToken(prefix) {
    return `${prefix}-${randomUuid()}`;
  }

  async function apiRequest(path, options) {
    const settings = options || {};
    const headers = { Accept: 'application/json' };
    const isMultipart = typeof window.FormData === 'function' && settings.body instanceof window.FormData;
    if (state.restNonce) {
      headers['X-WP-Nonce'] = state.restNonce;
    }
    if (settings.body !== undefined && !isMultipart) {
      headers['Content-Type'] = 'application/json';
    }
    if (settings.idempotencyKey) {
      headers['Idempotency-Key'] = settings.idempotencyKey;
    }

    const response = await window.fetch(`${state.restRoot}${path}`, {
      method: settings.method || 'GET',
      credentials: 'same-origin',
      headers,
      ...(settings.body !== undefined ? { body: isMultipart ? settings.body : JSON.stringify(settings.body) } : {}),
    });
    let payload = {};
    try {
      payload = await response.json();
    } catch (error) {
      payload = {};
    }
    if (!response.ok) {
      throw new ApiError(response.status, payload);
    }
    if (!isObject(payload) || !isObject(payload.data)) {
      throw new ApiError(503, {});
    }
    return payload;
  }

  async function loadStudentAssessmentCriteria() {
    let criteria = [];
    let unavailable = false;
    state.rubricPin = null;
    state.assessmentProject = null;
    state.latestAssessmentProject = null;
    state.workspaceProject = null;
    try {
      const pin = state.currentDocument?.settings?.assessment_project;
      const membership = await apiRequest(`/worksheets/${encodeURIComponent(state.worksheetId)}/assessment-project`);
      state.workspaceProject = membership.data?.project || null;
      if (state.workspaceProject) window.OJAIWorkflow?.setSelection({projectId:state.workspaceProject.project_id,projectTitle:state.workspaceProject.title});
      if (pin) {
        const project = state.workspaceProject;
        if (!project || project.project_id !== pin.project_id || project.revision_id !== pin.revision_id || project.content_hash !== pin.content_hash || project.state !== 'confirmed') throw Error('평가 기준 연결을 확인해 주세요.');
        state.assessmentProject = project;
        state.rubricPin = { rubric_revision_id:project.revision_id, rubric_content_hash:project.rubric_content_hash };
        criteria = clone(project.configuration.criteria);
        if (project.current_revision_id && project.current_revision_id !== project.revision_id) {
          try {
            const latest = await apiRequest(`/assessment-projects/${encodeURIComponent(project.project_id)}`);
            if (latest.data?.project?.project_id === project.project_id) state.latestAssessmentProject = latest.data.project;
          } catch (_error) { /* Keep the saved criteria usable when the latest lookup fails. */ }
        }
        window.OJAIWorkflow?.setSelection({projectId:project.project_id,projectTitle:project.title});
      } else {
      if (state.workspaceProject) {
        const latest = await apiRequest(`/assessment-projects/${encodeURIComponent(state.workspaceProject.project_id)}`);
        state.latestAssessmentProject = latest.data.project;
      }
      const [policyEnvelope, rubricEnvelope] = await Promise.all([
        apiRequest('/settings/policies'),
        apiRequest('/settings/rubrics'),
      ]);
      const policySubjects = Array.isArray(policyEnvelope.data?.evaluation_policies?.subjects)
        ? policyEnvelope.data.evaluation_policies.subjects : [];
      const policySubject = policySubjects.find((subject) => subject.subject_type === 'student_submission') || null;
      const policies = Array.isArray(policySubject?.revisions) ? policySubject.revisions : [];
      const activePolicy = policies.find((revision) => revision.revision_id === policySubject?.active_revision_id) || null;
      const rubricRevisionId = String(state.currentDocument?.settings?.preparation?.rubric_revision_id || activePolicy?.value?.rubric_revision_id || '');
      const rubricSubjects = Array.isArray(rubricEnvelope.data?.rubrics?.subjects) ? rubricEnvelope.data.rubrics.subjects : [];
      const rubricSubject = rubricSubjects.find((subject) => subject.subject_type === 'student_submission') || null;
      const rubrics = Array.isArray(rubricSubject?.revisions) ? rubricSubject.revisions : [];
      const active = rubrics.find((revision) => revision.revision_id === rubricRevisionId) || null;
      if (PUBLIC_ID_PATTERN.test(rubricRevisionId) && /^[a-f0-9]{64}$/.test(String(active?.content_hash || ''))) {
        state.rubricPin = { rubric_revision_id: rubricRevisionId, rubric_content_hash: active.content_hash };
      }
      criteria = Array.isArray(active?.value?.criteria)
        ? active.value.criteria
          .filter((criterion) => (
            typeof criterion?.criterion_id === 'string'
            && typeof criterion?.label === 'string'
            && ['response', 'submission'].includes(criterion?.scope)
          ))
          .map((criterion) => ({
            criterion_id: criterion.criterion_id,
            label: criterion.label,
            description: String(criterion.description || ''),
            levels: Array.isArray(criterion.levels) ? clone(criterion.levels) : [],
            maximum_points: String(criterion.maximum_points || '0'),
            scope: criterion.scope,
          }))
        : [];
      }
    } catch (_error) {
      unavailable = true;
    }
    state.assessmentCriteriaReady = true;
    state.assessmentUnavailable = unavailable;
    state.assessmentCriteria = criteria;
    if (state.currentDocument && !hasExplicitAssessmentBindings(state.currentDocument)) {
      state.assessmentBindings = legacyAssessmentBindings(state.currentDocument, criteria);
    }
    renderAssessmentMap();
    renderAchievementLinks();
    refreshPreparation();
    renderProjectCriteriaStatus();
    window.ojAiDashboardStudentRubricCriteria = criteria;
    window.dispatchEvent(new CustomEvent('ojai-worksheet-rubric-change', {
      detail: { criteria, unavailable },
    }));
  }

  function normalizeAchievementLinks(value) {
    const result = [];
    const seen = new Set();
    (Array.isArray(value) ? value : []).forEach((link) => {
      if (!isObject(link)) return;
      const scope = String(link.scope || '');
      const responseId = scope === 'response' ? String(link.response_id || '') : null;
      const standardId = String(link.standard_public_id || '');
      const alignmentType = String(link.alignment_type || '');
      if (
        !['worksheet', 'response'].includes(scope)
        || (scope === 'response' && !isDocumentId(responseId))
        || !PUBLIC_ID_PATTERN.test(standardId)
        || !['primary', 'supporting'].includes(alignmentType)
      ) return;
      const key = [scope, responseId || '', standardId, alignmentType].join('|');
      if (seen.has(key)) return;
      seen.add(key);
      result.push({
        scope,
        response_id: responseId,
        standard_public_id: standardId,
        alignment_type: alignmentType,
      });
    });
    return result;
  }

  function normalizeLearningGoals(value) {
    const result = [];
    const seen = new Set();
    (Array.isArray(value) ? value : []).forEach((goal) => {
      if (!isObject(goal)) return;
      const goalId = String(goal.goal_id || '');
      const text = String(goal.text || '');
      const scope = String(goal.scope || '');
      const responseId = scope === 'response' ? String(goal.response_id || '') : null;
      if (
        !isDocumentId(goalId)
        || seen.has(goalId)
        || !['worksheet', 'response'].includes(scope)
        || (scope === 'response' && !isDocumentId(responseId))
      ) return;
      seen.add(goalId);
      result.push({ goal_id: goalId, text, scope, response_id: responseId });
    });
    return result;
  }

  function learningGoalsFromDocument(documentValue) {
    const settings = isObject(documentValue?.settings) ? documentValue.settings : {};
    return normalizeLearningGoals(settings.learning_goals);
  }

  function normalizeAssessmentBindings(value) {
    const result = [];
    const seen = new Set();
    (Array.isArray(value) ? value : []).forEach((binding) => {
      if (!isObject(binding)) return;
      const criterionId = String(binding.criterion_id || '');
      const scope = String(binding.scope || '');
      const responseId = scope === 'response' ? String(binding.response_id || '') : null;
      if (
        !PUBLIC_ID_PATTERN.test(criterionId)
        || !['worksheet', 'response'].includes(scope)
        || (scope === 'response' && !isDocumentId(responseId))
        || seen.has(criterionId)
      ) return;
      seen.add(criterionId);
      result.push({ criterion_id: criterionId, scope, response_id: responseId });
    });
    return result;
  }

  function legacyAssessmentBindings(documentValue, criteria = []) {
    const result = [];
    const seen = new Set();
    const visit = (node) => {
      if (!isObject(node)) return;
      if (node.type === 'oj_ai_response' && isDocumentId(String(node.response_id || ''))) {
        const ids = Array.isArray(node.grading?.rubric_criterion_ids) ? node.grading.rubric_criterion_ids : [];
        ids.forEach((criterionId) => {
          const id = String(criterionId || '');
          if (!PUBLIC_ID_PATTERN.test(id) || seen.has(id)) return;
          seen.add(id);
          result.push({ criterion_id: id, scope: 'response', response_id: String(node.response_id) });
        });
      }
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    (Array.isArray(documentValue?.nodes) ? documentValue.nodes : []).forEach(visit);
    (Array.isArray(criteria) ? criteria : []).forEach((criterion) => {
      const criterionId = String(criterion?.criterion_id || '');
      if (criterion?.scope !== 'submission' || !PUBLIC_ID_PATTERN.test(criterionId) || seen.has(criterionId)) return;
      seen.add(criterionId);
      result.push({ criterion_id: criterionId, scope: 'worksheet', response_id: null });
    });
    return result;
  }

  function hasExplicitAssessmentBindings(documentValue) {
    const settings = isObject(documentValue?.settings) ? documentValue.settings : {};
    return Object.prototype.hasOwnProperty.call(settings, 'assessment_bindings');
  }

  function assessmentBindingsFromDocument(documentValue, criteria = []) {
    const settings = isObject(documentValue?.settings) ? documentValue.settings : {};
    return hasExplicitAssessmentBindings(documentValue)
      ? normalizeAssessmentBindings(settings.assessment_bindings)
      : legacyAssessmentBindings(documentValue, criteria);
  }

  function assessmentDecimalUnits(value) {
    const match = String(value || '').match(/^(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/);
    if (!match) return 0;
    const parts = String(value).split('.', 2);
    return (Number(parts[0]) * 10000) + Number(String(parts[1] || '').padEnd(4, '0'));
  }

  function assessmentDecimalString(units) {
    const whole = Math.floor(units / 10000);
    const fraction = String(units % 10000).padStart(4, '0').replace(/0+$/, '');
    return fraction ? `${whole}.${fraction}` : String(whole);
  }

  function synchronizeAssessmentBindings(documentValue, bindings, criteria) {
    if (!isObject(documentValue)) return;
    const normalized = normalizeAssessmentBindings(bindings);
    if (Array.isArray(documentValue.nodes)) {
      documentValue.settings = isObject(documentValue.settings) ? documentValue.settings : {};
      documentValue.settings.assessment_bindings = normalized;
    }
    const criterionLookup = new Map((Array.isArray(criteria) ? criteria : []).map((criterion) => [criterion.criterion_id, criterion]));
    const byResponse = new Map();
    normalized.forEach((binding) => {
      if (binding.scope !== 'response') return;
      const ids = byResponse.get(binding.response_id) || [];
      ids.push(binding.criterion_id);
      byResponse.set(binding.response_id, ids);
    });
    const visit = (node) => {
      if (!isObject(node)) return;
      if (['oj_ai_response', 'response_field', 'hws_response'].includes(String(node.type || ''))) {
        const ids = byResponse.get(String(node.response_id || '')) || [];
        const hadRubric = Array.isArray(node.grading?.rubric_criterion_ids);
        if (ids.length || hadRubric) {
          const grading = isObject(node.grading) ? clone(node.grading) : {};
          grading.rubric_criterion_ids = ids;
          if (ids.length) {
            grading.grading_enabled = true;
            if (ids.every((id) => criterionLookup.has(id))) {
              grading.max_points = assessmentDecimalString(ids.reduce((total, id) => (
                total + assessmentDecimalUnits(criterionLookup.get(id)?.maximum_points)
              ), 0));
            } else if (!Object.prototype.hasOwnProperty.call(grading, 'max_points')) {
              grading.max_points = '0';
            }
          } else {
            grading.grading_enabled = false;
            delete grading.max_points;
          }
          node.grading = grading;
        }
      }
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    const roots = Array.isArray(documentValue.nodes)
      ? documentValue.nodes
      : (Array.isArray(documentValue.plate_value) ? documentValue.plate_value : []);
    roots.forEach(visit);
  }

  function assessmentBindingsFromPlateSchema(schema, currentBindings, criteria) {
    const responseCriteria = new Set((Array.isArray(criteria) ? criteria : [])
      .filter((criterion) => criterion?.scope === 'response')
      .map((criterion) => String(criterion.criterion_id || ''))
      .filter((criterionId) => PUBLIC_ID_PATTERN.test(criterionId)));
    const responses = collectResponseDescriptors(schema);
    const responseIds = new Set(responses.map((response) => response.response_id));
    const bindings = normalizeAssessmentBindings(currentBindings)
      .filter((binding) => binding.scope === 'worksheet' || responseIds.has(binding.response_id));
    const seen = new Set(bindings.map((binding) => binding.criterion_id));
    responses.forEach((response) => {
      const ids = Array.isArray(response.grading?.rubric_criterion_ids)
        ? response.grading.rubric_criterion_ids
        : [];
      ids.forEach((criterionId) => {
        const id = String(criterionId || '');
        if (!responseCriteria.has(id) || seen.has(id)) return;
        seen.add(id);
        bindings.push({ criterion_id: id, scope: 'response', response_id: response.response_id });
      });
    });
    return normalizeAssessmentBindings(bindings);
  }
  function writeAssessmentBindingsToPlate() {
    try {
      const schema = parseBuilderSchema();
      synchronizeAssessmentBindings(schema, state.assessmentBindings, state.assessmentCriteria);
      writePlateSchema(schema, true);
    } catch (_error) {
      // Save validation reports malformed editor state.
    }
  }

  function collectResponseDescriptors(schema) {
    const source = Array.isArray(schema)
      ? schema
      : (isObject(schema) && Array.isArray(schema.plate_value) ? schema.plate_value : []);
    const result = [];
    let questionNumber = 0;
    const isResponse = (node) => isObject(node) && [
      'oj_ai_response', 'response_field', 'hws_response',
    ].includes(String(node.type || ''));
    const responsesIn = (node) => {
      const responses = [];
      const collect = (candidate) => {
        if (!isObject(candidate)) return;
        if (isResponse(candidate)) responses.push(candidate);
        if (Array.isArray(candidate.children)) candidate.children.forEach(collect);
      };
      collect(node);
      return responses;
    };
    const addQuestion = (node) => {
      questionNumber += 1;
      const heading = (Array.isArray(node.children) ? node.children : []).find((child) => (
        isObject(child) && ['h1', 'h2', 'h3', 'p'].includes(String(child.type || '')) && outlineText(child)
      ));
      const questionLabel = outlineText(heading) || `문항 ${questionNumber}`;
      responsesIn(node).forEach((response, responseIndex) => {
        const responseId = String(response.response_id || response.name || response.id || response.node_id || '');
        if (!isDocumentId(responseId)) return;
        result.push({
          response_id: responseId,
          question_id: String(node.question_id || node.node_id || responseId),
          question_number: questionNumber,
          question_label: questionLabel,
          response_number: responseIndex + 1,
          label: String(response.label || `응답 ${responseIndex + 1}`),
          kind: String(response.kind || response.canonical_kind || 'long_text'),
          grading: isObject(response.grading) ? response.grading : {},
        });
      });
    };
    const visit = (node) => {
      if (!isObject(node)) return;
      if (node.type === 'hws_question' || isCanonicalQuestionNode(node)) {
        addQuestion(node);
        return;
      }
      if (isResponse(node)) {
        addQuestion({
          type: 'hws_question',
          question_id: String(node.node_id || node.response_id || ''),
          children: [node],
        });
        return;
      }
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    source.forEach(visit);
    return result;
  }

  function currentResponseDescriptors() {
    try {
      return collectResponseDescriptors(parseBuilderSchema());
    } catch (_error) {
      return [];
    }
  }

  function renderLearningGoals() {
    const target = root.querySelector('[data-role="learning-goals"]');
    const status = root.querySelector('[data-role="learning-goal-status"]');
    if (!target) return;
    target.replaceChildren();
    const responses = currentResponseDescriptors();
    const editable = state.loaded && state.canManage && ['draft', 'active'].includes(state.currentState) && !state.actionBusy;
    const addTarget = root.querySelector('[data-field="learning-goal-add-target"]');
    const addText = root.querySelector('[data-field="learning-goal-text"]');
    if (addTarget) {
      const previous = String(addTarget.value || 'worksheet');
      addTarget.replaceChildren();
      const worksheetOption = document.createElement('option');
      worksheetOption.value = 'worksheet';
      worksheetOption.textContent = '활동지 전체';
      addTarget.append(worksheetOption);
      responses.forEach((response) => {
        const option = document.createElement('option');
        option.value = `response:${response.response_id}`;
        option.textContent = `문항 ${response.question_number} · ${response.label}`;
        addTarget.append(option);
      });
      addTarget.value = [...addTarget.options].some((option) => option.value === previous) ? previous : 'worksheet';
      addTarget.disabled = !editable;
    }
    if (addText) addText.disabled = !editable;
    const addButton = root.querySelector('[data-action="add-learning-goal"]');
    if (addButton) addButton.disabled = !editable || state.learningGoals.length >= learningGoalLimit;
    if (!state.learningGoals.length) {
      const empty = document.createElement('p');
      empty.className = 'oj-ai-workbench__inspector-empty';
      empty.textContent = '입력한 학습 목표가 없습니다.';
      target.append(empty);
    }
    state.learningGoals.forEach((goal, index) => {
      const row = document.createElement('div');
      row.className = 'oj-ai-workbench__learning-goal';
      row.dataset.goalIndex = String(index);
      const heading = document.createElement('div');
      const title = document.createElement('strong');
      const remove = document.createElement('button');
      title.textContent = `목표 ${index + 1}`;
      remove.type = 'button';
      remove.dataset.action = 'remove-learning-goal';
      remove.dataset.goalIndex = String(index);
      remove.textContent = '삭제';
      remove.disabled = !editable;
      heading.append(title, remove);

      const textField = document.createElement('label');
      const textLabel = document.createElement('span');
      const textarea = document.createElement('textarea');
      textLabel.textContent = '목표 문장';
      textarea.rows = 3;
      textarea.maxLength = learningGoalCharacterLimit;
      textarea.value = goal.text;
      textarea.dataset.field = 'learning-goal-edit';
      textarea.dataset.goalIndex = String(index);
      textarea.disabled = !editable;
      textField.append(textLabel, textarea);

      const targetField = document.createElement('label');
      const targetLabel = document.createElement('span');
      const targetSelect = document.createElement('select');
      targetLabel.textContent = '적용 대상';
      targetSelect.dataset.field = 'learning-goal-target';
      targetSelect.dataset.goalIndex = String(index);
      targetSelect.disabled = !editable;
      const worksheetOption = document.createElement('option');
      worksheetOption.value = 'worksheet';
      worksheetOption.textContent = '활동지 전체';
      targetSelect.append(worksheetOption);
      responses.forEach((response) => {
        const option = document.createElement('option');
        option.value = `response:${response.response_id}`;
        option.textContent = `문항 ${response.question_number} · ${response.label}`;
        targetSelect.append(option);
      });
      const selectedTarget = goal.scope === 'response' ? `response:${goal.response_id}` : 'worksheet';
      if (![...targetSelect.options].some((option) => option.value === selectedTarget)) {
        const missing = document.createElement('option');
        missing.value = selectedTarget;
        missing.textContent = '삭제된 문항';
        targetSelect.append(missing);
      }
      targetSelect.value = selectedTarget;
      targetField.append(targetLabel, targetSelect);
      row.append(heading, textField, targetField);
      target.append(row);
    });
    if (status) status.textContent = `${state.learningGoals.length}/${learningGoalLimit}개`;
  }

  function addLearningGoal() {
    if (!state.loaded || !state.canManage || !['draft', 'active'].includes(state.currentState) || state.actionBusy) return;
    const textField = root.querySelector('[data-field="learning-goal-text"]');
    const targetField = root.querySelector('[data-field="learning-goal-add-target"]');
    const status = root.querySelector('[data-role="learning-goal-status"]');
    const text = String(textField?.value || '').trim();
    if (!text) {
      if (status) status.textContent = '목표 문장을 입력해 주세요.';
      textField?.focus();
      return;
    }
    if (state.learningGoals.length >= learningGoalLimit) {
      if (status) status.textContent = `학습 목표는 ${learningGoalLimit}개까지 입력할 수 있습니다.`;
      return;
    }
    const targetValue = String(targetField?.value || 'worksheet');
    const scope = targetValue.startsWith('response:') ? 'response' : 'worksheet';
    const responseId = scope === 'response' ? targetValue.slice(9) : null;
    if (scope === 'response' && !currentResponseDescriptors().some((response) => response.response_id === responseId)) return;
    state.learningGoals.push({
      goal_id: createTemporaryId(),
      text: text.slice(0, learningGoalCharacterLimit),
      scope,
      response_id: responseId,
    });
    if (textField) textField.value = '';
    renderLearningGoals();
    scheduleRecovery();
  }

  function standardRecord(standardId) {
    const active = state.achievementStandards.find((item) => item.standard_public_id === standardId);
    if (active) return active;
    const snapshot = state.achievementSnapshots.find((item) => item.standard_public_id === standardId);
    return snapshot ? {
      standard_public_id: standardId,
      code: snapshot.standard_code_snapshot || '',
      title: snapshot.standard_label_snapshot || '성취기준',
      description: '',
      grade_label: '',
    } : {
      standard_public_id: standardId,
      code: '',
      title: '성취기준',
      description: '',
      grade_label: '',
    };
  }

  function standardTitle(standard) {
    const code = String(standard?.code || standard?.standard_key || '').trim();
    const title = String(standard?.title || '').trim();
    return [code, title].filter(Boolean).join(' · ') || '성취기준';
  }

  function renderAchievementStandards() {
    const target = root.querySelector('[data-role="achievement-standards"]');
    if (!target) return;
    target.replaceChildren();
    const editable = state.loaded && state.canManage && ['draft', 'active'].includes(state.currentState) && !state.actionBusy;
    const search = String(root.querySelector('[data-field="achievement-search"]')?.value || '').trim().toLocaleLowerCase();
    const rows = state.achievementStandards.filter((standard) => (
      !search || [
        standard.code, standard.standard_key, standard.title, standard.description, standard.grade_label,
      ].some((value) => String(value || '').toLocaleLowerCase().includes(search))
    ));
    if (!rows.length) {
      const empty = document.createElement('p');
      empty.className = 'oj-ai-workbench__inspector-empty';
      empty.textContent = state.achievementUnavailable
        ? '성취기준 목록을 불러오지 못했습니다.'
        : (search ? '검색 결과가 없습니다.' : '등록된 성취기준이 없습니다.');
      target.append(empty);
      return;
    }
    rows.forEach((standard) => {
      const row = document.createElement('div');
      row.className = 'oj-ai-workbench__standard-row';
      const text = document.createElement('span');
      const title = document.createElement('strong');
      const meta = document.createElement('small');
      title.textContent = standardTitle(standard);
      meta.textContent = String(standard.grade_label || standard.curriculum_title || '');
      text.append(title, meta);
      const add = document.createElement('button');
      add.type = 'button';
      add.dataset.action = 'add-achievement-link';
      add.dataset.standardId = String(standard.standard_public_id || '');
      add.textContent = '연결';
      add.disabled = !editable || !PUBLIC_ID_PATTERN.test(add.dataset.standardId);
      row.append(text, add);
      target.append(row);
    });
  }

  function renderAchievementLinks() {
    const target = root.querySelector('[data-role="achievement-links"]');
    const status = root.querySelector('[data-role="achievement-status"]');
    if (!target) return;
    target.replaceChildren();
    root.querySelectorAll('[data-project-hide="standards"]').forEach(control=>{control.hidden=Boolean(state.assessmentProject);});
    if (state.assessmentProject) {
      for (const standard of state.assessmentProject.configuration.achievement_standards) {
        const line=document.createElement('p');line.textContent=[standard.code,standard.text].filter(Boolean).join(' · ');target.append(line);
      }
      if(status)status.textContent=`평가 기준 ${state.assessmentProject.revision_no}차`;
      return;
    }
    const editable = state.loaded && state.canManage && ['draft', 'active'].includes(state.currentState) && !state.actionBusy;
    const responses = currentResponseDescriptors();
    const addTarget = root.querySelector('[data-field="achievement-add-target"]');
    if (addTarget) {
      const previous = String(addTarget.value || 'worksheet');
      addTarget.replaceChildren();
      const worksheetOption = document.createElement('option');
      worksheetOption.value = 'worksheet';
      worksheetOption.textContent = '활동지 전체';
      addTarget.append(worksheetOption);
      responses.forEach((response) => {
        const option = document.createElement('option');
        option.value = `response:${response.response_id}`;
        option.textContent = `문항 ${response.question_number} · ${response.label}`;
        addTarget.append(option);
      });
      addTarget.value = [...addTarget.options].some((option) => option.value === previous) ? previous : 'worksheet';
      addTarget.disabled = !editable;
    }
    if (!state.achievementLinks.length) {
      const empty = document.createElement('p');
      empty.className = 'oj-ai-workbench__inspector-empty';
      empty.textContent = '연결된 목표가 없습니다.';
      target.append(empty);
    }
    state.achievementLinks.forEach((link, index) => {
      const standard = standardRecord(link.standard_public_id);
      const row = document.createElement('div');
      row.className = 'oj-ai-workbench__achievement-link';
      row.dataset.linkIndex = String(index);
      const heading = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = standardTitle(standard);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.dataset.action = 'remove-achievement-link';
      remove.dataset.linkIndex = String(index);
      remove.textContent = '삭제';
      remove.disabled = !editable;
      heading.append(title, remove);

      const targetField = document.createElement('label');
      const targetLabel = document.createElement('span');
      const targetSelect = document.createElement('select');
      targetLabel.textContent = '적용 대상';
      targetSelect.dataset.field = 'achievement-target';
      targetSelect.dataset.linkIndex = String(index);
      targetSelect.disabled = !editable;
      const worksheetOption = document.createElement('option');
      worksheetOption.value = 'worksheet';
      worksheetOption.textContent = '활동지 전체';
      targetSelect.append(worksheetOption);
      responses.forEach((response) => {
        const option = document.createElement('option');
        option.value = `response:${response.response_id}`;
        option.textContent = `문항 ${response.question_number} · ${response.label}`;
        targetSelect.append(option);
      });
      const selectedTarget = link.scope === 'response' ? `response:${link.response_id}` : 'worksheet';
      if (![...targetSelect.options].some((option) => option.value === selectedTarget)) {
        const missing = document.createElement('option');
        missing.value = selectedTarget;
        missing.textContent = '삭제된 응답';
        targetSelect.append(missing);
      }
      targetSelect.value = selectedTarget;
      targetField.append(targetLabel, targetSelect);

      const alignmentField = document.createElement('label');
      const alignmentLabel = document.createElement('span');
      const alignmentSelect = document.createElement('select');
      alignmentLabel.textContent = '연결 수준';
      alignmentSelect.dataset.field = 'achievement-alignment';
      alignmentSelect.dataset.linkIndex = String(index);
      alignmentSelect.disabled = !editable;
      [['primary', '핵심'], ['supporting', '보조']].forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        option.selected = value === link.alignment_type;
        alignmentSelect.append(option);
      });
      alignmentField.append(alignmentLabel, alignmentSelect);
      row.append(heading, targetField, alignmentField);
      target.append(row);
    });
    if (status) {
      status.textContent = state.achievementUnavailable
        ? `${state.achievementLinks.length}개 연결 · 목록 확인 필요`
        : `${state.achievementLinks.length}개 연결`;
    }
  }

  function renderAssessmentMap() {
    const target = root.querySelector('[data-role="assessment-map"]');
    const summary = root.querySelector('[data-role="assessment-map-summary"]');
    if (!target || !summary) return;
    target.replaceChildren();
    const responses = currentResponseDescriptors();
    const criteria = state.assessmentCriteria;
    const bindings = normalizeAssessmentBindings(state.assessmentBindings);
    const bindingLookup = new Map(bindings.map((binding) => [binding.criterion_id, binding]));
    const criterionLookup = new Map(criteria.map((criterion) => [criterion.criterion_id, criterion]));
    const editable = state.loaded && state.canManage && ['draft', 'active'].includes(state.currentState) && !state.actionBusy;

    if (!criteria.length && !bindings.length) {
      summary.textContent = state.assessmentUnavailable
        ? '평가 기준을 불러오지 못했습니다.'
        : '사용 중인 학생 평가 기준이 없습니다.';
      return;
    }

    const renderRow = (criterion, binding, stale) => {
      const row = document.createElement('section');
      row.className = 'oj-ai-workbench__assessment-row';
      if (stale) row.dataset.tone = 'warning';
      const heading = document.createElement('div');
      const title = document.createElement('strong');
      const points = document.createElement('span');
      const toggle = document.createElement('label');
      const checkbox = document.createElement('input');
      title.textContent = stale ? '현재 목록에 없는 평가 요소' : criterion.label;
      points.textContent = stale ? '정리 필요' : `${criterion.maximum_points}점`;
      checkbox.type = 'checkbox';
      checkbox.checked = Boolean(binding);
      checkbox.disabled = !editable;
      checkbox.dataset.field = 'assessment-enabled';
      checkbox.dataset.criterionId = criterion.criterion_id;
      toggle.className = 'oj-ai-workbench__assessment-toggle';
      toggle.append(checkbox, document.createTextNode('사용'));
      heading.append(title, points, toggle);
      row.append(heading);
      if (!stale && criterion.description) {
        const description = document.createElement('p');
        description.textContent = criterion.description;
        row.append(description);
      }

      const targetField = document.createElement('label');
      const targetLabel = document.createElement('span');
      const targetSelect = document.createElement('select');
      targetField.className = 'oj-ai-workbench__assessment-target';
      targetLabel.textContent = '적용 대상';
      targetSelect.dataset.field = 'assessment-target';
      targetSelect.dataset.criterionId = criterion.criterion_id;
      targetSelect.disabled = !editable || !binding;
      const worksheetOption = document.createElement('option');
      worksheetOption.value = 'worksheet';
      worksheetOption.textContent = '활동지 전체';
      targetSelect.append(worksheetOption);
      responses.forEach((response) => {
        const option = document.createElement('option');
        option.value = `response:${response.response_id}`;
        option.textContent = `문항 ${response.question_number} · ${response.label}`;
        targetSelect.append(option);
      });
      const selectedTarget = binding?.scope === 'response' ? `response:${binding.response_id}` : 'worksheet';
      if (binding && ![...targetSelect.options].some((option) => option.value === selectedTarget)) {
        const missing = document.createElement('option');
        missing.value = selectedTarget;
        missing.textContent = '삭제된 문항';
        targetSelect.append(missing);
      }
      targetSelect.value = selectedTarget;
      targetField.append(targetLabel, targetSelect);
      row.append(targetField);

      const status = document.createElement('small');
      status.textContent = !binding
        ? '이 활동지에서는 사용하지 않음'
        : (binding.scope === 'worksheet' ? '활동지 전체 평가' : '선택한 문항 응답 평가');
      if (stale || (binding?.scope === 'response' && !responses.some((response) => response.response_id === binding.response_id))) {
        status.dataset.tone = 'warning';
      } else if (binding) {
        status.dataset.tone = 'ready';
      }
      row.append(status);
      target.append(row);
    };

    criteria.forEach((criterion) => renderRow(criterion, bindingLookup.get(criterion.criterion_id) || null, false));
    bindings.filter((binding) => !criterionLookup.has(binding.criterion_id)).forEach((binding) => renderRow({
      criterion_id: binding.criterion_id,
      label: '',
      description: '',
      maximum_points: '0',
      scope: binding.scope === 'worksheet' ? 'submission' : 'response',
    }, binding, true));
    summary.textContent = `${state.assessmentProject ? `적용 기준 ${state.assessmentProject.revision_no}차 · ` : ''}${bindings.filter((binding) => criterionLookup.has(binding.criterion_id)).length}/${criteria.length}개 사용`;
  }

  function renderProjectCriteriaStatus() {
    const panel = root.querySelector('[data-role="project-criteria-status"]');
    if (!panel) return;
    const current = state.assessmentProject || state.workspaceProject;
    const latest = state.latestAssessmentProject;
    panel.hidden = !current;
    if (!current) return;
    const unapplied = !state.assessmentProject;
    const changed = unapplied || (current.current_revision_id && current.current_revision_id !== current.revision_id);
    const published = Boolean(latest?.worksheets?.find(item => item.worksheet_id === state.worksheetId)?.publication_id);
    let text = unapplied ? '기존 활동지 기준 유지 · 프로젝트 기준 적용 전' : `이 활동지 적용 기준 ${current.revision_no}차`;
    if (changed) text += latest ? ` · 평가의 최신 기준 ${latest.revision_no}차${latest.state === 'confirmed' ? ' 확정' : ' 초안'}` : ' · 최신 기준 확인 필요';
    if (changed && published) text += ' · 발행된 자료는 기존 기준 유지';
    panel.classList.toggle('oj-ai-workbench__banner--warning', Boolean(changed));
    panel.querySelector('[data-role="project-criteria-version"]').textContent = text;
    const button = panel.querySelector('[data-action="refresh-project-criteria"]');
    button.hidden = !changed || published || latest?.state !== 'confirmed' || root.dataset.workflowStage !== 'author';
    button.disabled = !state.loaded || !state.canManage || state.currentState !== 'draft' || state.actionBusy || Boolean(state.criteriaRefreshBusy);
  }

  async function refreshProjectCriteria() {
    if (state.actionBusy || state.criteriaRefreshBusy || !(state.assessmentProject || state.workspaceProject)) return;
    if (hasPendingChanges()) { setNotice('작성 중인 내용을 저장한 뒤 최신 기준을 적용해 주세요.', true); return; }
    state.criteriaRefreshBusy = true;
    renderProjectCriteriaStatus();
    try {
      const previous = state.assessmentProject || state.workspaceProject;
      const envelope = await apiRequest(`/assessment-projects/${encodeURIComponent(previous.project_id)}`);
      const latest = envelope.data?.project;
      if (latest?.project_id !== previous.project_id || latest.state !== 'confirmed') throw new WorkbenchError('평가 기준을 먼저 확정해 주세요.');
      if (latest.worksheets?.find(item => item.worksheet_id === state.worksheetId)?.publication_id) throw new WorkbenchError('이미 발행한 활동지는 기존 활동지 복사로 최신 기준에 연결해 주세요.');
      if (hasPendingChanges()) throw new WorkbenchError('작성 중인 내용을 저장한 뒤 최신 기준을 적용해 주세요.');
      const previousGoals = new Set(previous.configuration.learning_goals.map(goal => goal.goal_id));
      const goals = new Map(state.learningGoals.map(goal => [goal.goal_id, goal]));
      const learningGoals = [
        ...latest.configuration.learning_goals.map(goal => ({ ...goal, scope: goals.get(goal.goal_id)?.scope || 'worksheet', response_id: goals.get(goal.goal_id)?.response_id || null })),
        ...state.learningGoals.filter(goal => !previousGoals.has(goal.goal_id)),
      ];
      const previousCriteria = new Set(previous.configuration.criteria.map(criterion => criterion.criterion_id));
      const criteriaIds = new Set(latest.configuration.criteria.map(criterion => criterion.criterion_id));
      const bindings = [
        ...state.assessmentBindings.filter(binding => criteriaIds.has(binding.criterion_id)),
        ...latest.configuration.criteria.filter(criterion => !previousCriteria.has(criterion.criterion_id) || (!state.assessmentProject && !state.assessmentBindings.some(binding => binding.criterion_id === criterion.criterion_id))).map(criterion => ({criterion_id:criterion.criterion_id,scope:'worksheet',response_id:null})),
      ];
      if (await saveEditor({project:latest,learning_goals:learningGoals,assessment_bindings:bindings})) {
        setNotice(`최신 확정 기준 ${latest.revision_no}차를 적용했습니다. 문항별 평가 연결을 확인해 주세요.`, false);
      }
    } catch (error) {
      setNotice(error instanceof Error ? error.message : '최신 기준을 적용하지 못했습니다.', true);
    } finally {
      state.criteriaRefreshBusy = false;
      renderProjectCriteriaStatus();
    }
  }
  let refreshOutlineResize = () => {};

  function bindOutlineResize() {
    const workspace = root.querySelector('.oj-ai-workbench__workspace');
    const outline = workspace?.querySelector('.oj-ai-workbench__outline');
    const canvas = workspace?.querySelector('.oj-ai-workbench__canvas');
    if (!state.workflowEnabled || !outline || !canvas) return;
    const storageKey = 'ojai:worksheet-outline-width:v1';
    const minimum = 210;
    const defaultWidth = 260;
    let preferredWidth = defaultWidth;
    let drag = null;
    try {
      const saved = Number(window.localStorage.getItem(storageKey));
      if (Number.isFinite(saved) && saved >= minimum && saved <= 480) preferredWidth = saved;
    } catch (_error) { /* Panel resizing remains available without browser storage. */ }
    const handle = document.createElement('div');
    handle.className = 'oj-ai-workbench__outline-resizer';
    handle.setAttribute('role', 'separator');
    handle.setAttribute('aria-orientation', 'vertical');
    handle.setAttribute('aria-label', '문항 목록 너비 조절');
    handle.setAttribute('aria-controls', outline.id);
    handle.title = '문항 목록 너비 조절';
    workspace.insertBefore(handle, canvas);
    const maximum = () => Math.max(minimum, Math.min(480, workspace.clientWidth - 428));
    const visible = () => handle.getClientRects().length > 0;
    const applyWidth = (width, persist = false) => {
      const max = maximum();
      const value = Math.round(Math.max(minimum, Math.min(max, width)));
      root.style.setProperty('--oj-ai-outline-width', value + 'px');
      handle.setAttribute('aria-valuemin', String(minimum));
      handle.setAttribute('aria-valuemax', String(max));
      handle.setAttribute('aria-valuenow', String(value));
      handle.setAttribute('aria-valuetext', value + 'px');
      if (persist) {
        preferredWidth = value;
        try { window.localStorage.setItem(storageKey, String(value)); } catch (_error) { /* Optional preference. */ }
      }
      return value;
    };
    const finishDrag = (commit) => {
      if (!drag) return;
      const previous = drag;
      drag = null;
      delete root.dataset.outlineResizing;
      if (handle.hasPointerCapture(previous.pointerId)) handle.releasePointerCapture(previous.pointerId);
      applyWidth(commit ? previous.value : preferredWidth, commit);
    };
    refreshOutlineResize = () => {
      const active = visible();
      handle.tabIndex = active ? 0 : -1;
      if (!active) finishDrag(false);
      // A narrow window must not overwrite the user's preferred desktop width.
      if (!drag) applyWidth(preferredWidth);
    };
    handle.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || !event.isPrimary || !visible()) return;
      event.preventDefault();
      handle.focus({ preventScroll: true });
      const width = outline.getBoundingClientRect().width;
      drag = { pointerId: event.pointerId, startX: event.clientX, width, value: width };
      root.dataset.outlineResizing = 'true';
      handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener('pointermove', (event) => {
      if (!drag || drag.pointerId !== event.pointerId) return;
      if (!visible()) { finishDrag(false); return; }
      drag.value = applyWidth(drag.width + event.clientX - drag.startX);
    });
    handle.addEventListener('pointerup', (event) => {
      if (drag?.pointerId === event.pointerId) finishDrag(true);
    });
    handle.addEventListener('pointercancel', () => finishDrag(false));
    handle.addEventListener('lostpointercapture', () => finishDrag(false));
    handle.addEventListener('dblclick', () => applyWidth(defaultWidth, true));
    handle.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && drag) {
        event.preventDefault(); event.stopPropagation(); finishDrag(false); return;
      }
      if (!visible() || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const current = Number(handle.getAttribute('aria-valuenow'));
      const step = event.shiftKey ? 64 : 16;
      const width = event.key === 'Home' ? minimum : event.key === 'End' ? maximum()
        : current + (event.key === 'ArrowLeft' ? -step : step);
      applyWidth(width, true);
    });
    if (window.ResizeObserver) {
      const observer = new window.ResizeObserver(() => {
        if (!root.isConnected) { finishDrag(false); observer.disconnect(); return; }
        refreshOutlineResize();
      });
      observer.observe(workspace);
    }
    refreshOutlineResize();
  }

  function setAuthorPanel(name, restoreFocus = false) {
    const previous = root.dataset.compactPanel || '';
    root.dataset.compactPanel = ['outline', 'inspector'].includes(name) ? name : '';
    root.querySelectorAll('[data-action="toggle-outline"], [data-action="toggle-inspector"]').forEach((button) => {
      button.setAttribute('aria-expanded', String(button.dataset.action === 'toggle-' + root.dataset.compactPanel));
    });
    refreshOutlineResize();
    if (restoreFocus && previous) root.querySelector(`[data-action="toggle-${previous}"]`)?.focus();
  }

  function activateInspectorTab(name) {
    if (!['question', 'goals', 'assessment'].includes(name)) return;
    state.activeInspectorTab = name;
    root.querySelectorAll('[data-inspector-tab]').forEach((button) => {
      const selected = button.dataset.inspectorTab === name;
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
      button.tabIndex = selected ? 0 : -1;
    });
    root.querySelectorAll('[data-inspector-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.inspectorPanel !== name;
    });
    if (state.workflowEnabled && root.dataset.workflowStage === 'criteria') {
      root.querySelectorAll('[data-inspector-panel]').forEach((panel) => { panel.hidden = panel.dataset.inspectorPanel === 'question'; });
    }
    if (name === 'goals') {
      renderLearningGoals();
      renderAchievementLinks();
      renderAchievementStandards();
    }
    if (name === 'assessment') renderAssessmentMap();
  }

  function addAchievementLink(standardId) {
    if (!state.loaded || !state.canManage || !['draft', 'active'].includes(state.currentState) || state.actionBusy) return;
    if (!PUBLIC_ID_PATTERN.test(standardId)) return;
    const targetValue = String(root.querySelector('[data-field="achievement-add-target"]')?.value || 'worksheet');
    const scope = targetValue.startsWith('response:') ? 'response' : 'worksheet';
    const responseId = scope === 'response' ? targetValue.slice(9) : null;
    if (scope === 'response' && !currentResponseDescriptors().some((response) => response.response_id === responseId)) return;
    const duplicate = state.achievementLinks.some((link) => (
      link.scope === scope
      && link.response_id === responseId
      && link.standard_public_id === standardId
      && link.alignment_type === 'primary'
    ));
    if (duplicate) {
      const status = root.querySelector('[data-role="achievement-status"]');
      if (status) status.textContent = '선택한 대상에 이미 연결되어 있습니다.';
      return;
    }
    state.achievementLinks.push({
      scope,
      response_id: responseId,
      standard_public_id: standardId,
      alignment_type: 'primary',
    });
    renderAchievementLinks();
    scheduleRecovery();
  }
  function renderCurriculums() {
    const select = root.querySelector('[data-field="curriculum-select"]');
    if (!select) return;
    const previous = state.selectedCurriculumId;
    select.replaceChildren();
    if (!state.curriculums.length) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = state.achievementUnavailable ? '불러오기 실패' : '등록된 교육과정 없음';
      select.append(option);
      select.disabled = true;
      state.selectedCurriculumId = '';
      return;
    }
    state.curriculums.forEach((curriculum) => {
      const option = document.createElement('option');
      option.value = curriculum.curriculum_id;
      option.textContent = curriculum.title;
      select.append(option);
    });
    state.selectedCurriculumId = state.curriculums.some((item) => item.curriculum_id === previous)
      ? previous
      : state.curriculums[0].curriculum_id;
    select.value = state.selectedCurriculumId;
    select.disabled = false;
  }

  async function loadCurriculums() {
    state.achievementUnavailable = false;
    try {
      const items = await window.OJAIWorkflow.allPages(apiRequest, `/curriculums?limit=${encodeURIComponent(String(catalogPageLimit))}`);
      state.curriculums = items.filter((item) => (
        isObject(item) && PUBLIC_ID_PATTERN.test(String(item.curriculum_id || '')) && String(item.title || '').trim()
      ));
      renderCurriculums();
      await loadAchievementStandards(state.selectedCurriculumId);
      return;
    } catch (_error) {
      state.achievementUnavailable = true;
      state.curriculums = [];
      state.selectedCurriculumId = '';
      state.achievementStandards = [];
    }
    renderCurriculums();
    renderAchievementLinks();
    renderAchievementStandards();
  }

  async function loadAchievementStandards(curriculumId) {
    state.achievementUnavailable = false;
    if (!PUBLIC_ID_PATTERN.test(String(curriculumId || ''))) {
      state.achievementStandards = [];
      renderAchievementLinks();
      renderAchievementStandards();
      return;
    }
    try {
      const items = await window.OJAIWorkflow.allPages(apiRequest, `/curriculums/${encodeURIComponent(curriculumId)}/standards?limit=${encodeURIComponent(String(catalogPageLimit))}`);
      state.achievementStandards = items.filter((item) => (
        isObject(item) && PUBLIC_ID_PATTERN.test(String(item.standard_public_id || ''))
      ));
    } catch (_error) {
      state.achievementUnavailable = true;
      state.achievementStandards = [];
    }
    renderAchievementLinks();
    renderAchievementStandards();
  }

  function setNotice(message, visible, tone) {
    const notice = root.querySelector('[data-role="notice"]');
    if (!notice) {
      return;
    }
    notice.textContent = message || '';
    notice.dataset.tone = tone || 'neutral';
    notice.hidden = visible === false || !message;
  }

  function responseHeader(response, name) {
    return response && response.headers && typeof response.headers.get === 'function'
      ? String(response.headers.get(name) || '')
      : '';
  }

  async function digestHex(bytes) {
    if (!window.crypto?.subtle || typeof window.crypto.subtle.digest !== 'function') {
      return '';
    }
    const digest = await window.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  async function downloadExportArtifact(artifactId, kind, revisionId) {
    const profile = EXPORT_KINDS[kind];
    if (!profile || !PUBLIC_ID_PATTERN.test(String(artifactId || ''))) {
      throw new WorkbenchError('다운로드 정보를 읽을 수 없습니다.', 'export_artifact_invalid');
    }
    const headers = { Accept: profile.mimeType };
    if (state.restNonce) {
      headers['X-WP-Nonce'] = state.restNonce;
    }
    const response = await window.fetch(
      `${state.restRoot}/artifacts/${encodeURIComponent(artifactId)}/download`,
      { method: 'GET', credentials: 'same-origin', headers }
    );
    if (!response.ok) {
      let payload = {};
      try {
        payload = await response.json();
      } catch (error) {
        payload = {};
      }
      throw new ApiError(response.status, payload);
    }
    const mimeType = responseHeader(response, 'Content-Type').split(';', 1)[0].trim().toLowerCase();
    const artifactKind = responseHeader(response, 'X-OJ-Artifact-Kind');
    const expectedHash = responseHeader(response, 'X-OJ-Content-SHA256').toLowerCase();
    const expectedLength = Number.parseInt(responseHeader(response, 'Content-Length'), 10);
    if (mimeType !== profile.mimeType || artifactKind !== profile.artifactKind || !/^[a-f0-9]{64}$/.test(expectedHash)) {
      throw new WorkbenchError('다운로드 파일을 확인할 수 없습니다.', 'export_response_invalid');
    }
    const bytes = await response.arrayBuffer();
    if (bytes.byteLength < 1 || bytes.byteLength > profile.maxBytes
      || (Number.isSafeInteger(expectedLength) && expectedLength !== bytes.byteLength)) {
      throw new WorkbenchError('다운로드 파일 크기가 올바르지 않습니다.', 'export_size_invalid');
    }
    const actualHash = await digestHex(bytes);
    if (actualHash && actualHash !== expectedHash) {
      throw new WorkbenchError('다운로드 파일이 손상되었습니다.', 'export_hash_mismatch');
    }
    const objectUrl = window.URL.createObjectURL(new window.Blob([bytes], { type: profile.mimeType }));
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = exportDownloadName(kind, revisionId);
    link.hidden = true;
    document.body.appendChild(link);
    try {
      link.click();
    } finally {
      link.remove();
      window.setTimeout(() => window.URL.revokeObjectURL(objectUrl), 5000);
    }
  }

  function setExportStatus(message, tone) {
    const status = root.querySelector('[data-role="export-status"]');
    if (!status) {
      return;
    }
    status.textContent = message;
    status.dataset.tone = tone || 'neutral';
  }

  function wait(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
  }

  function setSaveStatus(message, tone) {
    const status = root.querySelector('[data-role="save-status"]');
    if (!status) {
      return;
    }
    status.textContent = message;
    status.dataset.tone = tone || 'neutral';
  }

  function stateLabel(value) {
    return STATE_LABELS[value] || '확인 필요';
  }

  function updateWorksheetStateBadge() {
    const badge = root.querySelector('[data-role="worksheet-state"]');
    if (badge) {
      badge.textContent = stateLabel(state.currentState);
      badge.dataset.state = state.currentState;
    }
  }

  function setEditorReadOnly(readOnly) {
    const title = document.getElementById('ws-template-title');
    if (title) {
      title.disabled = readOnly;
    }
    const addQuestion = root.querySelector('[data-action="add-question"]');
    if (addQuestion) addQuestion.disabled = readOnly;
    const plate = document.getElementById('ws-plate-template-builder-root');
    if (!plate) {
      return;
    }
    plate.dataset.readonly = readOnly ? 'true' : 'false';
    plate.setAttribute('aria-readonly', readOnly ? 'true' : 'false');
    plate.querySelectorAll('[contenteditable]').forEach((element) => {
      if (readOnly && element.getAttribute('contenteditable') !== 'false') {
        element.dataset.ojAiWorkbenchEditable = '1';
        element.setAttribute('contenteditable', 'false');
      } else if (!readOnly && element.dataset.ojAiWorkbenchEditable === '1') {
        element.setAttribute('contenteditable', 'true');
        delete element.dataset.ojAiWorkbenchEditable;
      }
    });
    root.querySelectorAll('#ws-plate-template-builder-root :is(button, input, textarea, select), #ws-response-inspector-root :is(button, input, textarea, select)').forEach((element) => {
      if (readOnly && !element.disabled) {
        element.dataset.ojAiWorkbenchEnabled = '1';
        element.disabled = true;
      } else if (!readOnly && element.dataset.ojAiWorkbenchEnabled === '1') {
        element.disabled = false;
        delete element.dataset.ojAiWorkbenchEnabled;
      }
    });
  }

  function refreshReleaseStatus() {
    const title = root.querySelector('[data-role="release-title"]');
    const message = root.querySelector('[data-role="release-message"]');
    if (!title || !message) {
      return;
    }
    if (state.currentState === 'archived') {
      title.textContent = '보관됨';
      message.textContent = '복원하면 다시 사용할 수 있습니다.';
      return;
    }
    if (state.latestPublicationId) {
      title.textContent = '발행됨';
      message.textContent = '배포용 버전이 있습니다. 배포 대상과 일정은 배포에서 설정하세요.';
      return;
    }
    if (state.currentRevisionState === 'approved') {
      title.textContent = '승인됨';
      message.textContent = state.approval?.approval_ready === true
        ? '발행할 수 있습니다.'
        : '검증 후 발행할 수 있습니다.';
      return;
    }
    if (state.approval?.approval_ready === true) {
      title.textContent = '문서 확인 완료';
      const codes = (state.approval.warnings || []).map(item => item.code);
      message.textContent = codes.includes('worksheet_evaluation_not_run')
        ? 'AI 점검 미실행 · 교사 검토 승인 가능'
        : codes.includes('worksheet_evaluation_failed') ? 'AI 점검 실패 · 교사 검토 승인 가능'
          : codes.includes('worksheet_evaluation_cancelled') ? 'AI 점검 취소됨 · 교사 검토 승인 가능'
            : '교사 검토 승인 가능';
      return;
    }
    if (state.approval?.status === 'unavailable') {
      title.textContent = '설정 필요';
      message.textContent = '활동지 검토 기준을 확인해 주세요.';
      return;
    }
    const blockerCodes = Array.isArray(state.approval?.blockers)
      ? state.approval.blockers.map((item) => String(item?.code || ''))
      : [];
    if (blockerCodes.some((code) => code.startsWith('worksheet_evaluation_'))) {
      title.textContent = '검토 필요';
      message.textContent = blockerCodes.includes('worksheet_evaluation_in_progress')
        ? '진행 중인 AI 점검을 완료하거나 취소해 주세요.'
        : blockerCodes.includes('worksheet_evaluation_safety_review_required')
          ? 'AI가 표시한 안전성 경고를 검토해 주세요.'
          : '기존 AI 점검 결과의 교사 검토가 필요합니다.';
      return;
    }
    title.textContent = state.validation ? '확인 필요' : '작성 중';
    message.textContent = state.validation ? '검증 결과를 확인해 주세요.' : '문서 확인 후 교사 검토 승인이 필요합니다.';
  }

  function refreshEditorActions() {
    renderProjectCriteriaStatus();
    const editable = state.loaded && state.canManage && ['draft', 'active'].includes(state.currentState) && !state.actionBusy;
    const duplicable = state.loaded && state.canManage && ['draft', 'active', 'archived'].includes(state.currentState) && !state.actionBusy;
    const validatable = editable && ['draft', 'approved'].includes(state.currentRevisionState);
    const approvable = validatable && state.currentRevisionState === 'draft'
      && state.validation?.release_ready === true && state.approval?.approval_ready === true;
    const publishable = validatable && state.currentRevisionState === 'approved'
      && !state.currentPublicationId && state.approval?.approval_ready === true;
    ['save', 'asset-upload', 'import-template'].forEach((action) => {
      const button = root.querySelector(`[data-action="${action}"]`);
      if (button) {
        button.disabled = !editable || (root.dataset.workflowStage === 'author' && !preparationConfirmed());
      }
    });
    const duplicateButton = root.querySelector('[data-action="duplicate"]');
    if (duplicateButton) {
      duplicateButton.disabled = !duplicable;
    }
    const exportable = state.loaded && state.canExport && !state.exportBusy
      && PUBLIC_ID_PATTERN.test(state.currentRevisionId)
      && /^[a-f0-9]{64}$/.test(state.currentDocumentHash)
      && ['draft', 'approved'].includes(state.currentRevisionState);
    ['export-pdf', 'export-package'].forEach((action) => {
      const button = root.querySelector(`[data-action="${action}"]`);
      if (button) button.disabled = !exportable;
    });
    root.querySelectorAll('[data-action="validate"], [data-action="review-validate"]').forEach(button => { button.disabled = !validatable; });
    root.querySelectorAll('[data-action="preview-open"]').forEach(button => { button.disabled = !state.loaded || state.actionBusy; });
    const actionStates = {
      approve: approvable,
      publish: publishable,
      archive: editable,
      'restore-worksheet': state.loaded && state.canManage && state.currentState === 'archived' && !state.actionBusy,
    };
    Object.entries(actionStates).forEach(([action, enabled]) => {
      const button = root.querySelector(`[data-action="${action}"]`);
      if (button) {
        button.disabled = !enabled;
        if (action === 'archive') {
          button.hidden = state.currentState === 'archived';
        } else if (action === 'restore-worksheet') {
          button.hidden = state.currentState !== 'archived';
        }
      }
    });
    setEditorReadOnly(!editable || (state.workflowEnabled
      && (root.dataset.workflowStage !== 'author' || !preparationConfirmed())));
    updateWorksheetStateBadge();
    refreshReleaseStatus();
    renderLearningGoals();
    renderAchievementLinks();
    renderAchievementStandards();
    refreshPreparation();
  }

  function preparationFingerprint() {
    return JSON.stringify({
      goals: normalizeLearningGoals(state.learningGoals),
      standards: normalizeAchievementLinks(state.achievementLinks),
      bindings: normalizeAssessmentBindings(state.assessmentBindings),
    });
  }

  function preparationConfirmed() {
    return state.preparation.state === 'confirmed' && state.rubricPin !== null
      && state.preparation.rubric_revision_id === state.rubricPin.rubric_revision_id
      && state.preparation.rubric_content_hash === state.rubricPin.rubric_content_hash
      && (projectCriteriaConfirmed() || state.preparationBaseline === preparationFingerprint());
  }

  function projectCriteriaConfirmed() {
    const pin = state.currentDocument?.settings?.assessment_project;
    const project = state.assessmentProject;
    return !state.assessmentUnavailable && project?.state === 'confirmed' && pin
      && project.project_id === pin.project_id && project.revision_id === pin.revision_id
      && project.content_hash === pin.content_hash && state.rubricPin !== null
      && project.revision_id === state.rubricPin.rubric_revision_id
      && project.rubric_content_hash === state.rubricPin.rubric_content_hash;
  }

  function refreshPreparation() {
    if (!state.workflowEnabled) return;
    const confirmed = preparationConfirmed();
    if (state.loaded && state.assessmentCriteriaReady && !state.initialWorkflowResolved) {
      state.initialWorkflowResolved = true;
      setWorkflowStage(initialWorkflowStage());
    }
    const required = root.querySelector('[data-role="preparation-required"]');
    if (required) required.hidden = confirmed || root.dataset.workflowStage !== 'author';
    const basis = root.querySelector('[data-role="basis-summary"]');
    if (basis) {
      basis.replaceChildren();
      const groups = [
        ['학습 목표', state.learningGoals.map((goal) => goal.text)],
        ['성취기준', state.assessmentProject ? state.assessmentProject.configuration.achievement_standards.map(s=>[s.code,s.text].filter(Boolean).join(' · ')) : state.achievementLinks.map((link) => standardTitle(standardRecord(link.standard_public_id)))],
        ['평가 요소', state.assessmentBindings.map((binding) => state.assessmentCriteria.find((criterion) => criterion.criterion_id === binding.criterion_id)?.label || '확인 필요')],
      ];
      groups.forEach(([label, values]) => {
        const section = document.createElement('div');
        const heading = document.createElement('strong'); heading.textContent = label;
        const list = document.createElement('ul');
        [...new Set(values)].forEach((value) => { const item = document.createElement('li'); item.textContent = value; list.append(item); });
        section.append(heading, list); basis.append(section);
      });
    }
  }

  const requestedWorkflowStage = ['criteria', 'author', 'review'].includes(new URLSearchParams(window.location.search).get('stage'))
    ? new URLSearchParams(window.location.search).get('stage') : '';

  function initialWorkflowStage() {
    return requestedWorkflowStage === 'review' ? 'review' : 'author';
  }

  function setWorkflowStage(stage) {
    if (!state.workflowEnabled) return;
    if (!['author', 'review'].includes(stage)) return;
    if (stage === 'review' && hasPendingChanges()) {
      setNotice('변경 내용을 저장한 뒤 검토해 주세요.', true);
      return;
    }
    if (stage === 'author' && root.dataset.workflowStage !== 'author') setAuthorPanel('');
    root.dataset.workflowStage = stage;
    refreshOutlineResize();
    renderProjectCriteriaStatus();
    window.OJAIWorkflow?.setStage(stage);
    root.querySelectorAll('[data-inspector-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.inspectorPanel !== 'question';
    });
    root.querySelectorAll('[data-inspector-tab]').forEach((button) => button.setAttribute('aria-selected', button.dataset.inspectorTab === 'question' ? 'true' : 'false'));
    const basis = root.querySelector('[data-role="confirmed-basis"]'); if (basis) basis.hidden = false;
    const review = root.querySelector('[data-role="worksheet-review-stage"]'); if (review) review.hidden = stage !== 'review';
    setEditorReadOnly(stage !== 'author' || !preparationConfirmed() || state.actionBusy || !state.canManage || !['draft', 'active'].includes(state.currentState));
    const required = root.querySelector('[data-role="preparation-required"]');
    if (required) required.hidden = preparationConfirmed() || stage !== 'author';
    document.dispatchEvent(new CustomEvent('ojai:workflow-stage', { detail: { stage } }));
  }

  function setActionBusy(busy) {
    state.actionBusy = busy === true;
    refreshEditorActions();
    if (!state.actionBusy) refreshCompletedReview();
  }

  function refreshCompletedReview() {
    if (!state.pendingReviewRevisionId || state.actionBusy || !state.loaded) return;
    if (state.pendingReviewRevisionId !== state.currentRevisionId) {
      state.pendingReviewRevisionId = '';
      return;
    }
    if (hasPendingChanges()) return;
    const button = root.querySelector('[data-action="review-validate"], [data-action="validate"]');
    if (!button || button.disabled) return;
    state.pendingReviewRevisionId = '';
    void validateCurrentRevision(button, true);
  }

  function releaseUrlSet(urls) {
    if (window.URL && typeof window.URL.revokeObjectURL === 'function') {
      urls.forEach((url) => window.URL.revokeObjectURL(url));
    }
    urls.clear();
  }

  function releasePreviewUrls() {
    releaseUrlSet(state.previewUrls);
  }

  function releaseProjectionPreviewUrls() {
    releaseUrlSet(state.projectionPreviewUrls);
  }

  async function fetchAssetPreviewUrl(assetId, sourceType, sourceId, urlSet) {
    if (!window.URL || typeof window.URL.createObjectURL !== 'function') {
      return '';
    }
    const headers = { Accept: 'application/octet-stream' };
    if (state.restNonce) {
      headers['X-WP-Nonce'] = state.restNonce;
    }
    const query = new URLSearchParams({ source_type: sourceType, source_id: sourceId });
    const response = await window.fetch(
      `${state.restRoot}/worksheet-assets/${encodeURIComponent(assetId)}/content?${query.toString()}`,
      { method: 'GET', credentials: 'same-origin', headers }
    );
    if (!response.ok || typeof response.blob !== 'function') {
      throw new ApiError(response.status || 503, {});
    }
    const url = window.URL.createObjectURL(await response.blob());
    (urlSet instanceof Set ? urlSet : state.previewUrls).add(url);
    return url;
  }

  async function hydrateAssetPreviews(schema, revisionId) {
    const nextSchema = clone(schema);
    const targets = [];
    const visit = (value) => {
      if (Array.isArray(value)) {
        value.forEach(visit);
        return;
      }
      if (!isObject(value)) {
        return;
      }
      if (PUBLIC_ID_PATTERN.test(String(value.asset_id || ''))) {
        targets.push(value);
      }
      Object.values(value).forEach(visit);
    };
    visit(nextSchema);

    await Promise.all(targets.map(async (node) => {
      const assetId = String(node.asset_id);
      const staged = state.stagedAssets.get(assetId);
      const sourceType = staged ? 'staged' : 'revision';
      const sourceId = staged ? assetId : revisionId;
      if (!PUBLIC_ID_PATTERN.test(String(sourceId || ''))) {
        return;
      }
      try {
        node.url = await fetchAssetPreviewUrl(assetId, sourceType, sourceId);
      } catch (error) {
        delete node.url;
      }
      if (node.type === 'file' && !node.name) {
        node.name = String(node.label || staged?.original_name || '첨부 파일');
      }
    }));
    return nextSchema;
  }

  function renderStagedAssets() {
    const tray = root.querySelector('[data-role="asset-tray"]');
    const list = root.querySelector('[data-role="staged-assets"]');
    if (!tray || !list) {
      return;
    }
    list.replaceChildren();
    state.stagedAssets.forEach((asset) => {
      const item = document.createElement('div');
      item.className = 'oj-ai-workbench__asset';
      const name = document.createElement('span');
      name.textContent = String(asset.original_name || '첨부 파일');
      name.title = name.textContent;
      const actions = document.createElement('div');
      const insertButton = document.createElement('button');
      insertButton.type = 'button';
      insertButton.dataset.action = 'insert-asset';
      insertButton.dataset.assetId = String(asset.id || '');
      insertButton.textContent = '넣기';
      const discardButton = document.createElement('button');
      discardButton.type = 'button';
      discardButton.dataset.action = 'discard-asset';
      discardButton.dataset.assetId = String(asset.id || '');
      discardButton.textContent = '삭제';
      actions.append(insertButton, discardButton);
      item.append(name, actions);
      list.appendChild(item);
    });
    tray.hidden = state.stagedAssets.size === 0;
  }

  function hasPendingChanges() {
    if (!state.loaded || state.stagedAssets.size > 0) {
      return state.stagedAssets.size > 0;
    }
    try {
      const title = document.getElementById('ws-template-title')?.value || '';
      return editorFingerprint(parseBuilderSchema(), title) !== state.remoteFingerprint;
    } catch (error) {
      return true;
    }
  }

  async function insertStagedAsset(assetId) {
    const asset = state.stagedAssets.get(assetId);
    if (!asset) {
      return;
    }
    try {
      if (!asset.url) {
        asset.url = await fetchAssetPreviewUrl(assetId, 'staged', assetId);
      }
      window.dispatchEvent(new CustomEvent('ojai-plate-asset-insert', { detail: { asset } }));
      setNotice('본문에 추가했습니다.', true, 'success');
    } catch (error) {
      setNotice(error instanceof Error ? error.message : '파일을 넣지 못했습니다.', true, 'error');
    }
  }

  async function stageAssetFile(file, input) {
    if (!state.loaded || state.actionBusy || !(file instanceof window.File)) {
      return;
    }
    if (file.size < 1 || file.size > 20 * 1024 * 1024) {
      setNotice('파일은 20MB 이하만 추가할 수 있습니다.', true, 'error');
      input.value = '';
      return;
    }
    const button = root.querySelector('[data-action="asset-upload"]');
    const originalLabel = button?.textContent || '파일 추가';
    setActionBusy(true);
    if (button) {
      button.textContent = '올리는 중';
    }
    setSaveStatus('업로드 중', 'saving');
    try {
      const commandId = commandToken('asset');
      const formData = new window.FormData();
      formData.set('command_id', commandId);
      formData.set('expected_state', state.currentState);
      formData.set('expected_revision_id', state.currentRevisionId);
      formData.set('file', file, file.name);
      const envelope = await apiRequest(
        `/worksheets/${encodeURIComponent(state.worksheetId)}/commands/stage-asset`,
        { method: 'POST', idempotencyKey: commandToken('asset-key'), body: formData }
      );
      const asset = envelope.data.asset;
      const node = assetNodeFromReceipt(asset);
      const record = { ...asset, url: '' };
      state.stagedAssets.set(String(asset.id), record);
      renderStagedAssets();
      try {
        record.url = await fetchAssetPreviewUrl(String(asset.id), 'staged', String(asset.id));
      } catch (error) {
        record.url = '';
      }
      window.dispatchEvent(new CustomEvent('ojai-plate-asset-insert', { detail: { asset: record, node } }));
      scheduleRecovery();
      setNotice('파일을 추가했습니다.', true, 'success');
      setSaveStatus('저장 필요', 'pending');
    } catch (error) {
      setNotice(error instanceof Error ? error.message : '파일을 추가하지 못했습니다.', true, 'error');
      setSaveStatus('업로드 실패', 'error');
    } finally {
      input.value = '';
      if (button) {
        button.textContent = originalLabel;
      }
      setActionBusy(false);
    }
  }

  function schemaReferencesAsset(assetId) {
    try {
      return collectDocumentAssetIds(parseBuilderSchema()).has(assetId);
    } catch (error) {
      return true;
    }
  }

  async function discardStagedAsset(assetId) {
    if (!state.stagedAssets.has(assetId) || state.actionBusy) {
      return;
    }
    if (schemaReferencesAsset(assetId)) {
      setNotice('본문에서 파일을 먼저 삭제해 주세요.', true, 'error');
      return;
    }
    setActionBusy(true);
    try {
      await apiRequest(
        `/worksheets/${encodeURIComponent(state.worksheetId)}/assets/${encodeURIComponent(assetId)}/commands/discard`,
        {
          method: 'POST',
          idempotencyKey: commandToken('discard-key'),
          body: {
            command_id: commandToken('discard'),
            expected_state: state.currentState,
            expected_revision_id: state.currentRevisionId,
          },
        }
      );
      state.stagedAssets.delete(assetId);
      renderStagedAssets();
      persistRecovery();
      setNotice('파일을 삭제했습니다.', true, 'success');
    } catch (error) {
      setNotice(error instanceof Error ? error.message : '파일을 삭제하지 못했습니다.', true, 'error');
    } finally {
      setActionBusy(false);
    }
  }

  async function importTemplatePackage(file, input) {
    if (!state.loaded || state.actionBusy || !(file instanceof window.File)) {
      return;
    }
    if (!file.name.toLowerCase().endsWith('.ojws') || file.size < 1 || file.size > 220 * 1024 * 1024) {
      setNotice('올바른 .ojws 파일을 선택해 주세요.', true, 'error');
      input.value = '';
      return;
    }
    if (hasPendingChanges()) {
      setNotice('가져오기 전에 현재 내용을 저장해 주세요.', true, 'error');
      input.value = '';
      return;
    }
    const button = root.querySelector('[data-action="import-template"]');
    const originalLabel = button?.textContent || '가져오기';
    setActionBusy(true);
    if (button) {
      button.textContent = '가져오는 중';
    }
    setSaveStatus('가져오는 중', 'saving');
    try {
      const formData = new window.FormData();
      formData.set('command_id', commandToken('import'));
      formData.set('expected_state', state.currentState);
      formData.set('expected_revision_id', state.currentRevisionId);
      formData.set('package', file, file.name);
      const envelope = await apiRequest(
        `/worksheet-revisions/${encodeURIComponent(state.currentRevisionId)}/commands/import-template`,
        { method: 'POST', idempotencyKey: commandToken('import-key'), body: formData }
      );
      const revisionId = String(envelope.data.revision?.id || '');
      if (!PUBLIC_ID_PATTERN.test(revisionId)) {
        throw new ApiError(503, {});
      }
      state.currentRevisionId = revisionId;
      if (!await loadEditor(false)) throw new WorkbenchError('가져온 활동지가 서버에 저장되었지만 화면을 불러오지 못했습니다. 새로고침해 주세요.', 'import_reload_failed');
      setNotice('활동지를 가져왔습니다.', true, 'success');
      setSaveStatus('저장됨', 'saved');
    } catch (error) {
      setNotice(error instanceof Error ? error.message : '활동지를 가져오지 못했습니다.', true, 'error');
      setSaveStatus('가져오기 실패', 'error');
    } finally {
      input.value = '';
      if (button) {
        button.textContent = originalLabel;
      }
      setActionBusy(false);
    }
  }

  async function duplicateCurrentWorksheet(button) {
    if (!state.loaded || state.actionBusy || button.disabled) {
      return;
    }
    if (hasPendingChanges()) {
      setNotice('복제하기 전에 현재 내용을 저장해 주세요.', true, 'error');
      return;
    }
    const originalLabel = button.textContent;
    setActionBusy(true);
    button.textContent = '복제 중';
    try {
      const envelope = await apiRequest(
        `/worksheets/${encodeURIComponent(state.worksheetId)}/commands/duplicate`,
        {
          method: 'POST',
          idempotencyKey: commandToken('duplicate-key'),
          body: duplicateCommandBody(commandToken('duplicate'), state.currentState, state.currentRevisionId),
        }
      );
      const worksheetId = String(envelope.data.worksheet?.id || '');
      if (!PUBLIC_ID_PATTERN.test(worksheetId)) {
        throw new ApiError(503, {});
      }
      window.location.assign(await worksheetDestination(worksheetId));
    } catch (error) {
      button.textContent = originalLabel;
      setActionBusy(false);
      setNotice(error instanceof Error ? error.message : '활동지를 복제하지 못했습니다.', true, 'error');
    }
  }

  function editUrl(worksheetId) {
    return state.editUrlTemplate.replace('{worksheet_id}', encodeURIComponent(worksheetId));
  }

  async function worksheetDestination(worksheetId, preserveScope = false) {
    const route = `/worksheets/${encodeURIComponent(worksheetId)}/assessment-project`;
    let envelope = await apiRequest(route);
    if (envelope.data.project === null) {
      const commandId = commandToken('adopt-project');
      envelope = await apiRequest(route, {method:'POST',idempotencyKey:commandId,body:{command_id:commandId,expected:{},payload:{}}});
    }
    const project = envelope.data.project;
    if (!isObject(project) || !PUBLIC_ID_PATTERN.test(String(project.project_id || ''))) {
      throw new WorkbenchError('활동지의 평가 연결을 확인할 수 없습니다.', 'project_invalid');
    }
    const target = new URL('projects', new URL(state.assessmentsUrl, window.location.href));
    const current = new URLSearchParams(window.location.search);
    if (preserveScope) {
      for (const key of ['context_type', 'context_id', 'context_revision', 'assignment_id', 'publication_id', 'publication_hash']) {
        if (current.has(key)) target.searchParams.set(key, current.get(key));
      }
    }
    target.searchParams.set('project_id', project.project_id);
    target.searchParams.set('worksheet_id', worksheetId);
    target.searchParams.set('stage', preserveScope && ['criteria', 'author', 'review'].includes(current.get('stage')) ? current.get('stage') : 'author');
    return target.href;
  }

  function showEntryError(error) {
    const message = entryStatus?.querySelector('[data-worksheet-entry-message]');
    if (message) message.textContent = error instanceof Error ? error.message : '활동지를 불러오지 못했습니다.';
    const retry = entryStatus?.querySelector('[data-worksheet-entry-retry]');
    if (retry) retry.hidden = false;
  }

  async function enterEditor() {
    if (state.entryBusy) return;
    state.entryBusy = true;
    const retry = entryStatus?.querySelector('[data-worksheet-entry-retry]');
    if (retry) retry.hidden = true;
    const message = entryStatus?.querySelector('[data-worksheet-entry-message]');
    if (message) message.textContent = '활동지를 불러오는 중입니다.';
    try {
      // Resolve old bookmarks before binding the editor's unsaved-change guard.
      if (entryContent && !state.editorBound && !document.getElementById('oj-ai-projects')) {
        const destination = await worksheetDestination(state.worksheetId, true);
        if (destination) {
          window.location.replace(destination);
          return;
        }
        throw new WorkbenchError('평가 연결을 확인할 수 없습니다.', 'project_invalid');
      }
      if (state.editorBound) await loadEditor(true);
      else {
        state.editorBound = true;
        bindEditor();
      }
    } catch (error) {
      showEntryError(error);
    } finally {
      state.entryBusy = false;
    }
  }

  function formatUpdatedAt(value) {
    const normalized = typeof value === 'string' && !/[zZ]|[+-]\d\d:?\d\d$/.test(value)
      ? `${value.replace(' ', 'T')}Z`
      : value;
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) {
      return '-';
    }
    try {
      return new Intl.DateTimeFormat(state.locale.replace('_', '-'), {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: state.timeZone,
      }).format(date);
    } catch (error) {
      return date.toLocaleString();
    }
  }

  async function pollExportJob(jobId, kind, revisionId, sourceHash, token) {
    const profile = EXPORT_KINDS[kind];
    for (let attempt = 0; attempt < 120; attempt += 1) {
      if (token !== state.exportPollToken) {
        throw new WorkbenchError('내보내기가 중단되었습니다.', 'export_poll_cancelled');
      }
      const envelope = await apiRequest(`/export-jobs/${encodeURIComponent(jobId)}`);
      const job = envelope.data.export_job;
      if (!isObject(job)
        || String(job.job_id || '') !== jobId
        || String(job.artifact_kind || '') !== profile.artifactKind
        || String(job.source_id || '') !== revisionId
        || String(job.source_hash || '') !== sourceHash) {
        throw new WorkbenchError('내보내기 상태를 확인할 수 없습니다.', 'export_job_invalid');
      }
      const jobState = String(job.state || '');
      if (jobState === 'ready') {
        const artifactId = String(job.artifact_id || '');
        await downloadExportArtifact(artifactId, kind, revisionId);
        return;
      }
      if (['failed', 'cancelled', 'expired'].includes(jobState)) {
        throw new WorkbenchError(
          jobState === 'expired' ? '파일 유효기간이 끝났습니다.' : '파일을 만들지 못했습니다.',
          `export_${jobState}`
        );
      }
      if (!['queued', 'running'].includes(jobState)) {
        throw new WorkbenchError('내보내기 상태를 확인할 수 없습니다.', 'export_state_invalid');
      }
      await wait(attempt === 0 ? 250 : 1000);
    }
    throw new WorkbenchError('파일 생성이 지연되고 있습니다.', 'export_poll_timeout');
  }

  async function exportCurrentWorksheet(kind, button) {
    const profile = EXPORT_KINDS[kind];
    if (!profile || !state.loaded || !state.canExport || state.exportBusy || button.disabled) {
      return;
    }
    if (hasPendingChanges()) {
      setNotice('내보내기 전에 현재 내용을 저장해 주세요.', true, 'error');
      return;
    }
    const revisionId = state.currentRevisionId;
    const sourceHash = state.currentDocumentHash;
    const sourceState = state.currentRevisionState;
    const token = state.exportPollToken + 1;
    state.exportPollToken = token;
    state.exportBusy = true;
    refreshEditorActions();
    setExportStatus(`${profile.label} 만드는 중`, 'working');
    try {
      const envelope = await apiRequest(
        worksheetExportRequestPath(revisionId, kind),
        {
          method: 'POST',
          idempotencyKey: commandToken(`${kind}-export-key`),
          body: worksheetExportCommandBody(
            kind,
            commandToken(`${kind}-export`),
            revisionId,
            sourceHash,
            sourceState
          ),
        }
      );
      const job = envelope.data.export_job;
      const jobId = String(job?.id || '');
      if (!PUBLIC_ID_PATTERN.test(jobId)
        || String(job?.source_id || '') !== revisionId
        || String(job?.source_hash || '') !== sourceHash) {
        throw new WorkbenchError('내보내기 작업을 확인할 수 없습니다.', 'export_enqueue_invalid');
      }
      await pollExportJob(jobId, kind, revisionId, sourceHash, token);
      setExportStatus(`${profile.label} 다운로드됨`, 'ready');
      setNotice(`${profile.label}을 내려받았습니다.`, true, 'success');
    } catch (error) {
      if (token === state.exportPollToken) {
        setExportStatus('내보내기 실패', 'error');
        setNotice(error instanceof Error ? error.message : '파일을 만들지 못했습니다.', true, 'error');
      }
    } finally {
      if (token === state.exportPollToken) {
        state.exportBusy = false;
        refreshEditorActions();
      }
    }
  }


  function outlineText(node) {
    if (!isObject(node)) {
      return '';
    }
    return (Array.isArray(node.children) ? node.children : [])
      .map((child) => typeof child?.text === 'string' ? child.text : outlineText(child))
      .join('')
      .trim();
  }

  function collectQuestionOutlineItems(nodes) {
    const items = [];
    const kindLabels = {
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
    const isResponse = (node) => isObject(node) && [
      'oj_ai_response', 'response_field', 'hws_response',
    ].includes(String(node.type || ''));
    const responsesIn = (node) => {
      const responses = [];
      const collect = (candidate) => {
        if (!isObject(candidate)) return;
        if (isResponse(candidate)) responses.push(candidate);
        if (Array.isArray(candidate.children)) candidate.children.forEach(collect);
      };
      collect(node);
      return responses;
    };
    const isQuestion = (node) => isObject(node) && (
      node.type === 'hws_question' || isCanonicalQuestionNode(node)
    );
    const addQuestion = (node) => {
      const responses = responsesIn(node);
      const firstResponse = responses[0] || {};
      const heading = (Array.isArray(node.children) ? node.children : []).find((child) => (
        isObject(child) && ['h2', 'h3', 'p'].includes(String(child.type || '')) && outlineText(child)
      ));
      const key = String(
        node.question_id || node.node_id || firstResponse.response_id
        || firstResponse.name || firstResponse.id || `question_${items.length + 1}`
      );
      const responseKey = String(
        firstResponse.response_id || firstResponse.name || firstResponse.id
        || firstResponse.node_id || key
      );
      const kind = responses.length === 0
        ? '답안 없음'
        : responses.length === 1
          ? (kindLabels[String(firstResponse.kind || 'long_text')] || '서술형')
          : `답안 ${responses.length}개`;
      items.push({
        key,
        responseKey,
        kind,
        label: outlineText(heading) || String(firstResponse.label || `문항 ${items.length + 1}`),
      });
    };
    const visit = (node) => {
      if (!isObject(node)) return;
      if (isQuestion(node)) {
        addQuestion(node);
        return;
      }
      if (isResponse(node)) {
        const key = String(node.node_id || node.response_id || node.name || node.id || '');
        addQuestion({
          type: 'hws_question', question_id: key,
          children: [{ type: 'h2', children: [{ text: String(node.label || `문항 ${items.length + 1}`) }] }, node],
        });
        return;
      }
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    (Array.isArray(nodes) ? nodes : []).forEach(visit);
    return items;
  }

  function setSelectedQuestionOutline(selectedId) {
    const selected = String(selectedId || '');
    state.selectedQuestionId = selected;
    root.querySelectorAll('[data-role="document-outline"] button').forEach((button) => {
      const isSelected = selected !== '' && (
        button.dataset.outlineNodeId === selected
        || button.dataset.outlineResponseId === selected
      );
      if (isSelected) {
        button.setAttribute('aria-current', 'true');
      } else {
        button.removeAttribute('aria-current');
      }
    });
  }

  function renderQuestionOutline(items) {
    const target = root.querySelector('[data-role="document-outline"]');
    const count = root.querySelector('[data-role="outline-count"]');
    if (!target) {
      return;
    }
    target.replaceChildren();
    if (!items.length) {
      const empty = document.createElement('li');
      empty.className = 'oj-ai-workbench__outline-empty';
      empty.textContent = '문항이 없습니다. 위의 + 버튼으로 추가하세요.';
      target.append(empty);
    } else {
      items.forEach((item, index) => {
        const row = document.createElement('li');
        const button = document.createElement('button');
        const number = document.createElement('span');
        const text = document.createElement('span');
        const label = document.createElement('strong');
        const kind = document.createElement('small');
        button.type = 'button';
        button.dataset.outlineNodeId = item.key;
        button.dataset.outlineResponseId = item.responseKey;
        button.setAttribute('aria-label', `${index + 1}번 문항 ${item.label}`);
        number.className = 'oj-ai-workbench__question-number';
        number.textContent = String(index + 1);
        label.textContent = item.label;
        kind.textContent = item.kind;
        text.append(label, kind);
        button.append(number, text);
        row.append(button);
        target.append(row);
      });
    }
    if (count) {
      count.textContent = String(items.length) + '개';
    }
    const currentExists = items.some((item) => (
      item.key === state.selectedQuestionId || item.responseKey === state.selectedQuestionId
    ));
    setSelectedQuestionOutline(currentExists
      ? state.selectedQuestionId
      : String(items[0]?.key || ''));
  }

  function renderDocumentOutline(documentValue) {
    const nodes = Array.isArray(documentValue?.nodes) ? documentValue.nodes : [];
    renderQuestionOutline(collectQuestionOutlineItems(nodes));
  }

  function renderPlateQuestionOutline(schemaValue) {
    const schema = Array.isArray(schemaValue) ? { plate_value: schemaValue } : schemaValue;
    renderQuestionOutline(collectQuestionOutlineItems(schema?.plate_value));
  }

  function renderWorksheetList() {
    const body = root.querySelector('[data-role="worksheet-list"]');
    const content = root.querySelector('[data-role="content"]');
    const summary = root.querySelector('[data-role="summary"]');
    const search = root.querySelector('[data-field="search"]');
    if (!body || !content) {
      return;
    }
    const term = search ? search.value.trim().toLocaleLowerCase() : '';
    const items = state.listItems.filter((item) => {
      const haystack = [item.title, item.description, item.summary, item.subject_label].join(' ').toLocaleLowerCase();
      return haystack.includes(term);
    });
    body.replaceChildren();

    if (items.length === 0) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 6;
      cell.className = 'oj-ai-workbench__empty';
      cell.textContent = term ? '검색 결과가 없습니다.' : '아직 활동지가 없습니다.';
      row.appendChild(cell);
      body.appendChild(row);
    } else {
      items.forEach((item) => {
        const row = document.createElement('tr');
        const titleCell = document.createElement('td');
        titleCell.className = 'oj-ai-workbench__worksheet-title';
        const link = document.createElement(state.canManage ? 'a' : 'span');
      if (state.canManage) {
        link.href = editUrl(String(item.id || ''));
        link.dataset.action = 'open-worksheet';
        link.dataset.worksheetId = String(item.id || '');
      }
        link.textContent = String(item.title || '제목 없음');
        titleCell.appendChild(link);

        const descriptionCell = document.createElement('td');
        descriptionCell.className = 'oj-ai-workbench__description';
        descriptionCell.textContent = item.latest_publication_id ? '발행본 있음' : '미발행';

        const distributionCell = document.createElement('td');
        const distributionCount = Number(item.assignment_count ?? item.distribution_count);
        distributionCell.textContent = Number.isInteger(distributionCount) && distributionCount >= 0
          ? String(distributionCount) + '건'
          : '집계 없음';
        const distributionUrl = root.dataset.assignmentsUrl;
        if(distributionUrl && /^[0-9A-HJKMNP-TV-Z]{26}$/.test(String(item.id || ''))){
          const target = new URL(distributionUrl,location.href);
          target.searchParams.set('worksheet_id',item.id);
          const distributionLink = document.createElement('a');distributionLink.href=target.href;distributionLink.textContent='배포·제출 보기';
          distributionLink.setAttribute('aria-label',`${String(item.title || '활동지')} 배포·제출 보기`);
          distributionCell.replaceChildren(distributionLink);
        }

        const stateCell = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'oj-ai-workbench__state';
        badge.dataset.state = String(item.state || 'unknown');
        badge.textContent = stateLabel(item.state);
        stateCell.appendChild(badge);

        const updatedCell = document.createElement('td');
        updatedCell.textContent = formatUpdatedAt(item.updated_at_utc);
        const reuseCell = document.createElement('td');
        if (state.canManage) {
          const copy = document.createElement('button');
          copy.type = 'button'; copy.textContent = '복사'; copy.dataset.action = 'copy-worksheet'; copy.dataset.worksheetId = String(item.id || '');
          copy.setAttribute('aria-label', `${String(item.title || '활동지')} 복사`); reuseCell.append(copy);
        }
        row.append(titleCell, descriptionCell, distributionCell, stateCell, updatedCell, reuseCell);
        row.dataset.worksheetRow = '';
        const headers = root.querySelectorAll('.oj-ai-workbench__table thead th');
        [...row.cells].forEach((cell, index) => { cell.dataset.label = headers[index]?.textContent || ''; });
        body.appendChild(row);
      });
    }
    content.hidden = false;
    setNotice('', false);
    if (summary) {
      summary.textContent = String(items.length) + '개';
    }
  }

  async function loadWorksheetList() {
    const stateField = root.querySelector('[data-field="state"]');
    const query = new URLSearchParams({ limit: '100' });
    if (stateField && stateField.value) {
      query.set('state', stateField.value);
    }
    setNotice('활동지를 불러오는 중입니다.', true);
    const content = root.querySelector('[data-role="content"]');
    if (content) {
      content.hidden = true;
    }
    try {
      state.listItems = await window.OJAIWorkflow.allPages(apiRequest, `/worksheets?${query.toString()}`);
      renderWorksheetList();
    } catch (error) {
      setNotice(error instanceof Error ? error.message : '활동지를 불러오지 못했습니다.', true);
    }
  }

  function parseBuilderSchema() {
    const field = document.getElementById('ws-builder-schema');
    if (!field || !field.value.trim()) {
      throw new WorkbenchError('편집기 내용을 읽을 수 없습니다.', 'plate_schema_empty');
    }
    try {
      return JSON.parse(field.value);
    } catch (error) {
      throw new WorkbenchError('편집기 내용을 점검해 주세요.', 'plate_schema_invalid');
    }
  }

  function writePlateSchema(schema, dispatchLoad) {
    const builder = document.getElementById('ws-builder-schema');
    const render = document.getElementById('ws-render-schema');
    const serialized = JSON.stringify(schema);
    state.suppressRecovery = true;
    if (builder) {
      builder.value = serialized;
    }
    if (render) {
      render.value = serialized;
    }
    if (dispatchLoad) {
      window.dispatchEvent(new CustomEvent('ojai-plate-template-load', { detail: { schema } }));
    }
    window.setTimeout(() => {
      state.suppressRecovery = false;
    }, 0);
  }

  function fingerprintPlateValue(value) {
    if (Array.isArray(value)) {
      return value.map(fingerprintPlateValue);
    }
    if (isObject(value)) {
      const result = {};
      Object.keys(value)
        .filter((key) => key !== 'id' && !(key === 'url' && PUBLIC_ID_PATTERN.test(String(value.asset_id || ''))))
        .sort()
        .forEach((key) => {
          result[key] = fingerprintPlateValue(value[key]);
        });
      return result;
    }
    return value;
  }

  function editorFingerprint(schema, title, achievementLinks = state.achievementLinks, assessmentBindings = state.assessmentBindings, learningGoals = state.learningGoals) {
    const value = Array.isArray(schema)
      ? schema
      : (isObject(schema) && Array.isArray(schema.plate_value) ? schema.plate_value : []);
    return JSON.stringify({
      achievement_links: normalizeAchievementLinks(achievementLinks),
      assessment_bindings: normalizeAssessmentBindings(assessmentBindings),
      learning_goals: normalizeLearningGoals(learningGoals),
      plate_value: fingerprintPlateValue(value),
      title: String(title || ''),
    });
  }

  // The Plate editor rewrites its own hidden schema field once it finishes
  // loading a value (e.g. it pads a trailing empty paragraph so there's
  // somewhere to click below the last block) -- writePlateSchema() only
  // hands it the pre-rewrite schema, so the field's settled content can
  // differ from that schema even though the user changed nothing. Capture
  // the baseline from the field itself, right after the load round-trip,
  // so hasPendingChanges() compares like with like instead of flagging the
  // editor's own load-time rewrite as an unsaved change.
  function captureRemoteFingerprint(fallbackSchema, title) {
    try {
      return editorFingerprint(parseBuilderSchema(), title);
    } catch (error) {
      return editorFingerprint(fallbackSchema, title);
    }
  }

  function recoveryKey() {
    return `oj_ai_worksheet_recovery_v1:${state.worksheetId}`;
  }

  function readRecovery() {
    try {
      const value = JSON.parse(window.localStorage.getItem(recoveryKey()) || 'null');
      return isObject(value) && isObject(value.schema) ? value : null;
    } catch (error) {
      return null;
    }
  }

  function removeRecovery() {
    try {
      window.localStorage.removeItem(recoveryKey());
    } catch (error) {
      // Storage can be disabled without blocking authoring.
    }
    const banner = root.querySelector('[data-role="recovery"]');
    if (banner) {
      banner.hidden = true;
    }
  }

  function persistRecovery() {
    if (!state.loaded || state.suppressRecovery) {
      return;
    }
    try {
      const schema = parseBuilderSchema();
      const titleField = document.getElementById('ws-template-title');
      const title = titleField ? titleField.value : '';
      const fingerprint = editorFingerprint(schema, title);
      if (fingerprint === state.remoteFingerprint && state.stagedAssets.size === 0) {
        removeRecovery();
        return;
      }
      window.localStorage.setItem(recoveryKey(), JSON.stringify({
        revision_id: state.currentRevisionId,
        saved_at_utc: new Date().toISOString(),
        schema: stripAssetPreviewUrls(schema),
        learning_goals: clone(state.learningGoals),
        achievement_links: clone(state.achievementLinks),
        assessment_bindings: clone(state.assessmentBindings),
        staged_assets: Array.from(state.stagedAssets.values()).map((asset) => ({ ...asset, url: '' })),
        title,
      }));
      setSaveStatus('저장 필요', 'pending');
    } catch (error) {
      // Invalid source JSON remains visible in Plate and is handled on save.
    }
  }

  function scheduleRecovery() {
    if (state.workflowEnabled && !projectCriteriaConfirmed()
      && state.preparationBaseline && state.preparationBaseline !== preparationFingerprint()) {
      state.preparation = { state: 'draft', rubric_revision_id: null, rubric_content_hash: null };
      setNotice('', false);
      refreshPreparation();
    }
    if (state.suppressRecovery) {
      return;
    }
    hideValidation();
    window.clearTimeout(state.recoveryTimer);
    state.recoveryTimer = window.setTimeout(persistRecovery, 400);
  }

  function showRecoveryIfNeeded() {
    const recovery = readRecovery();
    if (!recovery) {
      return;
    }
    const recoveryAssessmentBindings = Array.isArray(recovery.assessment_bindings)
      ? recovery.assessment_bindings
      : state.assessmentBindings;
    const recoveryLearningGoals = Array.isArray(recovery.learning_goals)
      ? recovery.learning_goals
      : state.learningGoals;
    if (editorFingerprint(recovery.schema, recovery.title, recovery.achievement_links, recoveryAssessmentBindings, recoveryLearningGoals) === state.remoteFingerprint && (!Array.isArray(recovery.staged_assets) || recovery.staged_assets.length === 0)) {
      removeRecovery();
      return;
    }
    const banner = root.querySelector('[data-role="recovery"]');
    if (banner) {
      banner.hidden = false;
    }
  }

  async function restoreRecovery() {
    const recovery = readRecovery();
    if (!recovery) {
      removeRecovery();
      return;
    }
    state.stagedAssets.clear();
    if (Array.isArray(recovery.staged_assets)) {
      recovery.staged_assets.forEach((asset) => {
        if (isObject(asset) && PUBLIC_ID_PATTERN.test(String(asset.id || ''))) {
          state.stagedAssets.set(String(asset.id), { ...asset, url: '' });
        }
      });
    }
    state.achievementLinks = normalizeAchievementLinks(recovery.achievement_links);
    state.learningGoals = Array.isArray(recovery.learning_goals)
      ? normalizeLearningGoals(recovery.learning_goals)
      : normalizeLearningGoals(state.learningGoals);
    state.assessmentBindings = Array.isArray(recovery.assessment_bindings)
      ? normalizeAssessmentBindings(recovery.assessment_bindings)
      : normalizeAssessmentBindings(state.assessmentBindings);
    renderLearningGoals();
    renderAchievementLinks();
    renderAssessmentMap();
    renderStagedAssets();
    const titleField = document.getElementById('ws-template-title');
    if (titleField) {
      titleField.value = String(recovery.title || '');
    }
    releasePreviewUrls();
    const schema = await hydrateAssetPreviews(recovery.schema, state.currentRevisionId);
    writePlateSchema(schema, true);
    const banner = root.querySelector('[data-role="recovery"]');
    if (banner) {
      banner.hidden = true;
    }
    setSaveStatus('복구됨', 'pending');
  }

  function showConflict(error, localDraft) {
    state.conflict = {
      currentRevisionId: String(error.data.current_revision_id || ''),
      currentState: String(error.data.current_state || state.currentState),
      localDraft,
    };
    const banner = root.querySelector('[data-role="conflict"]');
    if (banner) {
      banner.hidden = false;
    }
    setSaveStatus('충돌', 'error');
    persistRecovery();
  }

  function hideConflict() {
    state.conflict = null;
    const banner = root.querySelector('[data-role="conflict"]');
    if (banner) {
      banner.hidden = true;
    }
  }

  function hideValidation() {
    state.validation = null;
    state.approval = null;
    const quality = root.querySelector('[data-role="quality-status"]');
    if (quality) quality.textContent = state.loaded ? '저장된 활동지를 점검할 수 있습니다.' : '저장 후 점검할 수 있습니다.';
    const result = root.querySelector('[data-role="validation-result"]');
    if (result) {
      result.hidden = true;
      result.dataset.tone = 'neutral';
    }
    refreshEditorActions();
  }

  function showValidation(validation, approval) {
    const result = root.querySelector('[data-role="validation-result"]');
    const title = root.querySelector('[data-role="validation-title"]');
    const message = root.querySelector('[data-role="validation-message"]');
    if (!result || !title || !message) {
      return;
    }
    const summary = validationSummary(validation);
    state.validation = validation;
    state.approval = isObject(approval) ? approval : null;
    title.textContent = summary.title;
    message.textContent = summary.message;
    result.dataset.tone = summary.tone;
    result.hidden = false;
    const quality = root.querySelector('[data-role="quality-status"]');
    if (quality) quality.textContent = summary.ready ? 'AI 점검 가능 · 선택 사항' : '먼저 확인할 항목이 있습니다.';
    refreshEditorActions();
  }

  async function validateCurrentRevision(button, silent) {
    if (!state.loaded || state.actionBusy || button.disabled) {
      return;
    }
    if (hasPendingChanges()) {
      setNotice('검증하기 전에 저장해 주세요.', true, 'error');
      return;
    }
    const originalLabel = button.textContent;
    setActionBusy(true);
    if (!silent) {
      button.textContent = '검증 중';
    }
    setNotice('', false);
    try {
      const envelope = await apiRequest(
        '/worksheet-revisions/' + encodeURIComponent(state.currentRevisionId) + '/commands/validate',
        {
          method: 'POST',
          idempotencyKey: commandToken('validate-key'),
          body: validationCommandBody(
            commandToken('validate'),
            state.currentRevisionState,
            state.currentRevisionId
          ),
        }
      );
      state.currentDocumentHash = String(envelope.data.revision?.document_hash || state.currentDocumentHash);
      showValidation(envelope.data.validation, envelope.data.approval);
    } catch (error) {
      hideValidation();
      setNotice(error instanceof Error ? error.message : '검증하지 못했습니다.', true, 'error');
    } finally {
      if (!silent) {
        button.textContent = originalLabel;
      }
      setActionBusy(false);
    }
  }

  function previewTextNode(node) {
    let current = document.createTextNode(String(node.text || ''));
    [
      ['bold', 'strong'],
      ['italic', 'em'],
      ['underline', 'u'],
      ['strikethrough', 's'],
    ].forEach(([mark, tag]) => {
      if (node[mark] === true) {
        const wrapper = document.createElement(tag);
        wrapper.appendChild(current);
        current = wrapper;
      }
    });
    return current;
  }

  function previewInput(type) {
    const input = document.createElement('input');
    input.type = type;
    input.disabled = true;
    return input;
  }

  function responsePreview(node) {
    const fieldset = document.createElement('fieldset');
    fieldset.className = 'oj-ai-workbench__preview-response';
    fieldset.disabled = true;
    const legend = document.createElement('legend');
    legend.textContent = String(node.label || '응답') + (node.required === true ? ' *' : '');
    fieldset.appendChild(legend);
    if (typeof node.help_text === 'string' && node.help_text) {
      const help = document.createElement('p');
      help.className = 'oj-ai-workbench__preview-help';
      help.textContent = node.help_text;
      fieldset.appendChild(help);
    }
    const constraints = isObject(node.constraints) ? node.constraints : {};
    const kind = String(node.kind || '');
    if (kind === 'short_text') {
      fieldset.appendChild(previewInput('text'));
    } else if (kind === 'long_text') {
      const textarea = document.createElement('textarea');
      textarea.disabled = true;
      fieldset.appendChild(textarea);
    } else if (kind === 'single_choice' || kind === 'multiple_choice') {
      const inputType = kind === 'single_choice' ? 'radio' : 'checkbox';
      (Array.isArray(constraints.options) ? constraints.options : []).forEach((option) => {
        const label = document.createElement('label');
        label.appendChild(previewInput(inputType));
        const text = document.createElement('span');
        text.textContent = String(option?.label || option?.value || '선택');
        label.appendChild(text);
        fieldset.appendChild(label);
      });
    } else if (kind === 'checkbox') {
      const label = document.createElement('label');
      label.appendChild(previewInput('checkbox'));
      const text = document.createElement('span');
      text.textContent = '확인';
      label.appendChild(text);
      fieldset.appendChild(label);
    } else if (kind === 'number') {
      const input = previewInput('number');
      ['min', 'max', 'step'].forEach((property) => {
        if (Object.hasOwn(constraints, property)) {
          input.setAttribute(property, String(constraints[property]));
        }
      });
      fieldset.appendChild(input);
    } else if (kind === 'table') {
      const table = document.createElement('table');
      const columns = Array.isArray(constraints.columns) ? constraints.columns : [];
      const rows = Array.isArray(constraints.rows) && constraints.rows.length > 0
        ? constraints.rows
        : [{ label: '응답' }];
      const head = document.createElement('thead');
      const headRow = document.createElement('tr');
      const corner = document.createElement('th');
      corner.textContent = '';
      headRow.appendChild(corner);
      columns.forEach((column) => {
        const cell = document.createElement('th');
        cell.textContent = String(column?.label || '항목');
        headRow.appendChild(cell);
      });
      head.appendChild(headRow);
      table.appendChild(head);
      const body = document.createElement('tbody');
      rows.forEach((row) => {
        const tableRow = document.createElement('tr');
        const label = document.createElement('th');
        label.textContent = String(row?.label || '응답');
        tableRow.appendChild(label);
        columns.forEach((column) => {
          const cell = document.createElement('td');
          cell.appendChild(previewInput(column?.cell_type === 'checkbox' ? 'checkbox' : column?.cell_type === 'number' ? 'number' : 'text'));
          tableRow.appendChild(cell);
        });
        body.appendChild(tableRow);
      });
      table.appendChild(body);
      fieldset.appendChild(table);
    } else if (kind === 'image_upload' || kind === 'file_upload') {
      const input = previewInput('file');
      if (Array.isArray(constraints.mime_types)) {
        input.accept = constraints.mime_types.join(',');
      }
      input.multiple = Number(constraints.max_files || 1) > 1;
      fieldset.appendChild(input);
    } else if (kind === 'drawing') {
      const placeholder = document.createElement('div');
      placeholder.className = 'oj-ai-workbench__preview-placeholder';
      placeholder.textContent = '그리기';
      fieldset.appendChild(placeholder);
    } else {
      throw new WorkbenchError('응답칸을 표시할 수 없습니다.', 'preview_response_invalid');
    }
    return fieldset;
  }

  async function mediaPreview(node, type) {
    const assetId = String(node.asset_id || '');
    if (!PUBLIC_ID_PATTERN.test(assetId)) {
      throw new WorkbenchError('첨부 파일을 표시할 수 없습니다.', 'preview_asset_invalid');
    }
    const url = await fetchAssetPreviewUrl(
      assetId,
      'revision',
      state.currentRevisionId,
      state.projectionPreviewUrls
    );
    if (type === 'img' || type === 'oj_ai_drawing') {
      const image = document.createElement('img');
      image.src = url;
      image.alt = String(node.alt || '활동지 이미지');
      if (Number.isInteger(node.width)) {
        image.style.width = String(node.width) + '%';
      }
      return image;
    }
    if (type === 'audio') {
      const audio = document.createElement('audio');
      audio.src = url;
      audio.controls = true;
      return audio;
    }
    if (type === 'video') {
      const video = document.createElement('video');
      video.src = url;
      video.controls = true;
      return video;
    }
    const link = document.createElement('a');
    link.href = url;
    link.textContent = String(node.label || '첨부 파일');
    link.target = '_blank';
    link.rel = 'noopener';
    return link;
  }

  async function previewNode(node) {
    if (!isObject(node)) {
      throw new WorkbenchError('미리보기 내용을 읽을 수 없습니다.', 'preview_node_invalid');
    }
    if (Object.hasOwn(node, 'text')) {
      return previewTextNode(node);
    }
    const type = String(node.type || '');
    if (type === 'oj_ai_response') {
      return responsePreview(node);
    }
    if (['img', 'audio', 'video', 'file', 'oj_ai_drawing'].includes(type)) {
      return mediaPreview(node, type);
    }
    if (type === 'hr') {
      return document.createElement('hr');
    }
    if (type === 'page_break') {
      const pageBreak = document.createElement('div');
      pageBreak.className = 'oj-ai-workbench__preview-page-break';
      pageBreak.setAttribute('role', 'separator');
      pageBreak.setAttribute('aria-label', '페이지 나눔');
      return pageBreak;
    }
    const tagNames = {
      p: 'p',
      h1: 'h1',
      h2: 'h2',
      h3: 'h3',
      blockquote: 'blockquote',
      ul: 'ul',
      ol: 'ol',
      li: 'li',
      a: 'a',
      table: 'table',
      tr: 'tr',
      td: 'td',
      th: 'th',
      caption: 'caption',
      code_block: 'pre',
      code_line: 'code',
      callout: 'aside',
      column_group: 'div',
      column: 'div',
      equation: 'div',
    };
    const isQuestion = isCanonicalQuestionNode(node);
    const tagName = isQuestion ? 'section' : tagNames[type];
    if (!tagName) {
      throw new WorkbenchError('미리보기 내용을 표시할 수 없습니다.', 'preview_node_type_invalid');
    }
    const element = document.createElement(tagName);
    const tableWidths = node.column_ratios || node.column_widths;
    if (type === 'table' && Array.isArray(tableWidths) && tableWidths.length) {
      const total = tableWidths.reduce((sum, width) => sum + width, 0);
      element.style.width = Number.isInteger(node.width_percent) ? `${node.width_percent}%` : `${total}px`;
      element.style.maxWidth = '100%';
      element.style.tableLayout = 'fixed';
      element.style.overflowWrap = 'anywhere';
      const columns = document.createElement('colgroup');
      tableWidths.forEach((width) => {
        const column = document.createElement('col');
        column.style.width = `${width / total * 100}%`;
        columns.append(column);
      });
      element.append(columns);
    }
    if (type === 'a' && typeof node.url === 'string' && /^https:\/\//i.test(node.url)) {
      element.href = node.url;
      element.target = '_blank';
      element.rel = 'noopener';
    }
    if (type === 'ol' && Number.isInteger(node.start)) {
      element.start = node.start;
    }
    if (type === 'callout') {
      element.className = isQuestion ? 'oj-ai-workbench__preview-question' : 'oj-ai-workbench__preview-callout';
    }
    if (type === 'column_group') {
      element.className = 'oj-ai-workbench__preview-columns';
    }
    if (type === 'equation') {
      element.textContent = String(node.tex || '');
      return element;
    }
    if (typeof node.align === 'string') {
      element.style.textAlign = node.align;
    }
    const children = Array.isArray(node.children) ? node.children : [];
    for (const child of children) {
      element.appendChild(await previewNode(child));
    }
    return element;
  }

  function projectionDocument(preview, kind) {
    const projection = preview?.projection;
    if (!isObject(projection)) {
      throw new WorkbenchError('미리보기 결과를 읽을 수 없습니다.', 'preview_projection_invalid');
    }
    if (kind === 'student') {
      const documentValue = projection.student_document?.payload;
      if (
        projection.kind !== 'student'
        || projection.student_document?.schema_id !== 'oj_ai.student_document'
        || !isObject(documentValue)
      ) {
        throw new WorkbenchError('학생 미리보기를 읽을 수 없습니다.', 'preview_student_invalid');
      }
      return documentValue;
    }
    const print = projection.print_projection;
    const documentValue = print?.payload?.document;
    if (
      projection.kind !== 'print'
      || print?.schema_id !== 'oj_ai.worksheet_print'
      || !isObject(documentValue)
    ) {
      throw new WorkbenchError('인쇄 미리보기를 읽을 수 없습니다.', 'preview_print_invalid');
    }
    return documentValue;
  }

  async function renderProjectionPreview(documentValue) {
    const content = root.querySelector('[data-role="preview-content"]');
    if (!content || !Array.isArray(documentValue.nodes)) {
      throw new WorkbenchError('미리보기 내용을 읽을 수 없습니다.', 'preview_document_invalid');
    }
    const article = document.createElement('article');
    article.className = 'oj-ai-workbench__preview-document';
    for (const node of documentValue.nodes) {
      article.appendChild(await previewNode(node));
    }
    content.replaceChildren(article);
  }

  function setPreviewMode(kind) {
    const shell = root.querySelector('[data-role="preview-shell"]');
    const title = root.querySelector('[data-role="preview-title"]');
    const printButton = root.querySelector('[data-action="preview-print-page"]');
    if (shell) {
      shell.dataset.kind = kind;
    }
    if (title) {
      title.textContent = kind === 'print' ? '인쇄 미리보기' : '학생 미리보기';
    }
    root.querySelectorAll('[data-action="preview-student"], [data-action="preview-print"]').forEach((tab) => {
      const selected = tab.dataset.action === (kind === 'print' ? 'preview-print' : 'preview-student');
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    if (printButton) {
      printButton.hidden = kind !== 'print';
    }
  }

  function closePreview() {
    const shell = root.querySelector('[data-role="preview-shell"]');
    if (shell) {
      shell.hidden = true;
    }
    document.body.classList.remove('oj-ai-preview-open');
    releaseProjectionPreviewUrls();
    root.querySelector('[data-action="preview-open"]')?.focus();
  }

  async function openPreview(kind) {
    if (!state.loaded || state.actionBusy) {
      return;
    }
    if (hasPendingChanges()) {
      setNotice('미리보기 전에 저장해 주세요.', true, 'error');
      return;
    }
    const shell = root.querySelector('[data-role="preview-shell"]');
    const status = root.querySelector('[data-role="preview-status"]');
    const content = root.querySelector('[data-role="preview-content"]');
    if (!shell || !status || !content) {
      return;
    }
    state.previewKind = kind;
    releaseProjectionPreviewUrls();
    setPreviewMode(kind);
    shell.hidden = false;
    document.body.classList.add('oj-ai-preview-open');
    content.replaceChildren();
    status.textContent = '불러오는 중';
    status.dataset.tone = 'neutral';
    setActionBusy(true);
    try {
      const envelope = await apiRequest(previewRequestPath(state.currentRevisionId, kind));
      await renderProjectionPreview(projectionDocument(envelope.data.preview, kind));
      status.textContent = kind === 'print' ? '인쇄 화면' : '학생 화면';
    } catch (error) {
      content.replaceChildren();
      status.textContent = error instanceof Error ? error.message : '미리보기를 불러오지 못했습니다.';
      status.dataset.tone = 'error';
    } finally {
      setActionBusy(false);
    }
  }

  async function loadWorksheetQualitySummary() {
    const quality = root.querySelector('[data-role="quality-status"]');
    if (!quality || !PUBLIC_ID_PATTERN.test(state.worksheetId)) {
      return;
    }
    quality.textContent = 'AI 점검 상태 확인 중';
    try {
      const query = new URLSearchParams({ worksheet_id: state.worksheetId, limit: '1' });
      const envelope = await apiRequest(`/worksheet-evaluations?${query.toString()}`);
      const evaluation = Array.isArray(envelope.data?.items) ? envelope.data.items[0] : null;
      if (!evaluation) {
        quality.textContent = '아직 AI 점검 전';
        return;
      }
      const findingCount = Math.max(0, Number(evaluation.status?.finding_count || 0));
      if (evaluation.is_stale === true) {
        quality.textContent = '새 버전 점검 필요';
      } else if (evaluation.state === 'reviewed') {
        quality.textContent = `점검 완료 · 지적 ${findingCount}개`;
      } else if (evaluation.state === 'review_required') {
        quality.textContent = `검토 필요 · 지적 ${findingCount}개`;
      } else if (['requested', 'queued', 'running'].includes(evaluation.state)) {
        quality.textContent = 'AI 점검 진행 중';
      } else if (evaluation.state === 'failed') {
        quality.textContent = '점검 실패 · 다시 확인';
      } else {
        quality.textContent = '평가 화면에서 확인하세요.';
      }
    } catch (_error) {
      quality.textContent = '평가 화면에서 확인하세요.';
    }
  }

  async function applyLoadedDocument(worksheet, revisionData, showRecovery) {
    const previousStage = state.loaded ? root.dataset.workflowStage : '';
    const documentValue = revisionData.teacher_document;
    const titleField = document.getElementById('ws-template-title');
    state.currentDocument = clone(documentValue);
    await loadStudentAssessmentCriteria();
    state.learningGoals = learningGoalsFromDocument(documentValue);
    state.assessmentBindings = assessmentBindingsFromDocument(documentValue, state.assessmentCriteria);
    state.achievementLinks = normalizeAchievementLinks(revisionData.achievement_links);
    state.achievementSnapshots = Array.isArray(revisionData.standard_snapshots) ? clone(revisionData.standard_snapshots) : [];
    state.preparation = isObject(documentValue.settings?.preparation) ? clone(documentValue.settings.preparation) : { state: 'draft', rubric_revision_id: null, rubric_content_hash: null };
    // Project criteria are immutable; worksheet response mappings do not reopen that approval.
    if (projectCriteriaConfirmed()) state.preparation = { state: 'confirmed', ...state.rubricPin };
    state.preparationBaseline = preparationFingerprint();
    state.currentDocumentHash = String(revisionData.revision?.document_hash || '');
    state.currentLockVersion = Number(worksheet.lock_version || 0);
    state.currentRevisionId = String(worksheet.current_revision_id || revisionData.revision.id || '');
    state.currentRevisionState = String(revisionData.revision?.state || 'draft');
    state.currentPublicationId = String(revisionData.revision?.publication_id || '');
    state.initialWorkflowResolved = false;
    state.currentState = String(worksheet.state || 'draft');
    state.latestPublicationId = String(worksheet.latest_publication_id || '');
    closePreview();
    releasePreviewUrls();
    hideValidation();
    const schema = await hydrateAssetPreviews(teacherDocumentToPlateSchema(documentValue), state.currentRevisionId);
    if (titleField) {
      titleField.value = String(documentValue.title || worksheet.title || '');
    }
    writePlateSchema(schema, true);
    renderDocumentOutline(documentValue);
    state.remoteFingerprint = captureRemoteFingerprint(schema, titleField ? titleField.value : documentValue.title);
    state.loaded = true;
    window.OJAIWorkflow?.setSelection({ worksheetId: state.worksheetId, title: documentValue.title || worksheet.title });
    renderLearningGoals();
    renderAchievementLinks();
    renderAssessmentMap();
    const qualityLink = root.querySelector('[data-role="quality-link"]');
    if (qualityLink) {
      qualityLink.href = window.OJAIWorkflow?.url('review') || qualityLink.href;
    }
    if (state.workflowEnabled) setWorkflowStage(previousStage || initialWorkflowStage());
    void loadWorksheetQualitySummary();
    const plateRoot = document.getElementById('ws-plate-template-builder-root');
    if (plateRoot) {
      plateRoot.setAttribute('aria-busy', 'false');
    }
    refreshEditorActions();
    hideConflict();
    setNotice('', false);
    setSaveStatus('저장됨', 'saved');
    if (showRecovery) {
      showRecoveryIfNeeded();
    }
  }

  async function loadEditor(showRecovery) {
    setSaveStatus('불러오는 중', 'neutral');
    state.loaded = false;
    refreshEditorActions();
    try {
      const worksheetEnvelope = await apiRequest(`/worksheets/${encodeURIComponent(state.worksheetId)}`);
      const worksheet = worksheetEnvelope.data.worksheet;
      const revisionId = String(worksheet.current_revision_id || '');
      if (!PUBLIC_ID_PATTERN.test(revisionId)) {
        throw new ApiError(503, {});
      }
      const revisionEnvelope = await apiRequest(`/worksheet-revisions/${encodeURIComponent(revisionId)}`);
      await applyLoadedDocument(worksheet, revisionEnvelope.data, showRecovery !== false);
      const entryWasHidden = entryContent?.hidden;
      if (entryContent) entryContent.hidden = false;
      if (entryStatus) entryStatus.hidden = true;
      if (entryWasHidden && state.workflowEnabled) {
        document.dispatchEvent(new CustomEvent('ojai:workflow-stage', { detail: { stage: root.dataset.workflowStage } }));
      }
      return true;
    } catch (error) {
      setSaveStatus('불러오기 실패', 'error');
      setNotice(error instanceof Error ? error.message : '활동지를 불러오지 못했습니다.', true, 'error');
      if (entryContent?.hidden) showEntryError(error);
      return false;
    }
  }

  async function saveEditor(forcedDraft) {
    const saveButton = root.querySelector('[data-action="save"]');
    const applyingCriteria = Boolean(forcedDraft?.project) && state.criteriaRefreshBusy
      && state.canManage && !state.actionBusy && state.currentState === 'draft';
    if (!state.loaded || (saveButton && saveButton.disabled && !applyingCriteria)) {
      return;
    }
    let schema;
    let title;
    let teacherDocument;
    let achievementLinks;
    let assessmentBindings;
    let learningGoals;
    const refreshedProject = forcedDraft?.project || null;
    try {
      schema = forcedDraft && forcedDraft.schema ? forcedDraft.schema : parseBuilderSchema();
      const titleField = document.getElementById('ws-template-title');
      title = forcedDraft && typeof forcedDraft.title === 'string'
        ? forcedDraft.title
        : (titleField ? titleField.value : '');
      achievementLinks = forcedDraft && Array.isArray(forcedDraft.achievement_links)
        ? normalizeAchievementLinks(forcedDraft.achievement_links)
        : normalizeAchievementLinks(state.achievementLinks);
      learningGoals = forcedDraft && Array.isArray(forcedDraft.learning_goals)
        ? normalizeLearningGoals(forcedDraft.learning_goals)
        : normalizeLearningGoals(state.learningGoals);
      if (learningGoals.length > learningGoalLimit) {
        throw new WorkbenchError(`학습 목표는 ${learningGoalLimit}개까지 입력할 수 있습니다.`, 'learning_goal_limit_exceeded');
      }
      if (learningGoals.some((goal) => !goal.text.trim())) {
        throw new WorkbenchError('학습 목표 문장을 입력해 주세요.', 'learning_goal_text_required');
      }
      if (learningGoals.some((goal) => Array.from(goal.text).length > learningGoalCharacterLimit)) {
        throw new WorkbenchError('학습 목표 문장이 너무 깁니다.', 'learning_goal_text_too_long');
      }
      const responseIds = new Set(currentResponseDescriptors().map((response) => response.response_id));
      if (learningGoals.some((goal) => goal.scope === 'response' && !responseIds.has(goal.response_id))) {
        throw new WorkbenchError('삭제된 문항에 연결된 학습 목표의 적용 대상을 바꿔 주세요.', 'learning_goal_response_missing');
      }
      const hasForcedAssessmentBindings = forcedDraft && Array.isArray(forcedDraft.assessment_bindings);
      assessmentBindings = hasForcedAssessmentBindings
        ? normalizeAssessmentBindings(forcedDraft.assessment_bindings)
        : normalizeAssessmentBindings(state.assessmentBindings);
      if (!hasForcedAssessmentBindings && state.assessmentCriteriaReady && !state.assessmentUnavailable) {
        assessmentBindings = assessmentBindingsFromPlateSchema(
          schema,
          assessmentBindings,
          state.assessmentCriteria
        );
        state.assessmentBindings = assessmentBindings;
      }
      teacherDocument = plateSchemaToTeacherDocument(
        schema,
        state.currentDocument,
        title,
        state.locale,
        assessmentBindings,
        {
          criteria: refreshedProject ? refreshedProject.configuration.criteria : state.assessmentCriteria,
          criteria_ready: state.assessmentCriteriaReady,
          unavailable: state.assessmentUnavailable,
        },
        learningGoals
      );
      if (state.workflowEnabled) teacherDocument.settings.preparation = clone(state.preparation);
      if (refreshedProject) {
        teacherDocument.settings.assessment_project = {project_id:refreshedProject.project_id,revision_id:refreshedProject.revision_id,content_hash:refreshedProject.content_hash};
        teacherDocument.settings.preparation = {state:'confirmed',rubric_revision_id:refreshedProject.revision_id,rubric_content_hash:refreshedProject.rubric_content_hash};
      }
    } catch (error) {
      setSaveStatus('점검 필요', 'error');
      setNotice(error instanceof Error ? error.message : '저장할 내용을 점검해 주세요.', true);
      return;
    }

    setActionBusy(true);
    setNotice('', false);
    setSaveStatus('저장 중', 'saving');
    const commandId = commandToken('save');
    const localDraft = { schema: clone(schema), learning_goals: clone(learningGoals), achievement_links: clone(achievementLinks), assessment_bindings: clone(assessmentBindings), title };
    const documentAssetIds = collectDocumentAssetIds(teacherDocument);
    const stagedReceiptIds = Array.from(state.stagedAssets.keys()).filter((assetId) => documentAssetIds.has(assetId));

    try {
      const envelope = await apiRequest(
        `/worksheets/${encodeURIComponent(state.worksheetId)}/commands/save-draft`,
        {
          method: 'POST',
          idempotencyKey: commandToken('save-key'),
          body: {
            command_id: commandId,
            expected: {
              expected_state: state.currentState,
              expected_revision_id: state.currentRevisionId,
            },
            payload: {
              teacher_document: teacherDocument,
              achievement_links: achievementLinks,
              staged_asset_receipt_ids: stagedReceiptIds,
            },
          },
        }
      );
      const temporaryMap = isObject(envelope.data.temporary_id_map) ? envelope.data.temporary_id_map : {};
      state.currentDocument = applyTemporaryIdMap(teacherDocument, temporaryMap);
      if (refreshedProject) {
        state.preparation = clone(teacherDocument.settings.preparation);
        await loadStudentAssessmentCriteria();
      }
      state.learningGoals = learningGoalsFromDocument(state.currentDocument);
      state.assessmentBindings = assessmentBindingsFromDocument(state.currentDocument);
      state.achievementLinks = achievementLinks.map((link) => ({
        ...link,
        response_id: link.scope === 'response' && temporaryMap[link.response_id]
          ? temporaryMap[link.response_id]
          : link.response_id,
      }));
      state.preparationBaseline = preparationFingerprint();
      state.currentDocumentHash = String(envelope.data.revision.document_hash || '');
      state.currentRevisionId = String(envelope.data.worksheet.current_revision_id || envelope.data.revision.id || '');
      state.currentRevisionState = String(envelope.data.revision.state || 'draft');
      state.currentState = String(envelope.data.worksheet.state || state.currentState);
      if (envelope.meta?.unchanged !== true) {
        state.currentLockVersion += 1;
        state.currentPublicationId = '';
      }
      stagedReceiptIds.forEach((assetId) => state.stagedAssets.delete(assetId));
      renderStagedAssets();
      releasePreviewUrls();
      const mappedSchema = await hydrateAssetPreviews(
        teacherDocumentToPlateSchema(state.currentDocument), state.currentRevisionId
      );
      const titleField = document.getElementById('ws-template-title');
      if (titleField) {
        titleField.value = String(envelope.data.worksheet.title || title);
      }
      writePlateSchema(mappedSchema, true);
      renderDocumentOutline(state.currentDocument);
      renderLearningGoals();
      renderAchievementLinks();
      renderAssessmentMap();
      state.remoteFingerprint = captureRemoteFingerprint(mappedSchema, titleField ? titleField.value : title);
      hideValidation();
      removeRecovery();
      hideConflict();
      setSaveStatus('저장됨', 'saved');
      return true;
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        showConflict(error, localDraft);
      } else {
        setSaveStatus(error instanceof ApiError && error.status === 422 ? '점검 필요' : '저장 실패', 'error');
        setNotice(error instanceof Error ? error.message : '저장하지 못했습니다.', true, 'error');
        persistRecovery();
      }
    } finally {
      setActionBusy(false);
    }
  }

  async function approveCurrentRevision(button) {
    if (button.disabled || hasPendingChanges()) {
      if (hasPendingChanges()) {
        setNotice('승인하기 전에 저장해 주세요.', true, 'error');
      }
      return;
    }
    const approval = state.approval;
    if (!isObject(approval) || approval.approval_ready !== true) {
      setNotice('검증 결과를 확인해 주세요.', true, 'error');
      return;
    }
    const originalLabel = button.textContent;
    setActionBusy(true);
    button.textContent = '승인 중';
    setNotice('', false);
    try {
      const envelope = await apiRequest(
        `/worksheet-revisions/${encodeURIComponent(state.currentRevisionId)}/commands/approve`,
        {
          method: 'POST',
          idempotencyKey: commandToken('approve-key'),
          body: approvalCommandBody(
            commandToken('approve'),
            state.currentRevisionId,
            state.currentDocumentHash,
            String(state.validation?.projection_hash || ''),
            String(approval.decision_hash || '')
          ),
        }
      );
      state.currentRevisionState = String(envelope.data.revision.state || 'approved');
      state.currentDocumentHash = String(envelope.data.revision.document_hash || state.currentDocumentHash);
      state.currentLockVersion += Number(envelope.meta?.mutation_count || 0);
      state.approval = {
        approval_ready: true,
        blockers: [],
        decision_hash: String(envelope.data.approval.decision_hash || ''),
        evidence_hash: String(envelope.data.approval.evidence_hash || ''),
        status: 'approved',
        warnings: [],
      };
      setSaveStatus('승인됨', 'saved');
      setNotice('교사 검토가 승인되었습니다.', true, 'success');
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        hideValidation();
      }
      setNotice(error instanceof Error ? error.message : '승인하지 못했습니다.', true, 'error');
    } finally {
      button.textContent = originalLabel;
      setActionBusy(false);
    }
  }

  function confirmPublication(button) {
    if (root.querySelector('.oj-ai-workbench__publish-dialog')) return Promise.resolve(false);
    const dialog = document.createElement('dialog');
    dialog.className = 'oj-ai-workbench__publish-dialog';
    dialog.setAttribute('aria-labelledby', 'oj-ai-publish-title');
    dialog.setAttribute('aria-describedby', 'oj-ai-publish-worksheet');
    const form = document.createElement('form');
    form.method = 'dialog';
    const title = document.createElement('h2');
    title.id = 'oj-ai-publish-title';
    title.textContent = '배포용 버전 발행';
    const worksheet = document.createElement('p');
    worksheet.id = 'oj-ai-publish-worksheet';
    worksheet.textContent = root.querySelector('#ws-template-title')?.value || '활동지';
    const footer = document.createElement('footer');
    const cancel = document.createElement('button');
    cancel.type = 'submit';
    cancel.value = 'cancel';
    cancel.textContent = '취소';
    cancel.autofocus = true;
    const confirm = document.createElement('button');
    confirm.type = 'submit';
    confirm.value = 'publish';
    confirm.className = 'oj-ai-workbench__primary';
    confirm.textContent = '발행하기';
    footer.append(cancel, confirm);
    form.append(title, worksheet, footer);
    dialog.append(form);
    root.append(dialog);
    return new Promise((resolve) => {
      dialog.addEventListener('close', () => {
        const accepted = dialog.returnValue === 'publish';
        dialog.remove();
        if (button.isConnected) button.focus();
        resolve(accepted);
      }, { once: true });
      try {
        dialog.showModal();
      } catch {
        dialog.remove();
        setNotice('발행 확인 창을 열지 못했습니다. 화면을 새로고침해 주세요.', true, 'error');
        resolve(false);
      }
    });
  }

  async function publishCurrentRevision(button) {
    if (button.disabled || hasPendingChanges()) {
      if (hasPendingChanges()) {
        setNotice('발행하기 전에 저장해 주세요.', true, 'error');
      }
      return;
    }
    const revisionId = state.currentRevisionId;
    const documentHash = state.currentDocumentHash;
    const decisionHash = state.approval?.decision_hash;
    if (!await confirmPublication(button)) {
      return;
    }
    if (button.disabled || hasPendingChanges() || revisionId !== state.currentRevisionId
      || documentHash !== state.currentDocumentHash || decisionHash !== state.approval?.decision_hash) {
      setNotice('내용이 바뀌었습니다. 문서를 다시 확인해 주세요.', true, 'error');
      return;
    }
    const originalLabel = button.textContent;
    setActionBusy(true);
    button.textContent = '발행 중';
    setNotice('', false);
    try {
      const envelope = await apiRequest(
        `/worksheet-revisions/${encodeURIComponent(state.currentRevisionId)}/commands/publish`,
        {
          method: 'POST',
          idempotencyKey: commandToken('publish-key'),
          body: publicationCommandBody(
            commandToken('publish'),
            state.currentRevisionId,
            state.currentDocumentHash,
            String(state.approval?.decision_hash || '')
          ),
        }
      );
      state.currentState = 'active';
      state.latestPublicationId = String(envelope.data.publication.id || '');
      state.currentPublicationId = state.latestPublicationId;
      state.currentLockVersion += Number(envelope.meta?.mutation_count || 0);
      setSaveStatus('발행됨', 'saved');
      setNotice('배포용 버전을 발행했습니다. 배포에서 수업과 일정을 선택해 주세요.', true, 'success');
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        hideValidation();
      }
      setNotice(error instanceof Error ? error.message : '발행하지 못했습니다.', true, 'error');
    } finally {
      button.textContent = originalLabel;
      setActionBusy(false);
    }
  }

  async function changeWorksheetLifecycle(operation, button) {
    if (button.disabled || hasPendingChanges()) {
      if (hasPendingChanges()) {
        setNotice('먼저 변경 내용을 저장해 주세요.', true, 'error');
      }
      return;
    }
    if (operation === 'archive' && !(await (window.OJP && typeof window.OJP.confirm === 'function' ? window.OJP.confirm({title:'이 활동지를 보관할까요?',message:'보관한 활동지는 보관함에서 복원할 수 있습니다.',confirmLabel:'보관'}) : Promise.resolve(window.confirm('이 활동지를 보관할까요?'))))) {
      return;
    }
    const originalLabel = button.textContent;
    setActionBusy(true);
    button.textContent = operation === 'archive' ? '보관 중' : '복원 중';
    setNotice('', false);
    try {
      const envelope = await apiRequest(
        `/worksheets/${encodeURIComponent(state.worksheetId)}/commands/${operation}`,
        {
          method: 'POST',
          idempotencyKey: commandToken(`${operation}-key`),
          body: lifecycleCommandBody(
            commandToken(operation),
            state.currentState,
            state.currentRevisionId,
            state.currentLockVersion
          ),
        }
      );
      const worksheet = envelope.data.worksheet;
      state.currentState = String(worksheet.state || state.currentState);
      state.currentRevisionId = String(worksheet.current_revision_id || state.currentRevisionId);
      state.latestPublicationId = String(worksheet.latest_publication_id || '');
      state.currentLockVersion = Number(worksheet.lock_version || state.currentLockVersion);
      hideValidation();
      setSaveStatus(operation === 'archive' ? '보관됨' : '복원됨', 'saved');
      setNotice(operation === 'archive' ? '보관했습니다.' : '복원했습니다.', true, 'success');
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        await loadEditor(false);
        setNotice('최신 상태를 불러왔습니다.', true, 'error');
      } else {
        setNotice(error instanceof Error ? error.message : '상태를 바꾸지 못했습니다.', true, 'error');
      }
    } finally {
      button.textContent = originalLabel;
      setActionBusy(false);
    }
  }

  function bindList() {
    root.querySelector('[data-role="worksheet-list"]')?.addEventListener('click', async (event) => {
      const link = event.target.closest('[data-action="open-worksheet"]');
      if (link && state.canManage && !event.defaultPrevented && event.button === 0
        && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && !link.target) {
        event.preventDefault();
        const sequence = ++state.listNavigationSequence;
        link.setAttribute('aria-busy', 'true');
        setNotice('', false);
        try {
          const destination = await worksheetDestination(link.dataset.worksheetId);
          if (sequence === state.listNavigationSequence) window.location.assign(destination || link.href);
        } catch (error) {
          if (sequence === state.listNavigationSequence) setNotice(error.message || '활동지를 열지 못했습니다.', true, 'error');
        } finally {
          link.removeAttribute('aria-busy');
        }
        return;
      }
      const button = event.target.closest('[data-action="copy-worksheet"]');
      if (!button || button.disabled || !state.canManage) return;
      button.disabled = true;
      try {
        const sourceId = button.dataset.worksheetId;
        const source = await apiRequest(`/worksheets/${encodeURIComponent(sourceId)}`);
        const worksheet = source.data.worksheet;
        const result = await apiRequest(`/worksheets/${encodeURIComponent(sourceId)}/commands/duplicate`, {
          method: 'POST', idempotencyKey: commandToken('duplicate-key'),
          body: duplicateCommandBody(commandToken('duplicate'), worksheet.state, worksheet.current_revision_id),
        });
        const id = result.data.worksheet?.id;
        if (!PUBLIC_ID_PATTERN.test(String(id || ''))) throw new WorkbenchError('복사한 활동지를 확인할 수 없습니다.', 'duplicate_invalid');
        window.location.assign(await worksheetDestination(id));
      } catch (error) { setNotice(error.message || '복사하지 못했습니다.', true); button.disabled = false; }
    });
    root.querySelector('[data-field="search"]')?.addEventListener('input', renderWorksheetList);
    root.querySelector('[data-field="state"]')?.addEventListener('change', loadWorksheetList);
    loadWorksheetList();
  }

  function bindEditor() {
    bindOutlineResize();
    document.addEventListener('ojai:worksheet-review-completed', (event) => {
      if (!state.loaded || event.detail?.worksheet_id !== state.worksheetId
        || event.detail?.revision_id !== state.currentRevisionId) return;
      state.pendingReviewRevisionId = state.currentRevisionId;
      hideValidation();
      refreshCompletedReview();
    });
    root.querySelectorAll('[data-action="workflow-criteria"]').forEach(button => button.addEventListener('click', () => {
      if (state.workspaceProject && window.OJAIWorkflow) {
        const href = window.OJAIWorkflow.url('criteria');
        if (document.dispatchEvent(new CustomEvent('ojai:before-navigation', { cancelable: true, detail: { href } }))) window.location.assign(href);
      }
    }));
    root.querySelector('[data-action="workflow-author"]')?.addEventListener('click', () => setWorkflowStage('author'));
    root.querySelector('[data-action="workflow-review"]')?.addEventListener('click', () => setWorkflowStage('review'));
    root.querySelector('[data-action="review-validate"]')?.addEventListener('click', event => validateCurrentRevision(event.currentTarget, false));
    document.addEventListener('ojai:before-navigation', event => {
      if (hasPendingChanges() || state.actionBusy || state.criteriaRefreshBusy) { event.preventDefault(); setNotice(state.criteriaRefreshBusy ? '평가 기준을 적용하고 있습니다. 잠시 기다려 주세요.' : '변경 내용을 저장한 뒤 이동해 주세요.', true); }
    });
    root.querySelectorAll('[data-criteria-library]').forEach(link => link.addEventListener('click', event => {
      if (hasPendingChanges() || state.actionBusy) { event.preventDefault(); setNotice('변경 내용을 저장한 뒤 평가 기준을 열어 주세요.', true); }
    }));
    root.querySelectorAll('[data-action="toggle-outline"], [data-action="toggle-inspector"]').forEach(button => button.addEventListener('click', () => {
      const name = button.dataset.action.replace('toggle-', '');
      setAuthorPanel(root.dataset.compactPanel === name ? '' : name);
    }));
    root.querySelectorAll('[data-action="close-author-panel"]').forEach(button => button.addEventListener('click', () => setAuthorPanel('', true)));
    root.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && root.dataset.workflowStage === 'author' && event.target instanceof Element
        && event.target.closest('.oj-ai-workbench__outline, .oj-ai-workbench__outline-resizer, .oj-ai-workbench__inspector')) {
        event.preventDefault(); setAuthorPanel('', true);
      }
    });
    root.querySelectorAll('[data-workflow-review], [data-role="quality-link"]').forEach((link) => link.addEventListener('click', (event) => {
      event.preventDefault();
      if (state.workflowEnabled && (!preparationConfirmed() || hasPendingChanges())) {
        event.preventDefault(); setNotice(!preparationConfirmed() ? '기준을 먼저 확정해 주세요.' : '변경 내용을 저장한 뒤 검토해 주세요.', true);
      } else setWorkflowStage('review');
    }));
    // The Plate editor's toolbar scrolls horizontally when it overflows
    // (resources/css/worksheet-authoring-workbench.css), but a plain mouse
    // wheel only ever produces vertical scroll deltas, and not every mouse
    // (or touchpad) offers a native horizontal-scroll gesture -- so on a
    // narrow viewport the trailing buttons (e.g. "응답") can become
    // unreachable. Redirect vertical wheel input to horizontal scroll while
    // hovering an overflowing toolbar, same idiom used for horizontally
    // scrolling tab bars/carousels.
    root.addEventListener('wheel', (event) => {
      const toolbar = event.target instanceof Element ? event.target.closest('[role="toolbar"]') : null;
      if (!toolbar || toolbar.scrollWidth <= toolbar.clientWidth) {
        return;
      }
      if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
        return;
      }
      toolbar.scrollLeft += event.deltaY;
      event.preventDefault();
    }, { passive: false });
    const builder = document.getElementById('ws-builder-schema');
    const title = document.getElementById('ws-template-title');
    builder?.addEventListener('input', () => {
      scheduleRecovery();
      try {
        const schema = parseBuilderSchema();
        if (!state.suppressRecovery) {
          state.assessmentBindings = assessmentBindingsFromPlateSchema(
            schema,
            state.assessmentBindings,
            state.assessmentCriteria
          );
        }
        renderPlateQuestionOutline(schema);
      } catch {
        // The save/validation flow displays malformed schema errors.
      }
      renderLearningGoals();
      renderAchievementLinks();
      renderAssessmentMap();
    });
    title?.addEventListener('input', scheduleRecovery);
    const inspector = root.querySelector('.oj-ai-workbench__inspector');
    inspector?.addEventListener('click', (event) => {
      const element = event.target instanceof Element ? event.target : null;
      const tab = element?.closest('[data-inspector-tab]');
      if (tab) {
        activateInspectorTab(String(tab.dataset.inspectorTab || 'question'));
        return;
      }
      const addGoal = element?.closest('[data-action="add-learning-goal"]');
      if (addGoal) {
        addLearningGoal();
        return;
      }
      const removeGoal = element?.closest('[data-action="remove-learning-goal"]');
      if (removeGoal) {
        const index = Number(removeGoal.dataset.goalIndex);
        if (Number.isInteger(index) && state.learningGoals[index]) {
          state.learningGoals.splice(index, 1);
          renderLearningGoals();
          scheduleRecovery();
        }
        return;
      }
      const add = element?.closest('[data-action="add-achievement-link"]');
      if (add) {
        addAchievementLink(String(add.dataset.standardId || ''));
        return;
      }
      const remove = element?.closest('[data-action="remove-achievement-link"]');
      if (remove) {
        const index = Number(remove.dataset.linkIndex);
        if (Number.isInteger(index) && state.achievementLinks[index]) {
          state.achievementLinks.splice(index, 1);
          renderAchievementLinks();
          scheduleRecovery();
        }
        return;
      }
      const assessmentTarget = element?.closest('[data-assessment-response-id]');
      if (assessmentTarget) {
        activateInspectorTab('question');
        const responseId = String(assessmentTarget.dataset.assessmentResponseId || '');
        const questionId = String(assessmentTarget.dataset.assessmentQuestionId || responseId);
        setSelectedQuestionOutline(questionId);
        window.dispatchEvent(new CustomEvent('ojai-plate-question-select', { detail: { id: questionId } }));
        window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
          detail: { id: responseId, questionId },
        }));
      }
    });
    inspector?.addEventListener('change', (event) => {
      const control = event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement
        ? event.target
        : null;
      if (!control) return;

      if (control.dataset.field === 'curriculum-select') {
        state.selectedCurriculumId = String(control.value || '');
        void loadAchievementStandards(state.selectedCurriculumId);
        return;
      }

      if (control.dataset.field === 'learning-goal-target') {
        const index = Number(control.dataset.goalIndex);
        if (!Number.isInteger(index) || !state.learningGoals[index]) return;
        const goal = { ...state.learningGoals[index] };
        if (control.value === 'worksheet') {
          goal.scope = 'worksheet';
          goal.response_id = null;
        } else if (control.value.startsWith('response:')) {
          goal.scope = 'response';
          goal.response_id = control.value.slice(9);
        }
        state.learningGoals[index] = goal;
        renderLearningGoals();
        scheduleRecovery();
        return;
      }

      if (control.dataset.hwsResponseProp === 'rubric_criterion_id') {
        const responseEditor = control.closest('[data-hws-response-editor="1"]');
        const responseId = String(responseEditor?.getAttribute('data-hws-response-id') || '');
        const criterionId = String(control.value || '');
        state.assessmentBindings = state.assessmentBindings.filter((binding) => binding.criterion_id !== criterionId);
        if (control.checked && isDocumentId(responseId) && PUBLIC_ID_PATTERN.test(criterionId)) {
          state.assessmentBindings.push({
            criterion_id: criterionId,
            scope: 'response',
            response_id: responseId,
          });
        }
        state.assessmentBindings = normalizeAssessmentBindings(state.assessmentBindings);
        renderAssessmentMap();
        scheduleRecovery();
        return;
      }
      if (control.dataset.field === 'assessment-enabled') {
        const criterionId = String(control.dataset.criterionId || '');
        const criterion = state.assessmentCriteria.find((item) => item.criterion_id === criterionId);
        state.assessmentBindings = state.assessmentBindings.filter((binding) => binding.criterion_id !== criterionId);
        if (control.checked && PUBLIC_ID_PATTERN.test(criterionId)) {
          const responses = currentResponseDescriptors();
          const response = criterion?.scope === 'response' ? responses[0] : null;
          state.assessmentBindings.push({
            criterion_id: criterionId,
            scope: response ? 'response' : 'worksheet',
            response_id: response ? response.response_id : null,
          });
        }
        state.assessmentBindings = normalizeAssessmentBindings(state.assessmentBindings);
        writeAssessmentBindingsToPlate();
        renderAssessmentMap();
        scheduleRecovery();
        return;
      }

      if (control.dataset.field === 'assessment-target') {
        const criterionId = String(control.dataset.criterionId || '');
        const index = state.assessmentBindings.findIndex((binding) => binding.criterion_id === criterionId);
        if (index < 0) return;
        const binding = { ...state.assessmentBindings[index] };
        if (control.value === 'worksheet') {
          binding.scope = 'worksheet';
          binding.response_id = null;
        } else if (control.value.startsWith('response:')) {
          binding.scope = 'response';
          binding.response_id = control.value.slice(9);
        }
        state.assessmentBindings[index] = binding;
        state.assessmentBindings = normalizeAssessmentBindings(state.assessmentBindings);
        writeAssessmentBindingsToPlate();
        renderAssessmentMap();
        scheduleRecovery();
        return;
      }

      if (!(control instanceof HTMLSelectElement)) return;
      const index = Number(control.dataset.linkIndex);
      if (!Number.isInteger(index) || !state.achievementLinks[index]) return;
      const link = { ...state.achievementLinks[index] };
      if (control.dataset.field === 'achievement-target') {
        if (control.value === 'worksheet') {
          link.scope = 'worksheet';
          link.response_id = null;
        } else if (control.value.startsWith('response:')) {
          link.scope = 'response';
          link.response_id = control.value.slice(9);
        }
      }
      if (control.dataset.field === 'achievement-alignment') {
        link.alignment_type = control.value;
      }
      state.achievementLinks[index] = link;
      state.achievementLinks = normalizeAchievementLinks(state.achievementLinks);
      renderAchievementLinks();
      scheduleRecovery();
    });
    inspector?.addEventListener('input', (event) => {
      const control = event.target;
      if (control instanceof HTMLTextAreaElement && control.dataset.field === 'learning-goal-edit') {
        const index = Number(control.dataset.goalIndex);
        if (Number.isInteger(index) && state.learningGoals[index]) {
          state.learningGoals[index] = { ...state.learningGoals[index], text: control.value.slice(0, learningGoalCharacterLimit) };
          scheduleRecovery();
        }
        return;
      }
      if (control instanceof HTMLInputElement && control.dataset.field === 'achievement-search') {
        renderAchievementStandards();
      }
    });
    inspector?.addEventListener('keydown', (event) => {
      const current = event.target instanceof Element ? event.target.closest('[data-inspector-tab]') : null;
      if (!current || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      const tabs = [...inspector.querySelectorAll('[data-inspector-tab]')];
      const index = tabs.indexOf(current);
      let next = index;
      if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      event.preventDefault();
      activateInspectorTab(String(tabs[next].dataset.inspectorTab || 'question'));
      tabs[next].focus();
    });
    window.addEventListener('ojai-plate-response-grading-change', (event) => {
      const detail = event.detail || {};
      const responseId = String(detail.response_id || '');
      if (!isDocumentId(responseId)) return;
      const responseCriteria = new Set(state.assessmentCriteria
        .filter((criterion) => criterion.scope === 'response')
        .map((criterion) => criterion.criterion_id));
      const selected = (Array.isArray(detail.rubric_criterion_ids) ? detail.rubric_criterion_ids : [])
        .map(String)
        .filter((criterionId) => responseCriteria.has(criterionId));
      const selectedSet = new Set(selected);
      state.assessmentBindings = state.assessmentBindings.filter((binding) => {
        if (!responseCriteria.has(binding.criterion_id)) return true;
        if (selectedSet.has(binding.criterion_id)) return false;
        return binding.scope !== 'response' || binding.response_id !== responseId;
      });
      selected.forEach((criterionId) => {
        state.assessmentBindings.push({
          criterion_id: criterionId,
          scope: 'response',
          response_id: responseId,
        });
      });
      state.assessmentBindings = normalizeAssessmentBindings(state.assessmentBindings);
      renderAssessmentMap();
      scheduleRecovery();
    });
    window.addEventListener('ojai-plate-response-select', (event) => {
      const detail = event.detail || {};
      const responseId = String(detail.id || '');
      let questionId = String(detail.questionId || '');
      if (!questionId && responseId) {
        const response = Array.from(document.querySelectorAll('.hws-response-node__preview')).find((candidate) => (
          candidate.getAttribute('data-hws-response-id') === responseId
        ));
        questionId = String(response?.closest('.hws-question-block')?.getAttribute('data-hws-question-id') || '');
      }
      const selectedId = questionId || responseId;
      if (responseId && detail.showInspector === true && root.dataset.workflowStage === 'author') {
        activateInspectorTab('question');
        setAuthorPanel('inspector');
      }
      setSelectedQuestionOutline(selectedId);
      window.dispatchEvent(new CustomEvent('ojai-plate-question-select', { detail: { id: selectedId } }));
    });
    root.querySelector('[data-action="add-question"]')?.addEventListener('click', () => {
      if (document.getElementById('ws-plate-template-builder-root')?.dataset.readonly === 'true') return;
      window.dispatchEvent(new CustomEvent('ojai-plate-question-insert'));
    });
    root.querySelector('[data-role="document-outline"]')?.addEventListener('click', (event) => {
      const button = event.target instanceof Element
        ? event.target.closest('button[data-outline-node-id]')
        : null;
      if (!button) {
        return;
      }
      const key = String(button.dataset.outlineNodeId || '');
      const responseKey = String(button.dataset.outlineResponseId || key);
      const questions = Array.from(document.querySelectorAll('.hws-question-block[data-hws-question-id]'));
      const question = questions.find((candidate) => (
        candidate.getAttribute('data-hws-question-id') === key
      ));
      const responses = Array.from(question?.querySelectorAll('.hws-response-node__preview') || []);
      const response = responses.find((candidate) => (
        candidate.getAttribute('data-hws-response-id') === responseKey
      )) || responses[0];
      window.dispatchEvent(new CustomEvent('ojai-plate-question-select', {
        detail: { id: key },
      }));
      window.dispatchEvent(new CustomEvent('ojai-plate-response-select', {
        detail: { id: response?.getAttribute('data-hws-response-id') || responseKey, questionId: key, showInspector: true },
      }));
      if (question instanceof HTMLElement) {
        question.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      if (response instanceof HTMLElement) {
        response.click();
        response.focus({ preventScroll: true });
      }
    });
    root.querySelector('[data-action="save"]')?.addEventListener('click', () => saveEditor());
    root.querySelector('[data-action="refresh-project-criteria"]')?.addEventListener('click', () => refreshProjectCriteria());
    root.querySelector('[data-action="validate"]')?.addEventListener('click', (event) => {
      validateCurrentRevision(event.currentTarget);
    });
    root.querySelector('[data-action="validate-retry"]')?.addEventListener('click', (event) => {
      validateCurrentRevision(event.currentTarget);
    });
    root.querySelector('[data-action="approve"]')?.addEventListener('click', (event) => {
      approveCurrentRevision(event.currentTarget);
    });
    root.querySelector('[data-action="publish"]')?.addEventListener('click', (event) => {
      publishCurrentRevision(event.currentTarget);
    });
    root.querySelector('[data-action="archive"]')?.addEventListener('click', (event) => {
      changeWorksheetLifecycle('archive', event.currentTarget);
    });
    root.querySelector('[data-action="restore-worksheet"]')?.addEventListener('click', (event) => {
      changeWorksheetLifecycle('restore', event.currentTarget);
    });
    root.querySelectorAll('[data-action="preview-open"]').forEach(button => button.addEventListener('click', () => openPreview('student')));
    root.querySelector('[data-action="export-pdf"]')?.addEventListener('click', (event) => {
      exportCurrentWorksheet('pdf', event.currentTarget);
    });
    root.querySelector('[data-action="export-package"]')?.addEventListener('click', (event) => {
      exportCurrentWorksheet('package', event.currentTarget);
    });
    root.querySelector('[data-action="preview-student"]')?.addEventListener('click', () => openPreview('student'));
    root.querySelector('[data-action="preview-print"]')?.addEventListener('click', () => openPreview('print'));
    root.querySelectorAll('[data-action="preview-close"]').forEach((button) => {
      button.addEventListener('click', closePreview);
    });
    root.querySelector('[data-action="preview-print-page"]')?.addEventListener('click', () => {
      if (state.previewKind === 'print' && typeof window.print === 'function') {
        window.print();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !root.querySelector('[data-role="preview-shell"]')?.hidden) {
        closePreview();
      }
    });
    root.querySelector('[data-action="asset-upload"]')?.addEventListener('click', () => {
      root.querySelector('[data-field="asset-file"]')?.click();
    });
    root.querySelector('[data-action="import-template"]')?.addEventListener('click', () => {
      root.querySelector('[data-field="template-package"]')?.click();
    });
    root.querySelector('[data-action="duplicate"]')?.addEventListener('click', (event) => {
      duplicateCurrentWorksheet(event.currentTarget);
    });
    root.querySelector('[data-field="asset-file"]')?.addEventListener('change', (event) => {
      const file = event.currentTarget.files?.[0];
      if (file) {
        stageAssetFile(file, event.currentTarget);
      }
    });
    root.querySelector('[data-field="template-package"]')?.addEventListener('change', (event) => {
      const file = event.currentTarget.files?.[0];
      if (file) {
        importTemplatePackage(file, event.currentTarget);
      }
    });
    root.querySelector('[data-action="restore"]')?.addEventListener('click', () => restoreRecovery());
    root.querySelector('[data-action="discard-recovery"]')?.addEventListener('click', removeRecovery);
    root.querySelector('[data-action="reload-latest"]')?.addEventListener('click', () => loadEditor(true));
    root.querySelector('[data-action="retry-local"]')?.addEventListener('click', () => {
      if (!state.conflict) {
        return;
      }
      const localDraft = state.conflict.localDraft;
      if (PUBLIC_ID_PATTERN.test(state.conflict.currentRevisionId)) {
        state.currentRevisionId = state.conflict.currentRevisionId;
      }
      if (['draft', 'active'].includes(state.conflict.currentState)) {
        state.currentState = state.conflict.currentState;
      }
      hideConflict();
      saveEditor(localDraft);
    });
    root.querySelector('[data-role="staged-assets"]')?.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('button[data-asset-id]') : null;
      if (!button) {
        return;
      }
      const assetId = String(button.dataset.assetId || '');
      if (button.dataset.action === 'insert-asset') {
        insertStagedAsset(assetId);
      } else if (button.dataset.action === 'discard-asset') {
        discardStagedAsset(assetId);
      }
    });
    window.addEventListener('beforeunload', (event) => {
      if (hasPendingChanges() || state.criteriaRefreshBusy) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
    renderStagedAssets();
    refreshEditorActions();
    activateInspectorTab('question');
    void loadCurriculums();
    loadEditor(true);
  }

  if (state.view === 'edit') {
    entryStatus?.querySelector('[data-worksheet-entry-retry]')?.addEventListener('click', enterEditor);
    void enterEditor();
  } else {
    bindList();
  }
})(window, document);

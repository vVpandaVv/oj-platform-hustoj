import type { Value } from 'platejs';
import katex from 'katex';

import type { ResponseField, ResponseFieldKind, WorksheetSchema } from '../types';

const DEFAULT_VALUE: Value = [
  { type: 'h1', children: [{ text: '새 활동지' }] },
  { type: 'p', children: [{ text: '활동 안내를 입력하세요.' }] },
  { type: 'p', children: [{ text: '학생이 작성할 위치에 응답 블록을 넣으세요.' }] }
];

export function emptyParagraphValue(): Value {
  return [emptyParagraphNode()];
}

export function ensureEditablePlateValue(value: unknown): Value {
  if (!Array.isArray(value)) {
    return emptyParagraphValue();
  }

  const nodes = value.filter((node) => node && typeof node === 'object');

  if (nodes.length === 0) {
    return emptyParagraphValue();
  }

  const normalized = nodes as Value;
  const lastNode = normalized[normalized.length - 1] as { type?: unknown };

  return lastNode?.type === 'p' ? normalized : [...normalized, emptyParagraphNode()];
}

function emptyParagraphNode() {
  return { type: 'p', children: [{ text: '' }] };
}

export function createDefaultSchema(source: Partial<WorksheetSchema> = {}): WorksheetSchema {
  const value = Array.isArray(source.plate_value)
    ? ensureEditablePlateValue(source.plate_value)
    : DEFAULT_VALUE;

  return {
    version: Number(source.version || 1),
    engine: 'plate',
    title: String(source.title || ''),
    plate_value: value,
    document_html: String(source.document_html || ''),
    response_fields: Array.isArray(source.response_fields) ? source.response_fields : [],
    blocks: Array.isArray(source.blocks) ? source.blocks : []
  };
}

export function parseSchemaFromField(field: HTMLTextAreaElement | null, fallback: WorksheetSchema): WorksheetSchema {
  if (!field || field.value.trim() === '') {
    return fallback;
  }

  try {
    const parsed = JSON.parse(field.value);
    return createDefaultSchema({ ...fallback, ...parsed });
  } catch {
    return fallback;
  }
}

export function collectResponseFields(value: Value): ResponseField[] {
  const fields: ResponseField[] = [];

  const visit = (node: any): void => {
    if (!node || typeof node !== 'object') {
      return;
    }

    if (node.type === 'hws_response') {
      const kind = normalizeKind(node.kind);
      const name = normalizeName(node.name || `field_${fields.length + 1}`);
      fields.push({
        id: name,
        name,
        kind,
        label: String(node.label || '학생 응답'),
        required: Boolean(node.required),
        options: Array.isArray(node.options) ? node.options.map(String).filter(Boolean) : [],
        columns: Array.isArray(node.columns) ? node.columns : [],
        row_count: Math.max(1, Number(node.row_count || 3))
      });
    }

    if (Array.isArray(node.children)) {
      node.children.forEach(visit);
    }
  };

  value.forEach(visit);
  return fields;
}

export function serializeSchema(value: Value, title: string): WorksheetSchema {
  return {
    version: 1,
    engine: 'plate',
    title,
    plate_value: value,
    document_html: '',
    response_fields: collectResponseFields(value),
    blocks: []
  };
}

function serializeNodes(nodes: Value): string {
  return nodes.map((node) => serializeNode(node as any)).join('');
}

function serializeNode(node: any): string {
  if (!node || typeof node !== 'object') {
    return '';
  }

  if (typeof node.text === 'string') {
    return serializeText(node);
  }

  const children = Array.isArray(node.children)
    ? node.children.map((child: any) => serializeNode(child)).join('')
    : '';

  switch (node.type) {
    case 'h1':
      return `<h1>${children || '<br>'}</h1>`;
    case 'h2':
      return `<h2>${children || '<br>'}</h2>`;
    case 'h3':
      return `<h3>${children || '<br>'}</h3>`;
    case 'h4':
      return `<h4>${children || '<br>'}</h4>`;
    case 'h5':
      return `<h5>${children || '<br>'}</h5>`;
    case 'h6':
      return `<h6>${children || '<br>'}</h6>`;
    case 'blockquote':
      return `<blockquote>${children || '<br>'}</blockquote>`;
    case 'hr':
      return '<hr>';
    case 'ul':
    case 'taskList':
      return `<ul>${children}</ul>`;
    case 'ol':
      return `<ol${Number.isInteger(node.start) && Number(node.start) > 0 ? ` start="${node.start}"` : ''}>${children}</ol>`;
    case 'li':
      return `<li>${children}</li>`;
    case 'lic':
      return children;
    case 'action_item':
      return `<div data-todo="${node.checked ? '1' : '0'}">${children || '<br>'}</div>`;
    case 'table':
      return `<table>${children}</table>`;
    case 'tr':
      return `<tr>${children}</tr>`;
    case 'td':
      return `<td>${children || '<br>'}</td>`;
    case 'th':
      return `<th>${children || '<br>'}</th>`;
    case 'a':
      return `<a href="${escapeHtml(node.url || node.href || '#')}">${children || escapeHtml(node.url || 'link')}</a>`;
    case 'img':
      return `<figure${mediaStyleAttr(node)}><img src="${escapeHtml(node.url || node.src || '')}" alt="${escapeHtml(node.alt || '')}">${captionHtml(node)}</figure>`;
    case 'video':
      return `<figure${mediaStyleAttr(node)}><video src="${escapeHtml(node.url || node.src || '')}" controls></video>${captionHtml(node)}</figure>`;
    case 'audio':
      return `<figure${mediaStyleAttr(node)}><audio src="${escapeHtml(node.url || node.src || '')}" controls></audio>${captionHtml(node)}</figure>`;
    case 'file':
      return `<a class="worksheet-file" href="${escapeHtml(node.url || node.src || '')}">${escapeHtml(node.label || node.name || 'file')}</a>`;
    case 'media_embed':
      return `<div data-media-embed="${escapeHtml(node.url || node.src || '')}"${mediaStyleAttr(node)}>${escapeHtml(node.url || node.src || 'embed')}</div>`;
    case 'date':
      return `<time datetime="${escapeHtml(node.date || node.value || '')}">${escapeHtml(node.date || node.value || '')}</time>`;
    case 'equation':
      return `<div class="worksheet-equation" data-equation="${escapeHtml(node.texExpression || '')}">${renderEquationHtml(node.texExpression || '', true)}</div>`;
    case 'inline_equation':
      return `<span class="worksheet-inline-equation" data-equation="${escapeHtml(node.texExpression || '')}">${renderEquationHtml(node.texExpression || '', false)}</span>`;
    case 'code_block':
      return `<pre><code>${children}</code></pre>`;
    case 'code_line':
      return `${children}\n`;
    case 'code_drawing':
      if (typeof node.renderedSvg === 'string' && node.renderedSvg.trim() !== '') {
        const renderedSvg = node.renderedSvg.trim();
        if (renderedSvg.startsWith('data:image/')) {
          return `<figure data-code-drawing="${escapeHtml(node.syntax || node.data?.drawingType || 'Mermaid')}"><img src="${escapeHtml(renderedSvg)}" alt="diagram"></figure>`;
        }
        return `<figure data-code-drawing="${escapeHtml(node.syntax || node.data?.drawingType || 'Mermaid')}">${renderedSvg}</figure>`;
      }
      if (typeof node.renderedImageUrl === 'string' && node.renderedImageUrl.trim() !== '') {
        return `<figure data-code-drawing="${escapeHtml(node.syntax || node.data?.drawingType || 'Mermaid')}"><img src="${escapeHtml(node.renderedImageUrl.trim())}" alt="diagram"></figure>`;
      }
      return `<figure data-code-drawing="${escapeHtml(node.syntax || node.data?.drawingType || 'Mermaid')}"><pre>${escapeHtml(node.code || node.data?.code || '')}</pre></figure>`;
    case 'excalidraw':
      return '<figure data-excalidraw="1">Excalidraw</figure>';
    case 'toggle':
      return `<details open><summary>${escapeHtml(node.summary || '토글')}</summary>${children}</details>`;
    case 'callout':
      return `<aside class="worksheet-callout">${escapeHtml(node.icon || '')}${children}</aside>`;
    case 'column_group':
      return `<div class="worksheet-columns">${children}</div>`;
    case 'column':
      return `<div class="worksheet-column">${children}</div>`;
    case 'toc':
      return '<nav data-toc="1">목차</nav>';
    case 'mention':
      return `<span data-mention="${escapeHtml(node.value || node.name || '')}">@${escapeHtml(node.value || node.name || 'mention')}</span>`;
    case 'emoji':
      return `<span data-emoji="${escapeHtml(node.emoji || node.value || '')}">${escapeHtml(node.emoji || node.value || '')}</span>`;
    case 'footnoteReference':
      return `<sup data-footnote-ref="${escapeHtml(node.id || '')}">[${escapeHtml(node.id || '1')}]</sup>`;
    case 'footnoteDefinition':
      return `<aside data-footnote="${escapeHtml(node.id || '')}">${children}</aside>`;
    case 'tag':
      return `<span data-tag="${escapeHtml(node.value || node.name || '')}">#${escapeHtml(node.value || node.name || 'tag')}</span>`;
    case 'hws_response':
      return `<div data-ws-response="${escapeHtml(node.name || '')}" data-kind="${escapeHtml(node.kind || 'long_text')}" data-label="${escapeHtml(node.label || '학생 응답')}" data-required="${node.required ? '1' : '0'}"${responseAttributeHtml(node)}></div>`;
    case 'p':
    default:
      return `<p>${children || '<br>'}</p>`;
  }
}

function responseAttributeHtml(node: any): string {
  const attrs: string[] = [];

  if (['single_choice', 'multiple_choice', 'select', 'checklist'].includes(node.kind) && Array.isArray(node.options)) {
    attrs.push(`data-options="${escapeHtml(JSON.stringify(node.options.map(String).filter(Boolean)))}"`);
  }

  if (node.kind === 'table') {
    attrs.push(`data-row-count="${escapeHtml(String(Math.max(1, Number(node.row_count || 3))))}"`);
    if (Array.isArray(node.columns)) {
      attrs.push(`data-columns="${escapeHtml(JSON.stringify(node.columns))}"`);
    }
  }

  return attrs.length > 0 ? ` ${attrs.join(' ')}` : '';
}

function serializeText(node: any): string {
  let text = escapeHtml(node.text);
  if (node.bold) {
    text = `<strong>${text}</strong>`;
  }
  if (node.italic) {
    text = `<em>${text}</em>`;
  }
  if (node.underline) {
    text = `<u>${text}</u>`;
  }
  if (node.strikethrough) {
    text = `<s>${text}</s>`;
  }
  if (node.subscript) {
    text = `<sub>${text}</sub>`;
  }
  if (node.superscript) {
    text = `<sup>${text}</sup>`;
  }
  if (node.highlight) {
    text = `<mark>${text}</mark>`;
  }
  if (node.kbd) {
    text = `<kbd>${text}</kbd>`;
  }
  if (node.code) {
    text = `<code>${text}</code>`;
  }
  const styles = [
    node.color ? `color:${escapeCss(node.color)}` : '',
    node.backgroundColor ? `background-color:${escapeCss(node.backgroundColor)}` : '',
    node.fontSize ? `font-size:${escapeCss(node.fontSize)}` : '',
    node.fontFamily ? `font-family:${escapeCss(node.fontFamily)}` : '',
    node.fontWeight ? `font-weight:${escapeCss(node.fontWeight)}` : ''
  ].filter(Boolean).join(';');
  if (styles) {
    text = `<span style="${styles}">${text}</span>`;
  }
  return text;
}

export function responseNode(kind: ResponseFieldKind): any {
  const nodeId = temporaryNodeId();
  const responseId = temporaryNodeId();
  const node: any = {
    type: 'hws_response',
    node_id: nodeId,
    response_id: responseId,
    kind,
    name: responseId,
    id: responseId,
    label: responseLabel(kind),
    required: false,
    children: [{ text: '' }]
  };
  if (kind === 'single_choice' || kind === 'multiple_choice') {
    node.options = ['선택지 1', '선택지 2'];
  }
  if (kind === 'table') {
    node.row_count = 3;
    node.columns = [
      { key: 'col_1', label: '내용', input_type: 'text' },
      { key: 'col_2', label: '정리', input_type: 'text' }
    ];
  }

  return node;
}

function temporaryNodeId(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return `tmp_${globalThis.crypto.randomUUID()}`;
  }
  const hex = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (token) => {
    const value = Math.floor(Math.random() * 16);
    return (token === 'x' ? value : (value & 0x3) | 0x8).toString(16);
  });
  return `tmp_${hex}`;
}

export function questionNode(kind: ResponseFieldKind = 'long_text'): any {
  const nodeId = temporaryNodeId();
  return {
    type: 'hws_question',
    node_id: nodeId,
    question_id: nodeId,
    children: [
      { type: 'h2', children: [{ text: '새 문항' }] },
      responseNode(kind),
    ],
  };
}

export function ensureQuestionGroups(value: Value): Value {
  return value.map((node: any) => {
    if (node?.type !== 'hws_response') return node;
    const nodeId = temporaryNodeId();
    return {
      type: 'hws_question',
      node_id: nodeId,
      question_id: nodeId,
      children: [
        { type: 'h2', children: [{ text: String(node.label || '문항') }] },
        node,
      ],
    };
  }) as Value;
}

function responseLabel(kind: ResponseFieldKind): string {
  const labels: Record<ResponseFieldKind, string> = {
    short_text: '학생 단답칸',
    long_text: '학생 긴 응답칸',
    single_choice: '학생 단일 선택칸',
    multiple_choice: '학생 복수 선택칸',
    checkbox: '학생 체크칸',
    number: '학생 숫자 입력칸',
    table: '학생 응답표',
    image_upload: '학생 이미지 첨부칸',
    file_upload: '학생 파일 첨부칸',
    drawing: '학생 그림 응답'
  };
  return labels[kind] || '학생 응답';
}

function normalizeKind(kind: string): ResponseFieldKind {
  const aliases: Record<string, ResponseFieldKind> = {
    select: 'single_choice',
    radio: 'single_choice',
    checklist: 'multiple_choice',
    multi_select: 'multiple_choice'
  };
  const normalized = aliases[kind] || kind;
  if ([
    'short_text',
    'long_text',
    'single_choice',
    'multiple_choice',
    'checkbox',
    'number',
    'table',
    'image_upload',
    'file_upload',
    'drawing'
  ].includes(normalized)) {
    return normalized as ResponseFieldKind;
  }
  return 'long_text';
}

function normalizeName(value: string): string {
  const normalized = String(value || '').replace(/[^A-Za-z0-9_-]/g, '_');
  return normalized || 'field';
}

function captionHtml(node: any): string {
  const caption = String(node.caption || '').trim();
  return caption ? `<figcaption>${escapeHtml(caption)}</figcaption>` : '';
}

function mediaStyleAttr(node: any): string {
  const styles = ['max-width:100%'];
  const width = String(node.width || node.size?.width || '').trim();
  if (width) {
    styles.push(`width:${escapeCssSize(width)}`);
  }

  const align = String(node.align || '').trim();
  if (align === 'left') {
    styles.push('margin-left:0', 'margin-right:auto');
  } else if (align === 'right') {
    styles.push('margin-left:auto', 'margin-right:0');
  } else if (align === 'center') {
    styles.push('margin-left:auto', 'margin-right:auto');
  }

  return ` style="${styles.join(';')}"`;
}

function escapeHtml(value: string): string {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function escapeCss(value: string): string {
  return String(value).replace(/[<>"']/g, '');
}

function escapeCssSize(value: string): string {
  const clean = String(value).trim().replace(/[;"'<>]/g, '');
  if (/^\d+(\.\d+)?$/.test(clean)) {
    return `${clean}px`;
  }
  if (/^\d+(\.\d+)?(px|%|rem|em|vw|vh)$/i.test(clean)) {
    return clean;
  }
  return '100%';
}

function renderEquationHtml(expression: string, displayMode: boolean): string {
  try {
    return katex.renderToString(normalizeEquationExpression(expression) || ' ', {
      displayMode,
      throwOnError: false,
      strict: false
    });
  } catch {
    return escapeHtml(expression);
  }
}

function normalizeEquationExpression(expression: string): string {
  return String(expression || '')
    .replace(/\bsqrt\s*\(([^()]+)\)/g, '\\sqrt{$1}')
    .replace(/\bpi\b/g, '\\pi')
    .replace(/\btheta\b/g, '\\theta')
    .replace(/\balpha\b/g, '\\alpha')
    .replace(/\bbeta\b/g, '\\beta')
    .replace(/\bgamma\b/g, '\\gamma');
}

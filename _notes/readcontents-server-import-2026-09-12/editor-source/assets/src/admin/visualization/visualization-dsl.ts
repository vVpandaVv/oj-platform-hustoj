import type {
  VisualizationCell,
  VisualizationDocument,
  VisualizationDirection,
  VisualizationEdge,
  VisualizationEdgeRouteSide,
  VisualizationElementState,
  VisualizationAnchorSide,
  VisualizationItem,
  VisualizationNode,
  VisualizationConnectorKind,
  VisualizationShapeKind,
  VisualizationStep,
  VisualizationStepAction,
  VisualizationTargetRef,
} from './visualization-model';
import { createEmptyVisualizationDocument } from './visualization-model';
import type { VisualizationType } from './visualization-types';
import { isVisualizationType } from './visualization-types';

type ParsedLine = {
  command: string;
  raw: string;
  rest: string;
};

const STATE_SET = new Set<VisualizationElementState>([
  'active',
  'error',
  'muted',
  'normal',
  'success',
  'warning',
]);

const ANCHOR_SET = new Set<VisualizationAnchorSide>([
  'bottom',
  'left',
  'right',
  'top',
]);

const ROUTE_SIDE_SET = new Set<VisualizationEdgeRouteSide>([
  'auto',
  'bottom',
  'left',
  'right',
  'top',
]);

const SHAPE_ALIASES = new Map<string, VisualizationShapeKind>([
  ['circle', 'circle'],
  ['data', 'data'],
  ['decision', 'decision'],
  ['diamond', 'diamond'],
  ['input', 'input'],
  ['output', 'output'],
  ['process', 'process'],
  ['rectangle', 'rectangle'],
  ['rounded', 'rounded-rectangle'],
  ['rounded-rectangle', 'rounded-rectangle'],
  ['start', 'terminator'],
  ['terminator', 'terminator'],
]);

function readQuoted(value: string) {
  const match = value.match(/^"([^"]*)"\s*(.*)$/);

  if (!match) {
    return null;
  }

  return {
    text: unescapeDslLabel(match[1]),
    rest: match[2].trim(),
  };
}

function normalizeVisualizationDslCode(code: string) {
  let normalized = '';
  let inQuote = false;

  for (let index = 0; index < code.length; index += 1) {
    const char = code[index];

    if (char === '"') {
      inQuote = !inQuote;
      normalized += char;
      continue;
    }

    if (inQuote && char === '\r') {
      if (code[index + 1] === '\n') {
        index += 1;
      }

      normalized += '\\n';
      continue;
    }

    if (inQuote && char === '\n') {
      normalized += '\\n';
      continue;
    }

    normalized += char;
  }

  return normalized;
}

function toParsedLine(raw: string): ParsedLine | null {
  const trimmed = raw.trim();

  if (!trimmed || trimmed.startsWith('#') || trimmed.startsWith('//')) {
    return null;
  }

  const [command = '', ...rest] = trimmed.split(/\s+/);

  return {
    command,
    raw: trimmed,
    rest: rest.join(' ').trim(),
  };
}

function parseNumber(value: string) {
  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : null;
}

function parseState(tokens: string[]) {
  const found = tokens.find((token) =>
    STATE_SET.has(token as VisualizationElementState)
  );

  return found as VisualizationElementState | undefined;
}

function stripStateTokens(tokens: string[]) {
  return tokens.filter(
    (token) => !STATE_SET.has(token as VisualizationElementState)
  );
}

function parseShape(value: string | undefined) {
  if (!value) {
    return undefined;
  }

  return SHAPE_ALIASES.get(value);
}

function parseAnchor(value: string | undefined) {
  if (!value || !ANCHOR_SET.has(value as VisualizationAnchorSide)) {
    return undefined;
  }

  return value as VisualizationAnchorSide;
}

function parseRouteSide(value: string | undefined) {
  if (!value || !ROUTE_SIDE_SET.has(value as VisualizationEdgeRouteSide)) {
    return undefined;
  }

  return value as VisualizationEdgeRouteSide;
}

function parseNode(line: ParsedLine): VisualizationNode | null {
  const match = line.rest.match(/^(\S+)\s+(.*)$/);

  if (!match) {
    return null;
  }

  const id = match[1];
  const quoted = readQuoted(match[2].trim());

  if (!quoted) {
    return null;
  }

  const tokens = quoted.rest.split(/\s+/).filter(Boolean);
  const atIndex = tokens.indexOf('at');
  const shapeToken = atIndex > 0 ? tokens[0] : undefined;
  const x = atIndex >= 0 ? parseNumber(tokens[atIndex + 1] ?? '') : null;
  const y = atIndex >= 0 ? parseNumber(tokens[atIndex + 2] ?? '') : null;

  return {
    id,
    label: quoted.text,
    position: {
      x: x ?? 0,
      y: y ?? 0,
    },
    shape: parseShape(shapeToken),
    state: parseState(tokens),
  };
}

function getDefaultEdgeKindForVisualType(
  visualType: VisualizationType
): VisualizationConnectorKind {
  return visualType === 'flowchart' ? 'orthogonal' : 'straight';
}

function parseEdge(
  line: ParsedLine,
  index: number,
  defaultKind: VisualizationConnectorKind = 'straight'
): VisualizationEdge | null {
  const tokens = line.rest.split(/\s+/).filter(Boolean);

  if (tokens.length < 2) {
    return null;
  }

  const labelIndex = tokens.indexOf('label');
  let label: string | undefined;

  if (labelIndex >= 0) {
    const quoted = readQuoted(tokens.slice(labelIndex + 1).join(' '));
    label = quoted?.text;
  }

  const fromAnchorIndex = tokens.indexOf('from', 2);
  const toAnchorIndex = tokens.indexOf('to', 2);
  const fromAnchor =
    fromAnchorIndex >= 0 ? parseAnchor(tokens[fromAnchorIndex + 1]) : undefined;
  const toAnchor =
    toAnchorIndex >= 0 ? parseAnchor(tokens[toAnchorIndex + 1]) : undefined;
  const routeIndex = tokens.indexOf('route', 2);
  const offsetIndex = tokens.indexOf('offset', 2);
  const routeSide =
    routeIndex >= 0 ? parseRouteSide(tokens[routeIndex + 1]) : undefined;
  const routeOffset =
    offsetIndex >= 0 ? parseNumber(tokens[offsetIndex + 1] ?? '') : null;
  const direction: VisualizationDirection = tokens.includes('none')
    ? 'none'
    : tokens.includes('backward')
      ? 'backward'
      : tokens.includes('both')
        ? 'both'
        : 'forward';

  return {
    id: `edge-${index + 1}`,
    from: tokens[0],
    fromAnchor,
    to: tokens[1],
    toAnchor,
    direction,
    kind: tokens.includes('straight')
      ? 'straight'
      : tokens.includes('orthogonal') || tokens.includes('elbow')
        ? 'orthogonal'
        : defaultKind,
    label,
    routeOffset: routeOffset ?? undefined,
    routeSide,
    state: parseState(tokens),
    style: tokens.includes('dashed') ? { strokeDasharray: '5 5' } : undefined,
  };
}

function parseDataItems(rest: string): VisualizationItem[] {
  return rest
    .split(/\s+/)
    .filter(Boolean)
    .map((rawValue, index) => {
      const labeledMatch = rawValue.match(/^([^=]+)=(.+)$/);
      const label = labeledMatch?.[1]?.replace(/_/g, ' ');
      const packedValue = labeledMatch?.[2] ?? rawValue;
      const bucketMatch = packedValue.match(/^(.*)@(\d+)$/);
      const value = bucketMatch ? bucketMatch[1] : packedValue;

      return {
        id: `item-${index}`,
        index,
        label: label ?? (bucketMatch ? `bucket:${bucketMatch[2]}` : undefined),
        value: Number.isFinite(Number(value)) ? Number(value) : value,
      };
    });
}

function applyActiveItems(items: VisualizationItem[], rest: string) {
  const activeIndexes = new Set(
    rest
      .split(/\s+/)
      .map((value) => Number(value))
      .filter((value) => Number.isInteger(value))
  );

  return items.map((item) =>
    activeIndexes.has(item.index ?? -1)
      ? { ...item, state: 'active' as const }
      : item
  );
}

function parseDpRow(rest: string): VisualizationCell[] {
  const match = rest.match(/^(\d+)\s*:\s*(.*)$/);

  if (!match) {
    return [];
  }

  const row = Number(match[1]);

  return match[2]
    .split(/\s+/)
    .filter(Boolean)
    .map((value, column) => ({
      id: `cell-${row}-${column}`,
      row,
      column,
      value: Number.isFinite(Number(value)) ? Number(value) : value,
    }));
}

function applyActiveCell(cells: VisualizationCell[], rest: string) {
  const [rowValue, columnValue] = rest.split(/\s+/);
  const row = Number(rowValue);
  const column = Number(columnValue);

  if (!Number.isInteger(row) || !Number.isInteger(column)) {
    return cells;
  }

  return cells.map((cell) =>
    cell.row === row && cell.column === column
      ? { ...cell, state: 'active' as const }
      : cell
  );
}

function parseDslScalar(value: string): number | string | boolean | null {
  if (value === 'true') {
    return true;
  }
  if (value === 'false') {
    return false;
  }
  if (value === 'null') {
    return null;
  }

  const numericValue = Number(value);

  if (Number.isFinite(numericValue) && value.trim() !== '') {
    return numericValue;
  }

  return value.replace(/_/g, ' ');
}

function parseStepValue(rest: string): number | string | boolean | null {
  const quoted = readQuoted(rest);

  if (quoted) {
    return quoted.text;
  }

  return parseDslScalar(rest.trim());
}

function parseStepTarget(value: string): VisualizationTargetRef | null {
  const [kind, first, second] = value.split(':');

  if (kind === 'cell') {
    const row = Number(first);
    const column = Number(second);

    if (Number.isInteger(row) && Number.isInteger(column)) {
      return { kind, row, column };
    }

    return null;
  }

  if (
    kind === 'annotation' ||
    kind === 'edge' ||
    kind === 'item' ||
    kind === 'node'
  ) {
    return first ? { kind, id: first } : null;
  }

  return null;
}

function createFallbackStep(id: string, order: number): VisualizationStep {
  return {
    id,
    order,
    description: '',
    title: '',
  };
}

function upsertStep(
  stepMap: Map<string, VisualizationStep>,
  id: string,
  updater: (step: VisualizationStep) => VisualizationStep,
  fallbackOrder = stepMap.size + 1
) {
  const current = stepMap.get(id) ?? createFallbackStep(id, fallbackOrder);
  stepMap.set(id, updater(current));
}

function pushStepAction(
  stepMap: Map<string, VisualizationStep>,
  stepId: string,
  action: VisualizationStepAction
) {
  upsertStep(stepMap, stepId, (step) => ({
    ...step,
    actions: [...(step.actions ?? []), action],
  }));
}

function parseStepCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const tokens = line.rest.split(/\s+/).filter(Boolean);
  const [id, orderValue] = tokens;
  const order = Number(orderValue);

  if (!id || !Number.isInteger(order)) {
    return false;
  }

  const titleSource = line.rest
    .replace(new RegExp(`^${id}\\s+${orderValue}\\s*`), '')
    .trim();
  const quoted = readQuoted(titleSource);
  const title = quoted?.text ?? titleSource;

  stepMap.set(id, {
    ...(stepMap.get(id) ?? createFallbackStep(id, order)),
    id,
    order,
    title,
  });

  return true;
}

function parseStepNoteCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const match = line.rest.match(/^(\S+)\s+(.*)$/);

  if (!match) {
    return false;
  }

  const [, id, descriptionSource] = match;
  const quoted = readQuoted(descriptionSource.trim());
  const description = quoted?.text ?? descriptionSource.trim();

  upsertStep(stepMap, id, (step) => ({
    ...step,
    description,
  }));

  return true;
}

function parseStepFocusCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const [id, ...rawTargetValues] = line.rest.split(/\s+/).filter(Boolean);

  if (!id) {
    return false;
  }

  const targetValues = rawTargetValues
    .flatMap((value) => value.split(','))
    .filter(Boolean);

  const focusRefs = targetValues
    .map(parseStepTarget)
    .filter((target): target is VisualizationTargetRef => Boolean(target));

  upsertStep(stepMap, id, (step) => ({
    ...step,
    focusRefs: [...(step.focusRefs ?? []), ...focusRefs],
    focusIds: [
      ...(step.focusIds ?? []),
      ...targetValues.filter((target) => !target.includes(':')),
    ],
  }));

  return true;
}

function parseStepStateCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const [id, ...parts] = line.rest.split(/\s+/).filter(Boolean);

  if (!id || parts.length < 2) {
    return false;
  }

  for (let index = 0; index < parts.length; index += 2) {
    const target = parseStepTarget(parts[index]);
    const state = parts[index + 1] as VisualizationElementState | undefined;

    if (target && state && STATE_SET.has(state)) {
      pushStepAction(stepMap, id, {
        target,
        state,
        type: 'set-state',
      });
    }
  }

  return true;
}

function parseStepValueCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const match = line.rest.match(/^(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/);

  if (!match) {
    return false;
  }

  const [, id, targetValue, field, rawValue] = match;
  const target = parseStepTarget(targetValue);

  if (!target) {
    return false;
  }

  pushStepAction(stepMap, id, {
    field,
    target,
    type: 'set-value',
    value: parseStepValue(rawValue),
  });

  return true;
}

function parseStepPositionCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const [id, targetValue, xValue, yValue] = line.rest
    .split(/\s+/)
    .filter(Boolean);
  const x = Number(xValue);
  const y = Number(yValue);
  const target = targetValue ? parseStepTarget(targetValue) : null;

  if (!id || !target || !Number.isFinite(x) || !Number.isFinite(y)) {
    return false;
  }

  pushStepAction(stepMap, id, {
    position: { x, y },
    target,
    type: 'set-position',
  });

  return true;
}

function parseStepVisibleCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const [id, targetValue, visibleValue] = line.rest.split(/\s+/).filter(Boolean);
  const target = targetValue ? parseStepTarget(targetValue) : null;

  if (!id || !target) {
    return false;
  }

  pushStepAction(stepMap, id, {
    target,
    type: 'set-visibility',
    visible: !['false', 'hide', 'hidden', '0', 'off'].includes(
      (visibleValue ?? '').toLowerCase()
    ),
  });

  return true;
}

function parseStepTypeDataCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const match = line.rest.match(/^(\S+)\s+(\S+)\s+(.*)$/);

  if (!match) {
    return false;
  }

  const [, id, key, rawValue] = match;

  pushStepAction(stepMap, id, {
    key,
    type: 'set-type-data',
    value: parseStepValue(rawValue),
  });

  return true;
}

function parseStepViewportCommand(
  line: ParsedLine,
  stepMap: Map<string, VisualizationStep>
) {
  const [id, xValue, yValue, zoomValue] = line.rest.split(/\s+/).filter(Boolean);
  const x = Number(xValue);
  const y = Number(yValue);
  const zoom = Number(zoomValue);

  if (!id || !Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(zoom)) {
    return false;
  }

  upsertStep(stepMap, id, (step) => ({
    ...step,
    viewport: {
      x,
      y,
      zoom,
    },
  }));

  return true;
}

function formatCoordinate(value: number) {
  return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function escapeDslLabel(value: string) {
  return normalizeDslLabelLineBreaks(value)
    .replace(/\n/g, '\\n')
    .replace(/"/g, "'");
}

function unescapeDslLabel(value: string) {
  return normalizeDslLabelLineBreaks(value);
}

function normalizeDslLabelLineBreaks(value: string) {
  return value
    .replace(/\r\n?/g, '\n')
    .replace(/\\+n/g, '\n');
}

function toNodeShapeToken(shape: VisualizationShapeKind) {
  return shape === 'rounded-rectangle' ? 'rounded-rectangle' : shape;
}

function getNodeIds(code: string) {
  return new Set(
    code
      .split(/\r?\n/)
      .map((line) => line.trim().match(/^node\s+(\S+)/)?.[1])
      .filter((id): id is string => Boolean(id))
  );
}

function createNodeId(code: string) {
  const ids = getNodeIds(code);
  let index = ids.size + 1;
  let id = `N${index}`;

  while (ids.has(id)) {
    index += 1;
    id = `N${index}`;
  }

  return id;
}

function updateNodeLine(
  line: string,
  nodeId: string,
  updater: (parts: {
    afterLabel: string;
    beforeLabel: string;
    label: string;
    quoteEnd: string;
  }) => string | null
) {
  const match = line.match(/^(\s*node\s+)(\S+)(\s+")([^"]*)("\s*)(.*)$/);

  if (!match || match[2] !== nodeId) {
    return line;
  }

  return (
    updater({
      beforeLabel: `${match[1]}${match[2]}${match[3]}`,
      label: match[4],
      quoteEnd: match[5],
      afterLabel: match[6],
    }) ?? line
  );
}

export function updateVisualizationNodePositionInDsl(
  code: string,
  nodeId: string,
  x: number,
  y: number
) {
  return code
    .split(/\r?\n/)
    .map((line) =>
      updateNodeLine(line, nodeId, (parts) => {
        const nextPosition = `at ${formatCoordinate(x)} ${formatCoordinate(y)}`;
        const afterLabel = parts.afterLabel.match(/\bat\s+-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\b/)
          ? parts.afterLabel.replace(
              /\bat\s+-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\b/,
              nextPosition
            )
          : `${parts.afterLabel.trimEnd()} ${nextPosition}`;

        return `${parts.beforeLabel}${parts.label}${parts.quoteEnd}${afterLabel.trim()}`;
      })
    )
    .join('\n');
}

export function updateVisualizationNodeLabelInDsl(
  code: string,
  nodeId: string,
  label: string
) {
  return code
    .split(/\r?\n/)
    .map((line) =>
      updateNodeLine(line, nodeId, (parts) =>
        `${parts.beforeLabel}${escapeDslLabel(label)}${parts.quoteEnd}${parts.afterLabel}`
      )
    )
    .join('\n');
}

export function appendVisualizationNodeInDsl(
  code: string,
  node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }
) {
  const lines = code.trimEnd().split(/\r?\n/);
  const id = createNodeId(code);
  const nodeLine = `node ${id} "${escapeDslLabel(node.label)}" ${toNodeShapeToken(
    node.shape
  )} at ${formatCoordinate(node.x)} ${formatCoordinate(node.y)}`;
  const firstEdgeIndex = lines.findIndex((line) => /^\s*edge\s+/.test(line));

  if (firstEdgeIndex >= 0) {
    lines.splice(firstEdgeIndex, 0, nodeLine);

    return lines.join('\n');
  }

  return code.trimEnd() ? `${code.trimEnd()}\n${nodeLine}` : nodeLine;
}

export function removeVisualizationNodeInDsl(code: string, nodeId: string) {
  return code
    .split(/\r?\n/)
    .filter((line) => {
      const trimmed = line.trim();
      const nodeMatch = trimmed.match(/^node\s+(\S+)/);

      if (nodeMatch?.[1] === nodeId) {
        return false;
      }

      const edgeMatch = trimmed.match(/^edge\s+(\S+)\s+(\S+)(?:\s|$)/);

      if (edgeMatch?.[1] === nodeId || edgeMatch?.[2] === nodeId) {
        return false;
      }

      return true;
    })
    .join('\n');
}

export function updateVisualizationNodeShapeInDsl(
  code: string,
  nodeId: string,
  shape: VisualizationShapeKind
) {
  const shapeToken = toNodeShapeToken(shape);

  return code
    .split(/\r?\n/)
    .map((line) =>
      updateNodeLine(line, nodeId, (parts) => {
        const tokens = parts.afterLabel.trim().split(/\s+/).filter(Boolean);
        const atIndex = tokens.indexOf('at');

        if (atIndex > 0 && SHAPE_ALIASES.has(tokens[0])) {
          tokens[0] = shapeToken;
        } else if (atIndex >= 0) {
          tokens.splice(atIndex, 0, shapeToken);
        } else {
          tokens.unshift(shapeToken);
        }

        return `${parts.beforeLabel}${parts.label}${parts.quoteEnd}${tokens.join(
          ' '
        )}`;
      })
    )
    .join('\n');
}

export function updateVisualizationNodeStateInDsl(
  code: string,
  nodeId: string,
  state: VisualizationElementState
) {
  return code
    .split(/\r?\n/)
    .map((line) =>
      updateNodeLine(line, nodeId, (parts) => {
        const tokens = stripStateTokens(
          parts.afterLabel.trim().split(/\s+/).filter(Boolean)
        );

        if (state !== 'normal') {
          tokens.push(state);
        }

        return `${parts.beforeLabel}${parts.label}${parts.quoteEnd}${tokens.join(
          ' '
        )}`;
      })
    )
    .join('\n');
}

export function appendVisualizationEdgeInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  options: {
    allowDuplicate?: boolean;
    direction?: VisualizationDirection;
    label?: string;
    routeOffset?: number;
    routeSide?: VisualizationEdgeRouteSide;
  } = {}
) {
  const lines = code.split(/\r?\n/);
  const hasEdge = lines.some((line) => {
    const match = line.match(/^\s*edge\s+(\S+)\s+(\S+)/);

    return match?.[1] === fromNodeId && match?.[2] === toNodeId;
  });

  if (hasEdge && !options.allowDuplicate) {
    return code;
  }

  const trimmed = code.trimEnd();
  const tokens = [`edge ${fromNodeId} ${toNodeId}`];

  if (
    options.direction &&
    options.direction !== 'forward'
  ) {
    tokens.push(options.direction);
  }

  if (options.label?.trim()) {
    tokens.push(`label "${escapeDslLabel(options.label.trim())}"`);
  }

  if (Number.isFinite(options.routeOffset)) {
    tokens.push(
      `route ${options.routeSide ?? 'auto'} offset ${Math.round(
        Math.max(32, options.routeOffset ?? 32)
      )}`
    );
  }

  const edgeLine = tokens.join(' ');

  return trimmed ? `${trimmed}\n${edgeLine}` : edgeLine;
}

function stripEdgeDirectionTokens(line: string) {
  return line
    .replace(/\s+(?:none|forward|backward|both)(?=\s|$)/g, '')
    .trimEnd();
}

function stripEdgeLineStyleTokens(line: string) {
  return line.replace(/\s+(?:solid|dashed)(?=\s|$)/g, '').trimEnd();
}

function edgeLineMatches(
  line: string,
  fromNodeId: string,
  toNodeId: string,
  edgeId?: string,
  parsedLineIndex?: number
) {
  const match = line.trim().match(/^edge\s+(\S+)\s+(\S+)(?:\s|$)/);

  if (edgeId) {
    return Boolean(
      match && parsedLineIndex && edgeId === `edge-${parsedLineIndex}`
    );
  }

  return match?.[1] === fromNodeId && match?.[2] === toNodeId;
}

export function removeVisualizationEdgeInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  edgeId?: string
) {
  let removed = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .filter((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        !removed &&
        edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        removed = true;

        return false;
      }

      return true;
    })
    .join('\n');
}

export function updateVisualizationEdgeLabelInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  label: string,
  edgeId?: string
) {
  let updated = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .map((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        updated ||
        !edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        return line;
      }

      updated = true;

      const withoutLabel = line.replace(/\s+label\s+"[^"]*"/, '').trimEnd();
      const trimmedLabel = label.trim();

      return trimmedLabel
        ? `${withoutLabel} label "${escapeDslLabel(trimmedLabel)}"`
        : withoutLabel;
    })
    .join('\n');
}

export function updateVisualizationEdgeEndpointsInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  nextFromNodeId: string,
  nextToNodeId: string,
  edgeId?: string
) {
  let updated = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .map((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        updated ||
        !edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        return line;
      }

      updated = true;

      return line.replace(
        /^(\s*edge\s+)(\S+)(\s+)(\S+)/,
        `$1${nextFromNodeId}$3${nextToNodeId}`
      );
    })
    .join('\n');
}

export function updateVisualizationEdgeAnchorsInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  fromAnchor: VisualizationAnchorSide,
  toAnchor: VisualizationAnchorSide
) {
  let updated = false;

  return code
    .split(/\r?\n/)
    .map((line) => {
      if (updated || !edgeLineMatches(line, fromNodeId, toNodeId)) {
        return line;
      }

      updated = true;

      const withoutAnchors = line
        .replace(/\s+from\s+(?:bottom|left|right|top)\b/, '')
        .replace(/\s+to\s+(?:bottom|left|right|top)\b/, '')
        .trimEnd();

      return `${withoutAnchors} from ${fromAnchor} to ${toAnchor}`;
    })
    .join('\n');
}

export function updateVisualizationEdgeRouteInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  routeSide: VisualizationEdgeRouteSide,
  routeOffset: number,
  edgeId?: string
) {
  let updated = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .map((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        updated ||
        !edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        return line;
      }

      updated = true;

      const normalizedOffset = Math.max(32, Math.round(routeOffset));
      const withoutRoute = line
        .replace(/\s+route\s+(?:auto|bottom|left|right|top)\b/, '')
        .replace(/\s+offset\s+-?\d+(?:\.\d+)?\b/, '')
        .trimEnd();

      return `${withoutRoute} route ${routeSide} offset ${normalizedOffset}`;
    })
    .join('\n');
}

export function updateVisualizationEdgeDirectionInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  direction: VisualizationDirection,
  edgeId?: string
) {
  let updated = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .map((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        updated ||
        !edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        return line;
      }

      updated = true;

      const withoutDirection = stripEdgeDirectionTokens(line);

      return direction === 'forward'
        ? withoutDirection
        : `${withoutDirection} ${direction}`;
    })
    .join('\n');
}

export function updateVisualizationEdgeLineStyleInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  lineStyle: 'solid' | 'dashed',
  edgeId?: string
) {
  let updated = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .map((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        updated ||
        !edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        return line;
      }

      updated = true;

      const withoutLineStyle = stripEdgeLineStyleTokens(line);

      return lineStyle === 'solid'
        ? withoutLineStyle
        : `${withoutLineStyle} dashed`;
    })
    .join('\n');
}

export function updateVisualizationEdgeStateInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string,
  state: VisualizationElementState,
  edgeId?: string
) {
  let updated = false;
  let parsedLineIndex = 0;

  return code
    .split(/\r?\n/)
    .map((line) => {
      const parsed = toParsedLine(line);

      if (parsed) {
        parsedLineIndex += 1;
      }

      if (
        updated ||
        !edgeLineMatches(line, fromNodeId, toNodeId, edgeId, parsedLineIndex)
      ) {
        return line;
      }

      updated = true;

      const tokens = stripStateTokens(line.trim().split(/\s+/));

      if (state !== 'normal') {
        tokens.push(state);
      }

      return tokens.join(' ');
    })
    .join('\n');
}

export function toggleVisualizationEdgeDirectionInDsl(
  code: string,
  fromNodeId: string,
  toNodeId: string
) {
  let updated = false;

  return code
    .split(/\r?\n/)
    .map((line) => {
      if (updated || !edgeLineMatches(line, fromNodeId, toNodeId)) {
        return line;
      }

      updated = true;

      return /\snone(?:\s|$)/.test(line)
        ? line.replace(/\snone(?:\s|$)/, ' ').trimEnd()
        : `${line.trimEnd()} none`;
    })
    .join('\n');
}

function normalizeInlineValue(value: string) {
  const trimmed = value.trim();

  return trimmed ? trimmed.replace(/\s+/g, '_') : '.';
}

function serializeCommandValue(value: string) {
  const trimmed = value.trim();

  if (!trimmed) {
    return '';
  }

  return /\s/.test(trimmed) ? `"${escapeDslLabel(trimmed)}"` : trimmed;
}

function getHashBucketCountFromDsl(code: string) {
  const bucketLine = code
    .split(/\r?\n/)
    .find((line) => line.trim().startsWith('bucket-count '));
  const count = Number(bucketLine?.trim().replace(/^bucket-count\s+/, '') ?? '');

  return Number.isInteger(count) && count > 0 ? count : 4;
}

function removeHashBucketFromDsl(code: string, bucketIndex: number) {
  if (!Number.isInteger(bucketIndex) || bucketIndex < 0) {
    return code;
  }

  const currentBucketCount = getHashBucketCountFromDsl(code);

  if (bucketIndex >= currentBucketCount || currentBucketCount <= 1) {
    return code;
  }

  const nextBucketCount = Math.max(1, currentBucketCount - 1);
  let bucketCountUpdated = false;
  const lines = code
    .split(/\r?\n/)
    .map((line) => {
      const trimmed = line.trim();
      const indent = line.match(/^\s*/)?.[0] ?? '';

      if (trimmed.startsWith('bucket-count ')) {
        bucketCountUpdated = true;

        return `${indent}bucket-count ${nextBucketCount}`;
      }

      if (trimmed.startsWith('active ')) {
        return '';
      }

      if (!trimmed.startsWith('data ')) {
        return line;
      }

      const values = trimmed
        .replace(/^data\s+/, '')
        .split(/\s+/)
        .filter(Boolean)
        .map((value) => {
          const bucketMatch = value.match(/^(.*)@(\d+)$/);

          if (!bucketMatch) {
            return value;
          }

          const bucket = Number(bucketMatch[2]);

          if (bucket === bucketIndex) {
            return '';
          }

          return bucket > bucketIndex
            ? `${bucketMatch[1]}@${bucket - 1}`
            : value;
        })
        .filter(Boolean);

      return values.length > 0 ? `${indent}data ${values.join(' ')}` : '';
    })
    .filter((line) => line.trim() !== '');

  if (!bucketCountUpdated) {
    lines.push(`bucket-count ${nextBucketCount}`);
  }

  return lines.join('\n');
}

function createNodeIdWithPrefix(code: string, prefix: string) {
  const ids = getNodeIds(code);
  let index = 1;
  let id = `${prefix}${index}`;

  while (ids.has(id)) {
    index += 1;
    id = `${prefix}${index}`;
  }

  return id;
}

function readDslNodePosition(code: string, nodeId: string) {
  const line = code
    .split(/\r?\n/)
    .find((currentLine) => currentLine.trim().startsWith(`node ${nodeId} `));
  const match = line?.match(/\bat\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\b/);

  if (!match) {
    return null;
  }

  return {
    x: Number(match[1]),
    y: Number(match[2]),
  };
}

function getVectorSpaceOrigin(code: string) {
  const ids = getNodeIds(code);

  if (ids.has('O')) {
    return {
      id: 'O',
      position: readDslNodePosition(code, 'O') ?? { x: 90, y: 225 },
    };
  }

  const firstNodeMatch = code.match(/^\s*node\s+(\S+)/m);
  const id = firstNodeMatch?.[1] ?? 'O';

  return {
    id,
    position: readDslNodePosition(code, id) ?? { x: 90, y: 225 },
  };
}

function insertNodeLineBeforeEdges(lines: string[], nodeLine: string) {
  const firstEdgeIndex = lines.findIndex((line) => /^\s*edge\s+/.test(line));

  if (firstEdgeIndex >= 0) {
    lines.splice(firstEdgeIndex, 0, nodeLine);
  } else {
    lines.push(nodeLine);
  }
}

function appendVectorSpaceVectorInDsl(code: string) {
  const lines = code.trimEnd().split(/\r?\n/);
  const origin = getVectorSpaceOrigin(code);
  const nodeId = createNodeIdWithPrefix(code, 'V');
  const index = Number(nodeId.replace(/^V/, '')) || 1;
  const label = `v${index + 2}`;
  const x = origin.position.x + 150 + index * 26;
  const y = origin.position.y - 110 + index * 20;

  insertNodeLineBeforeEdges(
    lines,
    `node ${nodeId} "${label}" circle at ${formatCoordinate(x)} ${formatCoordinate(y)}`
  );
  lines.push(`edge ${origin.id} ${nodeId} label "${label}" straight`);

  return lines.join('\n');
}

function appendVectorSpacePointInDsl(code: string) {
  const lines = code.trimEnd().split(/\r?\n/);
  const nodeId = createNodeIdWithPrefix(code, 'P');
  const index = Number(nodeId.replace(/^P/, '')) || 1;

  insertNodeLineBeforeEdges(
    lines,
    `node ${nodeId} "P${index}" circle at ${formatCoordinate(180 + index * 34)} ${formatCoordinate(238 - index * 18)}`
  );

  return lines.join('\n');
}

function appendVectorSpaceBoundaryInDsl(code: string) {
  const lines = code.trimEnd().split(/\r?\n/);
  const startId = createNodeIdWithPrefix(code, 'B');
  const endId = createNodeIdWithPrefix(`${code}\nnode ${startId} ""`, 'B');

  insertNodeLineBeforeEdges(lines, `node ${startId} "b1" circle at 180 80 muted`);
  insertNodeLineBeforeEdges(lines, `node ${endId} "b2" circle at 470 230 muted`);
  lines.push(`edge ${startId} ${endId} none label "boundary" straight dashed`);

  return lines.join('\n');
}

type NeuralLayerKind = 'hidden' | 'input' | 'output';

function getNeuralLayerKindFromDslNode(id: string, label: string): NeuralLayerKind {
  const normalizedId = id.toLowerCase();
  const normalizedLabel = label.toLowerCase();

  if (
    normalizedId.startsWith('i') ||
    normalizedLabel.includes('input') ||
    normalizedLabel.startsWith('x')
  ) {
    return 'input';
  }

  if (
    normalizedId.startsWith('o') ||
    normalizedLabel.includes('output') ||
    /^y\d*$/i.test(normalizedLabel)
  ) {
    return 'output';
  }

  return 'hidden';
}

type NeuralDslNode = {
  id: string;
  label: string;
  lineIndex: number;
  x: number;
  y: number;
};

const neuralNodePattern =
  /^(\s*node\s+)(\S+)(\s+")([^"]*)("\s+)(\S+)(\s+at\s+)(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)(.*)$/;

function readNeuralDslNodes(code: string): NeuralDslNode[] {
  return code
    .split(/\r?\n/)
    .map((line, lineIndex) => {
      const match = line.match(neuralNodePattern);

      if (!match) {
        return null;
      }

      return {
        id: match[2],
        label: match[4],
        lineIndex,
        x: Number(match[8]),
        y: Number(match[9]),
      };
    })
    .filter((node): node is NeuralDslNode => Boolean(node));
}

function groupHiddenNeuralDslNodes(nodes: NeuralDslNode[]) {
  const hiddenNodes = nodes
    .filter((node) => getNeuralLayerKindFromDslNode(node.id, node.label) === 'hidden')
    .sort((first, second) => {
      if (Math.abs(first.x - second.x) > 72) {
        return first.x - second.x;
      }

      return first.y - second.y;
    });
  const groups: NeuralDslNode[][] = [];

  for (const node of hiddenNodes) {
    const lastGroup = groups[groups.length - 1];
    const lastGroupX = lastGroup
      ? lastGroup.reduce((sum, current) => sum + current.x, 0) / lastGroup.length
      : null;

    if (!lastGroup || lastGroupX === null || Math.abs(node.x - lastGroupX) > 90) {
      groups.push([node]);
    } else {
      lastGroup.push(node);
    }
  }

  return groups;
}

function appendNeuralHiddenLayerInDsl(code: string) {
  const lines = code.trimEnd().split(/\r?\n/);
  const nodes = readNeuralDslNodes(code);
  const hiddenGroups = groupHiddenNeuralDslNodes(nodes);
  const outputNodes = nodes.filter(
    (node) => getNeuralLayerKindFromDslNode(node.id, node.label) === 'output'
  );
  const maxHiddenX =
    hiddenGroups.length > 0
      ? Math.max(
          ...hiddenGroups.map((group) =>
            group.reduce((sum, node) => sum + node.x, 0) / group.length
          )
        )
      : 270;
  const nextLayerIndex = hiddenGroups.length + 1;
  const nextX = maxHiddenX + 150;
  const nextOutputX = Math.max(nextX + 170, 445 + hiddenGroups.length * 150);

  for (const outputNode of outputNodes) {
    const currentLine = lines[outputNode.lineIndex];

    if (currentLine) {
      lines[outputNode.lineIndex] = currentLine.replace(
        /\bat\s+-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\b/,
        `at ${formatCoordinate(nextOutputX)} ${formatCoordinate(outputNode.y)}`
      );
    }
  }

  const firstId = createNodeIdWithPrefix(lines.join('\n'), 'H');
  const secondId = createNodeIdWithPrefix(`${lines.join('\n')}\nnode ${firstId} ""`, 'H');

  insertNodeLineBeforeEdges(
    lines,
    `node ${firstId} "h${nextLayerIndex}.1" circle at ${formatCoordinate(nextX)} 132`
  );
  insertNodeLineBeforeEdges(
    lines,
    `node ${secondId} "h${nextLayerIndex}.2" circle at ${formatCoordinate(nextX)} 220`
  );

  return lines.join('\n');
}

function alignNeuralNetworkLayersInDsl(code: string) {
  const nodes = readNeuralDslNodes(code);
  const inputNodes = nodes.filter(
    (node) => getNeuralLayerKindFromDslNode(node.id, node.label) === 'input'
  );
  const outputNodes = nodes.filter(
    (node) => getNeuralLayerKindFromDslNode(node.id, node.label) === 'output'
  );
  const hiddenGroups = groupHiddenNeuralDslNodes(nodes);
  const layerGroups = [
    { nodes: inputNodes, x: 95 },
    ...hiddenGroups.map((group, index) => ({
      nodes: group,
      x: 250 + index * 150,
    })),
    { nodes: outputNodes, x: 250 + hiddenGroups.length * 150 },
  ];
  const nextPositions = new Map<number, { x: number; y: number }>();

  for (const group of layerGroups) {
    const layerNodes = [...group.nodes].sort((first, second) => {
      if (first.y !== second.y) {
        return first.y - second.y;
      }

      return first.id.localeCompare(second.id);
    });
    const spacing = 84;
    const centerY = 176;
    const firstY = centerY - ((layerNodes.length - 1) * spacing) / 2;

    layerNodes.forEach((node, index) => {
      nextPositions.set(node.lineIndex, {
        x: group.x,
        y: firstY + index * spacing,
      });
    });
  }

  return code
    .split(/\r?\n/)
    .map((line, lineIndex) => {
      const position = nextPositions.get(lineIndex);

      if (!position) {
        return line;
      }

      return line.replace(
        /\bat\s+-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\b/,
        `at ${formatCoordinate(position.x)} ${formatCoordinate(position.y)}`
      );
    })
    .join('\n');
}

export function updateVisualizationCommandInDsl(
  code: string,
  command: string,
  value: string
) {
  if (command === 'hash-remove-bucket') {
    return removeHashBucketFromDsl(code, Number(value));
  }

  if (command === 'vector-add') {
    return appendVectorSpaceVectorInDsl(code);
  }

  if (command === 'vector-point-add') {
    return appendVectorSpacePointInDsl(code);
  }

  if (command === 'vector-boundary-add') {
    return appendVectorSpaceBoundaryInDsl(code);
  }

  if (command === 'neural-align') {
    return alignNeuralNetworkLayersInDsl(code);
  }

  if (command === 'neural-add-hidden-layer') {
    return appendNeuralHiddenLayerInDsl(code);
  }

  const nextValue = value.trim();
  const serializedValue =
    command === 'formula' ? serializeCommandValue(nextValue) : nextValue;
  const nextLine = `${command}${serializedValue ? ` ${serializedValue}` : ''}`;
  let updated = false;
  const lines = code.split(/\r?\n/).map((line) => {
    if (updated || !line.trim().startsWith(`${command} `) && line.trim() !== command) {
      return line;
    }

    updated = true;

    return `${line.match(/^\s*/)?.[0] ?? ''}${nextLine}`;
  });

  if (updated) {
    return lines.join('\n');
  }

  const trimmed = code.trimEnd();

  return trimmed ? `${trimmed}\n${nextLine}` : nextLine;
}

export function updateVisualizationDataItemInDsl(
  code: string,
  itemIndex: number,
  value: string
) {
  let updated = false;

  return code
    .split(/\r?\n/)
    .map((line) => {
      if (updated || !line.trim().startsWith('data ')) {
        return line;
      }

      updated = true;

      const indent = line.match(/^\s*/)?.[0] ?? '';
      const values = line
        .trim()
        .replace(/^data\s+/, '')
        .split(/\s+/)
        .filter(Boolean);

      if (itemIndex < 0 || itemIndex >= values.length) {
        return line;
      }

      const previousBucket = values[itemIndex].match(/@\d+$/)?.[0] ?? '';
      const nextValue = normalizeInlineValue(value);
      values[itemIndex] = /@\d+$/.test(nextValue)
        ? nextValue
        : `${nextValue}${previousBucket}`;

      return `${indent}data ${values.join(' ')}`;
    })
    .join('\n');
}

function updateActiveCommandInDsl(code: string, values: string[]) {
  const normalizedValues = values.filter(Boolean);

  if (normalizedValues.length === 0) {
    return code;
  }

  let updated = false;
  const activeLine = `active ${normalizedValues.join(' ')}`;
  const lines = code.split(/\r?\n/).map((line) => {
    if (updated || !line.trim().startsWith('active ')) {
      return line;
    }

    updated = true;

    return `${line.match(/^\s*/)?.[0] ?? ''}${activeLine}`;
  });

  if (updated) {
    return lines.join('\n');
  }

  const trimmed = code.trimEnd();

  return trimmed ? `${trimmed}\n${activeLine}` : activeLine;
}

export function updateVisualizationActiveItemsInDsl(
  code: string,
  itemIndexes: number[]
) {
  const indexes = Array.from(
    new Set(
      itemIndexes
        .filter((index) => Number.isInteger(index) && index >= 0)
        .map((index) => String(index))
    )
  );

  return updateActiveCommandInDsl(code, indexes);
}

export function updateVisualizationActiveCellInDsl(
  code: string,
  row: number,
  column: number
) {
  if (!Number.isInteger(row) || !Number.isInteger(column) || row < 0 || column < 0) {
    return code;
  }

  return updateActiveCommandInDsl(code, [String(row), String(column)]);
}

export function appendVisualizationDataItemInDsl(
  code: string,
  value = '0'
) {
  let updated = false;
  const lines = code.split(/\r?\n/);
  const nextValue = normalizeInlineValue(value);
  const nextLines = lines.map((line) => {
    if (updated || !line.trim().startsWith('data ')) {
      return line;
    }

    updated = true;

    return `${line.trimEnd()} ${nextValue}`;
  });

  if (updated) {
    return nextLines.join('\n');
  }

  const trimmed = code.trimEnd();
  const dataLine = `data ${nextValue}`;

  return trimmed ? `${trimmed}\n${dataLine}` : dataLine;
}

export function insertVisualizationDataItemInDsl(
  code: string,
  itemIndex: number,
  value = '0'
) {
  if (!Number.isInteger(itemIndex) || itemIndex < 0) {
    return appendVisualizationDataItemInDsl(code, value);
  }

  let updated = false;
  const nextValue = normalizeInlineValue(value);

  const nextLines = code.split(/\r?\n/).map((line) => {
    if (updated || !line.trim().startsWith('data ')) {
      return line;
    }

    updated = true;

    const indent = line.match(/^\s*/)?.[0] ?? '';
    const values = line
      .trim()
      .replace(/^data\s+/, '')
      .split(/\s+/)
      .filter(Boolean);
    const targetIndex = Math.min(itemIndex, values.length);

    values.splice(targetIndex, 0, nextValue);

    return `${indent}data ${values.join(' ')}`;
  });

  if (updated) {
    return nextLines.join('\n');
  }

  return appendVisualizationDataItemInDsl(code, value);
}

export function removeVisualizationDataItemInDsl(
  code: string,
  itemIndex: number
) {
  let updated = false;

  return code
    .split(/\r?\n/)
    .map((line) => {
      if (updated || !line.trim().startsWith('data ')) {
        return line;
      }

      updated = true;

      const indent = line.match(/^\s*/)?.[0] ?? '';
      const values = line
        .trim()
        .replace(/^data\s+/, '')
        .split(/\s+/)
        .filter(Boolean);

      if (itemIndex < 0 || itemIndex >= values.length) {
        return line;
      }

      values.splice(itemIndex, 1);

      return values.length > 0 ? `${indent}data ${values.join(' ')}` : '';
    })
    .filter((line) => line.trim() !== '')
    .join('\n');
}

function getRowDataFromCode(code: string) {
  return code
    .split(/\r?\n/)
    .map((line) => line.match(/^(\s*row\s+)(\d+)(\s*:\s*)(.*)$/))
    .filter((match): match is RegExpMatchArray => Boolean(match))
    .map((match) => ({
      columnCount: match[4].split(/\s+/).filter(Boolean).length,
      row: Number(match[2]),
    }));
}

function getColumnCountFromCode(code: string) {
  const columnsLine = code
    .split(/\r?\n/)
    .find((line) => line.trim().startsWith('columns '));
  const columnsCount = columnsLine
    ? columnsLine.trim().replace(/^columns\s+/, '').split(/\s+/).filter(Boolean)
        .length
    : 0;
  const rowColumnCount = Math.max(
    0,
    ...getRowDataFromCode(code).map((row) => row.columnCount)
  );

  return Math.max(columnsCount, rowColumnCount, 1);
}

export function appendVisualizationTableRowInDsl(code: string) {
  const rows = getRowDataFromCode(code);
  const columnCount = getColumnCountFromCode(code);
  const nextRow = rows.length > 0 ? Math.max(...rows.map((row) => row.row)) + 1 : 0;
  const rowLine = `row ${nextRow}: ${Array.from({ length: columnCount })
    .map(() => '.')
    .join(' ')}`;
  const trimmed = code.trimEnd();

  return trimmed ? `${trimmed}\n${rowLine}` : rowLine;
}

export function removeVisualizationTableRowInDsl(code: string, row: number) {
  return code
    .split(/\r?\n/)
    .filter((line) => {
      const match = line.match(/^(\s*row\s+)(\d+)(\s*:\s*)(.*)$/);

      return !match || Number(match[2]) !== row;
    })
    .join('\n');
}

export function appendVisualizationTableColumnInDsl(code: string) {
  let hasRow = false;
  const columnCount = getColumnCountFromCode(code);

  return code
    .split(/\r?\n/)
    .map((line) => {
      if (line.trim().startsWith('columns ')) {
        return `${line.trimEnd()} c${columnCount}`;
      }

      const match = line.match(/^(\s*row\s+)(\d+)(\s*:\s*)(.*)$/);

      if (!match) {
        return line;
      }

      hasRow = true;

      return `${match[1]}${match[2]}${match[3]}${match[4].trim()} .`;
    })
    .join('\n')
    .concat(hasRow ? '' : `\nrow 0: .`);
}

export function removeVisualizationTableColumnInDsl(
  code: string,
  column: number
) {
  return code
    .split(/\r?\n/)
    .map((line) => {
      if (line.trim().startsWith('columns ')) {
        const indent = line.match(/^\s*/)?.[0] ?? '';
        const values = line
          .trim()
          .replace(/^columns\s+/, '')
          .split(/\s+/)
          .filter(Boolean);

        if (column < 0 || column >= values.length) {
          return line;
        }

        values.splice(column, 1);

        return values.length > 0 ? `${indent}columns ${values.join(' ')}` : '';
      }

      const match = line.match(/^(\s*row\s+)(\d+)(\s*:\s*)(.*)$/);

      if (!match) {
        return line;
      }

      const values = match[4].split(/\s+/).filter(Boolean);

      if (column < 0 || column >= values.length) {
        return line;
      }

      values.splice(column, 1);

      return `${match[1]}${match[2]}${match[3]}${values.join(' ')}`;
    })
    .filter((line) => line.trim() !== '')
    .join('\n');
}

export function updateVisualizationCellValueInDsl(
  code: string,
  row: number,
  column: number,
  value: string
) {
  let updated = false;

  return code
    .split(/\r?\n/)
    .map((line) => {
      if (updated) {
        return line;
      }

      const match = line.match(/^(\s*row\s+)(\d+)(\s*:\s*)(.*)$/);

      if (!match || Number(match[2]) !== row) {
        return line;
      }

      updated = true;

      const values = match[4].split(/\s+/).filter(Boolean);

      if (column < 0 || column >= values.length) {
        return line;
      }

      values[column] = normalizeInlineValue(value);

      return `${match[1]}${match[2]}${match[3]}${values.join(' ')}`;
    })
    .join('\n');
}

function getStepDefinitions(code: string) {
  return code
    .split(/\r?\n/)
    .map((line) => line.trim().match(/^step\s+(\S+)\s+(\d+)\s+"?([^"]*)"?/))
    .filter((match): match is RegExpMatchArray => Boolean(match))
    .map((match) => ({
      id: match[1],
      order: Number(match[2]),
      title: match[3] || `Step ${match[2]}`,
    }));
}

function createStepId(code: string) {
  const stepIds = new Set(getStepDefinitions(code).map((step) => step.id));
  let index = stepIds.size + 1;
  let id = `s${index}`;

  while (stepIds.has(id)) {
    index += 1;
    id = `s${index}`;
  }

  return id;
}

export function appendVisualizationStepInDsl(code: string) {
  const steps = getStepDefinitions(code);
  const order = steps.length + 1;
  const id = createStepId(code);
  const title = `Step ${order}`;
  const suffix = `\n\nstep ${id} ${order} "${title}"\nstep-note ${id} "Describe this step."`;

  return `${code.trimEnd()}${suffix}`;
}

export function duplicateVisualizationStepInDsl(code: string, stepId: string) {
  const lines = code.split(/\r?\n/);
  const steps = getStepDefinitions(code);
  const sourceStep = steps.find((step) => step.id === stepId);

  if (!sourceStep) {
    return code;
  }

  const nextId = createStepId(code);
  const nextOrder = steps.length + 1;
  const stepLines = lines.filter((line) => {
    const trimmed = line.trim();

    return (
      trimmed.startsWith(`step ${stepId} `) ||
      trimmed.startsWith(`step-focus ${stepId} `) ||
      trimmed.startsWith(`step-note ${stepId} `) ||
      trimmed.startsWith(`step-position ${stepId} `) ||
      trimmed.startsWith(`step-state ${stepId} `) ||
      trimmed.startsWith(`step-type-data ${stepId} `) ||
      trimmed.startsWith(`step-value ${stepId} `) ||
      trimmed.startsWith(`step-viewport ${stepId} `) ||
      trimmed.startsWith(`step-visible ${stepId} `)
    );
  });

  const duplicatedLines = stepLines.map((line) => {
    const trimmed = line.trim();

    if (trimmed.startsWith(`step ${stepId} `)) {
      return `step ${nextId} ${nextOrder} "${sourceStep.title} Copy"`;
    }

    return line.replace(
      new RegExp(`^(\\s*step-[^\\s]+\\s+)${stepId}(\\s+)`),
      `$1${nextId}$2`
    );
  });

  return `${code.trimEnd()}\n\n${duplicatedLines.join('\n')}`;
}

export function removeVisualizationStepInDsl(code: string, stepId: string) {
  const stepCommandPattern = new RegExp(`^\\s*step\\s+${stepId}\\s+`);
  const stepDetailPattern = new RegExp(`^\\s*step-[^\\s]+\\s+${stepId}(\\s+|$)`);

  return code
    .split(/\r?\n/)
    .filter(
      (line) =>
        !stepCommandPattern.test(line) && !stepDetailPattern.test(line)
    )
    .join('\n')
    .replace(/\n{3,}/g, '\n\n')
    .trimEnd();
}

export function updateVisualizationStepFocusInDsl(
  code: string,
  stepId: string,
  targets: string[]
) {
  const nextLine = `step-focus ${stepId} ${targets.join(' ')}`;
  let updated = false;
  const lines = code.split(/\r?\n/).map((line) => {
    if (line.trim().startsWith(`step-focus ${stepId} `)) {
      updated = true;

      return nextLine;
    }

    return line;
  });

  if (updated) {
    return lines.join('\n');
  }

  const stepIndex = lines.findIndex((line) =>
    line.trim().startsWith(`step ${stepId} `)
  );

  if (stepIndex >= 0) {
    lines.splice(stepIndex + 1, 0, nextLine);

    return lines.join('\n');
  }

  return `${code.trimEnd()}\n${nextLine}`;
}

export function appendVisualizationStepStateInDsl(
  code: string,
  stepId: string,
  target: string,
  state: VisualizationElementState
) {
  const nextLine = `step-state ${stepId} ${target} ${state}`;
  const lines = code.split(/\r?\n/);
  const insertIndex = lines.reduce((lastIndex, line, index) => {
    const trimmed = line.trim();

    return trimmed.startsWith(`step ${stepId} `) ||
      trimmed.startsWith(`step-`) && trimmed.includes(` ${stepId} `)
      ? index
      : lastIndex;
  }, -1);

  if (insertIndex >= 0) {
    lines.splice(insertIndex + 1, 0, nextLine);

    return lines.join('\n');
  }

  return `${code.trimEnd()}\n${nextLine}`;
}

export function parseVisualizationDsl(
  code: string,
  fallbackType: VisualizationType,
  fallbackTitle = ''
): VisualizationDocument {
  let visualType = fallbackType;
  let title = fallbackTitle;
  let items: VisualizationItem[] = [];
  let cells: VisualizationCell[] = [];
  const nodes: VisualizationNode[] = [];
  const edges: VisualizationEdge[] = [];
  const typeData: Record<string, unknown> = {};
  const warnings: string[] = [];
  let activeRest: string | null = null;
  const stepMap = new Map<string, VisualizationStep>();

  const parsedLines = normalizeVisualizationDslCode(code)
    .split(/\r?\n/)
    .map(toParsedLine)
    .filter((line): line is ParsedLine => Boolean(line));

  parsedLines.forEach((line, index) => {
    switch (line.command) {
      case 'visual':
        if (isVisualizationType(line.rest)) {
          visualType = line.rest;
        }
        break;
      case 'title': {
        const quoted = readQuoted(line.rest);
        title = quoted?.text ?? line.rest;
        break;
      }
      case 'node': {
        const node = parseNode(line);

        if (node) {
          nodes.push(node);
        } else {
          warnings.push(`노드를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      }
      case 'edge': {
        const edge = parseEdge(
          line,
          index,
          getDefaultEdgeKindForVisualType(visualType)
        );

        if (edge) {
          edges.push(edge);
        } else {
          warnings.push(`연결선을 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      }
      case 'data':
        items = parseDataItems(line.rest);
        break;
      case 'active':
        activeRest = line.rest;
        break;
      case 'step':
        if (!parseStepCommand(line, stepMap)) {
          warnings.push(`단계 정보를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-focus':
        if (!parseStepFocusCommand(line, stepMap)) {
          warnings.push(`단계 강조 정보를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-note':
        if (!parseStepNoteCommand(line, stepMap)) {
          warnings.push(`단계 설명을 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-position':
        if (!parseStepPositionCommand(line, stepMap)) {
          warnings.push(`단계 위치 정보를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-state':
        if (!parseStepStateCommand(line, stepMap)) {
          warnings.push(`단계 상태 정보를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-type-data':
        if (!parseStepTypeDataCommand(line, stepMap)) {
          warnings.push(`단계 유형 데이터를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-value':
        if (!parseStepValueCommand(line, stepMap)) {
          warnings.push(`단계 값 정보를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-viewport':
        if (!parseStepViewportCommand(line, stepMap)) {
          warnings.push(`단계 화면 위치를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'step-visible':
        if (!parseStepVisibleCommand(line, stepMap)) {
          warnings.push(`단계 표시 정보를 해석할 수 없습니다: ${line.raw}`);
        }
        break;
      case 'columns':
        typeData.columns = line.rest.split(/\s+/).filter(Boolean);
        break;
      case 'formula': {
        const quoted = readQuoted(line.rest);
        typeData.formula = quoted?.text ?? line.rest;
        break;
      }
      case 'references':
        typeData.references = line.rest
          .split(/\s+/)
          .map((token) => token.match(/^(\d+):(\d+)$/))
          .filter((match): match is RegExpMatchArray => Boolean(match))
          .map((match) => ({
            column: Number(match[2]),
            row: Number(match[1]),
          }));
        break;
      case 'sort-mode':
        typeData.sortMode = line.rest || 'compare';
        break;
      case 'pointers': {
        const [low, mid, high] = line.rest
          .split(/\s+/)
          .map((value) => Number(value));
        typeData.pointers = {
          high: Number.isInteger(high) ? high : undefined,
          low: Number.isInteger(low) ? low : undefined,
          mid: Number.isInteger(mid) ? mid : undefined,
        };
        break;
      }
      case 'tree-type':
        typeData.treeType = line.rest || 'binary';
        break;
      case 'trend-line':
        typeData.trendLine = !['hide', 'hidden', 'off', 'false', '0', 'none'].includes(
          line.rest.trim().toLowerCase()
        );
        break;
      case 'hull':
        typeData.hull = !['hide', 'hidden', 'off', 'false', '0', 'none'].includes(
          line.rest.trim().toLowerCase()
        );
        break;
      case 'projection':
        typeData.projection = ![
          'hide',
          'hidden',
          'off',
          'false',
          '0',
          'none',
        ].includes(line.rest.trim().toLowerCase());
        break;
      case 'graph': {
        const tokens = line.rest.split(/\s+/).filter(Boolean);
        typeData.directed = tokens.includes('directed')
          ? true
          : tokens.includes('undirected')
            ? false
            : typeData.directed;
        typeData.weighted = tokens.includes('weighted')
          ? true
          : tokens.includes('unweighted')
            ? false
            : typeData.weighted;
        break;
      }
      case 'bucket-count': {
        const count = Number(line.rest);
        if (Number.isInteger(count) && count > 0) {
          typeData.bucketCount = count;
        }
        break;
      }
      case 'row':
        cells = [...cells, ...parseDpRow(line.rest)];
        break;
      case 'unweighted':
        typeData.weighted = false;
        break;
      default:
        warnings.push(`지원하지 않는 명령입니다: ${line.command}`);
    }
  });

  if (activeRest) {
    items = applyActiveItems(items, activeRest);
    cells = applyActiveCell(cells, activeRest);
  }

  const document = createEmptyVisualizationDocument(visualType, 'parsed', title);

  return {
    ...document,
    title,
    source: {
      code,
      format: 'algoviz-dsl',
    },
    model: {
      ...document.model,
      cells,
      edges,
      items,
      nodes,
      steps: [...stepMap.values()].sort((first, second) => first.order - second.order),
      typeData: {
        ...typeData,
        warnings,
      },
    },
  };
}

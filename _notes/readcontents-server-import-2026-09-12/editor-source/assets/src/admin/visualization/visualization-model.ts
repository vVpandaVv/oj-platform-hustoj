import type { VisualizationType } from './visualization-types';

export const VISUALIZATION_SCHEMA_VERSION = '1.0.0';

export type VisualizationSourceFormat = 'algoviz-dsl' | 'json';

export type VisualizationElementState =
  | 'normal'
  | 'active'
  | 'success'
  | 'warning'
  | 'error'
  | 'muted';

export type VisualizationShapeKind =
  | 'terminator'
  | 'process'
  | 'decision'
  | 'input'
  | 'output'
  | 'data'
  | 'circle'
  | 'rectangle'
  | 'rounded-rectangle'
  | 'diamond'
  | 'custom';

export type VisualizationConnectorKind =
  | 'straight'
  | 'orthogonal'
  | 'elbow';

export type VisualizationDirection = 'none' | 'forward' | 'backward' | 'both';

export type VisualizationAnchorSide = 'bottom' | 'left' | 'right' | 'top';

export type VisualizationEdgeRouteSide =
  | 'auto'
  | 'bottom'
  | 'left'
  | 'right'
  | 'top';

export type VisualizationPoint = {
  x: number;
  y: number;
};

export type VisualizationSize = {
  height: number;
  width: number;
};

export type VisualizationViewport = {
  height?: number;
  width?: number;
  x: number;
  y: number;
  zoom: number;
};

export type VisualizationSource = {
  code: string;
  format: VisualizationSourceFormat;
};

export type VisualizationStyle = {
  fill?: string;
  stroke?: string;
  strokeDasharray?: string;
  strokeWidth?: number;
  textColor?: string;
};

export type VisualizationNode = {
  id: string;
  label: string;
  position: VisualizationPoint;
  shape?: VisualizationShapeKind;
  size?: VisualizationSize;
  state?: VisualizationElementState;
  style?: VisualizationStyle;
  type?: string;
};

export type VisualizationEdge = {
  id: string;
  from: string;
  fromAnchor?: VisualizationAnchorSide;
  to: string;
  toAnchor?: VisualizationAnchorSide;
  direction?: VisualizationDirection;
  kind?: VisualizationConnectorKind;
  label?: string;
  points?: VisualizationPoint[];
  routeOffset?: number;
  routeSide?: VisualizationEdgeRouteSide;
  state?: VisualizationElementState;
  style?: VisualizationStyle;
  weight?: number | string;
};

export type VisualizationAnnotation = {
  id: string;
  label: string;
  position: VisualizationPoint;
  state?: VisualizationElementState;
  style?: VisualizationStyle;
};

export type VisualizationCell = {
  id: string;
  column: number;
  label?: string;
  row: number;
  state?: VisualizationElementState;
  value: number | string | boolean | null;
};

export type VisualizationItem = {
  id: string;
  index?: number;
  label?: string;
  position?: VisualizationPoint;
  state?: VisualizationElementState;
  value?: number | string | boolean | null;
};

export type VisualizationTargetRef =
  | { id: string; kind: 'annotation' }
  | { id: string; kind: 'edge' }
  | { id: string; kind: 'item' }
  | { id: string; kind: 'node' }
  | { column: number; kind: 'cell'; row: number };

export type VisualizationStepAction =
  | {
      state: VisualizationElementState;
      target: VisualizationTargetRef;
      type: 'set-state';
    }
  | {
      field: string;
      target: VisualizationTargetRef;
      type: 'set-value';
      value: number | string | boolean | null;
    }
  | {
      position: VisualizationPoint;
      target: VisualizationTargetRef;
      type: 'set-position';
    }
  | {
      target: VisualizationTargetRef;
      type: 'set-visibility';
      visible: boolean;
    }
  | {
      style: VisualizationStyle;
      target: VisualizationTargetRef;
      type: 'set-style';
    }
  | {
      key: string;
      type: 'set-type-data';
      value: unknown;
    };

export type VisualizationStep = {
  actions?: VisualizationStepAction[];
  id: string;
  description: string;
  durationMs?: number;
  focusRefs?: VisualizationTargetRef[];
  focusIds?: string[];
  order: number;
  title?: string;
  viewport?: VisualizationViewport;
};

export type VisualizationAssetRef = {
  alt?: string;
  caption?: string;
  id: string;
  license?: string;
  source?: string;
  type: 'image' | 'video' | 'audio' | 'file';
  url: string;
};

export type VisualizationModel = {
  annotations?: VisualizationAnnotation[];
  cells?: VisualizationCell[];
  edges?: VisualizationEdge[];
  items?: VisualizationItem[];
  nodes?: VisualizationNode[];
  steps?: VisualizationStep[];
  style?: VisualizationStyle;
  typeData?: Record<string, unknown>;
  viewport: VisualizationViewport;
};

export type VisualizationDocument = {
  assets: VisualizationAssetRef[];
  metadata: Record<string, unknown>;
  model: VisualizationModel;
  schemaVersion: string;
  source: VisualizationSource;
  title: string;
  visualId: string;
  visualType: VisualizationType;
};

export type VisualizationSelection =
  | { id: string; kind: 'node' }
  | { id: string; kind: 'edge' }
  | { id: string; kind: 'cell' }
  | { id: string; kind: 'item' }
  | { id: string; kind: 'annotation' }
  | { kind: 'canvas' }
  | null;

export type VisualizationEditMode = 'read' | 'select' | 'connect' | 'text';

function targetMatchesId(
  target: VisualizationTargetRef,
  id: string,
  fallbackIndex?: number
) {
  if (target.kind === 'cell') {
    return id === `cell-${target.row}-${target.column}`;
  }

  if (target.kind === 'item' && fallbackIndex !== undefined) {
    return target.id === id || target.id === String(fallbackIndex);
  }

  return target.id === id;
}

function applyStepState<T extends { id: string; index?: number; state?: VisualizationElementState }>(
  collection: T[] | undefined,
  targetKind: VisualizationTargetRef['kind'],
  target: VisualizationTargetRef,
  state: VisualizationElementState
) {
  if (!collection || target.kind !== targetKind) {
    return collection;
  }

  return collection.map((item) =>
    targetMatchesId(target, item.id, item.index) ? { ...item, state } : item
  );
}

function applyStepValue<T extends { id: string; index?: number }>(
  collection: T[] | undefined,
  targetKind: VisualizationTargetRef['kind'],
  target: VisualizationTargetRef,
  field: string,
  value: number | string | boolean | null
) {
  if (!collection || target.kind !== targetKind) {
    return collection;
  }

  return collection.map((item) =>
    targetMatchesId(target, item.id, item.index)
      ? { ...item, [field]: value }
      : item
  );
}

function applyStepPosition<T extends { id: string; index?: number; position?: VisualizationPoint }>(
  collection: T[] | undefined,
  targetKind: VisualizationTargetRef['kind'],
  target: VisualizationTargetRef,
  position: VisualizationPoint
) {
  if (!collection || target.kind !== targetKind) {
    return collection;
  }

  return collection.map((item) =>
    targetMatchesId(target, item.id, item.index)
      ? { ...item, position: { ...position } }
      : item
  );
}

function applyStepStyle<T extends { id: string; index?: number; style?: VisualizationStyle }>(
  collection: T[] | undefined,
  targetKind: VisualizationTargetRef['kind'],
  target: VisualizationTargetRef,
  style: VisualizationStyle
) {
  if (!collection || target.kind !== targetKind) {
    return collection;
  }

  return collection.map((item) =>
    targetMatchesId(target, item.id, item.index)
      ? { ...item, style: { ...(item.style ?? {}), ...style } }
      : item
  );
}

function clearActiveState<T extends { state?: VisualizationElementState }>(
  collection: T[] | undefined
) {
  return collection?.map((item) =>
    item.state === 'active' ? { ...item, state: undefined } : item
  );
}

function applyCellState(
  cells: VisualizationCell[] | undefined,
  target: VisualizationTargetRef,
  state: VisualizationElementState
) {
  if (!cells || target.kind !== 'cell') {
    return cells;
  }

  return cells.map((cell) =>
    cell.row === target.row && cell.column === target.column
      ? { ...cell, state }
      : cell
  );
}

function applyCellValue(
  cells: VisualizationCell[] | undefined,
  target: VisualizationTargetRef,
  field: string,
  value: number | string | boolean | null
) {
  if (!cells || target.kind !== 'cell') {
    return cells;
  }

  return cells.map((cell) =>
    cell.row === target.row && cell.column === target.column
      ? { ...cell, [field]: value }
      : cell
  );
}

function applyTargetState(
  model: VisualizationModel,
  target: VisualizationTargetRef,
  state: VisualizationElementState
) {
  return {
    ...model,
    annotations: applyStepState(model.annotations, 'annotation', target, state),
    cells: applyCellState(model.cells, target, state),
    edges: applyStepState(model.edges, 'edge', target, state),
    items: applyStepState(model.items, 'item', target, state),
    nodes: applyStepState(model.nodes, 'node', target, state),
  };
}

function applyTargetValue(
  model: VisualizationModel,
  target: VisualizationTargetRef,
  field: string,
  value: number | string | boolean | null
) {
  return {
    ...model,
    annotations: applyStepValue(model.annotations, 'annotation', target, field, value),
    cells: applyCellValue(model.cells, target, field, value),
    edges: applyStepValue(model.edges, 'edge', target, field, value),
    items: applyStepValue(model.items, 'item', target, field, value),
    nodes: applyStepValue(model.nodes, 'node', target, field, value),
  };
}

function applyTargetPosition(
  model: VisualizationModel,
  target: VisualizationTargetRef,
  position: VisualizationPoint
) {
  return {
    ...model,
    annotations: applyStepPosition(model.annotations, 'annotation', target, position),
    items: applyStepPosition(model.items, 'item', target, position),
    nodes: applyStepPosition(model.nodes, 'node', target, position),
  };
}

function applyTargetStyle(
  model: VisualizationModel,
  target: VisualizationTargetRef,
  style: VisualizationStyle
) {
  return {
    ...model,
    annotations: applyStepStyle(model.annotations, 'annotation', target, style),
    edges: applyStepStyle(model.edges, 'edge', target, style),
    items: applyStepStyle(model.items, 'item', target, style),
    nodes: applyStepStyle(model.nodes, 'node', target, style),
  };
}

function filterHiddenTargets<T extends { id: string; index?: number }>(
  collection: T[] | undefined,
  targetKind: VisualizationTargetRef['kind'],
  hiddenTargets: VisualizationTargetRef[]
) {
  if (!collection) {
    return collection;
  }

  return collection.filter(
    (item) =>
      !hiddenTargets.some(
        (target) =>
          target.kind === targetKind && targetMatchesId(target, item.id, item.index)
      )
  );
}

function filterHiddenCells(
  cells: VisualizationCell[] | undefined,
  hiddenTargets: VisualizationTargetRef[]
) {
  if (!cells) {
    return cells;
  }

  return cells.filter(
    (cell) =>
      !hiddenTargets.some(
        (target) =>
          target.kind === 'cell' &&
          target.row === cell.row &&
          target.column === cell.column
      )
  );
}

export function getOrderedVisualizationSteps(
  document: VisualizationDocument
) {
  return [...(document.model.steps ?? [])].sort((first, second) => {
    const orderDiff = first.order - second.order;

    if (orderDiff !== 0) {
      return orderDiff;
    }

    return first.id.localeCompare(second.id);
  });
}

export function applyVisualizationStepOverlay(
  document: VisualizationDocument,
  stepId?: string | null
): VisualizationDocument {
  if (!stepId) {
    return document;
  }

  const step = document.model.steps?.find((item) => item.id === stepId);

  if (!step) {
    return document;
  }

  let model: VisualizationModel = {
    ...document.model,
    annotations: document.model.annotations?.map((item) => ({ ...item })),
    cells: document.model.cells?.map((item) => ({ ...item })),
    edges: document.model.edges?.map((item) => ({ ...item })),
    items: document.model.items?.map((item) => ({ ...item })),
    nodes: document.model.nodes?.map((item) => ({ ...item })),
    typeData: { ...(document.model.typeData ?? {}) },
    viewport: step.viewport ? { ...step.viewport } : { ...document.model.viewport },
  };

  model = {
    ...model,
    annotations: clearActiveState(model.annotations),
    cells: clearActiveState(model.cells),
    edges: clearActiveState(model.edges),
    items: clearActiveState(model.items),
    nodes: clearActiveState(model.nodes),
  };

  step.focusRefs?.forEach((target) => {
    model = applyTargetState(model, target, 'active');
  });

  step.focusIds?.forEach((id) => {
    model.nodes = model.nodes?.map((node) =>
      node.id === id ? { ...node, state: 'active' } : node
    );
    model.edges = model.edges?.map((edge) =>
      edge.id === id ? { ...edge, state: 'active' } : edge
    );
    model.items = model.items?.map((item) =>
      item.id === id ? { ...item, state: 'active' } : item
    );
  });

  const hiddenTargets: VisualizationTargetRef[] = [];

  step.actions?.forEach((action) => {
    switch (action.type) {
      case 'set-state':
        model = applyTargetState(model, action.target, action.state);
        break;
      case 'set-value':
        model = applyTargetValue(model, action.target, action.field, action.value);
        break;
      case 'set-position':
        model = applyTargetPosition(model, action.target, action.position);
        break;
      case 'set-style':
        model = applyTargetStyle(model, action.target, action.style);
        break;
      case 'set-type-data':
        model = {
          ...model,
          typeData: {
            ...(model.typeData ?? {}),
            [action.key]: action.value,
          },
        };
        break;
      case 'set-visibility':
        if (!action.visible) {
          hiddenTargets.push(action.target);
        }
        break;
    }
  });

  if (hiddenTargets.length > 0) {
    model = {
      ...model,
      annotations: filterHiddenTargets(model.annotations, 'annotation', hiddenTargets),
      cells: filterHiddenCells(model.cells, hiddenTargets),
      edges: filterHiddenTargets(model.edges, 'edge', hiddenTargets),
      items: filterHiddenTargets(model.items, 'item', hiddenTargets),
      nodes: filterHiddenTargets(model.nodes, 'node', hiddenTargets),
    };
  }

  return {
    ...document,
    metadata: {
      ...document.metadata,
      activeStepId: step.id,
    },
    model,
  };
}

export function createEmptyVisualizationDocument(
  visualType: VisualizationType,
  visualId = 'visual-001',
  title = ''
): VisualizationDocument {
  return {
    visualId,
    schemaVersion: VISUALIZATION_SCHEMA_VERSION,
    visualType,
    title,
    source: {
      format: 'algoviz-dsl',
      code: `visual ${visualType}`,
    },
    model: {
      viewport: {
        x: 0,
        y: 0,
        zoom: 1,
      },
      nodes: [],
      edges: [],
      annotations: [],
      steps: [],
    },
    assets: [],
    metadata: {
      renderer: 'contents-editor-visualization',
    },
  };
}

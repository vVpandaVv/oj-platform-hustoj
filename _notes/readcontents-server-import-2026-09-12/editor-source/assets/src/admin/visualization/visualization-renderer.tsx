import * as React from 'react';

import type {
  VisualizationCell,
  VisualizationAnchorSide,
  VisualizationDocument,
  VisualizationDirection,
  VisualizationEdge,
  VisualizationEdgeRouteSide,
  VisualizationElementState,
  VisualizationItem,
  VisualizationNode,
  VisualizationShapeKind,
} from './visualization-model';
import { applyVisualizationStepOverlay } from './visualization-model';
import { parseVisualizationDsl } from './visualization-dsl';
import {
  getVisualizationEditingContract,
  isVisualizationEdgeBendable,
  isVisualizationEdgeDirected,
  shouldShowVisualizationEdgeCrossingMarker,
} from './visualization-editing-contract';
import type { VisualizationMenuAction } from './visualization-editing-contract';
import type { VisualizationType } from './visualization-types';

type VisualizationRendererProps = {
  currentStepId?: string | null;
  document: VisualizationDocument;
  onCellColumnCreate?: () => void;
  onCellColumnDelete?: (column: number) => void;
  onCellRowCreate?: () => void;
  onCellRowDelete?: (row: number) => void;
  onCellValueChange?: (row: number, column: number, value: string) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeAnchorChange?: (
    edge: VisualizationEdge,
    fromAnchor: VisualizationAnchorSide,
    toAnchor: VisualizationAnchorSide
  ) => void;
  onEdgeRouteChange?: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  onEdgeEndpointsChange?: (
    edge: VisualizationEdge,
    nextFromNodeId: string,
    nextToNodeId: string
  ) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeDirectionToggle?: (edge: VisualizationEdge) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange?: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onEdgeStateChange?: (
    edge: VisualizationEdge,
    state: VisualizationElementState
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange?: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  onItemCreate?: (itemIndex?: number, value?: string) => void;
  onItemDelete?: (itemIndex: number) => void;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  onCellActiveChange?: (row: number, column: number) => void;
  onCommandChange?: (command: string, value: string) => void;
  readOnly?: boolean;
};

const FLOWCHART_SHAPES = [
  { label: 'Start / End', shape: 'terminator' },
  { label: 'Process', shape: 'process' },
  { label: 'Input', shape: 'input' },
  { label: 'Decision', shape: 'decision' },
  { label: 'Output', shape: 'output' },
] as const satisfies readonly {
  label: string;
  shape: VisualizationShapeKind;
}[];

const FLOWCHART_DEFAULT_LABEL: Record<string, string> = {
  decision: 'Decision',
  input: 'Input',
  output: 'Output',
  process: 'Process',
  terminator: 'Start',
};

const ANCHOR_LABELS: Record<AnchorSide, string> = {
  bottom: 'Bottom',
  left: 'Left',
  right: 'Right',
  top: 'Top',
};

const FLOWCHART_SOURCE_ANCHORS: AnchorSide[] = ['bottom', 'right'];
const FLOWCHART_TARGET_ANCHORS: AnchorSide[] = ['top', 'left'];

const EDGE_ROUTE_LABELS: Record<VisualizationEdgeRouteSide, string> = {
  auto: 'Auto',
  bottom: 'Bottom',
  left: 'Left',
  right: 'Right',
  top: 'Top',
};

const EDGE_ROUTE_SIDES: VisualizationEdgeRouteSide[] = [
  'auto',
  'left',
  'right',
  'top',
  'bottom',
];

const EDGE_ROUTE_OFFSETS = [
  { label: 'Tight', value: 48 },
  { label: 'Normal', value: 88 },
  { label: 'Wide', value: 128 },
];

const EDGE_DIRECTION_OPTIONS: {
  label: string;
  value: VisualizationDirection;
}[] = [
  { label: 'No arrow', value: 'none' },
  { label: 'Parent → Child', value: 'forward' },
  { label: 'Child → Parent', value: 'backward' },
  { label: 'Bidirectional', value: 'both' },
];

const EDGE_LINE_STYLE_OPTIONS: {
  label: string;
  value: 'solid' | 'dashed';
}[] = [
  { label: 'Solid', value: 'solid' },
  { label: 'Dashed', value: 'dashed' },
];

type Bounds = {
  height: number;
  minX: number;
  minY: number;
  width: number;
};

type AnchorSide = VisualizationAnchorSide;

type EdgeGeometry = {
  end: { x: number; y: number };
  pathD?: string;
  points: { x: number; y: number }[];
  start: { x: number; y: number };
};

type EdgeAnchorMode = 'flowchart' | 'hierarchical' | 'radial';

type RectBounds = {
  height: number;
  width: number;
  x: number;
  y: number;
};

type NodeMenuAction = {
  defaultLabel: string;
  label: string;
  shape: VisualizationShapeKind;
};

type NodeContextMenuConfig = {
  allowShapeChange: boolean;
  canvasTitle: string;
  createActions: readonly NodeMenuAction[];
  deleteLabel: string;
  defaultAction: NodeMenuAction;
  nodeTitle: string;
  selectedActions: readonly VisualizationMenuAction[];
};

const NODE_WIDTH = 124;
const NODE_HEIGHT = 52;

const FLOWCHART_NODE_ACTIONS: readonly NodeMenuAction[] = [
  { defaultLabel: 'Start', label: 'Start / End', shape: 'terminator' },
  { defaultLabel: 'Process', label: 'Process', shape: 'process' },
  { defaultLabel: 'Input', label: 'Input', shape: 'input' },
  { defaultLabel: 'Decision', label: 'Decision', shape: 'decision' },
  { defaultLabel: 'Output', label: 'Output', shape: 'output' },
] as const;

const SINGLE_NODE_MENU_ACTIONS = {
  actor: [{ defaultLabel: 'Actor', label: 'Actor', shape: 'rectangle' }],
  candidate: [{ defaultLabel: 'Candidate', label: 'Candidate', shape: 'circle' }],
  circle: [{ defaultLabel: 'Vertex', label: 'Vertex', shape: 'circle' }],
  neuron: [{ defaultLabel: 'Neuron', label: 'Neuron', shape: 'circle' }],
  rounded: [
    { defaultLabel: 'Node', label: 'Node', shape: 'rounded-rectangle' },
  ],
  state: [{ defaultLabel: 'State', label: 'State', shape: 'circle' }],
  tree: [{ defaultLabel: 'Node', label: 'Node', shape: 'rounded-rectangle' }],
  treeCircle: [{ defaultLabel: 'Node', label: 'Node', shape: 'circle' }],
} as const satisfies Record<string, readonly NodeMenuAction[]>;

function getNodeContextMenuConfig(
  visualType: VisualizationType | string,
  fallbackShape: VisualizationShapeKind = 'rounded-rectangle'
): NodeContextMenuConfig {
  const contract = getVisualizationEditingContract(visualType as VisualizationType);
  const firstCanvasAddLabel =
    contract?.canvasMenu.find((action) => action.id.startsWith('add-'))?.label ??
    null;
  const deleteLabel =
    contract?.selectedMenu.find((action) => action.id === 'delete')?.label ?? null;
  const selectedActions = contract?.selectedMenu ?? [];

  if (visualType === 'flowchart') {
    return {
      allowShapeChange: true,
      canvasTitle: firstCanvasAddLabel ?? 'Add flowchart shape',
      createActions: FLOWCHART_NODE_ACTIONS,
      defaultAction: FLOWCHART_NODE_ACTIONS[1],
      deleteLabel: deleteLabel ?? 'Delete shape',
      nodeTitle: 'Edit flowchart shape',
      selectedActions,
    };
  }

  if (visualType === 'state-diagram') {
    const [defaultAction] = SINGLE_NODE_MENU_ACTIONS.state;

    return {
      allowShapeChange: false,
      canvasTitle: firstCanvasAddLabel ?? 'Add state',
      createActions: SINGLE_NODE_MENU_ACTIONS.state,
      defaultAction,
      deleteLabel: deleteLabel ?? 'Delete state',
      nodeTitle: 'State actions',
      selectedActions,
    };
  }

  if (visualType === 'neural-network') {
    const [defaultAction] = SINGLE_NODE_MENU_ACTIONS.neuron;

    return {
      allowShapeChange: false,
      canvasTitle: firstCanvasAddLabel ?? 'Add neuron',
      createActions: SINGLE_NODE_MENU_ACTIONS.neuron,
      defaultAction,
      deleteLabel: deleteLabel ?? 'Delete neuron',
      nodeTitle: 'Neuron actions',
      selectedActions,
    };
  }

  if (visualType === 'geometry-plane') {
    const pointAction = { defaultLabel: 'P', label: 'Add point', shape: 'circle' } as const;

    return {
      allowShapeChange: false,
      canvasTitle: 'Add point to plane',
      createActions: [pointAction],
      defaultAction: pointAction,
      deleteLabel: 'Delete point',
      nodeTitle: 'Edit point',
      selectedActions,
    };
  }

  if (visualType === 'network' || visualType === 'vector-space') {
    const [defaultAction] = SINGLE_NODE_MENU_ACTIONS.circle;

    return {
      allowShapeChange: false,
      canvasTitle: firstCanvasAddLabel ?? 'Add vertex',
      createActions: SINGLE_NODE_MENU_ACTIONS.circle,
      defaultAction,
      deleteLabel: deleteLabel ?? 'Delete vertex',
      nodeTitle: 'Vertex actions',
      selectedActions,
    };
  }

  if (visualType === 'tree') {
    const [defaultAction] = SINGLE_NODE_MENU_ACTIONS.treeCircle;

    return {
      allowShapeChange: false,
      canvasTitle: firstCanvasAddLabel ?? 'Add tree node',
      createActions: SINGLE_NODE_MENU_ACTIONS.treeCircle,
      defaultAction,
      deleteLabel: deleteLabel ?? 'Delete node',
      nodeTitle: 'Node actions',
      selectedActions,
    };
  }

  if (
    visualType === 'trie' ||
    visualType === 'segment-tree' ||
    visualType === 'recursion-tree' ||
    visualType === 'divide-conquer' ||
    visualType === 'state-space-tree' ||
    visualType === 'decision-tree'
  ) {
    const [defaultAction] = SINGLE_NODE_MENU_ACTIONS.tree;

    return {
      allowShapeChange: false,
      canvasTitle: firstCanvasAddLabel ?? 'Add tree node',
      createActions: SINGLE_NODE_MENU_ACTIONS.tree,
      defaultAction,
      deleteLabel: deleteLabel ?? 'Delete node',
      nodeTitle: 'Node actions',
      selectedActions,
    };
  }

  const defaultAction: NodeMenuAction = {
    defaultLabel: 'Node',
    label: 'Node',
    shape: fallbackShape,
  };

  return {
    allowShapeChange: false,
    canvasTitle: firstCanvasAddLabel ?? 'Add node',
    createActions: [defaultAction],
    defaultAction,
    deleteLabel: deleteLabel ?? 'Delete node',
    nodeTitle: 'Node actions',
    selectedActions,
  };
}
const ACTIVE_STROKE = '#2563eb';
const NORMAL_STROKE = '#8aa2c0';
const WARNING_STROKE = '#d97706';
const MUTED_STROKE = '#c5d0df';
const TEXT = '#0f172a';

function parseSource(document: VisualizationDocument) {
  if (document.source.format !== 'algoviz-dsl') {
    return document;
  }

  return {
    ...parseVisualizationDsl(
      document.source.code,
      document.visualType,
      document.title
    ),
    visualId: document.visualId,
    metadata: document.metadata,
  };
}

/**
 * Rough label-box estimate (not a real text-metrics measurement -- this file
 * has no DOM to measure against during layout) used only to make sure a
 * node's shape is never SMALLER than its own label needs. Deliberately
 * conservative: it only grows a shape, never shrinks one, so nodes that
 * already fit their label are unaffected.
 */
function measureNodeLabelBox(label: string | undefined) {
  const CHAR_NARROW = 7;
  const CHAR_WIDE = 14;
  const LINE_HEIGHT = 18;
  const lines = (label ?? '').split('\n');
  let maxWidth = 0;

  for (const line of lines) {
    let width = 0;

    for (const char of line) {
      width += char.codePointAt(0)! >= 0x1100 ? CHAR_WIDE : CHAR_NARROW;
    }

    maxWidth = Math.max(maxWidth, width);
  }

  return {
    height: lines.length * LINE_HEIGHT + 24,
    width: maxWidth + 28,
  };
}

function getNodeSize(node: VisualizationNode) {
  const width = node.size?.width ?? NODE_WIDTH;
  const height = node.size?.height ?? NODE_HEIGHT;
  const label = measureNodeLabelBox(node.label);

  return {
    width: Math.max(width, Math.min(label.width, width * 2.2)),
    height: Math.max(height, Math.min(label.height, height * 2.2)),
  };
}

function getNodeBounds(node: VisualizationNode) {
  const size = getNodeSize(node);

  return {
    height: size.height,
    width: size.width,
    x: node.position.x - size.width / 2,
    y: node.position.y - size.height / 2,
  };
}

function getOverlapArea(first: RectBounds, second: RectBounds, padding = 0) {
  const expandedSecond = {
    height: second.height + padding * 2,
    width: second.width + padding * 2,
    x: second.x - padding,
    y: second.y - padding,
  };
  const left = Math.max(first.x, expandedSecond.x);
  const right = Math.min(
    first.x + first.width,
    expandedSecond.x + expandedSecond.width
  );
  const top = Math.max(first.y, expandedSecond.y);
  const bottom = Math.min(
    first.y + first.height,
    expandedSecond.y + expandedSecond.height
  );

  if (right <= left || bottom <= top) {
    return 0;
  }

  return (right - left) * (bottom - top);
}

function getBounds(nodes: VisualizationNode[], fallback: Bounds): Bounds {
  if (nodes.length === 0) {
    return fallback;
  }

  const nodeBounds = nodes.map(getNodeBounds);
  const minX = Math.min(...nodeBounds.map((node) => node.x), 0);
  const minY = Math.min(...nodeBounds.map((node) => node.y), 0);
  const maxX = Math.max(...nodeBounds.map((node) => node.x + node.width));
  const maxY = Math.max(...nodeBounds.map((node) => node.y + node.height));
  const padding = 48;

  return {
    minX: minX - padding,
    minY: minY - padding,
    width: Math.max(fallback.width, maxX - minX + padding * 2),
    height: Math.max(fallback.height, maxY - minY + padding * 2),
  };
}

function getStroke(state: VisualizationNode['state']) {
  switch (state) {
    case 'active':
      return ACTIVE_STROKE;
    case 'error':
      return '#dc2626';
    case 'success':
      return '#0f766e';
    case 'warning':
      return WARNING_STROKE;
    case 'muted':
      return MUTED_STROKE;
    default:
      return NORMAL_STROKE;
  }
}

function getFill(state: VisualizationNode['state']) {
  switch (state) {
    case 'active':
      return '#dbeafe';
    case 'error':
      return '#fee2e2';
    case 'success':
      return '#dcfce7';
    case 'warning':
      return '#fff7ed';
    case 'muted':
      return '#f8fafc';
    default:
      return '#ffffff';
  }
}

function isDecisionShape(shape: VisualizationShapeKind | undefined) {
  return shape === 'decision' || shape === 'diamond';
}

function Shape({
  editable = false,
  node,
  onContextMenu,
  onDoubleClick,
  onLabelChange,
  onPointerDown,
  selected = false,
}: {
  editable?: boolean;
  node: VisualizationNode;
  onContextMenu?: (
    node: VisualizationNode,
    event: React.MouseEvent<SVGGElement>
  ) => void;
  onDoubleClick?: (
    node: VisualizationNode,
    event: React.MouseEvent<SVGGElement>
  ) => void;
  onLabelChange?: (nodeId: string, label: string) => void;
  onPointerDown?: (
    node: VisualizationNode,
    event: React.PointerEvent<SVGGElement>
  ) => void;
  selected?: boolean;
}) {
  const { height, width } = getNodeSize(node);
  const x = node.position.x - width / 2;
  const y = node.position.y - height / 2;
  const stroke = getStroke(node.state);
  const fill = getFill(node.state);
  const strokeWidth = node.state === 'active' ? 2.4 : 1.7;
  const shape = node.shape ?? 'process';

  return (
    <g
      className={editable ? 'cursor-move' : undefined}
      data-visual-node-id={node.id}
      onContextMenu={(event) => onContextMenu?.(node, event)}
      onDoubleClick={(event) => onDoubleClick?.(node, event)}
      onPointerDown={(event) => onPointerDown?.(node, event)}
    >
      {selected && (
        <rect
          fill="none"
          height={height + 14}
          pointerEvents="none"
          rx={10}
          stroke="#2563eb"
          strokeDasharray="4 4"
          strokeWidth={1.6}
          width={width + 14}
          x={x - 7}
          y={y - 7}
        />
      )}
      <NodeShape
        fill={fill}
        height={height}
        shape={shape}
        stroke={stroke}
        strokeWidth={strokeWidth}
        width={width}
        x={x}
        y={y}
      />
      <EditableSvgText
        dataVisualTextTarget="node-label"
        x={node.position.x}
        y={node.position.y + 4}
        value={node.label}
        readOnly={!editable || !onLabelChange}
        className="fill-slate-950 text-[13px] font-semibold"
        onCommit={(label) => onLabelChange?.(node.id, label)}
      />
    </g>
  );
}

function NodeShape({
  fill,
  height,
  shape,
  stroke,
  strokeWidth,
  width,
  x,
  y,
}: {
  fill: string;
  height: number;
  shape: VisualizationShapeKind;
  stroke: string;
  strokeWidth: number;
  width: number;
  x: number;
  y: number;
}) {
  if (shape === 'terminator') {
    return (
      <rect
        fill={fill}
        height={height}
        rx={height / 2}
        stroke={stroke}
        strokeWidth={strokeWidth}
        width={width}
        x={x}
        y={y}
      />
    );
  }

  if (shape === 'decision' || shape === 'diamond') {
    const cx = x + width / 2;
    const cy = y + height / 2;

    return (
      <polygon
        fill={fill}
        points={`${cx},${y} ${x + width},${cy} ${cx},${y + height} ${x},${cy}`}
        stroke={stroke}
        strokeWidth={strokeWidth}
      />
    );
  }

  if (shape === 'input' || shape === 'output' || shape === 'data') {
    const inset = 16;

    return (
      <polygon
        fill={fill}
        points={`${x + inset},${y} ${x + width},${y} ${
          x + width - inset
        },${y + height} ${x},${y + height}`}
        stroke={stroke}
        strokeWidth={strokeWidth}
      />
    );
  }

  if (shape === 'circle') {
    return (
      <ellipse
        cx={x + width / 2}
        cy={y + height / 2}
        fill={fill}
        rx={Math.min(width, height) / 2}
        ry={Math.min(width, height) / 2}
        stroke={stroke}
        strokeWidth={strokeWidth}
      />
    );
  }

  return (
    <rect
      fill={fill}
      height={height}
      rx={8}
      stroke={stroke}
      strokeWidth={strokeWidth}
      width={width}
      x={x}
      y={y}
    />
  );
}

function getConnectionPoint(
  node: VisualizationNode,
  target: VisualizationNode,
  role: 'source' | 'target' | 'auto' = 'auto'
) {
  const size = getNodeSize(node);
  const dx = target.position.x - node.position.x;
  const dy = target.position.y - node.position.y;
  const rx = size.width / 2;
  const ry = size.height / 2;

  if (dx === 0 && dy === 0) {
    return {
      x: node.position.x,
      y: node.position.y + ry,
    };
  }

  if (node.shape === 'circle') {
    const scale = 1 / Math.sqrt((dx * dx) / (rx * rx) + (dy * dy) / (ry * ry));

    return {
      x: node.position.x + dx * scale,
      y: node.position.y + dy * scale,
    };
  }

  return getAnchorPoint(node, getAnchorSide(node, target, role));
}

function getAnchorPoint(
  node: VisualizationNode,
  side: AnchorSide,
  portOffset = 0
) {
  const size = getNodeSize(node);
  const rx = size.width / 2;
  const ry = size.height / 2;
  // portOffset spreads multiple edges that share the same (node, side) along
  // that side instead of collapsing them onto one point, clamped so the
  // point never slides past the shape's own edge.
  const alongX = Math.max(-rx + 4, Math.min(rx - 4, portOffset));
  const alongY = Math.max(-ry + 4, Math.min(ry - 4, portOffset));

  switch (side) {
    case 'bottom':
      return { x: node.position.x + alongX, y: node.position.y + ry };
    case 'left':
      return { x: node.position.x - rx, y: node.position.y + alongY };
    case 'right':
      return { x: node.position.x + rx, y: node.position.y + alongY };
    case 'top':
      return { x: node.position.x + alongX, y: node.position.y - ry };
  }
}

function getAnchorSide(
  node: VisualizationNode,
  target: VisualizationNode,
  role: 'source' | 'target' | 'auto'
): AnchorSide {
  const size = getNodeSize(node);
  const dx = target.position.x - node.position.x;
  const dy = target.position.y - node.position.y;
  const sameRowThreshold = Math.max(28, size.height * 0.85);

  if (isDecisionShape(node.shape)) {
    if (role === 'source') {
      return dx > Math.max(18, size.width * 0.25) ? 'right' : 'bottom';
    }

    return dx < -Math.max(18, size.width * 0.25) ? 'left' : 'top';
  }

  if (role === 'source') {
    if (dx > Math.max(18, size.width * 0.25) || dy < -sameRowThreshold) {
      return 'right';
    }

    return 'bottom';
  }

  if (role === 'target') {
    if (dx < -Math.max(18, size.width * 0.25)) {
      return 'left';
    }

    return 'top';
  }

  if (Math.abs(dy) <= sameRowThreshold) {
    return dx >= 0 ? 'right' : 'left';
  }

  return dy > 0 ? 'bottom' : 'top';
}

function getPointSide(
  node: VisualizationNode,
  point: { x: number; y: number }
): AnchorSide {
  const dx = point.x - node.position.x;
  const dy = point.y - node.position.y;

  if (Math.abs(dx) > Math.abs(dy)) {
    return dx > 0 ? 'right' : 'left';
  }

  return dy > 0 ? 'bottom' : 'top';
}

function getOrthogonalEdgePoints(
  from: VisualizationNode,
  to: VisualizationNode,
  start: { x: number; y: number },
  end: { x: number; y: number },
  routeSide: VisualizationEdgeRouteSide = 'auto',
  routeOffset = 72,
  allNodes: VisualizationNode[] = [from, to]
) {
  const startSide = getPointSide(from, start);
  const endSide = getPointSide(to, end);
  const resolvedRouteSide =
    routeSide === 'auto'
      ? getAutoEdgeRouteSide(start, end, startSide, endSide)
      : routeSide;

  if (resolvedRouteSide !== 'auto') {
    const directPoints = getSimpleOrthogonalEdgePoints(
      start,
      end,
      startSide,
      endSide
    );
    const routedPoints = getRoutedEdgePoints(
      from,
      to,
      start,
      end,
      startSide,
      endSide,
      resolvedRouteSide,
      routeOffset,
      allNodes
    );

    if (
      shouldPreferSimpleRoute(start, end, directPoints, routedPoints, allNodes, [
        from.id,
        to.id,
      ])
    ) {
      return directPoints;
    }

    return routedPoints;
  }

  return getSimpleOrthogonalEdgePoints(start, end, startSide, endSide);
}

function getSimpleOrthogonalEdgePoints(
  start: { x: number; y: number },
  end: { x: number; y: number },
  startSide: AnchorSide,
  endSide: AnchorSide
) {
  if (startSide === 'right' && endSide === 'top') {
    return [start, { x: end.x, y: start.y }, end];
  }

  if (startSide === 'right' && endSide === 'left') {
    const midX = start.x + (end.x - start.x) / 2;

    return [
      start,
      { x: midX, y: start.y },
      { x: midX, y: end.y },
      end,
    ];
  }

  if (startSide === 'bottom' && endSide === 'left') {
    return [start, { x: start.x, y: end.y }, end];
  }

  if (start.y === end.y || Math.abs(start.y - end.y) < 1) {
    return [start, end];
  }

  if (start.x === end.x || Math.abs(start.x - end.x) < 1) {
    return [start, end];
  }

  const midY = start.y + (end.y - start.y) / 2;

  return [start, { x: start.x, y: midY }, { x: end.x, y: midY }, end];
}

function shouldPreferSimpleRoute(
  start: { x: number; y: number },
  end: { x: number; y: number },
  directPoints: { x: number; y: number }[],
  routedPoints: { x: number; y: number }[],
  allNodes: VisualizationNode[],
  ignoredNodeIds: string[]
) {
  // A target sitting above the source used to force the wide around-the-outside
  // detour unconditionally, even when the direct path was short and clean --
  // that produced the "goes around in a loop" look on adjacent decision
  // branches. Only fall back to the detour when the direct path actually
  // crosses a node, or is meaningfully longer than routing around.
  if (
    edgePointsIntersectNodes(directPoints, allNodes, new Set(ignoredNodeIds))
  ) {
    return false;
  }

  if (edgePathLength(routedPoints) < edgePathLength(directPoints) * 1.35) {
    return false;
  }

  return true;
}

function edgePathLength(points: { x: number; y: number }[]) {
  return points.reduce((total, point, index) => {
    const previous = points[index - 1];

    if (!previous) {
      return total;
    }

    return (
      total +
      Math.abs(point.x - previous.x) +
      Math.abs(point.y - previous.y)
    );
  }, 0);
}

function edgePointsIntersectNodes(
  points: { x: number; y: number }[],
  nodes: VisualizationNode[],
  ignoredNodeIds: Set<string>
) {
  return points.some((point, index) => {
    const previous = points[index - 1];

    if (!previous) {
      return false;
    }

    return nodes.some((node) => {
      if (ignoredNodeIds.has(node.id)) {
        return false;
      }

      return segmentIntersectsRect(previous, point, getNodeBounds(node), 10);
    });
  });
}

function segmentIntersectsRect(
  start: { x: number; y: number },
  end: { x: number; y: number },
  rect: RectBounds,
  padding = 0
) {
  const left = rect.x - padding;
  const right = rect.x + rect.width + padding;
  const top = rect.y - padding;
  const bottom = rect.y + rect.height + padding;

  if (Math.abs(start.x - end.x) < 1) {
    const segmentTop = Math.min(start.y, end.y);
    const segmentBottom = Math.max(start.y, end.y);

    return (
      start.x >= left &&
      start.x <= right &&
      segmentBottom >= top &&
      segmentTop <= bottom
    );
  }

  if (Math.abs(start.y - end.y) < 1) {
    const segmentLeft = Math.min(start.x, end.x);
    const segmentRight = Math.max(start.x, end.x);

    return (
      start.y >= top &&
      start.y <= bottom &&
      segmentRight >= left &&
      segmentLeft <= right
    );
  }

  return false;
}

function getAutoEdgeRouteSide(
  start: { x: number; y: number },
  end: { x: number; y: number },
  startSide: AnchorSide,
  endSide: AnchorSide
): VisualizationEdgeRouteSide {
  if (end.y < start.y - 16) {
    if (startSide === 'left' || startSide === 'right') {
      return startSide;
    }

    return endSide === 'left' ? 'left' : 'right';
  }

  return 'auto';
}

function getRoutedEdgePoints(
  from: VisualizationNode,
  to: VisualizationNode,
  start: { x: number; y: number },
  end: { x: number; y: number },
  startSide: AnchorSide,
  endSide: AnchorSide,
  routeSide: Exclude<VisualizationEdgeRouteSide, 'auto'>,
  routeOffset: number,
  allNodes: VisualizationNode[] = [from, to]
) {
  const margin = Math.max(32, routeOffset);
  const fromBounds = getNodeBounds(from);
  const toBounds = getNodeBounds(to);
  const minX = Math.min(fromBounds.x, toBounds.x);
  const minY = Math.min(fromBounds.y, toBounds.y);
  const maxX = Math.max(
    fromBounds.x + fromBounds.width,
    toBounds.x + toBounds.width
  );
  const maxY = Math.max(
    fromBounds.y + fromBounds.height,
    toBounds.y + toBounds.height
  );
  const startOut = getAnchorExtensionPoint(start, startSide, 24);
  const endIn = getAnchorExtensionPoint(end, endSide, 24);
  const ignoredNodeIds = new Set([from.id, to.id]);

  // Clearing the combined bounding box of *both* nodes (the old behaviour)
  // means a target that's adjacent to the source -- e.g. a decision branch
  // landing just above-right of the diamond it left -- gets routed past the
  // target's own far edge before doubling back into it, which reads as the
  // edge looping around for no reason. Try the tightest lane that clears
  // whatever nodes are actually in the way first, and only widen the lane
  // when a tighter one would actually cross something.
  const pickLane = (candidates: number[], buildPoints: (lane: number) => { x: number; y: number }[]) => {
    for (const lane of candidates) {
      const points = buildPoints(lane);

      if (!edgePointsIntersectNodes(points, allNodes, ignoredNodeIds)) {
        return points;
      }
    }

    return buildPoints(candidates[candidates.length - 1]);
  };

  if (routeSide === 'left' || routeSide === 'right') {
    const buildPoints = (routeX: number) =>
      compactEdgePoints([
        start,
        startOut,
        { x: routeX, y: startOut.y },
        { x: routeX, y: endIn.y },
        endIn,
        end,
      ]);
    const candidates =
      routeSide === 'left'
        ? [fromBounds.x - margin, toBounds.x - margin, minX - margin]
        : [
            fromBounds.x + fromBounds.width + margin,
            toBounds.x + toBounds.width + margin,
            maxX + margin,
          ];

    return pickLane(candidates, buildPoints);
  }

  const buildPoints = (routeY: number) =>
    compactEdgePoints([
      start,
      startOut,
      { x: startOut.x, y: routeY },
      { x: endIn.x, y: routeY },
      endIn,
      end,
    ]);
  const candidates =
    routeSide === 'top'
      ? [fromBounds.y - margin, toBounds.y - margin, minY - margin]
      : [
          fromBounds.y + fromBounds.height + margin,
          toBounds.y + toBounds.height + margin,
          maxY + margin,
        ];

  return pickLane(candidates, buildPoints);
}

function getAnchorExtensionPoint(
  point: { x: number; y: number },
  side: AnchorSide,
  distance: number
) {
  switch (side) {
    case 'bottom':
      return { x: point.x, y: point.y + distance };
    case 'left':
      return { x: point.x - distance, y: point.y };
    case 'right':
      return { x: point.x + distance, y: point.y };
    case 'top':
      return { x: point.x, y: point.y - distance };
  }
}

function compactEdgePoints(points: { x: number; y: number }[]) {
  const withoutDuplicates = points.filter((point, index) => {
    const previous = points[index - 1];

    return (
      !previous ||
      Math.abs(previous.x - point.x) >= 1 ||
      Math.abs(previous.y - point.y) >= 1
    );
  });

  return withoutDuplicates.filter((point, index) => {
    const previous = withoutDuplicates[index - 1];
    const next = withoutDuplicates[index + 1];

    if (!previous || !next) {
      return true;
    }

    const sameX =
      Math.abs(previous.x - point.x) < 1 && Math.abs(point.x - next.x) < 1;
    const sameY =
      Math.abs(previous.y - point.y) < 1 && Math.abs(point.y - next.y) < 1;

    return !sameX && !sameY;
  });
}

const EDGE_PORT_SPACING = 16;

/**
 * Multiple edges can independently resolve to the same (node, side) --
 * e.g. a decision node's two branches both landing on 'right' because both
 * targets sit to the right of it. Left alone they'd all start from the
 * exact same pixel and overlap right at the shape. This spreads edges that
 * share a (node, side) evenly along that side instead.
 */
function getEdgePortOffsets(
  edges: VisualizationEdge[],
  nodesById: Map<string, VisualizationNode>,
  anchorMode: EdgeAnchorMode = 'flowchart'
): {
  busySidesByNode: Map<string, Set<AnchorSide>>;
  sourceOffsets: Map<string, number>;
  targetOffsets: Map<string, number>;
} {
  const groups = new Map<string, { edgeId: string; end: 'source' | 'target' }[]>();
  const busySidesByNode = new Map<string, Set<AnchorSide>>();

  function resolveSide(
    node: VisualizationNode,
    target: VisualizationNode,
    role: 'source' | 'target',
    explicitAnchor: AnchorSide | undefined
  ): AnchorSide | null {
    if (explicitAnchor) {
      return explicitAnchor;
    }

    if (anchorMode === 'hierarchical') {
      return role === 'source' ? 'bottom' : 'top';
    }

    if (anchorMode === 'radial') {
      return null;
    }

    return getAnchorSide(node, target, role);
  }

  function markBusy(nodeId: string, side: AnchorSide) {
    const sides = busySidesByNode.get(nodeId) ?? new Set<AnchorSide>();

    sides.add(side);
    busySidesByNode.set(nodeId, sides);
  }

  for (const edge of edges) {
    const from = nodesById.get(edge.from);
    const to = nodesById.get(edge.to);

    if (!from || !to || from.id === to.id) {
      continue;
    }

    const sourceSide = resolveSide(from, to, 'source', edge.fromAnchor);
    const targetSide = resolveSide(to, from, 'target', edge.toAnchor);

    if (sourceSide) {
      const key = `${from.id}:${sourceSide}`;
      const group = groups.get(key) ?? [];

      group.push({ edgeId: edge.id, end: 'source' });
      groups.set(key, group);
      markBusy(from.id, sourceSide);
    }

    if (targetSide) {
      const key = `${to.id}:${targetSide}`;
      const group = groups.get(key) ?? [];

      group.push({ edgeId: edge.id, end: 'target' });
      groups.set(key, group);
      markBusy(to.id, targetSide);
    }
  }

  const sourceOffsets = new Map<string, number>();
  const targetOffsets = new Map<string, number>();

  for (const group of groups.values()) {
    if (group.length < 2) {
      continue;
    }

    group.forEach((entry, index) => {
      const offset = (index - (group.length - 1) / 2) * EDGE_PORT_SPACING;
      const target = entry.end === 'source' ? sourceOffsets : targetOffsets;

      target.set(entry.edgeId, offset);
    });
  }

  return { busySidesByNode, sourceOffsets, targetOffsets };
}

const SELF_LOOP_SIDES: AnchorSide[] = ['right', 'top', 'left', 'bottom'];

/**
 * Self-loops share none of the (node, side) grouping above -- they're
 * skipped by `getEdgePortOffsets` entirely -- so two self-loops on the same
 * node, or a self-loop and a regular edge that both default to the node's
 * 'right' side, used to land on the exact same point and render as one
 * indistinguishable curve. Spread self-loops across sides the node isn't
 * already using for a regular edge, cycling through all four sides (and
 * growing the loop radius) once a node has more self-loops than sides.
 */
function getSelfLoopRouteAssignments(
  edges: VisualizationEdge[],
  busySidesByNode: Map<string, Set<AnchorSide>>
): Map<string, { routeOffset: number; routeSide: VisualizationEdgeRouteSide }> {
  const countByNode = new Map<string, number>();
  const assignments = new Map<
    string,
    { routeOffset: number; routeSide: VisualizationEdgeRouteSide }
  >();

  for (const edge of edges) {
    if (edge.from !== edge.to) {
      continue;
    }

    if (edge.routeSide && edge.routeSide !== 'auto') {
      continue;
    }

    const nodeId = edge.from;
    const index = countByNode.get(nodeId) ?? 0;

    countByNode.set(nodeId, index + 1);

    const busy = busySidesByNode.get(nodeId);
    const availableSides = busy
      ? SELF_LOOP_SIDES.filter((side) => !busy.has(side))
      : SELF_LOOP_SIDES;
    const sidePool = availableSides.length > 0 ? availableSides : SELF_LOOP_SIDES;
    const side = sidePool[index % sidePool.length];
    const wrap = Math.floor(index / sidePool.length);

    assignments.set(edge.id, { routeOffset: 56 + wrap * 24, routeSide: side });
  }

  return assignments;
}

const PARALLEL_EDGE_SPACING = 28;

/**
 * Two edges between the same pair of nodes -- a reciprocal A->B/B->A pair,
 * or an outright duplicate -- resolve to the identical straight line since
 * geometry only ever looks at the two endpoints' positions. Group edges by
 * their *unordered* node pair and hand back a lateral offset for any pair
 * that shares more than one edge, so they bow apart instead of overlapping.
 */
function getParallelEdgeOffsets(edges: VisualizationEdge[]): Map<string, number> {
  const groups = new Map<string, string[]>();

  for (const edge of edges) {
    if (edge.from === edge.to) {
      continue;
    }

    const key = [edge.from, edge.to].sort().join('|');
    const group = groups.get(key) ?? [];

    group.push(edge.id);
    groups.set(key, group);
  }

  const offsets = new Map<string, number>();

  for (const group of groups.values()) {
    if (group.length < 2) {
      continue;
    }

    group.forEach((edgeId, index) => {
      offsets.set(edgeId, (index - (group.length - 1) / 2) * PARALLEL_EDGE_SPACING);
    });
  }

  return offsets;
}

function getParallelEdgeGeometry(
  start: { x: number; y: number },
  end: { x: number; y: number },
  offset: number
): EdgeGeometry {
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const length = Math.max(1, Math.hypot(dx, dy));
  const normalX = -dy / length;
  const normalY = dx / length;
  const mid = {
    x: (start.x + end.x) / 2 + normalX * offset,
    y: (start.y + end.y) / 2 + normalY * offset,
  };
  const control1 = {
    x: start.x + ((mid.x - start.x) * 2) / 3,
    y: start.y + ((mid.y - start.y) * 2) / 3,
  };
  const control2 = {
    x: end.x + ((mid.x - end.x) * 2) / 3,
    y: end.y + ((mid.y - end.y) * 2) / 3,
  };

  return getCurvedEdgeGeometry(start, control1, control2, end);
}

function getEdgeGeometry(
  edge: VisualizationEdge,
  nodesById: Map<string, VisualizationNode>,
  kind?: VisualizationEdge['kind'],
  anchorMode: EdgeAnchorMode = 'flowchart',
  sourcePortOffset = 0,
  targetPortOffset = 0,
  parallelOffset = 0,
  selfLoopAssignment?: { routeOffset: number; routeSide: VisualizationEdgeRouteSide }
): EdgeGeometry | null {
  const from = nodesById.get(edge.from);
  const to = nodesById.get(edge.to);

  if (!from || !to) {
    return null;
  }

  if (from.id === to.id) {
    const routeSide = edge.routeSide ?? selfLoopAssignment?.routeSide;
    const routeOffset = edge.routeOffset ?? selfLoopAssignment?.routeOffset;

    return getSelfLoopEdgeGeometry(from, routeSide, routeOffset);
  }

  const start = getEdgeEndpoint(from, to, 'source', edge.fromAnchor, anchorMode, sourcePortOffset);
  const end = getEdgeEndpoint(to, from, 'target', edge.toAnchor, anchorMode, targetPortOffset);
  const edgeKind = kind ?? edge.kind;

  if (edgeKind === 'straight' && parallelOffset !== 0) {
    return getParallelEdgeGeometry(start, end, parallelOffset);
  }

  const points =
    edgeKind !== 'straight'
      ? getOrthogonalEdgePoints(
          from,
          to,
          start,
          end,
          edge.routeSide,
          edge.routeOffset,
          Array.from(nodesById.values())
        )
      : [start, end];

  return {
    end,
    points,
    start,
  };
}

function getSelfLoopEdgeGeometry(
  node: VisualizationNode,
  routeSide: VisualizationEdgeRouteSide = 'auto',
  routeOffset = 56
): EdgeGeometry {
  const size = getNodeSize(node);
  const rx = size.width / 2;
  const ry = size.height / 2;
  const side = routeSide === 'auto' ? 'right' : routeSide;
  const offset = Math.max(38, routeOffset);

  if (side === 'left') {
    const start = { x: node.position.x - rx, y: node.position.y };
    const end = { x: node.position.x, y: node.position.y - ry };
    const control1 = { x: node.position.x - rx - offset, y: node.position.y };
    const control2 = { x: node.position.x, y: node.position.y - ry - offset };

    return getCurvedEdgeGeometry(start, control1, control2, end);
  }

  if (side === 'bottom') {
    const start = { x: node.position.x, y: node.position.y + ry };
    const end = { x: node.position.x + rx, y: node.position.y };
    const control1 = { x: node.position.x, y: node.position.y + ry + offset };
    const control2 = { x: node.position.x + rx + offset, y: node.position.y };

    return getCurvedEdgeGeometry(start, control1, control2, end);
  }

  if (side === 'top') {
    const start = { x: node.position.x, y: node.position.y - ry };
    const end = { x: node.position.x + rx, y: node.position.y };
    const control1 = { x: node.position.x, y: node.position.y - ry - offset };
    const control2 = { x: node.position.x + rx + offset, y: node.position.y };

    return getCurvedEdgeGeometry(start, control1, control2, end);
  }

  const start = { x: node.position.x + rx, y: node.position.y };
  const end = { x: node.position.x, y: node.position.y - ry };
  const control1 = { x: node.position.x + rx + offset, y: node.position.y };
  const control2 = { x: node.position.x, y: node.position.y - ry - offset };

  return getCurvedEdgeGeometry(start, control1, control2, end);
}

function getCurvedEdgeGeometry(
  start: { x: number; y: number },
  control1: { x: number; y: number },
  control2: { x: number; y: number },
  end: { x: number; y: number }
): EdgeGeometry {
  return {
    end,
    pathD: `M${start.x},${start.y} C${control1.x},${control1.y} ${control2.x},${control2.y} ${end.x},${end.y}`,
    points: sampleCubicBezier(start, control1, control2, end, 18),
    start,
  };
}

function sampleCubicBezier(
  start: { x: number; y: number },
  control1: { x: number; y: number },
  control2: { x: number; y: number },
  end: { x: number; y: number },
  steps: number
) {
  return Array.from({ length: steps + 1 }, (_, index) => {
    const t = index / steps;
    const oneMinusT = 1 - t;

    return {
      x:
        oneMinusT ** 3 * start.x +
        3 * oneMinusT ** 2 * t * control1.x +
        3 * oneMinusT * t ** 2 * control2.x +
        t ** 3 * end.x,
      y:
        oneMinusT ** 3 * start.y +
        3 * oneMinusT ** 2 * t * control1.y +
        3 * oneMinusT * t ** 2 * control2.y +
        t ** 3 * end.y,
    };
  });
}

function getEdgeEndpoint(
  node: VisualizationNode,
  target: VisualizationNode,
  role: 'source' | 'target',
  explicitAnchor: AnchorSide | undefined,
  anchorMode: EdgeAnchorMode,
  portOffset = 0
) {
  if (explicitAnchor) {
    return getAnchorPoint(node, explicitAnchor, portOffset);
  }

  if (anchorMode === 'hierarchical') {
    return getAnchorPoint(node, role === 'source' ? 'bottom' : 'top', portOffset);
  }

  if (anchorMode === 'radial') {
    return getConnectionPoint(node, target, 'auto');
  }

  return getAnchorPoint(node, getAnchorSide(node, target, role), portOffset);
}

function toPolylinePoints(points: { x: number; y: number }[]) {
  return points.map((point) => `${point.x},${point.y}`).join(' ');
}

function clamp(value: number, min: number, max: number) {
  return Math.min(max, Math.max(min, value));
}

function roundToStep(value: number, step = 1) {
  return Math.round(value / step) * step;
}

function formatVisualCoordinate(value: number) {
  const rounded = Math.round(value * 10) / 10;

  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

function getConvexHullNodes(nodes: VisualizationNode[]) {
  const uniqueNodes = Array.from(
    new Map(
      nodes.map((node) => [
        `${node.position.x}:${node.position.y}`,
        node,
      ])
    ).values()
  );

  if (uniqueNodes.length < 3) {
    return [];
  }

  const sorted = [...uniqueNodes].sort(
    (first, second) =>
      first.position.x - second.position.x || first.position.y - second.position.y
  );
  const cross = (
    origin: VisualizationNode,
    first: VisualizationNode,
    second: VisualizationNode
  ) =>
    (first.position.x - origin.position.x) *
      (second.position.y - origin.position.y) -
    (first.position.y - origin.position.y) *
      (second.position.x - origin.position.x);
  const lower: VisualizationNode[] = [];

  sorted.forEach((node) => {
    while (
      lower.length >= 2 &&
      cross(lower[lower.length - 2], lower[lower.length - 1], node) <= 0
    ) {
      lower.pop();
    }

    lower.push(node);
  });

  const upper: VisualizationNode[] = [];

  [...sorted].reverse().forEach((node) => {
    while (
      upper.length >= 2 &&
      cross(upper[upper.length - 2], upper[upper.length - 1], node) <= 0
    ) {
      upper.pop();
    }

    upper.push(node);
  });

  return [...lower.slice(0, -1), ...upper.slice(0, -1)];
}

function getEdgeLabelPlacement(
  points: { x: number; y: number }[],
  label: string,
  avoidRects: RectBounds[] = []
) {
  const segments = points
    .slice(1)
    .map((point, index) => {
      const previous = points[index];
      const dx = point.x - previous.x;
      const dy = point.y - previous.y;

      return {
        dx,
        dy,
        end: point,
        length: Math.hypot(dx, dy),
        start: previous,
      };
    })
    .filter((segment) => segment.length > 0);
  const sortedSegments =
    segments.sort((first, second) => second.length - first.length) ??
    [];
  const fallbackSegment =
    sortedSegments[0] ??
    {
      dx: 1,
      dy: 0,
      end: points[points.length - 1] ?? { x: 0, y: 0 },
      length: 1,
      start: points[0] ?? { x: 0, y: 0 },
    };
  const candidateSegments = sortedSegments.length > 0 ? sortedSegments : [fallbackSegment];
  const width = Math.max(26, label.length * 7 + 14);
  const height = 20;
  const makeCandidate = (
    segment: typeof fallbackSegment,
    ratio: number,
    normal: { x: number; y: number },
    offset: number
  ) => {
    const base = {
      x: segment.start.x + segment.dx * ratio,
      y: segment.start.y + segment.dy * ratio,
    };
    const x = base.x + normal.x * offset;
    const y = base.y + normal.y * offset;
    const plate = {
      height,
      width,
      x: x - width / 2,
      y: y - height / 2,
    };
    const overlapPenalty = avoidRects.reduce(
      (total, rect) => total + getOverlapArea(plate, rect, 8),
      0
    );

    return {
      overlapPenalty,
      plate,
      score: overlapPenalty * 1000 + Math.abs(ratio - 0.5) * 20 + offset,
      text: {
        x,
        y: y + 4,
      },
    };
  };
  const candidates = candidateSegments.flatMap((segment) => {
    const ratios = segment.length > 92 ? [0.5, 0.35, 0.65] : [0.5];
    const offsets = [22, 32, 44];
    let normals: { x: number; y: number }[];

    if (Math.abs(segment.dx) < 1) {
      normals = [
        { x: 1, y: 0 },
        { x: -1, y: 0 },
      ];
    } else if (Math.abs(segment.dy) < 1) {
      normals = [
        { x: 0, y: -1 },
        { x: 0, y: 1 },
      ];
    } else {
      const normal = {
        x: -segment.dy / segment.length,
        y: segment.dx / segment.length,
      };
      normals = normal.y <= 0 ? [normal, { x: -normal.x, y: -normal.y }] : [
        { x: -normal.x, y: -normal.y },
        normal,
      ];
    }

    return ratios.flatMap((ratio) =>
      normals.flatMap((normal) =>
        offsets.map((offset) => makeCandidate(segment, ratio, normal, offset))
      )
    );
  });
  const placement =
    candidates
      .slice()
      .sort((first, second) => first.score - second.score)[0] ??
    makeCandidate(fallbackSegment, 0.5, { x: 0, y: -1 }, 22);

  return {
    plate: placement.plate,
    text: placement.text,
  };
}

function getEdgeCrossings(
  geometries: { edge: VisualizationEdge; geometry: EdgeGeometry }[]
) {
  const crossings = new Map<string, { x: number; y: number }[]>();

  for (let i = 0; i < geometries.length; i += 1) {
    for (let j = i + 1; j < geometries.length; j += 1) {
      const first = geometries[i];
      const second = geometries[j];

      if (
        first.edge.from === second.edge.from ||
        first.edge.from === second.edge.to ||
        first.edge.to === second.edge.from ||
        first.edge.to === second.edge.to
      ) {
        continue;
      }

      const intersections = getPolylineIntersections(
        first.geometry.points,
        second.geometry.points
      );

      if (intersections.length === 0) {
        continue;
      }

      crossings.set(first.edge.id, [
        ...(crossings.get(first.edge.id) ?? []),
        ...intersections,
      ]);
      crossings.set(second.edge.id, [
        ...(crossings.get(second.edge.id) ?? []),
        ...intersections,
      ]);
    }
  }

  return crossings;
}

function getPolylineIntersections(
  first: { x: number; y: number }[],
  second: { x: number; y: number }[]
) {
  const intersections: { x: number; y: number }[] = [];

  for (let i = 0; i < first.length - 1; i += 1) {
    for (let j = 0; j < second.length - 1; j += 1) {
      const point = getSegmentIntersection(
        first[i],
        first[i + 1],
        second[j],
        second[j + 1]
      );

      if (point) {
        intersections.push(point);
      }
    }
  }

  return intersections;
}

function getSegmentIntersection(
  a: { x: number; y: number },
  b: { x: number; y: number },
  c: { x: number; y: number },
  d: { x: number; y: number }
) {
  const firstVertical = Math.abs(a.x - b.x) < 1;
  const firstHorizontal = Math.abs(a.y - b.y) < 1;
  const secondVertical = Math.abs(c.x - d.x) < 1;
  const secondHorizontal = Math.abs(c.y - d.y) < 1;

  if (firstVertical && secondHorizontal) {
    return pointAtOrthogonalIntersection(a.x, c.y, a, b, c, d);
  }

  if (firstHorizontal && secondVertical) {
    return pointAtOrthogonalIntersection(c.x, a.y, a, b, c, d);
  }

  return null;
}

function pointAtOrthogonalIntersection(
  x: number,
  y: number,
  a: { x: number; y: number },
  b: { x: number; y: number },
  c: { x: number; y: number },
  d: { x: number; y: number }
) {
  const inFirst =
    x > Math.min(a.x, b.x) + 2 &&
    x < Math.max(a.x, b.x) - 2 &&
    y > Math.min(a.y, b.y) + 2 &&
    y < Math.max(a.y, b.y) - 2;
  const inSecond =
    x > Math.min(c.x, d.x) + 2 &&
    x < Math.max(c.x, d.x) - 2 &&
    y > Math.min(c.y, d.y) + 2 &&
    y < Math.max(c.y, d.y) - 2;

  return inFirst && inSecond ? { x, y } : null;
}

function EdgeLine({
  anchorMode = 'flowchart',
  crossings = [],
  directed = true,
  edge,
  editable = false,
  geometry,
  nodesById,
  onDoubleClick,
  onLabelChange,
  onPointerDown,
  selected = false,
}: {
  anchorMode?: EdgeAnchorMode;
  crossings?: { x: number; y: number }[];
  directed?: boolean;
  edge: VisualizationEdge;
  editable?: boolean;
  geometry?: EdgeGeometry | null;
  nodesById: Map<string, VisualizationNode>;
  onDoubleClick?: (
    edge: VisualizationEdge,
    event: React.MouseEvent<SVGGeometryElement>
  ) => void;
  onLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onPointerDown?: (
    edge: VisualizationEdge,
    event: React.PointerEvent<SVGGeometryElement>
  ) => void;
  selected?: boolean;
}) {
  const from = nodesById.get(edge.from);
  const to = nodesById.get(edge.to);

  if (!from || !to) {
    return null;
  }

  const edgeGeometry = geometry ?? getEdgeGeometry(edge, nodesById, undefined, anchorMode);

  if (!edgeGeometry) {
    return null;
  }

  const { end, start } = edgeGeometry;
  const stroke = selected || edge.state === 'active' ? ACTIVE_STROKE : '#64748b';
  const pathD = edgeGeometry.pathD;
  const points = toPolylinePoints(edgeGeometry.points);
  const showStartArrow =
    directed && (edge.direction === 'backward' || edge.direction === 'both');
  const showEndArrow =
    directed && (edge.direction === 'forward' || edge.direction === 'both');
  const strokeDasharray = edge.style?.strokeDasharray;
  const avoidRects = Array.from(nodesById.values()).map(getNodeBounds);
  const labelPlacement = edge.label
    ? getEdgeLabelPlacement(edgeGeometry.points, edge.label, avoidRects)
    : null;

  return (
    <g>
      {selected && pathD ? (
        <path
          d={pathD}
          fill="none"
          pointerEvents="none"
          stroke="#93c5fd"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={9}
          opacity={0.5}
        />
      ) : null}
      {selected && !pathD ? (
        <polyline
          fill="none"
          points={points}
          pointerEvents="none"
          stroke="#93c5fd"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={9}
          opacity={0.5}
        />
      ) : null}
      {editable && pathD ? (
        <path
          aria-label={`Flow line from ${from.label} to ${to.label}`}
          d={pathD}
          fill="none"
          pointerEvents="stroke"
          stroke="transparent"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={14}
          onDoubleClick={(event) => onDoubleClick?.(edge, event)}
          onPointerDown={(event) => onPointerDown?.(edge, event)}
        />
      ) : null}
      {editable && !pathD ? (
        <polyline
          aria-label={`Flow line from ${from.label} to ${to.label}`}
          fill="none"
          points={points}
          pointerEvents="stroke"
          stroke="transparent"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={14}
          onDoubleClick={(event) => onDoubleClick?.(edge, event)}
          onPointerDown={(event) => onPointerDown?.(edge, event)}
        />
      ) : null}
      {pathD ? (
        <path
          data-visual-edge-id={edge.id}
          d={pathD}
          fill="none"
          markerEnd={showEndArrow ? 'url(#arrow-small)' : undefined}
          markerStart={showStartArrow ? 'url(#arrow-small-start)' : undefined}
          pointerEvents="none"
          stroke={stroke}
          strokeDasharray={strokeDasharray}
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={selected ? 3 : 2}
        />
      ) : (
        <polyline
          data-visual-edge-id={edge.id}
          fill="none"
          markerEnd={showEndArrow ? 'url(#arrow-small)' : undefined}
          markerStart={showStartArrow ? 'url(#arrow-small-start)' : undefined}
          points={points}
          pointerEvents="none"
          stroke={stroke}
          strokeDasharray={strokeDasharray}
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={selected ? 3 : 2}
        />
      )}
      {crossings.map((point, index) => (
        <circle
          cx={point.x}
          cy={point.y}
          fill="#ffffff"
          key={`${point.x}-${point.y}-${index}`}
          pointerEvents="none"
          r={4.5}
          stroke={stroke}
          strokeWidth={1.4}
        />
      ))}
      {edge.label && labelPlacement && (
        <g pointerEvents={editable && onLabelChange ? 'auto' : 'none'}>
          <rect
            data-visual-label-plate
            fill="#ffffff"
            height={labelPlacement.plate.height}
            rx={6}
            stroke="#dbe5f2"
            strokeWidth={1}
            width={labelPlacement.plate.width}
            x={labelPlacement.plate.x}
            y={labelPlacement.plate.y}
          />
          <EditableSvgText
            dataVisualEdgeLabel
            dataVisualTextTarget="edge-label"
            x={labelPlacement.text.x}
            y={labelPlacement.text.y}
            value={edge.label}
            readOnly={!editable || !onLabelChange}
            className="fill-slate-700 text-[12px] font-semibold"
            onCommit={(label) => onLabelChange?.(edge, label)}
          />
        </g>
      )}
    </g>
  );
}

function isDivideConquerMergeEdge(edge: VisualizationEdge) {
  const label = edge.label?.trim().toLowerCase() ?? '';

  return (
    edge.direction === 'none' ||
    label === 'merge' ||
    label === '병합' ||
    label.includes('merge') ||
    label.includes('병합')
  );
}

function getDivideConquerMergeGeometry(
  edge: VisualizationEdge,
  nodesById: Map<string, VisualizationNode>
) {
  const from = nodesById.get(edge.from);
  const to = nodesById.get(edge.to);

  if (!from || !to) {
    return null;
  }

  const leftNode = from.position.x <= to.position.x ? from : to;
  const rightNode = from.position.x <= to.position.x ? to : from;
  const leftBounds = getNodeBounds(leftNode);
  const rightBounds = getNodeBounds(rightNode);
  const leftStart = {
    x: leftNode.position.x,
    y: leftBounds.y + leftBounds.height,
  };
  const rightStart = {
    x: rightNode.position.x,
    y: rightBounds.y + rightBounds.height,
  };
  const laneY = Math.max(leftStart.y, rightStart.y) + 50;
  const centerX = (leftNode.position.x + rightNode.position.x) / 2;
  const label = edge.label?.trim() || 'merge';
  const labelWidth = Math.max(76, Math.min(150, label.length * 10 + 34));
  const labelHeight = 30;
  const labelBox = {
    height: labelHeight,
    width: labelWidth,
    x: centerX - labelWidth / 2,
    y: laneY - labelHeight / 2,
  };
  const leftEnd = {
    x: labelBox.x,
    y: laneY,
  };
  const rightEnd = {
    x: labelBox.x + labelBox.width,
    y: laneY,
  };

  return {
    centerX,
    label,
    labelBox,
    leftPath: `M ${leftStart.x} ${leftStart.y} L ${leftStart.x} ${laneY} L ${leftEnd.x} ${leftEnd.y}`,
    rightPath: `M ${rightStart.x} ${rightStart.y} L ${rightStart.x} ${laneY} L ${rightEnd.x} ${rightEnd.y}`,
  };
}

function DivideConquerMergeEdgeLine({
  edge,
  editable = false,
  nodesById,
  onDoubleClick,
  onLabelChange,
  onPointerDown,
  selected = false,
}: {
  edge: VisualizationEdge;
  editable?: boolean;
  nodesById: Map<string, VisualizationNode>;
  onDoubleClick?: (
    edge: VisualizationEdge,
    event: React.MouseEvent<SVGGeometryElement>
  ) => void;
  onLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onPointerDown?: (
    edge: VisualizationEdge,
    event: React.PointerEvent<SVGGeometryElement>
  ) => void;
  selected?: boolean;
}) {
  const geometry = getDivideConquerMergeGeometry(edge, nodesById);

  if (!geometry) {
    return null;
  }

  const stroke = selected || edge.state === 'active' ? ACTIVE_STROKE : '#0f766e';
  const hitStrokeWidth = 16;
  const strokeWidth = selected ? 3 : 2;

  return (
    <g data-visual-divide-conquer-merge-edge={edge.id}>
      {selected ? (
        <>
          <path
            d={geometry.leftPath}
            fill="none"
            opacity={0.45}
            pointerEvents="none"
            stroke="#93c5fd"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={9}
          />
          <path
            d={geometry.rightPath}
            fill="none"
            opacity={0.45}
            pointerEvents="none"
            stroke="#93c5fd"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={9}
          />
        </>
      ) : null}
      {editable ? (
        <>
          <path
            d={geometry.leftPath}
            fill="none"
            pointerEvents="stroke"
            stroke="transparent"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={hitStrokeWidth}
            onDoubleClick={(event) => onDoubleClick?.(edge, event)}
            onPointerDown={(event) => onPointerDown?.(edge, event)}
          />
          <path
            d={geometry.rightPath}
            fill="none"
            pointerEvents="stroke"
            stroke="transparent"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={hitStrokeWidth}
            onDoubleClick={(event) => onDoubleClick?.(edge, event)}
            onPointerDown={(event) => onPointerDown?.(edge, event)}
          />
        </>
      ) : null}
      <path
        data-visual-edge-id={edge.id}
        d={geometry.leftPath}
        fill="none"
        pointerEvents="none"
        stroke={stroke}
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth={strokeWidth}
      />
      <path
        data-visual-edge-id={edge.id}
        d={geometry.rightPath}
        fill="none"
        pointerEvents="none"
        stroke={stroke}
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth={strokeWidth}
      />
      <g pointerEvents={editable && onLabelChange ? 'auto' : 'none'}>
        <rect
          data-visual-label-plate
          fill="#ecfdf5"
          height={geometry.labelBox.height}
          rx={8}
          stroke="#0f766e"
          strokeWidth={1.5}
          width={geometry.labelBox.width}
          x={geometry.labelBox.x}
          y={geometry.labelBox.y}
        />
        <EditableSvgText
          dataVisualEdgeLabel
          dataVisualTextTarget="edge-label"
          x={geometry.centerX}
          y={geometry.labelBox.y + 19}
          value={geometry.label}
          readOnly={!editable || !onLabelChange}
          className="fill-slate-700 text-[12px] font-bold"
          onCommit={(label) => onLabelChange?.(edge, label)}
        />
      </g>
    </g>
  );
}

function Canvas({
  bounds,
  children,
  onContextMenu,
  onDoubleClick,
  onKeyDown,
  onPointerDown,
  onPointerLeave,
  onPointerMove,
  onPointerUp,
  svgRef,
}: {
  bounds: Bounds;
  children: React.ReactNode;
  onContextMenu?: React.MouseEventHandler<SVGSVGElement>;
  onDoubleClick?: React.MouseEventHandler<SVGSVGElement>;
  onKeyDown?: React.KeyboardEventHandler<SVGSVGElement>;
  onPointerDown?: React.PointerEventHandler<SVGSVGElement>;
  onPointerLeave?: React.PointerEventHandler<SVGSVGElement>;
  onPointerMove?: React.PointerEventHandler<SVGSVGElement>;
  onPointerUp?: React.PointerEventHandler<SVGSVGElement>;
  svgRef?: React.Ref<SVGSVGElement>;
}) {
  const aspectRatio =
    bounds.width > 0 && bounds.height > 0
      ? `${bounds.width} / ${bounds.height}`
      : undefined;

  return (
    <svg
      aria-label="Visualization rendering"
      className="block h-auto min-h-[260px] w-full max-w-full touch-none select-none rounded-lg border bg-[linear-gradient(#d9e5f3_1px,transparent_1px),linear-gradient(90deg,#d9e5f3_1px,transparent_1px)] bg-[size:24px_24px]"
      data-visualization-canvas
      onContextMenu={onContextMenu}
      onDoubleClick={onDoubleClick}
      onKeyDown={onKeyDown}
      onPointerDown={onPointerDown}
      onPointerLeave={onPointerLeave}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      ref={svgRef}
      role="img"
      style={aspectRatio ? { aspectRatio } : undefined}
      tabIndex={0}
      viewBox={`${bounds.minX} ${bounds.minY} ${bounds.width} ${bounds.height}`}
    >
      <defs>
        <marker
          id="arrow-small"
          markerHeight="6"
          markerWidth="7"
          orient="auto"
          refX="6.2"
          refY="3"
        >
          <path d="M0,0 L6,3 L0,6 Z" fill="#64748b" />
        </marker>
        <marker
          id="arrow-small-start"
          markerHeight="6"
          markerWidth="7"
          orient="auto-start-reverse"
          refX="0.8"
          refY="3"
        >
          <path d="M0,0 L6,3 L0,6 Z" fill="#64748b" />
        </marker>
        <marker
          id="arrow-merge"
          markerHeight="6"
          markerWidth="7"
          orient="auto"
          refX="6.2"
          refY="3"
        >
          <path d="M0,0 L6,3 L0,6 Z" fill="#0f766e" />
        </marker>
      </defs>
      {children}
    </svg>
  );
}

function getSvgPoint(svg: SVGSVGElement | null, clientX: number, clientY: number) {
  if (!svg) {
    return null;
  }

  const matrix = svg.getScreenCTM();

  if (!matrix) {
    return null;
  }

  const point = svg.createSVGPoint();
  point.x = clientX;
  point.y = clientY;

  return point.matrixTransform(matrix.inverse());
}

/**
 * A `position: fixed` element normally resolves against the viewport -- but
 * a CSS `transform` (or `perspective`/`filter`/`will-change: transform`) on
 * ANY ancestor makes that ancestor the containing block instead (this is the
 * editor dialog's own `translate(-50%,-50%)` centering). Clamping a fixed
 * menu's position against `window.innerWidth/innerHeight` in that case
 * clamps against the wrong box entirely, so it can still render off-screen.
 * Walk up to find the real containing block, if any, so callers can convert
 * a viewport-relative click point into a coordinate space that actually
 * matches how the menu will be positioned.
 *
 * This dialog centers itself with Tailwind's translate-x-[-50%]/translate-y
 * utilities, which this build compiles to the standalone CSS `translate`
 * property (Chrome 104+/Edge), not the combined `transform` shorthand --
 * `getComputedStyle(el).transform` reports 'none' even though `translate` is
 * '-50% -50%', so checking `transform` alone missed this exact case and the
 * containing-block detection silently fell through to the viewport
 * fallback, right in the one place it actually mattered. The standalone
 * `scale`/`rotate` properties establish a containing block the same way and
 * are checked here too for the same reason.
 */
function getFixedPositioningContainerRect(node: Element | null) {
  let current: Element | null = node?.parentElement ?? null;

  while (current && current !== document.body) {
    const style = window.getComputedStyle(current);

    if (
      (style.transform && style.transform !== 'none') ||
      (style.translate && style.translate !== 'none') ||
      (style.scale && style.scale !== 'none') ||
      (style.rotate && style.rotate !== 'none') ||
      (style.perspective && style.perspective !== 'none') ||
      (style.filter && style.filter !== 'none') ||
      style.willChange === 'transform'
    ) {
      return current.getBoundingClientRect();
    }

    current = current.parentElement;
  }

  return { height: window.innerHeight, left: 0, top: 0, width: window.innerWidth };
}

function normalizeVisualTextLineBreaks(
  value: number | string | boolean | null | undefined
) {
  return String(value ?? '')
    .replace(/\r\n?/g, '\n')
    .replace(/\\+n/g, '\n');
}

function EditableSvgText({
  className,
  dataVisualEdgeLabel,
  dataVisualTextTarget,
  height = 28,
  onCommit,
  readOnly,
  textAnchor = 'middle',
  value,
  width = 96,
  x,
  y,
}: {
  className?: string;
  dataVisualEdgeLabel?: boolean;
  dataVisualTextTarget?: string;
  height?: number;
  onCommit?: (value: string) => void;
  readOnly?: boolean;
  textAnchor?: 'end' | 'middle' | 'start';
  value: number | string | boolean | null | undefined;
  width?: number;
  x: number;
  y: number;
}) {
  const [editing, setEditing] = React.useState(false);
  const normalizedValue = normalizeVisualTextLineBreaks(value);
  const [draft, setDraft] = React.useState(normalizedValue);
  const inputRef = React.useRef<HTMLTextAreaElement>(null);

  React.useEffect(() => {
    setDraft(normalizedValue);
  }, [normalizedValue]);

  React.useEffect(() => {
    if (!editing) {
      return;
    }

    window.requestAnimationFrame(() => {
      inputRef.current?.focus();
      inputRef.current?.select();
    });
  }, [editing]);

  const commit = React.useCallback(() => {
    setEditing(false);

    if (draft.trim() !== normalizedValue) {
      onCommit?.(draft.trim());
    }
  }, [draft, normalizedValue, onCommit]);

  const cancel = React.useCallback(() => {
    setDraft(normalizedValue);
    setEditing(false);
  }, [normalizedValue]);

  const inputX =
    textAnchor === 'middle' ? x - width / 2 : textAnchor === 'end' ? x - width : x;

  if (!readOnly && editing) {
    return (
      <foreignObject
        data-visual-inline-editor
        data-visual-editable-text
        data-visual-edge-label={dataVisualEdgeLabel ? 'true' : undefined}
        data-visual-text-target={dataVisualTextTarget}
        height={height}
        width={width}
        x={inputX}
        y={y - height + 8}
        onDoubleClick={(event) => event.stopPropagation()}
        onPointerDown={(event) => event.stopPropagation()}
      >
        <textarea
          ref={inputRef}
          value={draft}
          className="h-full w-full resize-none rounded-md border border-blue-500 bg-white px-2 py-1 text-center text-[12px] font-semibold text-slate-950 shadow-sm outline-none"
          onBlur={commit}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
              event.preventDefault();
              commit();
            }

            if (event.key === 'Escape') {
              event.preventDefault();
              cancel();
            }
          }}
        />
      </foreignObject>
    );
  }

  const lines = normalizedValue.split('\n');

  return (
    <text
      data-visual-editable-text
      data-visual-edge-label={dataVisualEdgeLabel ? 'true' : undefined}
      data-visual-text-target={dataVisualTextTarget}
      x={x}
      y={y - (lines.length - 1) * 7}
      textAnchor={textAnchor}
      className={className}
      style={{
        cursor: !readOnly && onCommit ? 'text' : undefined,
        userSelect: 'none',
      }}
      onDoubleClick={(event) => {
        if (readOnly || !onCommit) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        setEditing(true);
      }}
      onPointerDown={(event) => {
        if (readOnly || !onCommit) {
          return;
        }

        event.stopPropagation();
      }}
    >
      {lines.map((line, index) => (
        <tspan dy={index === 0 ? 0 : 15} key={`${line}-${index}`} x={x}>
          {line}
        </tspan>
      ))}
    </text>
  );
}

function isPointInsideNode(node: VisualizationNode, point: { x: number; y: number }) {
  const bounds = getNodeBounds(node);

  return (
    point.x >= bounds.x &&
    point.x <= bounds.x + bounds.width &&
    point.y >= bounds.y &&
    point.y <= bounds.y + bounds.height
  );
}

function findNodeAtPoint(
  nodes: VisualizationNode[],
  point: { x: number; y: number },
  excludeNodeId: string,
  allowExcludedNode = false
) {
  return [...nodes]
    .reverse()
    .find(
      (node) =>
        (allowExcludedNode || node.id !== excludeNodeId) &&
        isPointInsideNode(node, point)
    );
}

function ConnectionPreview({
  from,
  point,
}: {
  from: VisualizationNode;
  point: { x: number; y: number };
}) {
  const start = getConnectionPoint(
    from,
    {
      ...from,
      position: point,
    },
    'source'
  );

  return (
    <g pointerEvents="none">
      <line
        markerEnd="url(#arrow-small)"
        stroke="#2563eb"
        strokeDasharray="5 5"
        strokeLinecap="round"
        strokeWidth={2}
        x1={start.x}
        x2={point.x}
        y1={start.y}
        y2={point.y}
      />
    </g>
  );
}

function NodeContextMenu({
  config,
  kind,
  onCreateNode,
  onDeleteNode,
  onNodeShapeChange,
  onSelectedAction,
  x,
  y,
}: {
  config: NodeContextMenuConfig;
  kind: 'canvas' | 'node';
  onCreateNode: (shape: VisualizationShapeKind) => void;
  onDeleteNode: () => void;
  onNodeShapeChange: (shape: VisualizationShapeKind) => void;
  onSelectedAction: (actionId: string) => void;
  x: number;
  y: number;
}) {
  const selectionActions = config.selectedActions.filter(
    (action) => action.id !== 'delete' && action.id !== 'change-shape'
  );

  return (
    <div
      className="fixed z-[2147483000] min-w-40 rounded-lg border bg-background p-1.5 text-sm shadow-lg"
      data-visualization-context-menu
      style={{ left: x, top: y }}
      onContextMenu={(event) => event.preventDefault()}
      onMouseDown={(event) => event.stopPropagation()}
    >
      <div className="px-2 py-1 text-[11px] font-semibold text-muted-foreground">
        {kind === 'canvas' ? config.canvasTitle : config.nodeTitle}
      </div>
      {kind === 'canvas' &&
        config.createActions.map((item) => (
          <button
            className="block w-full rounded-md px-2 py-1.5 text-left hover:bg-muted"
            data-visual-node-create-action={item.shape}
            key={item.shape}
            type="button"
            onClick={() => onCreateNode(item.shape)}
          >
            {item.label}
          </button>
        ))}
      {kind === 'node' &&
        config.allowShapeChange &&
        config.createActions.map((item) => (
          <button
            className="block w-full rounded-md px-2 py-1.5 text-left hover:bg-muted"
            data-visual-node-shape-action={item.shape}
            key={item.shape}
            type="button"
            onClick={() => onNodeShapeChange(item.shape)}
          >
            {item.label}
          </button>
        ))}
      {kind === 'node' && (
        <>
          {(config.allowShapeChange || selectionActions.length > 0) && (
            <div className="my-1 border-t" />
          )}
          {selectionActions.map((action) => (
            <button
              className="block w-full rounded-md px-2 py-1.5 text-left hover:bg-muted"
              data-visual-node-selected-action={action.id}
              key={action.id}
              type="button"
              onClick={() => onSelectedAction(action.id)}
            >
              {action.label}
            </button>
          ))}
          {selectionActions.length > 0 && <div className="my-1 border-t" />}
          <button
            className="block w-full rounded-md px-2 py-1.5 text-left text-red-600 hover:bg-red-50"
            data-visual-node-delete-action
            type="button"
            onClick={onDeleteNode}
          >
            {config.deleteLabel}
          </button>
        </>
      )}
    </div>
  );
}

function EdgeAnchorMenu({
  edge,
  fromAnchor,
  onAnchorChange,
  onDirectionChange,
  onLineStyleChange,
  onRouteChange,
  routeOffset,
  routeSide,
  toAnchor,
  x,
  y,
}: {
  edge: VisualizationEdge;
  fromAnchor: AnchorSide;
  onAnchorChange?: (
    edge: VisualizationEdge,
    fromAnchor: AnchorSide,
    toAnchor: AnchorSide
  ) => void;
  onDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onLineStyleChange?: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onRouteChange?: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  routeOffset: number;
  routeSide: VisualizationEdgeRouteSide;
  toAnchor: AnchorSide;
  x: number;
  y: number;
}) {
  const currentLineStyle = edge.style?.strokeDasharray ? 'dashed' : 'solid';

  return (
    <div
      className="fixed z-[2147483000] max-h-[calc(100vh-16px)] w-64 overflow-y-auto rounded-lg border bg-background p-2 text-xs shadow-lg"
      style={{ left: x, top: y }}
      onContextMenu={(event) => event.preventDefault()}
    >
      {onDirectionChange && (
        <>
          <div className="font-semibold text-foreground">연결 설정</div>
          <div className="mt-2 text-[11px] font-semibold text-muted-foreground">
            방향
          </div>
          <div className="mt-1 grid grid-cols-2 gap-1">
            {EDGE_DIRECTION_OPTIONS.map((option) => (
              <button
                className={`rounded-md border px-2 py-1 text-left hover:bg-muted ${
                  (edge.direction ?? 'forward') === option.value
                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                    : ''
                }`}
                data-visual-tree-edge-direction={option.value}
                key={option.value}
                type="button"
                onClick={() => onDirectionChange(edge, option.value)}
              >
                {option.label}
              </button>
            ))}
          </div>
        </>
      )}
      {onLineStyleChange && (
        <>
          <div className="mt-2 text-[11px] font-semibold text-muted-foreground">
            선 스타일
          </div>
          <div className="mt-1 grid grid-cols-2 gap-1">
            {EDGE_LINE_STYLE_OPTIONS.map((option) => (
              <button
                className={`rounded-md border px-2 py-1 text-left hover:bg-muted ${
                  currentLineStyle === option.value
                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                    : ''
                }`}
                data-visual-tree-edge-style={option.value}
                key={option.value}
                type="button"
                onClick={() => onLineStyleChange(edge, option.value)}
              >
                {option.label}
              </button>
            ))}
          </div>
        </>
      )}
      {onAnchorChange && (
        <>
      <div className="font-semibold text-foreground">흐름선 접점</div>
      <div className="mt-2 text-[11px] font-semibold text-muted-foreground">
        나가는 위치
      </div>
      <div className="mt-1 grid grid-cols-2 gap-1">
        {FLOWCHART_SOURCE_ANCHORS.map((anchor) => (
          <button
            className={`rounded-md border px-2 py-1 text-left hover:bg-muted ${
              fromAnchor === anchor ? 'border-blue-500 bg-blue-50 text-blue-700' : ''
            }`}
            key={anchor}
            type="button"
            onClick={() => onAnchorChange?.(edge, anchor, toAnchor)}
          >
            {ANCHOR_LABELS[anchor]}
          </button>
        ))}
      </div>
      <div className="mt-2 text-[11px] font-semibold text-muted-foreground">
        들어오는 위치
      </div>
      <div className="mt-1 grid grid-cols-2 gap-1">
        {FLOWCHART_TARGET_ANCHORS.map((anchor) => (
          <button
            className={`rounded-md border px-2 py-1 text-left hover:bg-muted ${
              toAnchor === anchor ? 'border-blue-500 bg-blue-50 text-blue-700' : ''
            }`}
            key={anchor}
            type="button"
            onClick={() => onAnchorChange?.(edge, fromAnchor, anchor)}
          >
            {ANCHOR_LABELS[anchor]}
          </button>
        ))}
      </div>
        </>
      )}
      {onRouteChange && (
        <>
          <div className="mt-2 border-t pt-2 text-[11px] font-semibold text-muted-foreground">
            반복선 경로
          </div>
          <div className="mt-1 grid grid-cols-3 gap-1">
            {EDGE_ROUTE_SIDES.map((side) => (
              <button
                className={`rounded-md border px-2 py-1 text-left hover:bg-muted ${
                  routeSide === side ? 'border-blue-500 bg-blue-50 text-blue-700' : ''
                }`}
                key={side}
                type="button"
                onClick={() => onRouteChange(edge, side, routeOffset)}
              >
                {EDGE_ROUTE_LABELS[side]}
              </button>
            ))}
          </div>
          <div className="mt-2 text-[11px] font-semibold text-muted-foreground">
            우회 간격
          </div>
          <div className="mt-1 grid grid-cols-3 gap-1">
            {EDGE_ROUTE_OFFSETS.map((offset) => (
              <button
                className={`rounded-md border px-2 py-1 text-left hover:bg-muted ${
                  Math.round(routeOffset) === offset.value
                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                    : ''
                }`}
                key={offset.value}
                type="button"
                onClick={() => onRouteChange(edge, routeSide, offset.value)}
              >
                {offset.label}
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

function getNodeStateForSelectionAction(
  actionId: string
): VisualizationElementState | null {
  if (
    [
      'change-anchor',
      'change-shape',
      'connect',
      'delete',
      'duplicate',
      'edit-edge-label',
      'edit-label',
      'edit-weight',
      'toggle-direction',
    ].includes(actionId)
  ) {
    return null;
  }

  if (actionId.includes('black') || actionId.includes('normal') || actionId.includes('clear')) {
    return 'normal';
  }

  if (actionId.includes('red') || actionId.includes('duplicate-call')) {
    return 'error';
  }

  if (
    actionId.includes('prune') ||
    actionId.includes('collision') ||
    actionId.includes('fault') ||
    actionId.includes('obstacle') ||
    actionId.includes('swap') ||
    actionId.includes('balance')
  ) {
    return 'warning';
  }

  if (
    actionId.includes('prime') ||
    actionId.includes('choose') ||
    actionId.includes('goal') ||
    actionId.includes('sorted') ||
    actionId.includes('return')
  ) {
    return 'success';
  }

  return 'active';
}

function useNodeDirectEditing({
  allowSelfConnection = false,
  edgeAnchorEditable = false,
  edgeStyleEditable = false,
  edges,
  nodes,
  nodeMenuConfig,
  onEdgeAnchorChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeEndpointsChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onEdgeRouteChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  readOnly,
}: {
  allowSelfConnection?: boolean;
  edgeAnchorEditable?: boolean;
  edgeStyleEditable?: boolean;
  edges: VisualizationEdge[];
  nodes: VisualizationNode[];
  nodeMenuConfig: NodeContextMenuConfig;
  onEdgeAnchorChange?: (
    edge: VisualizationEdge,
    fromAnchor: AnchorSide,
    toAnchor: AnchorSide
  ) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeEndpointsChange?: (
    edge: VisualizationEdge,
    nextFromNodeId: string,
    nextToNodeId: string
  ) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange?: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onEdgeRouteChange?: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange?: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const svgRef = React.useRef<SVGSVGElement>(null);
  const [contextMenu, setContextMenu] = React.useState<
    | {
        kind: 'canvas';
        point: { x: number; y: number };
        x: number;
        y: number;
      }
    | {
        kind: 'node';
        nodeId: string;
        x: number;
        y: number;
      }
    | null
  >(null);
  const [selectedEdgeId, setSelectedEdgeId] = React.useState<string | null>(null);
  const [selectedNodeId, setSelectedNodeId] = React.useState<string | null>(null);
  const [edgeAnchorMenu, setEdgeAnchorMenu] = React.useState<{
    edgeId: string;
    x: number;
    y: number;
  } | null>(null);
  const [connectionPreview, setConnectionPreview] = React.useState<{
    fromNodeId: string;
    point: { x: number; y: number };
  } | null>(null);
  const draggingRef = React.useRef<{
    dx: number;
    dy: number;
    id: string;
  } | null>(null);
  const connectingRef = React.useRef<{
    fromNodeId: string;
  } | null>(null);

  const handleNodePointerDown = React.useCallback(
    (node: VisualizationNode, event: React.PointerEvent<SVGGElement>) => {
      setSelectedNodeId(node.id);
      setSelectedEdgeId(null);
      setContextMenu(null);
      setEdgeAnchorMenu(null);
      svgRef.current?.focus();

      if (readOnly) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!point) {
        return;
      }

      if (event.shiftKey && onEdgeCreate) {
        connectingRef.current = {
          fromNodeId: node.id,
        };
        setConnectionPreview({
          fromNodeId: node.id,
          point: {
            x: point.x,
            y: point.y,
          },
        });

        return;
      }

      if (!onNodeMove) {
        return;
      }

      draggingRef.current = {
        id: node.id,
        dx: node.position.x - point.x,
        dy: node.position.y - point.y,
      };

      event.currentTarget.setPointerCapture?.(event.pointerId);
    },
    [onEdgeCreate, onNodeMove, readOnly]
  );

  const handleNodeDoubleClick = React.useCallback(
    (node: VisualizationNode, event: React.MouseEvent<SVGGElement>) => {
      event.preventDefault();
      event.stopPropagation();
      setSelectedNodeId(node.id);
      setSelectedEdgeId(null);
    },
    []
  );

  const handleEdgePointerDown = React.useCallback(
    (edge: VisualizationEdge, event: React.PointerEvent<SVGGeometryElement>) => {
      event.preventDefault();
      event.stopPropagation();
      setSelectedEdgeId(edge.id);
      setSelectedNodeId(null);
      setContextMenu(null);
      // The menu is `position: fixed`, but this editor renders inside a
      // dialog that centers itself with `translate(-50%,-50%)` -- any
      // transformed ancestor becomes the containing block for fixed
      // descendants, so its coordinates must be measured relative to THAT
      // box, not the browser window, or the clamp below targets the wrong
      // bounds and the menu can still end up off-screen.
      const containerRect = getFixedPositioningContainerRect(event.currentTarget);
      const relativeX = event.clientX - containerRect.left + 10;
      const relativeY = event.clientY - containerRect.top + 10;

      setEdgeAnchorMenu(
        readOnly ||
          (!edgeAnchorEditable && !edgeStyleEditable) ||
          (!onEdgeAnchorChange &&
            !onEdgeRouteChange &&
            !onEdgeDirectionChange &&
            !onEdgeLineStyleChange)
          ? null
          : {
              edgeId: edge.id,
              // The menu can show up to six stacked sections (direction, line
              // style, two anchor pickers, route side, offset) at a fixed
              // 256px width -- clicking an edge near the right/bottom of the
              // container (a common spot for decision-branch edges) would
              // otherwise position most of it off-screen and unreachable.
              x: Math.min(
                Math.max(8, relativeX),
                Math.max(8, containerRect.width - 264)
              ),
              y: Math.min(
                Math.max(8, relativeY),
                Math.max(8, containerRect.height - 420)
              ),
            }
      );
      svgRef.current?.focus();
    },
    [
      edgeAnchorEditable,
      edgeStyleEditable,
      onEdgeAnchorChange,
      onEdgeDirectionChange,
      onEdgeLineStyleChange,
      onEdgeRouteChange,
      readOnly,
    ]
  );

  const handleEdgeDoubleClick = React.useCallback(
    (edge: VisualizationEdge, event: React.MouseEvent<SVGGeometryElement>) => {
      if (readOnly || !onEdgeLabelChange) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      setSelectedEdgeId(edge.id);
      setSelectedNodeId(null);

      if (!edge.label) {
        onEdgeLabelChange(edge, 'Label');
      }
    },
    [onEdgeLabelChange, readOnly]
  );

  const handleNodeContextMenu = React.useCallback(
    (node: VisualizationNode, event: React.MouseEvent<SVGGElement>) => {
      if (readOnly) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      setSelectedNodeId(node.id);
      setSelectedEdgeId(null);
      setEdgeAnchorMenu(null);
      svgRef.current?.focus();
      setContextMenu({
        kind: 'node',
        nodeId: node.id,
        x: event.clientX,
        y: event.clientY,
      });
    },
    [readOnly]
  );

  const handleCanvasContextMenu = React.useCallback(
    (event: React.MouseEvent<SVGSVGElement>) => {
      if (readOnly || !onNodeCreate) {
        return;
      }

      event.preventDefault();
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!point) {
        return;
      }

      setSelectedEdgeId(null);
      setSelectedNodeId(null);
      setEdgeAnchorMenu(null);
      setContextMenu({
        kind: 'canvas',
        point: {
          x: Math.round(point.x),
          y: Math.round(point.y),
        },
        x: event.clientX,
        y: event.clientY,
      });
    },
    [onNodeCreate, readOnly]
  );

  const handleCanvasDoubleClick = React.useCallback(
    (event: React.MouseEvent<SVGSVGElement>) => {
      if (readOnly || !onNodeCreate) {
        return;
      }

      event.preventDefault();
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!point) {
        return;
      }

      onNodeCreate({
        label: nodeMenuConfig.defaultAction.defaultLabel,
        shape: nodeMenuConfig.defaultAction.shape,
        x: Math.round(point.x),
        y: Math.round(point.y),
      });
      setContextMenu(null);
    },
    [nodeMenuConfig, onNodeCreate, readOnly]
  );

  const handleCanvasPointerDown = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      if (event.target !== event.currentTarget) {
        return;
      }

      setContextMenu(null);
      setEdgeAnchorMenu(null);
      setSelectedEdgeId(null);
      setSelectedNodeId(null);
    },
    []
  );

  const createNodeFromContextMenu = React.useCallback(
    (shape: VisualizationShapeKind) => {
      if (!onNodeCreate || contextMenu?.kind !== 'canvas') {
        return;
      }
      const action =
        nodeMenuConfig.createActions.find((item) => item.shape === shape) ??
        nodeMenuConfig.defaultAction;

      onNodeCreate({
        label: action.defaultLabel,
        shape: action.shape,
        x: contextMenu.point.x,
        y: contextMenu.point.y,
      });
      setContextMenu(null);
    },
    [contextMenu, nodeMenuConfig, onNodeCreate]
  );

  const changeSelectedNodeShape = React.useCallback(
    (shape: VisualizationShapeKind) => {
      if (!onNodeShapeChange || contextMenu?.kind !== 'node') {
        return;
      }

      onNodeShapeChange(contextMenu.nodeId, shape);
      setContextMenu(null);
    },
    [contextMenu, onNodeShapeChange]
  );

  const deleteSelectedNode = React.useCallback(() => {
    if (!onNodeDelete || contextMenu?.kind !== 'node') {
      return;
    }

    onNodeDelete(contextMenu.nodeId);
    setContextMenu(null);
    setSelectedNodeId(null);
  }, [contextMenu, onNodeDelete]);

  const runSelectedNodeAction = React.useCallback(
    (actionId: string) => {
      if (contextMenu?.kind !== 'node') {
        return;
      }

      const node = nodes.find((item) => item.id === contextMenu.nodeId);

      if (!node) {
        setContextMenu(null);

        return;
      }

      if (actionId === 'delete') {
        deleteSelectedNode();

        return;
      }

      if (actionId === 'duplicate' && onNodeCreate) {
        onNodeCreate({
          label: node.label,
          shape: node.shape ?? nodeMenuConfig.defaultAction.shape,
          x: Math.round(node.position.x + 56),
          y: Math.round(node.position.y + 56),
        });
        setContextMenu(null);

        return;
      }

      if (actionId === 'add-self-transition' && onEdgeCreate) {
        onEdgeCreate(node.id, node.id);
        setSelectedNodeId(node.id);
        setContextMenu(null);

        return;
      }

      const state = getNodeStateForSelectionAction(actionId);

      if (state && onNodeStateChange) {
        onNodeStateChange(node.id, state);
      }

      setSelectedNodeId(node.id);
      setContextMenu(null);
    },
    [
      contextMenu,
      deleteSelectedNode,
      nodeMenuConfig,
      nodes,
      onNodeCreate,
      onEdgeCreate,
      onNodeStateChange,
    ]
  );

  const handleCanvasKeyDown = React.useCallback(
    (event: React.KeyboardEvent<SVGSVGElement>) => {
      if (event.key === 'Escape') {
        setContextMenu(null);
        setEdgeAnchorMenu(null);
        setSelectedEdgeId(null);
        setSelectedNodeId(null);

        return;
      }

      if (
        !readOnly &&
        onNodeDelete &&
        selectedNodeId &&
        (event.key === 'Delete' || event.key === 'Backspace')
      ) {
        event.preventDefault();
        event.stopPropagation();
        onNodeDelete(selectedNodeId);
        setSelectedNodeId(null);
        setEdgeAnchorMenu(null);

        return;
      }

      if (
        readOnly ||
        !onEdgeDelete ||
        !selectedEdgeId ||
        (event.key !== 'Delete' && event.key !== 'Backspace')
      ) {
        return;
      }

      const selectedEdge = edges.find((edge) => edge.id === selectedEdgeId);

      if (!selectedEdge) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      onEdgeDelete(selectedEdge);
      setSelectedEdgeId(null);
      setEdgeAnchorMenu(null);
    },
    [edges, onEdgeDelete, onNodeDelete, readOnly, selectedEdgeId, selectedNodeId]
  );

  const handleSvgPointerMove = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      if (!draggingRef.current && !connectingRef.current) {
        return;
      }

      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!point) {
        return;
      }

      if (connectingRef.current) {
        setConnectionPreview({
          fromNodeId: connectingRef.current.fromNodeId,
          point: {
            x: point.x,
            y: point.y,
          },
        });

        return;
      }

      if (!draggingRef.current || !onNodeMove) {
        return;
      }

      const x = Math.round(point.x + draggingRef.current.dx);
      const y = Math.round(point.y + draggingRef.current.dy);

      onNodeMove(draggingRef.current.id, x, y);
    },
    [onNodeMove]
  );

  const stopDragging = React.useCallback(() => {
    draggingRef.current = null;
    connectingRef.current = null;
    setConnectionPreview(null);
  }, []);

  const handleSvgPointerUp = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      if (!connectingRef.current || !onEdgeCreate) {
        stopDragging();

        return;
      }

      const fromNodeId = connectingRef.current.fromNodeId;
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (point) {
        const target = findNodeAtPoint(
          nodes,
          point,
          fromNodeId,
          allowSelfConnection
        );

        if (target) {
          onEdgeCreate(fromNodeId, target.id);
        }
      }

      stopDragging();
    },
    [allowSelfConnection, nodes, onEdgeCreate, stopDragging]
  );

  return {
    changeSelectedNodeShape,
    connectionPreview,
    contextMenu,
    createNodeFromContextMenu,
    deleteSelectedNode,
    edgeAnchorMenu,
    handleCanvasContextMenu,
    handleCanvasDoubleClick,
    handleCanvasKeyDown,
    handleCanvasPointerDown,
    handleEdgeDoubleClick,
    handleEdgePointerDown,
    handleNodeContextMenu,
    handleNodeDoubleClick,
    handleNodePointerDown,
    handleSvgPointerLeave: stopDragging,
    handleSvgPointerMove,
    handleSvgPointerUp,
    runSelectedNodeAction,
    selectedEdgeId,
    selectedNodeId,
    setSelectedNodeId,
    svgRef,
  };
}

function FlowchartRenderer({
  document,
  onEdgeAnchorChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onEdgeRouteChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onEdgeAnchorChange?: (
    edge: VisualizationEdge,
    fromAnchor: AnchorSide,
    toAnchor: AnchorSide
  ) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange?: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onEdgeRouteChange?: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange?: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const nodes = document.model.nodes ?? [];
  const edges = document.model.edges ?? [];
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const bounds = getBounds(nodes, { minX: 0, minY: 0, width: 520, height: 300 });
  const directEditing = useNodeDirectEditing({
    edgeAnchorEditable: isVisualizationEdgeBendable(document.visualType),
    edges,
    nodeMenuConfig: getNodeContextMenuConfig(document.visualType, 'process'),
    nodes,
    onEdgeAnchorChange,
    onEdgeCreate,
    onEdgeDelete,
    onEdgeLabelChange,
    onEdgeRouteChange,
    onNodeCreate,
    onNodeDelete,
    onNodeLabelChange,
    onNodeMove,
    onNodeShapeChange,
    onNodeStateChange,
    readOnly,
  });
  const edgePortOffsets = getEdgePortOffsets(edges, nodesById);
  const selfLoopAssignments = getSelfLoopRouteAssignments(
    edges,
    edgePortOffsets.busySidesByNode
  );
  const parallelEdgeOffsets = getParallelEdgeOffsets(edges);
  const edgeGeometries = edges
    .map((edge) => {
      const geometry = getEdgeGeometry(
        edge,
        nodesById,
        undefined,
        undefined,
        edgePortOffsets.sourceOffsets.get(edge.id) ?? 0,
        edgePortOffsets.targetOffsets.get(edge.id) ?? 0,
        parallelEdgeOffsets.get(edge.id) ?? 0,
        selfLoopAssignments.get(edge.id)
      );

      return geometry ? { edge, geometry } : null;
    })
    .filter(
      (item): item is { edge: VisualizationEdge; geometry: EdgeGeometry } =>
        item !== null
    );
  const edgeCrossings = shouldShowVisualizationEdgeCrossingMarker(
    document.visualType
  )
    ? getEdgeCrossings(edgeGeometries)
    : new Map<string, { x: number; y: number }[]>();
  const edgeGeometryById = new Map(
    edgeGeometries.map((item) => [item.edge.id, item.geometry])
  );
  const anchorMenuEdge = directEditing.edgeAnchorMenu
    ? edges.find((edge) => edge.id === directEditing.edgeAnchorMenu?.edgeId)
    : undefined;
  const anchorMenuFromNode = anchorMenuEdge
    ? nodesById.get(anchorMenuEdge.from)
    : undefined;
  const anchorMenuToNode = anchorMenuEdge ? nodesById.get(anchorMenuEdge.to) : undefined;
  const anchorMenuFromAnchor =
    anchorMenuEdge && anchorMenuFromNode && anchorMenuToNode
      ? anchorMenuEdge.fromAnchor ??
        getAnchorSide(anchorMenuFromNode, anchorMenuToNode, 'source')
      : 'bottom';
  const anchorMenuToAnchor =
    anchorMenuEdge && anchorMenuFromNode && anchorMenuToNode
      ? anchorMenuEdge.toAnchor ??
        getAnchorSide(anchorMenuToNode, anchorMenuFromNode, 'target')
      : 'top';
  const anchorMenuRouteSide = anchorMenuEdge?.routeSide ?? 'auto';
  const anchorMenuRouteOffset = anchorMenuEdge?.routeOffset ?? 72;

  return (
    <>
      <Canvas
        bounds={bounds}
        onContextMenu={directEditing.handleCanvasContextMenu}
        onDoubleClick={directEditing.handleCanvasDoubleClick}
        onKeyDown={directEditing.handleCanvasKeyDown}
        onPointerDown={directEditing.handleCanvasPointerDown}
        onPointerLeave={directEditing.handleSvgPointerLeave}
        onPointerMove={directEditing.handleSvgPointerMove}
        onPointerUp={directEditing.handleSvgPointerUp}
        svgRef={directEditing.svgRef}
      >
        {nodes.map((node) => (
          <Shape
            editable={!readOnly}
            key={node.id}
            node={node}
            selected={directEditing.selectedNodeId === node.id}
            onContextMenu={directEditing.handleNodeContextMenu}
            onDoubleClick={directEditing.handleNodeDoubleClick}
            onLabelChange={onNodeLabelChange}
            onPointerDown={directEditing.handleNodePointerDown}
          />
        ))}
        {directEditing.connectionPreview && (
          <ConnectionPreview
            from={
              nodesById.get(directEditing.connectionPreview.fromNodeId) ??
              nodes[0]
            }
            point={directEditing.connectionPreview.point}
          />
        )}
        {edges.map((edge) => (
          <EdgeLine
            crossings={edgeCrossings.get(edge.id)}
            editable={!readOnly}
            edge={edge}
            geometry={edgeGeometryById.get(edge.id)}
            key={edge.id}
            nodesById={nodesById}
            selected={directEditing.selectedEdgeId === edge.id}
            onDoubleClick={directEditing.handleEdgeDoubleClick}
            onLabelChange={onEdgeLabelChange}
            onPointerDown={directEditing.handleEdgePointerDown}
          />
        ))}
      </Canvas>
      {directEditing.contextMenu && (
        <NodeContextMenu
          config={getNodeContextMenuConfig(document.visualType, 'process')}
          kind={directEditing.contextMenu.kind}
          x={directEditing.contextMenu.x}
          y={directEditing.contextMenu.y}
          onCreateNode={directEditing.createNodeFromContextMenu}
          onDeleteNode={directEditing.deleteSelectedNode}
          onNodeShapeChange={directEditing.changeSelectedNodeShape}
          onSelectedAction={directEditing.runSelectedNodeAction}
        />
      )}
      {directEditing.edgeAnchorMenu && anchorMenuEdge && onEdgeAnchorChange && (
        <EdgeAnchorMenu
          edge={anchorMenuEdge}
          fromAnchor={anchorMenuFromAnchor}
          routeOffset={anchorMenuRouteOffset}
          routeSide={anchorMenuRouteSide}
          toAnchor={anchorMenuToAnchor}
          x={directEditing.edgeAnchorMenu.x}
          y={directEditing.edgeAnchorMenu.y}
          onAnchorChange={onEdgeAnchorChange}
          onRouteChange={onEdgeRouteChange}
        />
      )}
    </>
  );
}

function NetworkRenderer({
  document,
  onCommandChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange?: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange?: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const nodes = (document.model.nodes ?? []).map((node) => ({
    ...node,
    shape: 'circle' as const,
    size: node.size ?? { width: 58, height: 58 },
  }));
  const edges = document.model.edges ?? [];
  const directedEdges =
    typeof document.model.typeData?.directed === 'boolean'
      ? Boolean(document.model.typeData.directed)
      : isVisualizationEdgeDirected(document.visualType);
  const weightedEdges = document.model.typeData?.weighted !== false;
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const bounds = getBounds(nodes, { minX: 0, minY: 0, width: 360, height: 220 });
  const directEditing = useNodeDirectEditing({
    edgeAnchorEditable: isVisualizationEdgeBendable(document.visualType),
    edges,
    nodeMenuConfig: getNodeContextMenuConfig(document.visualType, 'circle'),
    nodes,
    onEdgeCreate,
    onEdgeDelete,
    onEdgeLabelChange,
    onNodeCreate,
    onNodeDelete,
    onNodeLabelChange,
    onNodeMove,
    onNodeShapeChange,
    onNodeStateChange,
    readOnly,
  });
  return (
    <>
      <Canvas
        bounds={bounds}
        onContextMenu={directEditing.handleCanvasContextMenu}
        onDoubleClick={directEditing.handleCanvasDoubleClick}
        onKeyDown={directEditing.handleCanvasKeyDown}
        onPointerDown={directEditing.handleCanvasPointerDown}
        onPointerLeave={directEditing.handleSvgPointerLeave}
        onPointerMove={directEditing.handleSvgPointerMove}
        onPointerUp={directEditing.handleSvgPointerUp}
        svgRef={directEditing.svgRef}
      >
        {!readOnly && onCommandChange && (
          <g data-visual-network-options>
            {[
              {
                active: directedEdges,
                label: directedEdges ? '방향 그래프' : '무방향 그래프',
                next: `${directedEdges ? 'undirected' : 'directed'} ${weightedEdges ? 'weighted' : 'unweighted'}`,
                x: bounds.minX + 18,
              },
              {
                active: weightedEdges,
                label: weightedEdges ? '가중치 있음' : '가중치 없음',
                next: `${directedEdges ? 'directed' : 'undirected'} ${weightedEdges ? 'unweighted' : 'weighted'}`,
                x: bounds.minX + 128,
              },
            ].map((option) => (
              <g
                className="cursor-pointer"
                key={option.label}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onCommandChange('graph', option.next);
                }}
                onPointerDown={(event) => event.stopPropagation()}
              >
                <rect fill={option.active ? '#dbeafe' : '#ffffff'} height={28} rx={8} stroke={option.active ? ACTIVE_STROKE : NORMAL_STROKE} strokeWidth={1.3} width={100} x={option.x} y={bounds.minY + 14} />
                <text x={option.x + 50} y={bounds.minY + 33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
                  {option.label}
                </text>
              </g>
            ))}
          </g>
        )}
        {edges.map((edge) => (
          <EdgeLine
            anchorMode="radial"
            directed={directedEdges}
            editable={!readOnly}
            edge={{ ...edge, kind: 'straight', label: weightedEdges ? edge.label : undefined }}
            key={edge.id}
            nodesById={nodesById}
            selected={directEditing.selectedEdgeId === edge.id}
            onDoubleClick={directEditing.handleEdgeDoubleClick}
            onLabelChange={onEdgeLabelChange}
            onPointerDown={directEditing.handleEdgePointerDown}
          />
        ))}
        {nodes.map((node) => (
          <Shape
            editable={!readOnly}
            key={node.id}
            node={node}
            selected={directEditing.selectedNodeId === node.id}
            onContextMenu={directEditing.handleNodeContextMenu}
            onDoubleClick={directEditing.handleNodeDoubleClick}
            onLabelChange={onNodeLabelChange}
            onPointerDown={directEditing.handleNodePointerDown}
          />
        ))}
        {directEditing.connectionPreview && (
          <ConnectionPreview
            from={
              nodesById.get(directEditing.connectionPreview.fromNodeId) ??
              nodes[0]
            }
            point={directEditing.connectionPreview.point}
          />
        )}
      </Canvas>
      {directEditing.contextMenu && (
        <NodeContextMenu
          config={getNodeContextMenuConfig(document.visualType, 'circle')}
          kind={directEditing.contextMenu.kind}
          x={directEditing.contextMenu.x}
          y={directEditing.contextMenu.y}
          onCreateNode={directEditing.createNodeFromContextMenu}
          onDeleteNode={directEditing.deleteSelectedNode}
          onNodeShapeChange={directEditing.changeSelectedNodeShape}
          onSelectedAction={directEditing.runSelectedNodeAction}
        />
      )}
    </>
  );
}

function ArrayGridRenderer({
  document,
  onItemActiveChange,
  onItemCreate,
  onItemDelete,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: (itemIndex?: number) => void;
  onItemDelete?: (itemIndex: number) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const cellWidth = 66;
  const cellHeight = 52;
  const startX = 48;
  const startY = 80;
  const width = Math.max(420, items.length * cellWidth + startX * 2);
  const activeIndex = items.findIndex((item) => item.state === 'active');
  const effectiveActiveIndex = activeIndex >= 0 ? activeIndex : 0;
  const draggingPointerRef = React.useRef(false);
  const updateActiveIndexFromPointer = React.useCallback(
    (svg: SVGSVGElement | null, clientX: number, clientY: number) => {
      if (!onItemActiveChange || items.length === 0) {
        return;
      }

      const point = getSvgPoint(svg, clientX, clientY);

      if (!point) {
        return;
      }

      const nextIndex = Math.round(
        clamp((point.x - startX - cellWidth / 2) / cellWidth, 0, items.length - 1)
      );

      onItemActiveChange([nextIndex]);
    },
    [cellWidth, items.length, onItemActiveChange, startX]
  );

  return (
    <Canvas
      bounds={{ minX: 0, minY: 0, width, height: 230 }}
      onPointerLeave={() => {
        draggingPointerRef.current = false;
      }}
      onPointerMove={(event) => {
        if (readOnly || !onItemActiveChange || !draggingPointerRef.current) {
          return;
        }

        updateActiveIndexFromPointer(
          event.currentTarget,
          event.clientX,
          event.clientY
        );
      }}
      onPointerUp={() => {
        draggingPointerRef.current = false;
      }}
    >
      {!readOnly && onItemCreate && (
        <g>
          {Array.from({ length: items.length + 1 }).map((_, insertIndex) => {
            const x = startX + insertIndex * cellWidth - 8;
            const y = startY + cellHeight / 2;

            return (
              <g
                className="cursor-pointer opacity-0 transition-opacity hover:opacity-100"
                data-visual-array-insert-underlay={insertIndex}
                key={`insert-${insertIndex}`}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemCreate(insertIndex);
                }}
                onPointerDown={(event) => {
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x}
                  cy={y}
                  fill="#ffffff"
                  r={9}
                  stroke={ACTIVE_STROKE}
                  strokeWidth={1.5}
                />
                <path
                  d={`M${x - 4} ${y} H${x + 4} M${x} ${y - 4} V${y + 4}`}
                  stroke={ACTIVE_STROKE}
                  strokeWidth={1.5}
                />
              </g>
            );
          })}
        </g>
      )}
      {items.map((item) => {
        const itemIndex = item.index ?? items.indexOf(item);
        const x = startX + itemIndex * cellWidth;
        const active = item.state === 'active';
        const activateItem = () => {
          onItemActiveChange?.([itemIndex]);
        };

        return (
          <g
            className={
              !readOnly && (onItemActiveChange || onItemValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            data-visual-array-item={itemIndex}
            key={item.id}
            onPointerDown={(event) => {
              if (readOnly || !onItemActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              draggingPointerRef.current = true;
              activateItem();
              event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onClick={(event) => {
              if (readOnly || !onItemActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              activateItem();
            }}
          >
            <text
              x={x + cellWidth / 2}
              y={startY + cellHeight + 20}
              textAnchor="middle"
              className="fill-slate-500 text-[12px] font-semibold"
            >
              {itemIndex}
            </text>
            <rect
              fill={active ? '#dbeafe' : '#ffffff'}
              height={cellHeight}
              rx={8}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.4 : 1.6}
              width={cellWidth}
              x={x}
              y={startY}
              onPointerDown={(event) => {
                if (readOnly || !onItemActiveChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingPointerRef.current = true;
                activateItem();
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onClick={(event) => {
                if (readOnly || !onItemActiveChange) return;

                event.preventDefault();
                event.stopPropagation();
                activateItem();
              }}
            />
            <EditableSvgText
              dataVisualTextTarget="array-item"
              x={x + cellWidth / 2}
              y={startY + 33}
              value={item.value}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-950 text-[15px] font-bold"
              onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue)}
            />
            {!readOnly && onItemDelete && active && items.length > 1 && (
              <g
                className="cursor-pointer"
                data-visual-array-delete={itemIndex}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemDelete(itemIndex);
                }}
                onPointerDown={(event) => {
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x + cellWidth - 8}
                  cy={startY - 12}
                  fill="#fff1f2"
                  r={9}
                  stroke="#dc2626"
                  strokeWidth={1.4}
                />
                <path
                  d={`M${x + cellWidth - 12} ${startY - 16} L${x + cellWidth - 4} ${startY - 8} M${x + cellWidth - 4} ${startY - 16} L${x + cellWidth - 12} ${startY - 8}`}
                  stroke="#dc2626"
                  strokeWidth={1.5}
                />
              </g>
            )}
          </g>
        );
      })}
      {!readOnly && onItemCreate && (
        <g>
          {Array.from({ length: items.length + 1 }).map((_, insertIndex) => {
            const x = startX + insertIndex * cellWidth - 8;
            const y = startY + cellHeight / 2;

            return (
              <g
                className="cursor-pointer opacity-40 transition-opacity hover:opacity-100"
                data-visual-array-insert={insertIndex}
                key={`insert-overlay-${insertIndex}`}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemCreate(insertIndex);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x}
                  cy={y}
                  fill="#ffffff"
                  r={9}
                  stroke={ACTIVE_STROKE}
                  strokeWidth={1.5}
                />
                <path
                  d={`M${x - 4} ${y} H${x + 4} M${x} ${y - 4} V${y + 4}`}
                  stroke={ACTIVE_STROKE}
                  strokeWidth={1.5}
                />
              </g>
            );
          })}
        </g>
      )}
      {items.length > 0 && (
        <g
          className={!readOnly && onItemActiveChange ? 'cursor-ew-resize' : undefined}
          data-visual-array-pointer
          onPointerDown={(event) => {
            if (readOnly || !onItemActiveChange) {
              return;
            }

            event.preventDefault();
            event.stopPropagation();
            draggingPointerRef.current = true;
            event.currentTarget.setPointerCapture(event.pointerId);
            updateActiveIndexFromPointer(
              event.currentTarget.ownerSVGElement,
              event.clientX,
              event.clientY
            );
          }}
        >
          <line
            markerEnd="url(#arrow-small)"
            stroke={ACTIVE_STROKE}
            strokeWidth={1.8}
            x1={startX + effectiveActiveIndex * cellWidth + cellWidth / 2}
            x2={startX + effectiveActiveIndex * cellWidth + cellWidth / 2}
            y1={startY - 38}
            y2={startY - 8}
          />
          <text
            className="fill-blue-700 text-[12px] font-semibold"
            textAnchor="middle"
            x={startX + effectiveActiveIndex * cellWidth + cellWidth / 2}
            y={startY - 44}
          >
            active
          </text>
        </g>
      )}
    </Canvas>
  );
}

function SortingRenderer({
  document,
  onCommandChange,
  onItemActiveChange,
  onItemCreate,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: (itemIndex?: number, value?: string) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const values = items.map((item) => Number(item.value ?? 0));
  const maxValue = Math.max(...values, 1);
  const scaleMax = Math.max(12, maxValue);
  const barWidth = 44;
  const gap = 18;
  const baseY = 210;
  const plotHeight = 128;
  const startX = 52;
  const width = Math.max(480, items.length * (barWidth + gap) + startX * 2);
  const sortMode = String(document.model.typeData?.sortMode ?? 'compare');
  // `item.index` is the item's real position in the array; falling back to
  // the position within the *filtered* (active-only) list used to point the
  // compare/swap markers at the wrong bars whenever an active item without
  // an explicit `index` wasn't the first one filtered. `items.indexOf` finds
  // its real position instead. Also clamp into range so a stray/out-of-sync
  // index can't push the marker off the canvas -- SearchingRenderer already
  // does this for its low/mid/high pointers; SortingRenderer didn't.
  const activeIndexes = items
    .filter((item) => item.state === 'active')
    .map((item) =>
      clamp(item.index ?? items.indexOf(item), 0, Math.max(0, items.length - 1))
    );
  const compareIndexes = activeIndexes.slice(0, 2);
  const activeIndexSet = new Set(compareIndexes);
  const compareCenters = compareIndexes.map(
    (index) => startX + index * (barWidth + gap) + barWidth / 2
  );
  const compareMarkerY = baseY + 42;
  const compareTargetY = baseY + 8;
  const showCompare = sortMode !== 'swap' && compareCenters.length > 0;
  const showSwap = sortMode !== 'compare' && compareCenters.length === 2;
  const compareLabelX =
    compareCenters.length > 1
      ? (compareCenters[0] + compareCenters[compareCenters.length - 1]) / 2
      : (compareCenters[0] ?? startX);
  const swapArrowY = compareMarkerY - 10;
  const swapBottomY = compareMarkerY + 10;
  const swapGuideY = showCompare ? compareMarkerY + 8 : compareMarkerY;
  const draggingIndexRef = React.useRef<number | null>(null);
  const toValue = React.useCallback(
    (y: number) => Math.max(0, Math.round(((baseY - y) / plotHeight) * scaleMax)),
    [baseY, plotHeight, scaleMax]
  );
  const activateItem = React.useCallback(
    (itemIndex: number, additive: boolean) => {
      if (!onItemActiveChange) return;

      const currentIndexes = activeIndexes.slice(0, 2);

      if (additive) {
        const nextIndexes = currentIndexes.includes(itemIndex)
          ? currentIndexes.filter((index) => index !== itemIndex)
          : [...currentIndexes, itemIndex];

        onItemActiveChange(nextIndexes.slice(-2));
        return;
      }

      if (currentIndexes.length === 1 && currentIndexes[0] !== itemIndex) {
        onItemActiveChange([currentIndexes[0], itemIndex]);
        return;
      }

      onItemActiveChange([itemIndex]);
    },
    [activeIndexes, onItemActiveChange]
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height: 300 }}>
      {!readOnly && onCommandChange && (
        <g data-visual-sorting-mode>
          {[
            ['compare', '비교'],
            ['swap', '교환'],
            ['both', '비교 + 교환'],
          ].map(([mode, label], index) => {
            const x = 20 + index * 82;
            const active = sortMode === mode;

            return (
              <g
                className="cursor-pointer"
                key={mode}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onCommandChange('sort-mode', mode);
                }}
                onPointerDown={(event) => event.stopPropagation()}
              >
                <rect
                  fill={active ? '#dbeafe' : '#ffffff'}
                  height={28}
                  rx={8}
                  stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
                  strokeWidth={active ? 1.8 : 1.2}
                  width={index === 2 ? 78 : 68}
                  x={x}
                  y={14}
                />
                <text
                  className="fill-slate-700 text-[12px] font-semibold"
                  textAnchor="middle"
                  x={x + (index === 2 ? 39 : 34)}
                  y={33}
                >
                  {label}
                </text>
              </g>
            );
          })}
        </g>
      )}
      {!readOnly && onItemCreate && (
        <g data-visual-sorting-insert-controls>
          {Array.from({ length: items.length + 1 }).map((_, insertIndex) => {
            const x = startX + insertIndex * (barWidth + gap) - gap / 2;

            return (
              <g
                className="cursor-pointer opacity-30 transition-opacity hover:opacity-100"
                data-visual-sorting-insert={insertIndex}
                key={`sorting-insert-${insertIndex}`}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemCreate(insertIndex, '0');
                }}
                onPointerDown={(event) => event.stopPropagation()}
              >
                <circle cx={x} cy={baseY + 18} fill="#ffffff" r={8} stroke={ACTIVE_STROKE} strokeWidth={1.4} />
                <path d={`M${x - 4} ${baseY + 18} H${x + 4} M${x} ${baseY + 14} V${baseY + 22}`} stroke={ACTIVE_STROKE} strokeWidth={1.4} />
              </g>
            );
          })}
        </g>
      )}
      {items.map((item, index) => {
        const value = Number(item.value ?? 0);
        const height = Math.max(20, (value / scaleMax) * plotHeight);
        const x = startX + (item.index ?? 0) * (barWidth + gap);
        const y = baseY - height;
        const itemIndex = item.index ?? index;
        const compareRole = compareIndexes.indexOf(itemIndex);
        const active = activeIndexSet.has(itemIndex);
        const activeFill = compareRole === 1 ? '#ffedd5' : '#dbeafe';
        const activeStroke = compareRole === 1 ? WARNING_STROKE : ACTIVE_STROKE;

        return (
          <g
            className={!readOnly && onItemValueChange ? 'cursor-ns-resize' : undefined}
            key={item.id}
          >
            <rect
              fill={active ? activeFill : '#ffffff'}
              height={height}
              rx={7}
              stroke={active ? activeStroke : NORMAL_STROKE}
              strokeWidth={active ? 2.4 : 1.6}
              width={barWidth}
              x={x}
              y={y}
              onPointerDown={(event) => {
                if (readOnly || !onItemValueChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingIndexRef.current = index;
                activateItem(itemIndex, event.shiftKey || event.ctrlKey || event.metaKey);
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                  return;
                }

                const point = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!point) return;

                onItemValueChange(
                  itemIndex,
                  String(toValue(clamp(point.y, baseY - plotHeight, baseY)))
                );
              }}
              onPointerUp={() => {
                draggingIndexRef.current = null;
              }}
            />
            <circle
              data-visual-control
              data-visual-sorting-handle={itemIndex}
              cx={x + barWidth / 2}
              cy={y}
              r={5}
              fill="#ffffff"
              stroke={active ? activeStroke : ACTIVE_STROKE}
              strokeWidth={1.6}
              className={!readOnly && onItemValueChange ? 'cursor-ns-resize' : undefined}
              onPointerDown={(event) => {
                if (readOnly || !onItemValueChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingIndexRef.current = index;
                activateItem(itemIndex, event.shiftKey || event.ctrlKey || event.metaKey);
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                  return;
                }

                const point = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!point) return;

                onItemValueChange(
                  itemIndex,
                  String(toValue(clamp(point.y, baseY - plotHeight, baseY)))
                );
              }}
              onPointerUp={() => {
                draggingIndexRef.current = null;
              }}
            />
            <EditableSvgText
              x={x + barWidth / 2}
              y={y - 10}
              value={value}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-950 text-[13px] font-bold"
              onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue)}
            />
          </g>
        );
      })}
      <line
        stroke="#cbd5e1"
        strokeWidth={1.5}
        x1={36}
        x2={width - 36}
        y1={baseY}
        y2={baseY}
      />
      {(showCompare || showSwap) && (
        <g data-visual-sorting-compare>
          {showCompare &&
            compareCenters.map((centerX, index) => (
              <line
                data-visual-sorting-compare-marker={compareIndexes[index]}
                key={`compare-marker-${compareIndexes[index]}`}
                markerEnd="url(#arrow-small)"
                stroke={ACTIVE_STROKE}
                strokeLinecap="round"
                strokeWidth={1.7}
                x1={centerX}
                x2={centerX}
                y1={compareMarkerY}
                y2={compareTargetY}
              />
            ))}
          {showSwap && sortMode === 'both' && (
            <path
              data-visual-sorting-swap-arrow
              data-visual-sorting-swap-direction="guide"
              d={`M ${compareCenters[0]} ${swapGuideY} H ${compareCenters[1]}`}
              fill="none"
              stroke={WARNING_STROKE}
              strokeLinecap="round"
              strokeWidth={1.7}
            />
          )}
          {showSwap && sortMode !== 'both' && (
            <g data-visual-sorting-swap-arrow>
              <path
                data-visual-sorting-swap-direction="left-to-right"
                d={`M ${compareCenters[0]} ${swapArrowY} H ${compareCenters[1]}`}
                fill="none"
                markerEnd="url(#arrow-small)"
                stroke={WARNING_STROKE}
                strokeLinecap="round"
                strokeWidth={1.7}
              />
              <path
                data-visual-sorting-swap-direction="right-to-left"
                d={`M ${compareCenters[1]} ${swapBottomY} H ${compareCenters[0]}`}
                fill="none"
                markerEnd="url(#arrow-small)"
                stroke={WARNING_STROKE}
                strokeLinecap="round"
                strokeWidth={1.7}
              />
            </g>
          )}
          <text className="fill-blue-700 text-[12px] font-semibold" textAnchor="middle" x={compareLabelX} y={compareMarkerY + 28}>
            {sortMode === 'swap' ? 'swap' : sortMode === 'both' ? 'compare + swap' : 'compare'}
          </text>
        </g>
      )}
    </Canvas>
  );
}

function formatDpCoordinate(cell: VisualizationCell) {
  return `dp[${cell.row}][${cell.column}]`;
}

function formatDpCellValue(cell: VisualizationCell) {
  return `${formatDpCoordinate(cell)}=${cell.value}`;
}

function getNumericCellValue(cell: VisualizationCell | null | undefined) {
  const value = Number(cell?.value);

  return Number.isFinite(value) ? value : null;
}

function clipFormulaText(value: string, maxLength = 74) {
  return value.length > maxLength ? `${value.slice(0, maxLength - 3)}...` : value;
}

function describeDpConsistency(
  formula: string,
  currentCell: VisualizationCell | null,
  previousCells: VisualizationCell[]
) {
  if (!currentCell || previousCells.length === 0) {
    return {
      calculation: 'Select the current cell and reference cells to show the calculation relation.',
      relation: 'Click: current cell / Shift, Ctrl, Alt+click: reference cells',
    };
  }

  const relation = `${formatDpCoordinate(currentCell)} <- ${previousCells
    .map(formatDpCellValue)
    .join(', ')}`;
  const numbers = previousCells.map(getNumericCellValue);
  const currentNumber = getNumericCellValue(currentCell);
  const canCalculate =
    currentNumber !== null && numbers.every((value): value is number => value !== null);
  const lowerFormula = formula.toLowerCase();
  let expected: number | null = null;
  let expression = '';

  if (canCalculate && numbers.length > 0) {
    if (lowerFormula.includes('max')) {
      expected = Math.max(...numbers);
      expression = `max(${numbers.join(', ')})`;
    } else if (lowerFormula.includes('min')) {
      expected = Math.min(...numbers);
      expression = `min(${numbers.join(', ')})`;
    } else if (formula.includes('+')) {
      expected = numbers.reduce((sum, value) => sum + value, 0);
      expression = numbers.join(' + ');
    }
  }

  if (expected === null || currentNumber === null) {
    return {
      calculation: `Selected calculation: ${relation}`,
      relation,
    };
  }

  const matches = Math.abs(expected - currentNumber) < 0.000001;

  return {
    calculation: `${expression} = ${expected}, current value ${currentCell.value} (${matches ? 'match' : 'mismatch'})`,
    relation,
  };
}

function DpTableRenderer({
  document,
  onCommandChange,
  onCellActiveChange,
  onCellValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onCellActiveChange?: (row: number, column: number) => void;
  onCellValueChange?: (row: number, column: number, value: string) => void;
  readOnly?: boolean;
}) {
  const cells = document.model.cells ?? [];
  const columns = ((document.model.typeData?.columns as string[]) ?? []).map(
    String
  );
  const maxRow = Math.max(...cells.map((cell) => cell.row), 0);
  const maxColumn = Math.max(...cells.map((cell) => cell.column), 0);
  const columnCount = Math.max(columns.length, maxColumn + 1);
  const cellWidth = 58;
  const cellHeight = 42;
  const startX = 86;
  const startY = 66;
  const width = Math.max(440, startX * 2 + columnCount * cellWidth);
  const cellsByKey = new Map(
    cells.map((cell) => [`${cell.row}:${cell.column}`, cell])
  );
  const currentCell =
    cells.find((cell) => cell.state === 'active') ??
    cells.find((cell) => cell.row > 0 && cell.column > 0) ??
    null;
  const referencePoints = Array.isArray(document.model.typeData?.references)
    ? (document.model.typeData.references as { column: number; row: number }[])
    : [];
  const selectedReferenceCells = referencePoints
    .map((point) => cellsByKey.get(`${point.row}:${point.column}`))
    .filter((cell): cell is VisualizationCell => Boolean(cell));
  const fallbackReferenceCells = [
    currentCell && currentCell.row > 0
      ? cellsByKey.get(`${currentCell.row - 1}:${currentCell.column}`)
      : undefined,
    currentCell && currentCell.column > 0
      ? cellsByKey.get(`${currentCell.row}:${currentCell.column - 1}`)
      : undefined,
  ].filter((cell): cell is VisualizationCell => Boolean(cell));
  const previousCells =
    selectedReferenceCells.length > 0 ? selectedReferenceCells : fallbackReferenceCells;
  const tableBottom = startY + (maxRow + 1) * cellHeight;
  const formulaBoxY = tableBottom + 28;
  const currentFormulaY = formulaBoxY + 66;
  const height = Math.max(350, currentFormulaY + 64);
  const getCellRect = React.useCallback(
    (row: number, column: number) => ({
      x: startX + column * cellWidth,
      y: startY + row * cellHeight,
    }),
    [cellWidth, startX, startY]
  );
  // `previousCells` is ordered by click order when the user manually
  // selects reference cells (shift/ctrl-click), not by position -- picking
  // index 0/1 as "top"/"left" used to mislabel the recurrence whenever the
  // left neighbor happened to get clicked before the top one. Look each up
  // by its actual position relative to the current cell instead.
  const topCell = currentCell
    ? previousCells.find(
        (cell) => cell.row === currentCell.row - 1 && cell.column === currentCell.column
      )
    : undefined;
  const leftCell = currentCell
    ? previousCells.find(
        (cell) => cell.row === currentCell.row && cell.column === currentCell.column - 1
      )
    : undefined;
  const customFormula = String(document.model.typeData?.formula ?? '').trim();
  const generatedFormula =
    currentCell && topCell && leftCell
      ? `top dp[${topCell.row}][${topCell.column}]=${topCell.value} + left dp[${leftCell.row}][${leftCell.column}]=${leftCell.value}`
      : 'Select reference cells to show the recurrence relation.';
  const referenceText = customFormula || generatedFormula;
  const formulaTemplate = customFormula || 'dp[i][j] = dp[i-1][j] + dp[i][j-1]';
  const consistency = describeDpConsistency(
    formulaTemplate,
    currentCell,
    previousCells
  );
  const getCellAnchor = React.useCallback(
    (source: VisualizationCell, target: VisualizationCell, outward = false) => {
      const sourceRect = getCellRect(source.row, source.column);
      const targetRect = getCellRect(target.row, target.column);
      const sourceCenter = {
        x: sourceRect.x + cellWidth / 2,
        y: sourceRect.y + cellHeight / 2,
      };
      const targetCenter = {
        x: targetRect.x + cellWidth / 2,
        y: targetRect.y + cellHeight / 2,
      };
      const dx = targetCenter.x - sourceCenter.x;
      const dy = targetCenter.y - sourceCenter.y;
      const padding = outward ? 6 : 8;

      if (Math.abs(dx / cellWidth) > Math.abs(dy / cellHeight)) {
        return {
          x:
            sourceCenter.x +
            (dx >= 0 ? cellWidth / 2 + padding : -cellWidth / 2 - padding),
          y: sourceCenter.y,
        };
      }

      return {
        x: sourceCenter.x,
        y:
          sourceCenter.y +
          (dy >= 0 ? cellHeight / 2 + padding : -cellHeight / 2 - padding),
      };
    },
    [cellHeight, cellWidth, getCellRect]
  );
  const currentText =
    currentCell && topCell && leftCell
      ? `current dp[${currentCell.row}][${currentCell.column}] = ${topCell.value} + ${leftCell.value} = ${currentCell.value}`
      : 'Select the current cell to show the formula.';

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {Array.from({ length: columnCount }).map((_, column) => (
        <text
          key={`column-${column}`}
          x={startX + column * cellWidth + cellWidth / 2}
          y={startY - 18}
          textAnchor="middle"
          className="fill-slate-600 text-[12px] font-semibold"
        >
          {columns[column] ?? column}
        </text>
      ))}
      {Array.from({ length: maxRow + 1 }).map((_, row) => (
        <text
          key={`row-${row}`}
          x={startX - 20}
          y={startY + row * cellHeight + 27}
          textAnchor="middle"
          className="fill-slate-600 text-[12px] font-semibold"
        >
          {row}
        </text>
      ))}
      {Array.from({ length: maxRow + 1 }).flatMap((_, row) =>
        Array.from({ length: columnCount }).map((__, column) => {
          const cell = cellsByKey.get(`${row}:${column}`) as
            | VisualizationCell
            | undefined;
          const active = cell?.state === 'active';
          const referenceIndex = previousCells.findIndex(
            (previousCell) => previousCell.row === row && previousCell.column === column
          );
          const reference = referenceIndex >= 0;
          const x = startX + column * cellWidth;
          const y = startY + row * cellHeight;
          const updateReferences = (event: React.PointerEvent<SVGGElement | SVGRectElement>) => {
            if (
              !(event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) ||
              readOnly ||
              !onCommandChange ||
              !cell ||
              active
            ) {
              return false;
            }

            event.preventDefault();
            event.stopPropagation();
            const alreadySelected = referencePoints.some(
              (point) => point.row === row && point.column === column
            );
            const existing = referencePoints.filter(
              (point) => !(point.row === row && point.column === column)
            );
            const next = alreadySelected
              ? existing
              : [...existing, { row, column }].slice(-4);
            onCommandChange(
              'references',
              next.map((point) => `${point.row}:${point.column}`).join(' ')
            );
            return true;
          };

          return (
            <g
            className={
              !readOnly && (onCellActiveChange || onCellValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            data-visual-cell={`${row}:${column}`}
            data-visual-dp-current-cell={active ? `${row}:${column}` : undefined}
            data-visual-dp-reference-cell={reference ? String(referenceIndex + 1) : undefined}
            key={`${row}-${column}`}
            onPointerDown={(event) => {
              if (updateReferences(event)) return;
              if (readOnly || !onCellActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              onCellActiveChange(row, column);
            }}
          >
              <rect
                fill={active ? '#dbeafe' : reference ? '#fef3c7' : '#ffffff'}
                height={cellHeight}
                rx={6}
                stroke={active ? ACTIVE_STROKE : reference ? WARNING_STROKE : NORMAL_STROKE}
                strokeWidth={active ? 2.4 : 1.4}
                width={cellWidth}
                x={x}
                y={y}
                onPointerDown={(event) => {
                  if (updateReferences(event)) return;
                  if (readOnly || !onCellActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  onCellActiveChange(row, column);
                }}
              />
              <EditableSvgText
                dataVisualTextTarget="dp-cell"
                x={x + cellWidth / 2}
                y={y + 27}
                value={cell?.value}
                readOnly={readOnly || !onCellValueChange || !cell}
                className="fill-slate-950 text-[13px] font-semibold"
                onCommit={(nextValue) => onCellValueChange?.(row, column, nextValue)}
              />
            </g>
          );
        })
      )}
      {currentCell && previousCells.length > 0 && (
        <g data-visual-dp-references>
          {previousCells.map((previousCell, index) => {
            const start = getCellAnchor(previousCell, currentCell, true);
            const end = getCellAnchor(currentCell, previousCell, false);

            return (
              <g key={`dp-reference-${previousCell.row}-${previousCell.column}`}>
                <path
                  d={`M ${start.x} ${start.y} L ${end.x} ${end.y}`}
                  data-visual-dp-reference={index + 1}
                  fill="none"
                  markerEnd="url(#arrow-small)"
                  stroke={WARNING_STROKE}
                  strokeLinecap="round"
                  strokeWidth={1.7}
                />
              </g>
            );
          })}
        </g>
      )}
      <g data-visual-dp-recurrence>
        <rect
          fill="#fff7ed"
          height={54}
          rx={8}
          stroke="#fed7aa"
          strokeWidth={1.2}
          width={Math.min(width - 88, 360)}
          x={44}
          y={formulaBoxY}
        />
        <EditableSvgText
          dataVisualTextTarget="dp-formula"
          className="fill-slate-700 text-[12px] font-semibold"
          textAnchor="middle"
          width={Math.min(width - 112, 336)}
          x={44 + Math.min(width - 88, 360) / 2}
          y={formulaBoxY + 20}
          value={formulaTemplate}
          readOnly={readOnly || !onCommandChange}
          onCommit={(nextValue) => onCommandChange?.('formula', nextValue)}
        />
        <text
          className="fill-amber-900 text-[11px] font-semibold"
          textAnchor="middle"
          x={44 + Math.min(width - 88, 360) / 2}
          y={formulaBoxY + 42}
        >
          {clipFormulaText(consistency.relation)}
        </text>
        <rect
          fill="#dbeafe"
          height={40}
          rx={8}
          stroke={ACTIVE_STROKE}
          strokeWidth={1.5}
          width={Math.min(width - 88, 360)}
          x={44}
          y={currentFormulaY}
        />
        <text
          className="fill-slate-950 text-[12px] font-bold"
          textAnchor="middle"
          x={44 + Math.min(width - 88, 360) / 2}
          y={currentFormulaY + 24}
        >
          {clipFormulaText(consistency.calculation)}
        </text>
      </g>
    </Canvas>
  );
}

function NodeEdgeRenderer({
  allowSelfConnection,
  anchorMode,
  directedEdges,
  document,
  edgeKind = 'straight',
  forceEdgeKind,
  nodeShape,
  nodeSize,
  onCommandChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  readOnly,
}: {
  allowSelfConnection?: boolean;
  anchorMode?: EdgeAnchorMode;
  directedEdges?: boolean;
  document: VisualizationDocument;
  edgeKind?: VisualizationEdge['kind'];
  forceEdgeKind?: VisualizationEdge['kind'];
  nodeShape?: VisualizationShapeKind;
  nodeSize?: { height: number; width: number };
  onCommandChange?: (command: string, value: string) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange?: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange?: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const nodes = (document.model.nodes ?? []).map((node) => ({
    ...node,
    shape: nodeShape ?? node.shape ?? 'rounded-rectangle',
    size: node.size ?? nodeSize,
  }));
  const edges = document.model.edges ?? [];
  const edgePolicy = getVisualizationEditingContract(document.visualType)?.edgePolicy;
  const resolvedDirectedEdges =
    directedEdges ?? edgePolicy?.directed ?? isVisualizationEdgeDirected(document.visualType);
  const resolvedEdgeKind =
    edgeKind ??
    (edgePolicy?.lineStyle === 'orthogonal' ? 'orthogonal' : 'straight');
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const baseBounds = getBounds(nodes, { minX: 0, minY: 0, width: 560, height: 300 });
  const divideConquerMergeBounds =
    document.visualType === 'divide-conquer'
      ? edges
          .filter(isDivideConquerMergeEdge)
          .map((edge) => getDivideConquerMergeGeometry(edge, nodesById))
          .filter((geometry) => geometry !== null)
      : [];
  const bounds = divideConquerMergeBounds.reduce<Bounds>((nextBounds, geometry) => {
    const box = geometry.labelBox;
    const padding = 48;
    const minX = Math.min(nextBounds.minX, box.x - padding);
    const minY = Math.min(nextBounds.minY, box.y - padding);
    const maxX = Math.max(
      nextBounds.minX + nextBounds.width,
      box.x + box.width + padding
    );
    const maxY = Math.max(
      nextBounds.minY + nextBounds.height,
      box.y + box.height + padding
    );

    return {
      minX,
      minY,
      width: maxX - minX,
      height: maxY - minY,
    };
  }, baseBounds);
  const resolvedAnchorMode =
    anchorMode ?? (nodeShape === 'circle' ? 'radial' : 'hierarchical');
  const edgePortOffsets = getEdgePortOffsets(edges, nodesById, resolvedAnchorMode);
  const selfLoopAssignments = getSelfLoopRouteAssignments(
    edges,
    edgePortOffsets.busySidesByNode
  );
  const parallelEdgeOffsets = getParallelEdgeOffsets(edges);
  const edgeGeometryById = new Map(
    edges.map((edge) => [
      edge.id,
      getEdgeGeometry(
        edge,
        nodesById,
        forceEdgeKind ?? edge.kind ?? resolvedEdgeKind,
        resolvedAnchorMode,
        edgePortOffsets.sourceOffsets.get(edge.id) ?? 0,
        edgePortOffsets.targetOffsets.get(edge.id) ?? 0,
        parallelEdgeOffsets.get(edge.id) ?? 0,
        selfLoopAssignments.get(edge.id)
      ),
    ])
  );
  const directEditing = useNodeDirectEditing({
    allowSelfConnection,
    edgeAnchorEditable: isVisualizationEdgeBendable(document.visualType),
    edgeStyleEditable: Boolean(onEdgeDirectionChange || onEdgeLineStyleChange),
    edges,
    nodeMenuConfig: getNodeContextMenuConfig(
      document.visualType,
      nodeShape ?? 'rounded-rectangle'
    ),
    nodes,
    onEdgeCreate,
    onEdgeDelete,
    onEdgeDirectionChange,
    onEdgeLabelChange,
    onEdgeLineStyleChange,
    onNodeCreate,
    onNodeDelete,
    onNodeLabelChange,
    onNodeMove,
    onNodeShapeChange,
    onNodeStateChange,
    readOnly,
  });
  const anchorMenuEdge = directEditing.edgeAnchorMenu
    ? edges.find((edge) => edge.id === directEditing.edgeAnchorMenu?.edgeId)
    : undefined;
  const anchorMenuFromNode = anchorMenuEdge
    ? nodesById.get(anchorMenuEdge.from)
    : undefined;
  const anchorMenuToNode = anchorMenuEdge
    ? nodesById.get(anchorMenuEdge.to)
    : undefined;
  const anchorMenuFromAnchor =
    anchorMenuEdge && anchorMenuFromNode && anchorMenuToNode
      ? anchorMenuEdge.fromAnchor ??
        getAnchorSide(anchorMenuFromNode, anchorMenuToNode, 'source')
      : 'bottom';
  const anchorMenuToAnchor =
    anchorMenuEdge && anchorMenuFromNode && anchorMenuToNode
      ? anchorMenuEdge.toAnchor ??
        getAnchorSide(anchorMenuToNode, anchorMenuFromNode, 'target')
      : 'top';
  const anchorMenuRouteSide = anchorMenuEdge?.routeSide ?? 'auto';
  const anchorMenuRouteOffset = anchorMenuEdge?.routeOffset ?? 72;

  return (
    <>
      <Canvas
        bounds={bounds}
        onContextMenu={directEditing.handleCanvasContextMenu}
        onDoubleClick={directEditing.handleCanvasDoubleClick}
        onKeyDown={directEditing.handleCanvasKeyDown}
        onPointerDown={directEditing.handleCanvasPointerDown}
        onPointerLeave={directEditing.handleSvgPointerLeave}
        onPointerMove={directEditing.handleSvgPointerMove}
        onPointerUp={directEditing.handleSvgPointerUp}
        svgRef={directEditing.svgRef}
      >
        {document.visualType === 'tree' && !readOnly && onCommandChange && (
          <g data-visual-tree-type-options>
            {[
              ['binary', 'Binary'],
              ['b-tree', 'B-Tree'],
              ['b-plus-tree', 'B+Tree'],
              ['avl', 'AVL'],
              ['red-black', 'Red-Black'],
            ].map(([type, label], index) => {
              const active = String(document.model.typeData?.treeType ?? 'binary') === type;
              const x = bounds.minX + 18 + index * 86;

              return (
                <g
                  className="cursor-pointer"
                  key={type}
                  onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    onCommandChange('tree-type', type);
                  }}
                  onPointerDown={(event) => event.stopPropagation()}
                >
                  <rect fill={active ? '#dbeafe' : '#ffffff'} height={28} rx={8} stroke={active ? ACTIVE_STROKE : NORMAL_STROKE} strokeWidth={1.3} width={78} x={x} y={bounds.minY + 14} />
                  <text x={x + 39} y={bounds.minY + 33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
                    {label}
                  </text>
                </g>
              );
            })}
          </g>
        )}
        {edges.map((edge) =>
          document.visualType === 'divide-conquer' &&
          isDivideConquerMergeEdge(edge) ? (
            <DivideConquerMergeEdgeLine
              editable={!readOnly}
              edge={edge}
              key={edge.id}
              nodesById={nodesById}
              selected={directEditing.selectedEdgeId === edge.id}
              onDoubleClick={directEditing.handleEdgeDoubleClick}
              onLabelChange={onEdgeLabelChange}
              onPointerDown={directEditing.handleEdgePointerDown}
            />
          ) : (
            <EdgeLine
              anchorMode={resolvedAnchorMode}
              directed={resolvedDirectedEdges}
              editable={!readOnly}
              edge={{ ...edge, kind: forceEdgeKind ?? edge.kind ?? resolvedEdgeKind }}
              geometry={edgeGeometryById.get(edge.id)}
              key={edge.id}
              nodesById={nodesById}
              selected={directEditing.selectedEdgeId === edge.id}
              onDoubleClick={directEditing.handleEdgeDoubleClick}
              onLabelChange={onEdgeLabelChange}
              onPointerDown={directEditing.handleEdgePointerDown}
            />
          )
        )}
        {nodes.map((node) => (
          <Shape
            editable={!readOnly}
            key={node.id}
            node={node}
            selected={directEditing.selectedNodeId === node.id}
            onContextMenu={directEditing.handleNodeContextMenu}
            onDoubleClick={directEditing.handleNodeDoubleClick}
            onLabelChange={onNodeLabelChange}
            onPointerDown={directEditing.handleNodePointerDown}
          />
        ))}
        {directEditing.connectionPreview && (
          <ConnectionPreview
            from={
              nodesById.get(directEditing.connectionPreview.fromNodeId) ??
              nodes[0]
            }
            point={directEditing.connectionPreview.point}
          />
        )}
      </Canvas>
      {directEditing.contextMenu && (
        <NodeContextMenu
          config={getNodeContextMenuConfig(
            document.visualType,
            nodeShape ?? 'rounded-rectangle'
          )}
          kind={directEditing.contextMenu.kind}
          x={directEditing.contextMenu.x}
          y={directEditing.contextMenu.y}
          onCreateNode={directEditing.createNodeFromContextMenu}
          onDeleteNode={directEditing.deleteSelectedNode}
          onNodeShapeChange={directEditing.changeSelectedNodeShape}
          onSelectedAction={directEditing.runSelectedNodeAction}
        />
      )}
      {directEditing.edgeAnchorMenu && anchorMenuEdge && (
        <EdgeAnchorMenu
          edge={anchorMenuEdge}
          fromAnchor={anchorMenuFromAnchor}
          routeOffset={anchorMenuRouteOffset}
          routeSide={anchorMenuRouteSide}
          toAnchor={anchorMenuToAnchor}
          x={directEditing.edgeAnchorMenu.x}
          y={directEditing.edgeAnchorMenu.y}
          onDirectionChange={onEdgeDirectionChange}
          onLineStyleChange={onEdgeLineStyleChange}
        />
      )}
    </>
  );
}

function isVectorBoundaryEdge(edge: VisualizationEdge) {
  const label = String(edge.label ?? '').trim().toLowerCase();

  return (
    label === 'boundary' ||
    label.includes('boundary') ||
    (edge.direction === 'none' && Boolean(edge.style?.strokeDasharray))
  );
}

function getVectorNormal(
  start: { x: number; y: number },
  end: { x: number; y: number }
) {
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const length = Math.max(1, Math.hypot(dx, dy));

  return {
    x: -dy / length,
    y: dx / length,
  };
}

function getVectorLabelPoint(
  start: { x: number; y: number },
  end: { x: number; y: number },
  ratio = 0.58,
  offset = 24
) {
  const normal = getVectorNormal(start, end);

  return {
    x: start.x + (end.x - start.x) * ratio + normal.x * offset,
    y: start.y + (end.y - start.y) * ratio + normal.y * offset,
  };
}

function VectorSpaceRenderer({
  document,
  onCommandChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeLabelChange,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  readOnly?: boolean;
}) {
  const fallbackOrigin: VisualizationNode = {
    id: 'O',
    label: 'O',
    position: { x: 90, y: 225 },
    shape: 'circle',
  };
  const nodes =
    document.model.nodes && document.model.nodes.length > 0
      ? document.model.nodes
      : [fallbackOrigin];
  const edges = document.model.edges ?? [];
  const origin =
    nodes.find((node) => node.id === 'O') ??
    nodes.find((node) => node.label.toLowerCase() === 'o') ??
    nodes[0] ??
    fallbackOrigin;
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const boundaryEdges = edges.filter(isVectorBoundaryEdge);
  const boundaryNodeIds = new Set(
    boundaryEdges.flatMap((edge) => [edge.from, edge.to])
  );
  const vectorEdges = edges.filter(
    (edge) =>
      !isVectorBoundaryEdge(edge) &&
      edge.from === origin.id &&
      nodesById.has(edge.to)
  );
  const vectorEndpointIds = new Set(vectorEdges.map((edge) => edge.to));
  const loosePoints = nodes.filter(
    (node) =>
      node.id !== origin.id &&
      !boundaryNodeIds.has(node.id) &&
      !vectorEndpointIds.has(node.id)
  );
  const bounds = getBounds(nodes, { minX: 0, minY: 0, width: 600, height: 330 });
  const paddedBounds = {
    minX: Math.min(bounds.minX, origin.position.x - 110),
    minY: Math.min(bounds.minY, origin.position.y - 230),
    width: Math.max(bounds.width + 120, 620),
    height: Math.max(bounds.height + 130, 360),
  };
  const maxX = paddedBounds.minX + paddedBounds.width;
  const maxY = paddedBounds.minY + paddedBounds.height;
  const axisLeft = paddedBounds.minX + 40;
  const axisRight = maxX - 36;
  const axisTop = paddedBounds.minY + 48;
  const axisBottom = maxY - 38;
  const showProjection = document.model.typeData?.projection !== false;
  const markerId = `${document.visualId.replace(/[^a-zA-Z0-9_-]/g, '-')}-vector-arrow`;
  const svgRef = React.useRef<SVGSVGElement>(null);
  const dragRef = React.useRef<
    | {
        kind: 'boundary';
        edge: VisualizationEdge;
        fromStart: { x: number; y: number };
        pointerStart: { x: number; y: number };
        toStart: { x: number; y: number };
      }
    | { kind: 'connect'; fromNodeId: string; point: { x: number; y: number } }
    | { kind: 'node'; nodeId: string }
    | null
  >(null);
  const [selectedNodeId, setSelectedNodeId] = React.useState<string | null>(null);
  const [selectedEdgeId, setSelectedEdgeId] = React.useState<string | null>(null);
  const [connectionPoint, setConnectionPoint] = React.useState<{
    x: number;
    y: number;
  } | null>(null);

  const moveNodeToPoint = React.useCallback(
    (nodeId: string, point: { x: number; y: number }) => {
      onNodeMove?.(nodeId, roundToStep(point.x), roundToStep(point.y));
    },
    [onNodeMove]
  );

  const handleNodePointerDown = React.useCallback(
    (node: VisualizationNode, event: React.PointerEvent<SVGGElement>) => {
      if (readOnly) return;

      event.preventDefault();
      event.stopPropagation();
      setSelectedNodeId(node.id);
      setSelectedEdgeId(null);

      if (event.shiftKey) {
        dragRef.current = {
          fromNodeId: node.id,
          kind: 'connect',
          point: node.position,
        };
        setConnectionPoint(node.position);
      } else {
        dragRef.current = { kind: 'node', nodeId: node.id };
      }

      event.currentTarget.setPointerCapture(event.pointerId);
    },
    [readOnly]
  );

  const handleBoundaryPointerDown = React.useCallback(
    (edge: VisualizationEdge, event: React.PointerEvent<SVGRectElement | SVGPolylineElement>) => {
      if (readOnly) return;

      const from = nodesById.get(edge.from);
      const to = nodesById.get(edge.to);
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!from || !to || !point) return;

      event.preventDefault();
      event.stopPropagation();
      setSelectedEdgeId(edge.id);
      setSelectedNodeId(null);
      dragRef.current = {
        edge,
        fromStart: from.position,
        kind: 'boundary',
        pointerStart: point,
        toStart: to.position,
      };
      event.currentTarget.setPointerCapture(event.pointerId);
    },
    [nodesById, readOnly]
  );

  const handlePointerMove = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      const drag = dragRef.current;
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!drag || !point) return;

      if (drag.kind === 'node') {
        moveNodeToPoint(drag.nodeId, point);
      }

      if (drag.kind === 'connect') {
        setConnectionPoint(point);
      }

      if (drag.kind === 'boundary') {
        const dx = point.x - drag.pointerStart.x;
        const dy = point.y - drag.pointerStart.y;

        onNodeMove?.(
          drag.edge.from,
          roundToStep(drag.fromStart.x + dx),
          roundToStep(drag.fromStart.y + dy)
        );
        onNodeMove?.(
          drag.edge.to,
          roundToStep(drag.toStart.x + dx),
          roundToStep(drag.toStart.y + dy)
        );
      }
    },
    [moveNodeToPoint, onNodeMove]
  );

  const handlePointerUp = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      const drag = dragRef.current;
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (drag?.kind === 'connect' && point) {
        const target = findNodeAtPoint(nodes, point, drag.fromNodeId);

        if (target) {
          onEdgeCreate?.(drag.fromNodeId, target.id);
        }
      }

      dragRef.current = null;
      setConnectionPoint(null);
    },
    [nodes, onEdgeCreate]
  );

  const selectedEdge = edges.find((edge) => edge.id === selectedEdgeId) ?? null;
  const selectedNode = nodes.find((node) => node.id === selectedNodeId) ?? null;
  const selectedMenuPoint = selectedNode
    ? selectedNode.position
    : selectedEdge
      ? getVectorLabelPoint(
          nodesById.get(selectedEdge.from)?.position ?? origin.position,
          nodesById.get(selectedEdge.to)?.position ?? origin.position,
          0.5,
          -32
        )
      : null;

  const renderActionButton = (
    action: string,
    label: string,
    x: number,
    onClick: () => void,
    width = 94
  ) => (
    <g
      className="cursor-pointer"
      data-visual-vector-action={action}
      key={action}
      onPointerDown={(event) => {
        event.preventDefault();
        event.stopPropagation();
        onClick();
      }}
    >
      <rect
        data-visual-control
        fill="#ffffff"
        height={28}
        rx={9}
        stroke="#cbd5e1"
        strokeWidth={1.2}
        width={width}
        x={x}
        y={paddedBounds.minY + 14}
      />
      <text
        className="fill-slate-700 text-[11px] font-bold"
        textAnchor="middle"
        x={x + width / 2}
        y={paddedBounds.minY + 33}
      >
        {label}
      </text>
    </g>
  );

  return (
    <Canvas
      bounds={paddedBounds}
      onKeyDown={(event) => {
        if (readOnly || event.key !== 'Delete') return;

        if (selectedEdge) {
          onEdgeDelete?.(selectedEdge);
        } else if (selectedNode && selectedNode.id !== origin.id) {
          onNodeDelete?.(selectedNode.id);
        }
      }}
      onPointerDown={() => {
        setSelectedEdgeId(null);
        setSelectedNodeId(null);
      }}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      svgRef={svgRef}
    >
      <defs>
        <marker
          id={markerId}
          markerHeight="6"
          markerWidth="7"
          orient="auto"
          refX="6.2"
          refY="3"
        >
          <path d="M0,0 L6,3 L0,6 Z" fill={ACTIVE_STROKE} />
        </marker>
      </defs>


      <g data-visual-vector-axes>
        <line
          data-visual-vector-axis="x"
          markerEnd="url(#arrow-small)"
          stroke="#94a3b8"
          strokeWidth={1.6}
          x1={axisLeft}
          x2={axisRight}
          y1={origin.position.y}
          y2={origin.position.y}
        />
        <line
          data-visual-vector-axis="y"
          markerEnd="url(#arrow-small)"
          stroke="#94a3b8"
          strokeWidth={1.6}
          x1={origin.position.x}
          x2={origin.position.x}
          y1={axisBottom}
          y2={axisTop}
        />
        <text className="fill-slate-500 text-[11px] font-bold" x={axisRight - 8} y={origin.position.y - 10}>
          x
        </text>
        <text className="fill-slate-500 text-[11px] font-bold" x={origin.position.x + 10} y={axisTop + 12}>
          y
        </text>
      </g>

      {boundaryEdges.map((edge) => {
        const from = nodesById.get(edge.from);
        const to = nodesById.get(edge.to);

        if (!from || !to) return null;

        const labelPoint = getVectorLabelPoint(from.position, to.position, 0.52, -26);
        const selected = selectedEdgeId === edge.id;
        const boundaryHitX = Math.min(from.position.x, to.position.x) - 16;
        const boundaryHitY = Math.min(from.position.y, to.position.y) - 16;
        const boundaryHitWidth = Math.abs(to.position.x - from.position.x) + 32;
        const boundaryHitHeight = Math.abs(to.position.y - from.position.y) + 32;

        return (
          <g data-visual-vector-boundary-group key={edge.id}>
            <rect
              data-visual-vector-boundary
              fill="#ffffff"
              height={boundaryHitHeight}
              opacity={0.001}
              pointerEvents="all"
              width={boundaryHitWidth}
              x={boundaryHitX}
              y={boundaryHitY}
              onPointerDown={(event) => handleBoundaryPointerDown(edge, event)}
            />
            <polyline
              fill="none"
              points={`${from.position.x},${from.position.y} ${to.position.x},${to.position.y}`}
              pointerEvents="stroke"
              stroke="transparent"
              strokeLinecap="round"
              strokeWidth={16}
              onPointerDown={(event) => handleBoundaryPointerDown(edge, event)}
            />
            <polyline
              data-visual-edge-id={edge.id}
              fill="none"
              points={`${from.position.x},${from.position.y} ${to.position.x},${to.position.y}`}
              pointerEvents="none"
              stroke={selected ? ACTIVE_STROKE : '#0f766e'}
              strokeDasharray="7 6"
              strokeLinecap="round"
              strokeWidth={selected ? 3 : 2.2}
            />
            <rect
              data-visual-label-plate
              fill="#ffffff"
              height={24}
              rx={7}
              stroke="#cbd5e1"
              width={Math.max(82, String(edge.label ?? 'boundary').length * 7 + 20)}
              x={labelPoint.x - Math.max(82, String(edge.label ?? 'boundary').length * 7 + 20) / 2}
              y={labelPoint.y - 18}
            />
            <EditableSvgText
              dataVisualEdgeLabel
              dataVisualTextTarget="boundary-label"
              className="fill-teal-800 text-[12px] font-bold"
              readOnly={readOnly || !onEdgeLabelChange}
              value={edge.label ?? 'boundary'}
              width={120}
              x={labelPoint.x}
              y={labelPoint.y - 1}
              onCommit={(label) => onEdgeLabelChange?.(edge, label)}
            />
            {[from, to].map((node) => (
              <g
                className={!readOnly ? 'cursor-move' : undefined}
                data-visual-node-id={node.id}
                data-visual-vector-boundary-handle={node.id}
                key={node.id}
                onPointerDown={(event) => handleNodePointerDown(node, event)}
              >
                <rect
                  fill="#f0fdfa"
                  height={14}
                  rx={4}
                  stroke="#0f766e"
                  strokeWidth={1.5}
                  width={14}
                  x={node.position.x - 7}
                  y={node.position.y - 7}
                />
              </g>
            ))}
          </g>
        );
      })}

      {showProjection &&
        vectorEdges.map((edge) => {
          const endpoint = nodesById.get(edge.to);

          if (!endpoint) return null;

          const foot = { x: endpoint.position.x, y: origin.position.y };
          const labelX = endpoint.position.x + 58;
          const labelY = (endpoint.position.y + foot.y) / 2;

          return (
            <g data-visual-vector-projection={edge.id} key={`${edge.id}-projection`}>
              <line
                stroke="#64748b"
                strokeDasharray="5 5"
                strokeLinecap="round"
                strokeWidth={1.6}
                x1={endpoint.position.x}
                x2={foot.x}
                y1={endpoint.position.y}
                y2={foot.y}
              />
              <circle cx={foot.x} cy={foot.y} fill="#ffffff" r={4} stroke="#64748b" strokeWidth={1.3} />
              <rect
                data-visual-label-plate
                fill="#ffffff"
                height={24}
                rx={7}
                stroke="#dbe5f2"
                width={92}
                x={labelX - 46}
                y={labelY - 18}
              />
              <text
                className="fill-slate-600 text-[10px] font-semibold"
                data-visual-edge-label
                textAnchor="middle"
                x={labelX}
                y={labelY - 2}
              >
                projection
              </text>
            </g>
          );
        })}

      {vectorEdges.map((edge) => {
        const endpoint = nodesById.get(edge.to);

        if (!endpoint) return null;

        const selected = selectedEdgeId === edge.id;
        const labelPoint = getVectorLabelPoint(origin.position, endpoint.position);
        const label = edge.label ?? endpoint.label;
        const labelWidth = Math.max(48, String(label).length * 8 + 20);

        return (
          <g data-visual-vector-edge-group={edge.id} key={edge.id}>
            <polyline
              fill="none"
              points={`${origin.position.x},${origin.position.y} ${endpoint.position.x},${endpoint.position.y}`}
              pointerEvents="stroke"
              stroke="transparent"
              strokeLinecap="round"
              strokeWidth={16}
              onPointerDown={(event) => {
                if (readOnly) return;
                event.preventDefault();
                event.stopPropagation();
                setSelectedEdgeId(edge.id);
                setSelectedNodeId(null);
              }}
            />
            <polyline
              data-visual-edge-id={edge.id}
              data-visual-vector-edge={edge.id}
              fill="none"
              markerEnd={`url(#${markerId})`}
              points={`${origin.position.x},${origin.position.y} ${endpoint.position.x},${endpoint.position.y}`}
              pointerEvents="none"
              stroke={selected ? ACTIVE_STROKE : '#2563eb'}
              strokeLinecap="round"
              strokeWidth={selected ? 3.2 : 2.3}
            />
            <rect
              data-visual-label-plate
              fill="#ffffff"
              height={24}
              rx={7}
              stroke="#dbe5f2"
              width={labelWidth}
              x={labelPoint.x - labelWidth / 2}
              y={labelPoint.y - 18}
            />
            <EditableSvgText
              dataVisualEdgeLabel
              dataVisualTextTarget="vector-label"
              className="fill-blue-800 text-[12px] font-bold"
              readOnly={readOnly || !onEdgeLabelChange}
              value={label}
              width={labelWidth + 18}
              x={labelPoint.x}
              y={labelPoint.y - 1}
              onCommit={(nextLabel) => onEdgeLabelChange?.(edge, nextLabel)}
            />
          </g>
        );
      })}

      {[origin, ...vectorEdges.map((edge) => nodesById.get(edge.to)).filter(Boolean), ...loosePoints].map((node) => {
        if (!node) return null;

        const selected = selectedNodeId === node.id;
        const isOrigin = node.id === origin.id;
        const isVectorEndpoint = vectorEndpointIds.has(node.id);
        const labelX = isOrigin ? node.position.x - 24 : node.position.x;
        const labelY = isOrigin ? node.position.y + 30 : node.position.y + 28;
        const coordinateX = isVectorEndpoint
          ? node.position.x + 82
          : node.position.x;
        const coordinateY = isVectorEndpoint
          ? node.position.y - 18
          : node.position.y + 46;
        const coordinateLabel = `(${formatVisualCoordinate(node.position.x - origin.position.x)}, ${formatVisualCoordinate(origin.position.y - node.position.y)})`;

        return (
          <g
            className={!readOnly ? 'cursor-move' : undefined}
            data-visual-node-id={node.id}
            data-visual-vector-origin={isOrigin ? 'true' : undefined}
            key={node.id}
            onPointerDown={(event) => handleNodePointerDown(node, event)}
          >
            {selected && (
              <circle
                fill="none"
                pointerEvents="none"
                r={15}
                stroke={ACTIVE_STROKE}
                strokeDasharray="4 4"
                strokeWidth={1.5}
                cx={node.position.x}
                cy={node.position.y}
              />
            )}
            <circle
              data-visual-vector-endpoint={!isOrigin ? node.id : undefined}
              fill={isOrigin ? '#ffffff' : node.state === 'active' ? '#dbeafe' : '#eff6ff'}
              r={isOrigin ? 7 : 8}
              stroke={isOrigin ? '#64748b' : ACTIVE_STROKE}
              strokeWidth={isOrigin ? 1.6 : 2}
              cx={node.position.x}
              cy={node.position.y}
            />
            {isOrigin || !isVectorEndpoint ? (
              <>
                <rect
                  data-visual-label-plate
                  fill="#ffffff"
                  height={22}
                  rx={7}
                  stroke="#e2e8f0"
                  width={Math.max(36, String(node.label).length * 7 + 18)}
                  x={labelX - Math.max(36, String(node.label).length * 7 + 18) / 2}
                  y={labelY - 17}
                />
                <EditableSvgText
                  dataVisualTextTarget="vector-point-label"
                  className="fill-slate-800 text-[11px] font-bold"
                  readOnly={readOnly || !onNodeLabelChange}
                  value={node.label}
                  width={72}
                  x={labelX}
                  y={labelY}
                  onCommit={(label) => onNodeLabelChange?.(node.id, label)}
                />
              </>
            ) : null}
            {!isOrigin && (
              <g data-visual-vector-coordinate={node.id}>
                <rect
                  data-visual-label-plate
                  fill="#ffffff"
                  height={22}
                  rx={7}
                  stroke="#e2e8f0"
                  width={Math.max(82, coordinateLabel.length * 6.6 + 20)}
                  x={coordinateX - Math.max(82, coordinateLabel.length * 6.6 + 20) / 2}
                  y={coordinateY - 16}
                />
                <text
                  className="fill-slate-500 text-[10px] font-semibold"
                  textAnchor="middle"
                  x={coordinateX}
                  y={coordinateY - 1}
                >
                  {coordinateLabel}
                </text>
              </g>
            )}
          </g>
        );
      })}

      {dragRef.current?.kind === 'connect' && connectionPoint ? (
        <line
          data-visual-connection-preview
          markerEnd={`url(#${markerId})`}
          stroke="#93c5fd"
          strokeDasharray="5 5"
          strokeLinecap="round"
          strokeWidth={2}
          x1={nodesById.get(dragRef.current.fromNodeId)?.position.x ?? origin.position.x}
          x2={connectionPoint.x}
          y1={nodesById.get(dragRef.current.fromNodeId)?.position.y ?? origin.position.y}
          y2={connectionPoint.y}
        />
      ) : null}

      {!readOnly && selectedMenuPoint ? (
        <g data-visual-vector-selection-menu transform={`translate(${selectedMenuPoint.x + 18}, ${selectedMenuPoint.y - 42})`}>
          <rect fill="#ffffff" height={32} rx={10} stroke="#cbd5e1" width={selectedEdge ? 166 : 74} />
          {selectedEdge ? (
            <g
              className="cursor-pointer"
              data-visual-vector-action="projection-toggle-selected"
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onCommandChange?.('projection', showProjection ? 'hide' : 'show');
              }}
            >
              <text className="fill-slate-700 text-[10px] font-bold" x={12} y={20}>
                투영
              </text>
            </g>
          ) : null}
          <g
            className="cursor-pointer"
            data-visual-vector-action="delete-selected"
            transform={`translate(${selectedEdge ? 98 : 10}, 0)`}
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();

              if (selectedEdge) {
                onEdgeDelete?.(selectedEdge);
              } else if (selectedNode && selectedNode.id !== origin.id) {
                onNodeDelete?.(selectedNode.id);
              }
            }}
          >
            <rect fill="#fff1f2" height={22} rx={8} stroke="#fecdd3" width={56} x={0} y={5} />
            <text className="fill-rose-700 text-[10px] font-bold" textAnchor="middle" x={28} y={20}>
              삭제</text>
          </g>
        </g>
      ) : null}
    </Canvas>
  );
}

type NeuralLayerKind = 'hidden' | 'input' | 'output';

type NeuralLayer = {
  hiddenIndex?: number;
  key: string;
  kind: NeuralLayerKind;
  nodes: VisualizationNode[];
  title: string;
  x: number;
};

function getNeuralLayerKind(node: VisualizationNode): NeuralLayerKind {
  const id = node.id.toLowerCase();
  const label = String(node.label ?? '').toLowerCase();

  if (id.startsWith('i') || label.includes('input') || label.startsWith('x')) {
    return 'input';
  }

  if (id.startsWith('o') || label.includes('output') || /^y\d*$/i.test(label)) {
    return 'output';
  }

  return 'hidden';
}

function buildNeuralLayers(nodes: VisualizationNode[]): NeuralLayer[] {
  const inputNodes = nodes
    .filter((node) => getNeuralLayerKind(node) === 'input')
    .sort((first, second) => first.position.y - second.position.y);
  const outputNodes = nodes
    .filter((node) => getNeuralLayerKind(node) === 'output')
    .sort((first, second) => first.position.y - second.position.y);
  // Sorting by x first, y second is a strict total order (unlike the old
  // "compare by x only when far apart, else by y" rule, which could disagree
  // with itself across three or more nodes and leave the sort result
  // dependent on the engine's merge order). Grouping into columns below uses
  // this same threshold and anchors each column to its FIRST member's x
  // (not a running average, which drifts as a column fills up and could
  // silently absorb a visually-distinct next column).
  const HIDDEN_COLUMN_THRESHOLD = 80;
  const hiddenNodes = nodes
    .filter((node) => getNeuralLayerKind(node) === 'hidden')
    .sort(
      (first, second) =>
        first.position.x - second.position.x || first.position.y - second.position.y
    );
  const hiddenGroups: VisualizationNode[][] = [];

  for (const node of hiddenNodes) {
    const lastGroup = hiddenGroups[hiddenGroups.length - 1];
    const groupAnchorX = lastGroup ? lastGroup[0].position.x : null;

    if (
      !lastGroup ||
      groupAnchorX === null ||
      Math.abs(node.position.x - groupAnchorX) > HIDDEN_COLUMN_THRESHOLD
    ) {
      hiddenGroups.push([node]);
    } else {
      lastGroup.push(node);
    }
  }

  const layers: NeuralLayer[] = [];

  if (inputNodes.length > 0) {
    layers.push({
      key: 'input',
      kind: 'input',
      nodes: inputNodes,
      title: 'Input layer',
      x: inputNodes.reduce((sum, node) => sum + node.position.x, 0) / inputNodes.length,
    });
  }

  hiddenGroups.forEach((group, index) => {
    group.sort((first, second) => first.position.y - second.position.y);
    layers.push({
      hiddenIndex: index + 1,
      key: `hidden-${index + 1}`,
      kind: 'hidden',
      nodes: group,
      title: hiddenGroups.length > 1 ? `Hidden layer ${index + 1}` : 'Hidden layer',
      x: group.reduce((sum, node) => sum + node.position.x, 0) / group.length,
    });
  });

  if (outputNodes.length > 0) {
    layers.push({
      key: 'output',
      kind: 'output',
      nodes: outputNodes,
      title: 'Output layer',
      x: outputNodes.reduce((sum, node) => sum + node.position.x, 0) / outputNodes.length,
    });
  }

  return layers;
}

function getNeuralBoundaryPoint(
  from: VisualizationNode,
  to: VisualizationNode,
  radius = 25
) {
  const dx = to.position.x - from.position.x;
  const dy = to.position.y - from.position.y;
  const length = Math.max(1, Math.hypot(dx, dy));

  return {
    x: from.position.x + (dx / length) * radius,
    y: from.position.y + (dy / length) * radius,
  };
}

function getNeuralLabelPoint(
  from: VisualizationNode,
  to: VisualizationNode,
  edgeIndex: number
) {
  const start = getNeuralBoundaryPoint(from, to);
  const end = getNeuralBoundaryPoint(to, from);
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const length = Math.max(1, Math.hypot(dx, dy));
  const normal = {
    x: -dy / length,
    y: dx / length,
  };
  const offset = edgeIndex % 2 === 0 ? 15 : -15;

  return {
    x: start.x + dx * 0.54 + normal.x * offset,
    y: start.y + dy * 0.54 + normal.y * offset,
  };
}

function NeuralNetworkRenderer({
  document,
  onCommandChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeLabelChange,
  onEdgeStateChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeStateChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange?: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeStateChange?: (
    edge: VisualizationEdge,
    state: VisualizationElementState
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const nodes = (document.model.nodes ?? []).map((node) => ({
    ...node,
    shape: 'circle' as const,
    size: node.size ?? { height: 58, width: 58 },
  }));
  const edges = document.model.edges ?? [];
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const layers = buildNeuralLayers(nodes);
  const hiddenLayers = layers.filter((layer) => layer.kind === 'hidden');
  const bounds = getBounds(nodes, { minX: 0, minY: 0, width: 640, height: 360 });
  const paddedBounds = {
    minX: Math.min(bounds.minX, -10),
    minY: Math.min(bounds.minY, -18),
    width: Math.max(bounds.width + 60, 680),
    height: Math.max(bounds.height + 96, 410),
  };
  const svgRef = React.useRef<SVGSVGElement>(null);
  const dragRef = React.useRef<
    | { kind: 'connect'; fromNodeId: string; point: { x: number; y: number } }
    | { kind: 'node'; nodeId: string }
    | null
  >(null);
  const [connectionPoint, setConnectionPoint] = React.useState<{
    x: number;
    y: number;
  } | null>(null);
  const [selectedEdgeId, setSelectedEdgeId] = React.useState<string | null>(null);
  const [selectedNodeId, setSelectedNodeId] = React.useState<string | null>(null);
  const markerBaseId = document.visualId.replace(/[^a-zA-Z0-9_-]/g, '-');
  const activeMarkerId = `${markerBaseId}-neural-arrow-active`;
  const mutedMarkerId = `${markerBaseId}-neural-arrow-muted`;
  const backpropMarkerId = `${markerBaseId}-neural-arrow-backprop`;

  const createNeuron = React.useCallback(
    (kind: NeuralLayerKind) => {
      const selectedLayer = selectedNodeId
        ? layers.find((layer) =>
            layer.nodes.some((node) => node.id === selectedNodeId)
          )
        : null;
      const targetHiddenLayer =
        kind === 'hidden' && selectedLayer?.kind === 'hidden'
          ? selectedLayer
          : hiddenLayers[hiddenLayers.length - 1] ?? null;
      const layer =
        kind === 'hidden'
          ? targetHiddenLayer
          : layers.find((currentLayer) => currentLayer.kind === kind);
      const baseX =
        kind === 'input'
          ? (layer?.x ?? 95)
          : kind === 'output'
            ? (layer?.x ?? 445)
            : (targetHiddenLayer?.x ?? 270);
      const index = (layer?.nodes.length ?? 0) + 1;
      const label =
        kind === 'input'
          ? `x${index}`
          : kind === 'output'
            ? `y${index}`
            : targetHiddenLayer?.hiddenIndex && hiddenLayers.length > 1
              ? `h${targetHiddenLayer.hiddenIndex}.${index}`
              : `h${index}`;

      onNodeCreate?.({
        label,
        shape: 'circle',
        x: baseX,
        y: 88 + index * 64,
      });
    },
    [hiddenLayers, layers, onNodeCreate, selectedNodeId]
  );

  const handleNodePointerDown = React.useCallback(
    (node: VisualizationNode, event: React.PointerEvent<SVGGElement>) => {
      if (readOnly) return;

      event.preventDefault();
      event.stopPropagation();
      setSelectedEdgeId(null);
      setSelectedNodeId(node.id);

      if (event.shiftKey) {
        dragRef.current = {
          fromNodeId: node.id,
          kind: 'connect',
          point: node.position,
        };
        setConnectionPoint(node.position);
      } else {
        dragRef.current = { kind: 'node', nodeId: node.id };
      }

      event.currentTarget.setPointerCapture(event.pointerId);
    },
    [readOnly]
  );

  const handlePointerMove = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      const drag = dragRef.current;
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (!drag || !point) return;

      if (drag.kind === 'node') {
        onNodeMove?.(drag.nodeId, roundToStep(point.x), roundToStep(point.y));
      }

      if (drag.kind === 'connect') {
        setConnectionPoint(point);
      }
    },
    [onNodeMove]
  );

  const handlePointerUp = React.useCallback(
    (event: React.PointerEvent<SVGSVGElement>) => {
      const drag = dragRef.current;
      const point = getSvgPoint(svgRef.current, event.clientX, event.clientY);

      if (drag?.kind === 'connect' && point) {
        const target = findNodeAtPoint(nodes, point, drag.fromNodeId);

        if (target) {
          onEdgeCreate?.(drag.fromNodeId, target.id);
        }
      }

      dragRef.current = null;
      setConnectionPoint(null);
    },
    [nodes, onEdgeCreate]
  );

  const selectedNode = nodes.find((node) => node.id === selectedNodeId) ?? null;
  const selectedEdge = edges.find((edge) => edge.id === selectedEdgeId) ?? null;
  const selectedEdgeFrom = selectedEdge ? nodesById.get(selectedEdge.from) : null;
  const selectedEdgeTo = selectedEdge ? nodesById.get(selectedEdge.to) : null;
  const selectedEdgeMenuPoint =
    selectedEdge && selectedEdgeFrom && selectedEdgeTo
      ? getNeuralLabelPoint(selectedEdgeFrom, selectedEdgeTo, 0)
      : null;
  const selectEdge = React.useCallback(
    (edge: VisualizationEdge) => {
      if (readOnly) return;

      setSelectedEdgeId(edge.id);
      setSelectedNodeId(null);
    },
    [readOnly]
  );

  const renderActionButton = (
    action: string,
    label: string,
    x: number,
    width: number,
    onPointerDown: () => void
  ) => (
    <g
      className="cursor-pointer"
      data-visual-neural-action={action}
      key={action}
      onPointerDown={(event) => {
        event.preventDefault();
        event.stopPropagation();
        onPointerDown();
      }}
    >
      <rect
        data-visual-control
        fill="#ffffff"
        height={28}
        rx={9}
        stroke="#cbd5e1"
        strokeWidth={1.2}
        width={width}
        x={x}
        y={paddedBounds.minY + 14}
      />
      <text
        className="fill-slate-700 text-[11px] font-bold"
        textAnchor="middle"
        x={x + width / 2}
        y={paddedBounds.minY + 33}
      >
        {label}
      </text>
    </g>
  );

  return (
    <Canvas
      bounds={paddedBounds}
      onKeyDown={(event) => {
        if (readOnly || event.key !== 'Delete') return;

        if (selectedEdge) {
          onEdgeDelete?.(selectedEdge);
        } else if (selectedNode) {
          onNodeDelete?.(selectedNode.id);
        }
      }}
      onPointerDown={() => {
        setSelectedEdgeId(null);
        setSelectedNodeId(null);
      }}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      svgRef={svgRef}
    >
      <defs>
        <marker
          id={activeMarkerId}
          markerHeight="5"
          markerWidth="6"
          orient="auto"
          refX="5.4"
          refY="2.5"
        >
          <path d="M0,0 L5.5,2.5 L0,5 Z" fill={ACTIVE_STROKE} />
        </marker>
        <marker
          id={mutedMarkerId}
          markerHeight="5"
          markerWidth="6"
          orient="auto"
          refX="5.4"
          refY="2.5"
        >
          <path d="M0,0 L5.5,2.5 L0,5 Z" fill="#7890ad" />
        </marker>
        <marker
          id={backpropMarkerId}
          markerHeight="5"
          markerWidth="6"
          orient="auto"
          refX="5.4"
          refY="2.5"
        >
          <path d="M0,0 L5.5,2.5 L0,5 Z" fill="#a855f7" />
        </marker>
      </defs>

      {!readOnly && (
        <g data-visual-neural-menu>
          {renderActionButton('add-input-neuron', 'Add input', paddedBounds.minX + 14, 88, () =>
            createNeuron('input')
          )}
          {renderActionButton('add-hidden-neuron', 'Add hidden', paddedBounds.minX + 110, 98, () =>
            createNeuron('hidden')
          )}
          {renderActionButton('add-output-neuron', 'Add output', paddedBounds.minX + 216, 98, () =>
            createNeuron('output')
          )}
          {renderActionButton('add-hidden-layer', 'Add layer', paddedBounds.minX + 322, 86, () =>
            onCommandChange?.('neural-add-hidden-layer', '')
          )}
          {renderActionButton('align-layers', 'Align', paddedBounds.minX + 416, 64, () =>
            onCommandChange?.('neural-align', '')
          )}
        </g>
      )}

      <g data-visual-neural-layers>
        {layers.map((layer) => {
          if (layer.nodes.length === 0) return null;

          const minX = Math.min(...layer.nodes.map((node) => node.position.x)) - 46;
          const maxX = Math.max(...layer.nodes.map((node) => node.position.x)) + 46;
          const minY = Math.min(...layer.nodes.map((node) => node.position.y)) - 58;
          const maxY = Math.max(...layer.nodes.map((node) => node.position.y)) + 76;
          const centerX = (minX + maxX) / 2;

          return (
            <g data-visual-neural-layer={layer.key} key={layer.key}>
              <rect
                data-visual-control
                fill="#f8fafc"
                height={maxY - minY}
                opacity={0.62}
                rx={18}
                stroke="#dbe5f2"
                strokeDasharray="6 6"
                width={maxX - minX}
                x={minX}
                y={minY}
              />
              <rect
                data-visual-label-plate
                fill="#ffffff"
                height={24}
                rx={8}
                stroke="#dbe5f2"
                width={104}
                x={centerX - 52}
                y={maxY - 23}
              />
              <text
                className="fill-slate-600 text-[10px] font-bold"
                textAnchor="middle"
                x={centerX}
                y={maxY - 7}
              >
                {layer.title}
              </text>
            </g>
          );
        })}
      </g>

      <g data-visual-neural-edges>
        {edges.map((edge, index) => {
          const from = nodesById.get(edge.from);
          const to = nodesById.get(edge.to);

          if (!from || !to) return null;

          const start = getNeuralBoundaryPoint(from, to);
          const end = getNeuralBoundaryPoint(to, from);
          const active = edge.state === 'active';
          const selected = selectedEdgeId === edge.id;
          const label = edge.label ?? edge.weight ?? '';
          const backprop = edge.direction === 'backward';
          const both = edge.direction === 'both';
          const directed = edge.direction !== 'none';
          const strokeColor = backprop
            ? '#a855f7'
            : active || selected
              ? ACTIVE_STROKE
              : '#7890ad';
          const arrowMarker = backprop
            ? backpropMarkerId
            : active || selected
              ? activeMarkerId
              : mutedMarkerId;
          const shouldShowLabel = Boolean(label) || selected;
          const displayLabel = String(label || (selected ? 'weight' : ''));
          const labelPoint = getNeuralLabelPoint(from, to, index);
          const labelWidth = Math.max(52, displayLabel.length * 7 + 18);

          return (
            <g data-visual-neural-edge-group={edge.id} key={edge.id}>
              <line
                x1={start.x}
                x2={end.x}
                y1={start.y}
                y2={end.y}
                data-visual-edge-hit-target={edge.id}
                pointerEvents="stroke"
                stroke="transparent"
                strokeLinecap="round"
                strokeWidth={24}
                style={{ pointerEvents: 'all' }}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  selectEdge(edge);
                }}
                onPointerDown={(event) => {
                  if (readOnly) return;

                  event.preventDefault();
                  event.stopPropagation();
                  selectEdge(edge);
                }}
              />
              <line
                data-visual-edge-id={edge.id}
                data-visual-neural-edge={edge.id}
                x1={start.x}
                x2={end.x}
                y1={start.y}
                y2={end.y}
                markerEnd={directed && edge.direction !== 'backward' ? `url(#${arrowMarker})` : undefined}
                markerStart={backprop || both ? `url(#${arrowMarker})` : undefined}
                pointerEvents="none"
                stroke={strokeColor}
                strokeDasharray={backprop ? '5 5' : undefined}
                strokeLinecap="round"
                strokeOpacity={active || selected ? 0.95 : 0.54}
                strokeWidth={active || selected ? 2.7 : 1.45}
              />
              {shouldShowLabel ? (
                <g
                  data-visual-neural-weight={edge.id}
                  onPointerDownCapture={(event) => {
                    if (readOnly) return;

                    event.stopPropagation();
                    selectEdge(edge);
                  }}
                >
                  <rect
                    data-visual-label-plate
                    fill="#ffffff"
                    height={22}
                    rx={7}
                    stroke="#bfdbfe"
                    width={labelWidth}
                    x={labelPoint.x - labelWidth / 2}
                    y={labelPoint.y - 16}
                  />
                  <EditableSvgText
                    dataVisualEdgeLabel
                    dataVisualTextTarget="neural-weight-label"
                    className="fill-blue-800 text-[10px] font-bold"
                    readOnly={readOnly || !onEdgeLabelChange}
                    value={displayLabel}
                    width={labelWidth + 18}
                    x={labelPoint.x}
                    y={labelPoint.y}
                    onCommit={(nextLabel) => onEdgeLabelChange?.(edge, nextLabel)}
                  />
                </g>
              ) : null}
            </g>
          );
        })}
      </g>

      <g data-visual-neural-neurons>
        {nodes.map((node) => {
          const selected = selectedNodeId === node.id;
          const active = node.state === 'active';
          const layerKind = getNeuralLayerKind(node);

          return (
            <g
              className={!readOnly ? 'cursor-move' : undefined}
              data-visual-neural-layer-kind={layerKind}
              data-visual-neural-neuron={node.id}
              data-visual-node-id={node.id}
              key={node.id}
              onPointerDown={(event) => handleNodePointerDown(node, event)}
            >
              {selected && (
                <circle
                  data-visual-control
                  fill="none"
                  r={31}
                  stroke={ACTIVE_STROKE}
                  strokeDasharray="4 4"
                  strokeWidth={1.5}
                  cx={node.position.x}
                  cy={node.position.y}
                />
              )}
              {active && (
                <circle
                  data-visual-control
                  fill="#dbeafe"
                  opacity={0.72}
                  r={31}
                  cx={node.position.x}
                  cy={node.position.y}
                />
              )}
              <circle
                fill={
                  active
                    ? '#dbeafe'
                    : layerKind === 'input'
                      ? '#ecfeff'
                      : layerKind === 'output'
                        ? '#fefce8'
                        : '#ffffff'
                }
                r={24}
                stroke={active || selected ? ACTIVE_STROKE : '#8aa1bf'}
                strokeWidth={active || selected ? 2.5 : 1.7}
                cx={node.position.x}
                cy={node.position.y}
              />
              <EditableSvgText
                dataVisualTextTarget="neural-node-label"
                className="fill-slate-900 text-[10px] font-bold"
                readOnly={readOnly || !onNodeLabelChange}
                value={node.label}
                width={54}
                x={node.position.x}
                y={node.position.y + 4}
                onCommit={(label) => onNodeLabelChange?.(node.id, label)}
              />
            </g>
          );
        })}
      </g>

      {dragRef.current?.kind === 'connect' && connectionPoint ? (
        <line
          data-visual-connection-preview
          stroke="#93c5fd"
          strokeDasharray="5 5"
          strokeLinecap="round"
          strokeWidth={2}
          x1={nodesById.get(dragRef.current.fromNodeId)?.position.x ?? 0}
          x2={connectionPoint.x}
          y1={nodesById.get(dragRef.current.fromNodeId)?.position.y ?? 0}
          y2={connectionPoint.y}
        />
      ) : null}

      {!readOnly && selectedNode ? (
        <g
          data-visual-neural-selection-menu
          transform={`translate(${selectedNode.position.x + 28}, ${selectedNode.position.y - 42})`}
        >
          <rect data-visual-control fill="#ffffff" height={32} rx={10} stroke="#cbd5e1" width={128} />
          <g
            className="cursor-pointer"
            data-visual-neural-action="toggle-active-node"
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onNodeStateChange?.(
                selectedNode.id,
                selectedNode.state === 'active' ? 'normal' : 'active'
              );
            }}
          >
            <text className="fill-blue-700 text-[10px] font-bold" x={12} y={20}>
              Active
            </text>
          </g>
          <g
            className="cursor-pointer"
            data-visual-neural-action="delete-node"
            transform="translate(70, 0)"
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onNodeDelete?.(selectedNode.id);
            }}
          >
            <rect fill="#fff1f2" height={22} rx={8} stroke="#fecdd3" width={48} x={0} y={5} />
            <text className="fill-rose-700 text-[10px] font-bold" textAnchor="middle" x={24} y={20}>
              Delete</text>
          </g>
        </g>
      ) : null}

      {!readOnly && selectedEdge && selectedEdgeMenuPoint ? (
        <g
          data-visual-neural-selection-menu
          transform={`translate(${selectedEdgeMenuPoint.x + 28}, ${selectedEdgeMenuPoint.y - 34})`}
        >
          <rect data-visual-control fill="#ffffff" height={62} rx={10} stroke="#cbd5e1" width={262} />
          {[
            ['forward', 'Forward'],
            ['backward', 'Backprop'],
            ['both', 'Both'],
            ['none', 'No arrow'],
          ].map(([direction, label], index) => (
            <g
              className="cursor-pointer"
              data-visual-neural-action={`edge-direction-${direction}`}
              key={direction}
              transform={`translate(${8 + index * 62}, 7)`}
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onEdgeDirectionChange?.(
                  selectedEdge,
                  direction as VisualizationDirection
                );
              }}
            >
              <rect
                fill={selectedEdge.direction === direction ? '#dbeafe' : '#ffffff'}
                height={22}
                rx={7}
                stroke={selectedEdge.direction === direction ? ACTIVE_STROKE : '#cbd5e1'}
                width={56}
              />
              <text className="fill-slate-700 text-[9px] font-bold" textAnchor="middle" x={28} y={15}>
                {label}
              </text>
            </g>
          ))}
          <g
            className="cursor-pointer"
            data-visual-neural-action="toggle-active-weight"
            transform="translate(8, 35)"
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onEdgeStateChange?.(
                selectedEdge,
                selectedEdge.state === 'active' ? 'normal' : 'active'
              );
            }}
          >
            <rect
              fill={selectedEdge.state === 'active' ? '#dbeafe' : '#ffffff'}
              height={22}
              rx={8}
              stroke={selectedEdge.state === 'active' ? ACTIVE_STROKE : '#cbd5e1'}
              width={72}
            />
            <text className="fill-blue-700 text-[10px] font-bold" textAnchor="middle" x={36} y={15}>
              Active path
            </text>
          </g>
          <g
            className="cursor-pointer"
            data-visual-neural-action="ensure-weight-label"
            transform="translate(86, 35)"
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onEdgeLabelChange?.(selectedEdge, String(selectedEdge.label ?? selectedEdge.weight ?? '0.00'));
            }}
          >
            <rect fill="#ffffff" height={22} rx={8} stroke="#bfdbfe" width={58} />
            <text className="fill-blue-700 text-[10px] font-bold" textAnchor="middle" x={29} y={15}>
              Weight
            </text>
          </g>
          <g
            className="cursor-pointer"
            data-visual-neural-action="delete-weight"
            transform="translate(150, 35)"
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onEdgeDelete?.(selectedEdge);
            }}
          >
            <rect fill="#fff1f2" height={22} rx={8} stroke="#fecdd3" width={54} />
            <text className="fill-rose-700 text-[10px] font-bold" textAnchor="middle" x={27} y={15}>
              Delete</text>
          </g>
        </g>
      ) : null}
    </Canvas>
  );
}

function GeometryPlaneRenderer({
  document,
  onCommandChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeLabelChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeStateChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const nodes = (document.model.nodes ?? []).map((node) => ({
    ...node,
    shape: 'circle' as const,
    size: node.size ?? { height: 24, width: 24 },
  }));
  const edges = document.model.edges ?? [];
  const baseBounds = getBounds(nodes, { minX: 0, minY: 0, width: 640, height: 420 });
  const bounds = {
    minX: Math.min(baseBounds.minX, 0),
    minY: Math.min(baseBounds.minY, 0),
    width: Math.max(baseBounds.width, 640),
    height: Math.max(baseBounds.height, 420),
  };
  const origin = { x: 320, y: 260 };
  const unit = 32;
  const showHull = document.model.typeData?.hull !== false;
  const hullNodes = showHull ? getConvexHullNodes(nodes) : [];
  const hullPoints = hullNodes.map((node) => node.position);
  const hullLabelPoint =
    hullPoints.length > 0
      ? {
          x: Math.min(
            bounds.minX + bounds.width - 72,
            Math.max(...hullPoints.map((point) => point.x)) + 64
          ),
          y:
            Math.max(
              bounds.minY + 42,
              Math.min(...hullPoints.map((point) => point.y)) + 12
            ),
        }
      : null;
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const xTickStart = Math.ceil((bounds.minX - origin.x) / unit);
  const xTickEnd = Math.floor((bounds.minX + bounds.width - origin.x) / unit);
  const yTickStart = Math.ceil((origin.y - (bounds.minY + bounds.height)) / unit);
  const yTickEnd = Math.floor((origin.y - bounds.minY) / unit);
  const xTicks = Array.from(
    { length: Math.min(61, Math.max(0, xTickEnd - xTickStart + 1)) },
    (_, index) => xTickStart + index
  );
  const yTicks = Array.from(
    { length: Math.min(61, Math.max(0, yTickEnd - yTickStart + 1)) },
    (_, index) => yTickStart + index
  );
  const directEditing = useNodeDirectEditing({
    edgeAnchorEditable: false,
    edges,
    nodeMenuConfig: getNodeContextMenuConfig(document.visualType, 'circle'),
    nodes,
    onEdgeCreate,
    onEdgeDelete,
    onEdgeLabelChange,
    onNodeCreate,
    onNodeDelete,
    onNodeLabelChange,
    onNodeMove,
    onNodeStateChange,
    readOnly,
  });

  return (
    <>
      <Canvas
        bounds={bounds}
        onContextMenu={directEditing.handleCanvasContextMenu}
        onDoubleClick={directEditing.handleCanvasDoubleClick}
        onKeyDown={directEditing.handleCanvasKeyDown}
        onPointerDown={directEditing.handleCanvasPointerDown}
        onPointerLeave={directEditing.handleSvgPointerLeave}
        onPointerMove={directEditing.handleSvgPointerMove}
        onPointerUp={directEditing.handleSvgPointerUp}
        svgRef={directEditing.svgRef}
      >
        <g data-visual-geometry-plane>
          <line
            data-visual-geometry-axis="x"
            x1={bounds.minX + 24}
            x2={bounds.minX + bounds.width - 24}
            y1={origin.y}
            y2={origin.y}
            stroke="#475569"
            strokeWidth={1.4}
          />
          <line
            data-visual-geometry-axis="y"
            x1={origin.x}
            x2={origin.x}
            y1={bounds.minY + 24}
            y2={bounds.minY + bounds.height - 24}
            stroke="#475569"
            strokeWidth={1.4}
          />
          {xTicks.map((tick) => {
            const x = origin.x + tick * unit;

            return (
              <g key={`x-${tick}`}>
                <line x1={x} x2={x} y1={origin.y - 5} y2={origin.y + 5} stroke="#64748b" strokeWidth={1} />
                {tick !== 0 && tick % 2 === 0 && (
                  <text x={x} y={origin.y + 19} textAnchor="middle" className="fill-slate-500 text-[10px] font-medium">
                    {tick}
                  </text>
                )}
              </g>
            );
          })}
          {yTicks.map((tick) => {
            const y = origin.y - tick * unit;

            return (
              <g key={`y-${tick}`}>
                <line x1={origin.x - 5} x2={origin.x + 5} y1={y} y2={y} stroke="#64748b" strokeWidth={1} />
                {tick !== 0 && tick % 2 === 0 && (
                  <text x={origin.x - 10} y={y + 4} textAnchor="end" className="fill-slate-500 text-[10px] font-medium">
                    {tick}
                  </text>
                )}
              </g>
            );
          })}
          <text x={bounds.minX + bounds.width - 32} y={origin.y - 10} textAnchor="middle" className="fill-slate-600 text-[11px] font-semibold">
            x
          </text>
          <text x={origin.x + 12} y={bounds.minY + 34} textAnchor="start" className="fill-slate-600 text-[11px] font-semibold">
            y
          </text>
          <text x={origin.x - 8} y={origin.y + 18} textAnchor="end" className="fill-slate-600 text-[10px] font-semibold">
            0
          </text>
        </g>
        {hullPoints.length >= 3 && (
          <g data-visual-geometry-hull>
            <polygon
              data-visual-control
              fill="#bfdbfe"
              fillOpacity={0.36}
              points={toPolylinePoints(hullPoints)}
              stroke="#2563eb"
              strokeLinejoin="round"
              strokeWidth={2.2}
            />
            {hullLabelPoint && (
              <g pointerEvents="none">
                <rect
                  data-visual-label-plate
                  fill="#ffffff"
                  height={24}
                  rx={8}
                  stroke="#bfdbfe"
                  width={96}
                  x={hullLabelPoint.x - 48}
                  y={hullLabelPoint.y - 12}
                />
                <text
                  x={hullLabelPoint.x}
                  y={hullLabelPoint.y + 4}
                  textAnchor="middle"
                  className="fill-blue-700 text-[11px] font-semibold"
                >
                  convex hull
                </text>
              </g>
            )}
          </g>
        )}
        {edges.map((edge) => (
          <EdgeLine
            anchorMode="radial"
            directed={false}
            editable={!readOnly}
            edge={{ ...edge, direction: 'none', kind: 'straight' }}
            key={edge.id}
            nodesById={nodesById}
            selected={directEditing.selectedEdgeId === edge.id}
            onDoubleClick={directEditing.handleEdgeDoubleClick}
            onLabelChange={onEdgeLabelChange}
            onPointerDown={directEditing.handleEdgePointerDown}
          />
        ))}
        {nodes.map((node) => {
          const selected = directEditing.selectedNodeId === node.id;
          const stroke = selected || node.state === 'active' ? ACTIVE_STROKE : getStroke(node.state);
          const fill =
            node.state === 'muted'
              ? '#f8fafc'
              : node.state === 'warning'
                ? '#fff7ed'
                : selected || node.state === 'active'
                  ? '#dbeafe'
                  : '#ffffff';

          return (
            <g
              className={!readOnly && onNodeMove ? 'cursor-move' : undefined}
              data-visual-geometry-point={node.id}
              key={node.id}
              onContextMenu={(event) => directEditing.handleNodeContextMenu(node, event)}
              onDoubleClick={(event) => directEditing.handleNodeDoubleClick(node, event)}
            >
              <circle
                cx={node.position.x}
                cy={node.position.y}
                data-visual-node-id={node.id}
                fill={fill}
                r={selected ? 12 : 10}
                stroke={stroke}
                strokeWidth={selected ? 2.8 : 2}
                onPointerDown={(event) =>
                  directEditing.handleNodePointerDown(
                    node,
                    event as unknown as React.PointerEvent<SVGGElement>
                  )
                }
              />
              <EditableSvgText
                dataVisualTextTarget="geometry-point-label"
                x={node.position.x}
                y={node.position.y - 18}
                value={node.label}
                width={72}
                readOnly={readOnly || !onNodeLabelChange}
                className="fill-slate-900 text-[12px] font-semibold"
                onCommit={(label) => onNodeLabelChange?.(node.id, label)}
              />
            </g>
          );
        })}
        {!readOnly && onCommandChange && (
          <g
            className="cursor-pointer"
            data-visual-geometry-hull-toggle
            onClick={(event) => {
              event.preventDefault();
              event.stopPropagation();
            }}
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onCommandChange('hull', showHull ? 'hide' : 'show');
            }}
          >
            <rect
              fill="#ffffff"
              height={30}
              rx={9}
              stroke={showHull ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={1.3}
              width={112}
              x={bounds.minX + 16}
              y={bounds.minY + 16}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
              }}
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onCommandChange('hull', showHull ? 'hide' : 'show');
              }}
            />
            <text
              x={bounds.minX + 72}
              y={bounds.minY + 36}
              textAnchor="middle"
              className="fill-slate-700 text-[11px] font-semibold"
            >
              {showHull ? 'Convex hull 숨기기' : 'Convex hull 보이기'}
            </text>
          </g>
        )}
        {directEditing.connectionPreview && (
          <ConnectionPreview
            from={
              nodesById.get(directEditing.connectionPreview.fromNodeId) ??
              nodes[0]
            }
            point={directEditing.connectionPreview.point}
          />
        )}
      </Canvas>
      {directEditing.contextMenu && (
        <NodeContextMenu
          config={getNodeContextMenuConfig(document.visualType, 'circle')}
          kind={directEditing.contextMenu.kind}
          x={directEditing.contextMenu.x}
          y={directEditing.contextMenu.y}
          onCreateNode={directEditing.createNodeFromContextMenu}
          onDeleteNode={directEditing.deleteSelectedNode}
          onNodeShapeChange={directEditing.changeSelectedNodeShape}
          onSelectedAction={directEditing.runSelectedNodeAction}
        />
      )}
    </>
  );
}

function TraceTableRenderer({
  document,
  onCellActiveChange,
  onCellValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCellActiveChange?: (row: number, column: number) => void;
  onCellValueChange?: (row: number, column: number, value: string) => void;
  readOnly?: boolean;
}) {
  const cells = document.model.cells ?? [];
  const columns = ((document.model.typeData?.columns as string[]) ?? []).map(
    String
  );
  const maxRow = Math.max(...cells.map((cell) => cell.row), 0);
  const maxColumn = Math.max(...cells.map((cell) => cell.column), 0);
  const columnCount = Math.max(columns.length, maxColumn + 1, 3);
  const rowCount = Math.max(maxRow + 1, 3);
  const cellWidth = 72;
  const cellHeight = 42;
  const startX = 58;
  const startY = 62;
  const width = Math.max(460, startX * 2 + columnCount * cellWidth);
  const height = Math.max(240, startY * 2 + (rowCount + 1) * cellHeight);
  const cellsByKey = new Map(
    cells.map((cell) => [`${cell.row}:${cell.column}`, cell])
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {Array.from({ length: columnCount }).map((_, column) => (
        <g key={`header-${column}`}>
          <rect
            fill="#eff6ff"
            height={cellHeight}
            rx={6}
            stroke={NORMAL_STROKE}
            strokeWidth={1.2}
            width={cellWidth}
            x={startX + column * cellWidth}
            y={startY}
          />
          <text
            x={startX + column * cellWidth + cellWidth / 2}
            y={startY + 27}
            textAnchor="middle"
            className="fill-slate-700 text-[12px] font-semibold"
          >
            {columns[column] ?? `v${column}`}
          </text>
        </g>
      ))}
      {Array.from({ length: rowCount }).flatMap((_, row) =>
        Array.from({ length: columnCount }).map((__, column) => {
          const cell = cellsByKey.get(`${row}:${column}`);
          const active = cell?.state === 'active';
          const x = startX + column * cellWidth;
          const y = startY + (row + 1) * cellHeight;

          return (
            <g
            className={
              !readOnly && (onCellActiveChange || onCellValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            data-visual-matrix-cell={`${row}:${column}`}
            key={`${row}-${column}`}
            onPointerDown={(event) => {
              if (readOnly || !onCellActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              onCellActiveChange(row, column);
            }}
          >
              <rect
                fill={active ? '#dbeafe' : '#ffffff'}
                height={cellHeight}
                rx={6}
                stroke={active ? ACTIVE_STROKE : '#cbd5e1'}
                strokeWidth={active ? 2.2 : 1.2}
                width={cellWidth}
                x={x}
                y={y}
                onPointerDown={(event) => {
                  if (readOnly || !onCellActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  onCellActiveChange(row, column);
                }}
              />
              <EditableSvgText
                dataVisualTextTarget="trace-cell"
                x={x + cellWidth / 2}
                y={y + 27}
                value={cell?.value}
                readOnly={readOnly || !onCellValueChange || !cell}
                className="fill-slate-900 text-[12px] font-semibold"
                onCommit={(nextValue) => onCellValueChange?.(row, column, nextValue)}
              />
            </g>
          );
        })
      )}
    </Canvas>
  );
}

function SequenceDiagramRenderer({
  document,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeEndpointsChange,
  onEdgeLabelChange,
  onEdgeRouteChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeStateChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onEdgeCreate?: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete?: (edge: VisualizationEdge) => void;
  onEdgeEndpointsChange?: (
    edge: VisualizationEdge,
    nextFromNodeId: string,
    nextToNodeId: string
  ) => void;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onEdgeRouteChange?: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const actors = [...(document.model.nodes ?? [])].sort(
    (a, b) => a.position.x - b.position.x
  );
  const edges = document.model.edges ?? [];
  const startX = 86;
  const topY = 54;
  const messageGap = 52;
  const messageYs = edges.map((edge, index) =>
    Number.isFinite(edge.routeOffset)
      ? Math.max(topY + 60, edge.routeOffset ?? 0)
      : topY + 74 + index * messageGap
  );
  const height = Math.max(260, Math.max(...messageYs, topY + 130) + 80);
  const width = Math.max(
    480,
    Math.max(...actors.map((actor) => actor.position.x), startX + 170) + startX
  );
  const nextActorX =
    Math.max(...actors.map((actor) => actor.position.x), startX) + 170;
  const canAddMessage = actors.length >= 2 && Boolean(onEdgeCreate);
  const xById = new Map(
    actors.map((actor, index) => [
      actor.id,
      Number.isFinite(actor.position.x)
        ? actor.position.x
        : startX + index * 170,
    ])
  );
  const dragRef = React.useRef<{
    id: string;
    pointerX: number;
    startX: number;
    y: number;
  } | null>(null);
  const messageDragRef = React.useRef<{
    edge: VisualizationEdge;
    pointerY: number;
    startY: number;
  } | null>(null);
  const messageEndpointDragRef = React.useRef<{
    edge: VisualizationEdge;
    endpoint: 'from' | 'to';
  } | null>(null);
  const updateActorFromPointer = React.useCallback(
    (event: React.PointerEvent<SVGElement>, actor: VisualizationNode) => {
      if (readOnly || !onNodeMove || dragRef.current?.id !== actor.id) {
        return;
      }

      const point = getSvgPoint(
        event.currentTarget.ownerSVGElement,
        event.clientX,
        event.clientY
      );

      if (!point) return;

      const nextX = clamp(
        dragRef.current.startX + point.x - dragRef.current.pointerX,
        56,
        width - 56
      );

      onNodeMove(actor.id, Math.round(nextX), dragRef.current.y);
    },
    [onNodeMove, readOnly, width]
  );
  const updateMessageFromPointer = React.useCallback(
    (event: React.PointerEvent<SVGElement>) => {
      if (readOnly || !onEdgeRouteChange || !messageDragRef.current) {
        return;
      }

      const point = getSvgPoint(
        event.currentTarget.ownerSVGElement,
        event.clientX,
        event.clientY
      );

      if (!point) return;

      const nextY = clamp(
        messageDragRef.current.startY + point.y - messageDragRef.current.pointerY,
        topY + 56,
        height - 42
      );

      onEdgeRouteChange(
        messageDragRef.current.edge,
        'auto',
        Math.round(nextY)
      );
    },
    [height, onEdgeRouteChange, readOnly]
  );
  const nearestActorId = React.useCallback(
    (x: number) => {
      if (actors.length === 0) {
        return null;
      }

      return actors
        .map((actor, index) => ({
          distance: Math.abs((xById.get(actor.id) ?? startX + index * 170) - x),
          id: actor.id,
        }))
        .sort((a, b) => a.distance - b.distance)[0]?.id ?? null;
    },
    [actors, xById]
  );
  const updateMessageEndpointFromPointer = React.useCallback(
    (event: React.PointerEvent<SVGCircleElement>) => {
      if (
        readOnly ||
        !onEdgeEndpointsChange ||
        !messageEndpointDragRef.current
      ) {
        return;
      }

      const point = getSvgPoint(
        event.currentTarget.ownerSVGElement,
        event.clientX,
        event.clientY
      );

      if (!point) return;

      const actorId = nearestActorId(point.x);

      if (!actorId) return;

      const { edge, endpoint } = messageEndpointDragRef.current;
      let nextFromNodeId = endpoint === 'from' ? actorId : edge.from;
      let nextToNodeId = endpoint === 'to' ? actorId : edge.to;

      if (nextFromNodeId === nextToNodeId) {
        nextFromNodeId = edge.to;
        nextToNodeId = edge.from;
      }

      if (nextFromNodeId === edge.from && nextToNodeId === edge.to) {
        return;
      }

      onEdgeEndpointsChange(edge, nextFromNodeId, nextToNodeId);
    },
    [nearestActorId, onEdgeEndpointsChange, readOnly]
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {!readOnly && (onNodeCreate || canAddMessage) && (
        <g>
          {onNodeCreate && (
            <g
              data-visual-sequence-add-actor
              role="button"
              tabIndex={0}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onNodeCreate({
                  label: 'Actor',
                  shape: 'rectangle',
                  x: nextActorX,
                  y: topY,
                });
              }}
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
              }}
            >
              <rect
                fill="#ffffff"
                height={26}
                rx={8}
                stroke="#cbd5e1"
                width={86}
                x={18}
                y={16}
              />
              <text
                className="fill-slate-700 text-[11px] font-semibold"
                pointerEvents="none"
                textAnchor="middle"
                x={61}
                y={33}
              >
                Add actor
              </text>
            </g>
          )}
          {canAddMessage && (
            <g
              data-visual-sequence-add-message
              pointerEvents="all"
              role="button"
              tabIndex={0}
              onPointerUp={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onEdgeCreate?.(actors[0].id, actors[1].id);
              }}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                if (event.detail !== 0) {
                  return;
                }
                onEdgeCreate?.(actors[0].id, actors[1].id);
              }}
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
              }}
            >
              <rect
                fill="#ffffff"
                height={26}
                rx={8}
                stroke="#cbd5e1"
                width={104}
                x={112}
                y={16}
              />
              <text
                className="fill-slate-700 text-[11px] font-semibold"
                pointerEvents="none"
                textAnchor="middle"
                x={164}
                y={33}
              >
                Add message
              </text>
            </g>
          )}
        </g>
      )}
      {actors.map((actor, index) => {
        const x = xById.get(actor.id) ?? startX + index * 170;

        return (
          <g
            className={!readOnly && onNodeMove ? 'cursor-move' : undefined}
            key={actor.id}
            onPointerDown={(event) => {
              if (readOnly || !onNodeMove) return;

              const point = getSvgPoint(
                event.currentTarget.ownerSVGElement,
                event.clientX,
                event.clientY
              );

              if (!point) return;

              event.preventDefault();
              event.stopPropagation();
              dragRef.current = {
                id: actor.id,
                pointerX: point.x,
                startX: x,
                y: actor.position.y,
              };
              event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMove={(event) => updateActorFromPointer(event, actor)}
            onPointerUp={() => {
              dragRef.current = null;
            }}
          >
            <rect
              fill="#ffffff"
              height={34}
              rx={8}
              stroke={actor.state === 'active' ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={actor.state === 'active' ? 2 : 1.4}
              width={110}
              x={x - 55}
              y={topY}
              onPointerDown={(event) => {
                if (readOnly || !onNodeMove) return;

                const point = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!point) return;

                event.preventDefault();
                event.stopPropagation();
                dragRef.current = {
                  id: actor.id,
                  pointerX: point.x,
                  startX: x,
                  y: actor.position.y,
                };
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => updateActorFromPointer(event, actor)}
              onPointerUp={() => {
                dragRef.current = null;
              }}
            />
            <EditableSvgText
              x={x}
              y={topY + 22}
              value={actor.label}
              readOnly={readOnly || !onNodeLabelChange}
              className="fill-slate-950 text-[12px] font-semibold"
              onCommit={(nextValue) => onNodeLabelChange?.(actor.id, nextValue)}
            />
            {!readOnly && onNodeDelete && actors.length > 1 && (
              <g
                data-visual-sequence-delete-actor
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onNodeDelete(actor.id);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x + 58}
                  cy={topY - 8}
                  fill="#ffffff"
                  r={10}
                  stroke="#fecaca"
                />
                <text
                  className="fill-red-600 text-[12px] font-bold"
                  textAnchor="middle"
                  x={x + 58}
                  y={topY - 4}
                >
                  ×
                </text>
              </g>
            )}
            <line
              stroke="#94a3b8"
              strokeDasharray="5 5"
              strokeWidth={1.2}
              x1={x}
              x2={x}
              y1={topY + 34}
              y2={height - 34}
            />
          </g>
        );
      })}
      {edges.map((edge, index) => {
        const fromX = xById.get(edge.from) ?? startX;
        const toX = xById.get(edge.to) ?? fromX + 170;
        const y = messageYs[index] ?? topY + 74 + index * messageGap;
        const minX = Math.min(fromX, toX);
        const maxX = Math.max(fromX, toX);

        return (
          <g key={edge.id}>
            {!readOnly && onEdgeRouteChange && (
              <rect
                className="cursor-ns-resize"
                data-visual-sequence-message-drag
                fill="transparent"
                height={24}
                pointerEvents="all"
                width={Math.max(24, maxX - minX)}
                x={minX}
                y={y - 12}
                onPointerDown={(event) => {
                  const point = getSvgPoint(
                    event.currentTarget.ownerSVGElement,
                    event.clientX,
                    event.clientY
                  );

                  if (!point) return;

                  event.preventDefault();
                  event.stopPropagation();
                  messageDragRef.current = {
                    edge,
                    pointerY: point.y,
                    startY: y,
                  };
                  event.currentTarget.setPointerCapture(event.pointerId);
                }}
                onPointerMove={updateMessageFromPointer}
                onPointerUp={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  messageDragRef.current = null;
                  event.currentTarget.releasePointerCapture(event.pointerId);
                }}
                onPointerCancel={() => {
                  messageDragRef.current = null;
                }}
              />
            )}
            <line
              markerEnd="url(#arrow-small)"
              pointerEvents="none"
              stroke={edge.state === 'active' ? ACTIVE_STROKE : '#64748b'}
              strokeWidth={1.8}
              x1={fromX}
              x2={toX}
              y1={y}
              y2={y}
            />
            {!readOnly && onEdgeEndpointsChange && (
              <>
                <circle
                  className="cursor-ew-resize"
                  data-visual-sequence-message-endpoint="from"
                  cx={fromX}
                  cy={y}
                  fill="#ffffff"
                  r={6}
                  stroke={ACTIVE_STROKE}
                  strokeWidth={1.6}
                  onPointerDown={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    messageEndpointDragRef.current = {
                      edge,
                      endpoint: 'from',
                    };
                    event.currentTarget.setPointerCapture(event.pointerId);
                  }}
                  onPointerMove={updateMessageEndpointFromPointer}
                  onPointerUp={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    messageEndpointDragRef.current = null;
                    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                      event.currentTarget.releasePointerCapture(event.pointerId);
                    }
                  }}
                  onPointerCancel={() => {
                    messageEndpointDragRef.current = null;
                  }}
                />
                <circle
                  className="cursor-ew-resize"
                  data-visual-sequence-message-endpoint="to"
                  cx={toX}
                  cy={y}
                  fill="#ffffff"
                  r={6}
                  stroke={ACTIVE_STROKE}
                  strokeWidth={1.6}
                  onPointerDown={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    messageEndpointDragRef.current = {
                      edge,
                      endpoint: 'to',
                    };
                    event.currentTarget.setPointerCapture(event.pointerId);
                  }}
                  onPointerMove={updateMessageEndpointFromPointer}
                  onPointerUp={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    messageEndpointDragRef.current = null;
                    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                      event.currentTarget.releasePointerCapture(event.pointerId);
                    }
                  }}
                  onPointerCancel={() => {
                    messageEndpointDragRef.current = null;
                  }}
                />
              </>
            )}
            <EditableSvgText
              x={(fromX + toX) / 2}
              y={y - 10}
              value={edge.label ?? ''}
              readOnly={readOnly || !onEdgeLabelChange}
              className="fill-slate-800 text-[12px] font-semibold"
              onCommit={(nextValue) => onEdgeLabelChange?.(edge, nextValue)}
            />
            {!readOnly && onEdgeDelete && (
              <g
                data-visual-sequence-delete-message
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onEdgeDelete(edge);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={(fromX + toX) / 2 + 58}
                  cy={y - 12}
                  fill="#ffffff"
                  r={9}
                  stroke="#fecaca"
                />
                <text
                  className="fill-red-600 text-[11px] font-bold"
                  textAnchor="middle"
                  x={(fromX + toX) / 2 + 58}
                  y={y - 8}
                >
                  ×
                </text>
              </g>
            )}
          </g>
        );
      })}
    </Canvas>
  );
}

function StoryboardRenderer({
  document,
  onItemActiveChange,
  onItemValueChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeStateChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  onNodeCreate?: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete?: (nodeId: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  onNodeStateChange?: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  readOnly?: boolean;
}) {
  const usesModelNodes = Boolean(document.model.nodes && document.model.nodes.length > 0);
  const nodes = usesModelNodes
    ? [...(document.model.nodes ?? [])].sort(
        (a, b) =>
          a.position.x - b.position.x ||
          a.position.y - b.position.y ||
          a.id.localeCompare(b.id)
      )
    : (document.model.items ?? []).map((item) => ({
        id: item.id,
        label: String(item.value ?? ''),
        position: { x: 0, y: 0 },
        shape: 'rectangle' as VisualizationShapeKind,
        state: item.state,
      }));
  const cardWidth = 150;
  const cardHeight = 104;
  const gap = 34;
  const startX = 46;
  const startY = 86;
  const sceneStep = cardWidth + gap;
  const [selectedNodeId, setSelectedNodeId] = React.useState<string | null>(null);
  const width = Math.max(
    560,
    Math.max(
      startX * 2 + nodes.length * sceneStep,
      ...nodes.map((node, index) =>
        usesModelNodes
          ? node.position.x + 236 + startX
          : startX + (index + 1) * sceneStep
      )
    )
  );
  const height = Math.max(
    280,
    Math.max(
      startY + cardHeight + 76,
      ...nodes.map((node) =>
        usesModelNodes
          ? node.position.y + cardHeight + 76
          : startY + cardHeight + 76
      )
    )
  );
  const nextCardX = usesModelNodes
    ? Math.max(...nodes.map((node) => node.position.x), startX) + sceneStep
    : startX + nodes.length * sceneStep;
  const dragRef = React.useRef<{
    id: string;
    pointerX: number;
    pointerY: number;
    startX: number;
    startY: number;
  } | null>(null);
  const updateNodeFromPointer = React.useCallback(
    (event: React.PointerEvent<SVGElement>, node: VisualizationNode) => {
      if (readOnly || !onNodeMove || dragRef.current?.id !== node.id) {
        return;
      }

      const point = getSvgPoint(
        event.currentTarget.ownerSVGElement,
        event.clientX,
        event.clientY
      );

      if (!point) return;

      onNodeMove(
        node.id,
        Math.round(dragRef.current.startX + point.x - dragRef.current.pointerX),
        Math.round(dragRef.current.startY + point.y - dragRef.current.pointerY)
      );
    },
    [onNodeMove, readOnly]
  );
  const createScene = React.useCallback(
    (x: number, y: number, label = '새 장면\\n설명을 입력하세요') => {
      onNodeCreate?.({
        label,
        shape: 'rectangle',
        x: Math.round(x),
        y: Math.round(y),
      });
    },
    [onNodeCreate]
  );
  const splitSceneLabel = React.useCallback((label: string) => {
    const [title = '', ...bodyParts] = normalizeVisualTextLineBreaks(label).split('\n');

    return {
      body: bodyParts.join('\n').trim(),
      title: title.trim() || '장면',
    };
  }, []);
  const joinSceneLabel = React.useCallback((title: string, body: string) => {
    const normalizedTitle = title.trim() || '장면';
    const normalizedBody = normalizeVisualTextLineBreaks(body).trim().replace(/\n/g, '\\n');

    return normalizedBody ? `${normalizedTitle}\\n${normalizedBody}` : normalizedTitle;
  }, []);
  const selectedNode = nodes.find((node) => node.id === selectedNodeId) ?? null;
  const stateOptions: readonly {
    label: string;
    state: VisualizationElementState;
  }[] = [
    { label: '기본', state: 'normal' },
    { label: '강조', state: 'active' },
    { label: '완료', state: 'success' },
    { label: '주의', state: 'warning' },
    { label: '오류', state: 'error' },
    { label: '숨김', state: 'muted' },
  ];

  return (
    <Canvas
      bounds={{ minX: 0, minY: 0, width, height }}
      onDoubleClick={(event) => {
        if (readOnly || !usesModelNodes || !onNodeCreate) return;

        const point = getSvgPoint(
          event.currentTarget,
          event.clientX,
          event.clientY
        );

        if (!point) return;

        event.preventDefault();
        event.stopPropagation();
        createScene(point.x - cardWidth / 2, point.y - cardHeight / 2);
      }}
    >
      {!readOnly && usesModelNodes && onNodeCreate && (
        <g
          data-visual-storyboard-add
          role="button"
          tabIndex={0}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            createScene(nextCardX, startY);
          }}
          onPointerDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          <rect
            fill="#ffffff"
            height={28}
            rx={8}
            stroke="#cbd5e1"
            width={82}
            x={18}
            y={18}
          />
          <text
            className="fill-slate-700 text-[11px] font-semibold"
            textAnchor="middle"
            x={59}
            y={36}
          >
            장면 추가
          </text>
        </g>
      )}
      {nodes.map((node, index) => {
        const x = usesModelNodes ? node.position.x : startX + index * sceneStep;
        const y = usesModelNodes ? node.position.y : startY;
        const selected = selectedNode?.id === node.id;
        const active = node.state === 'active';
        const { body, title } = splitSceneLabel(node.label);
        const bodyText = body || '설명을 입력하세요';

        return (
          <g
            className={
              !readOnly && (onNodeMove || onItemActiveChange || onItemValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            key={node.id}
            onDoubleClick={(event) => event.stopPropagation()}
            onPointerDown={(event) => {
              if (readOnly) return;

              setSelectedNodeId(node.id);

              if (usesModelNodes && onNodeMove) {
                const point = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!point) return;

                event.preventDefault();
                event.stopPropagation();
                dragRef.current = {
                  id: node.id,
                  pointerX: point.x,
                  pointerY: point.y,
                  startX: x,
                  startY: y,
                };
                event.currentTarget.setPointerCapture(event.pointerId);
                return;
              }

              if (!usesModelNodes && onItemActiveChange) {
                event.preventDefault();
                event.stopPropagation();
                onItemActiveChange([index]);
              }
            }}
            onPointerMove={(event) => updateNodeFromPointer(event, node)}
            onPointerUp={(event) => {
              dragRef.current = null;
              if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                event.currentTarget.releasePointerCapture(event.pointerId);
              }
            }}
          >
            <circle
              cx={x + 18}
              cy={y - 22}
              fill={active ? ACTIVE_STROKE : getFill(node.state)}
              r={15}
              stroke={getStroke(node.state)}
              strokeWidth={1.4}
            />
            <text
              x={x + 18}
              y={y - 17}
              textAnchor="middle"
              className={active ? 'fill-white text-[12px] font-bold' : 'fill-slate-700 text-[12px] font-bold'}
            >
              {index + 1}
            </text>
            {index < nodes.length - 1 && (
              <line
                markerEnd="url(#arrow-small)"
                stroke="#94a3b8"
                strokeWidth={1.6}
                x1={x + 38}
                x2={x + sceneStep - 18}
                y1={y - 22}
                y2={y - 22}
              />
            )}
            <rect
              data-visual-storyboard-scene-card
              fill={getFill(node.state)}
              height={cardHeight}
              rx={10}
              stroke={selected ? ACTIVE_STROKE : getStroke(node.state)}
              strokeWidth={selected || active ? 2 : 1.4}
              width={cardWidth}
              x={x}
              y={y}
            />
            <EditableSvgText
              dataVisualTextTarget="storyboard-title"
              x={x + cardWidth / 2}
              y={y + 30}
              value={title}
              width={cardWidth - 20}
              readOnly={
                readOnly ||
                (usesModelNodes ? !onNodeLabelChange : !onItemValueChange)
              }
              className="fill-slate-950 text-[13px] font-semibold"
              onCommit={(nextValue) => {
                if (usesModelNodes) {
                  onNodeLabelChange?.(node.id, joinSceneLabel(nextValue, body));
                } else {
                  onItemValueChange?.(index, nextValue);
                }
              }}
            />
            <EditableSvgText
              dataVisualTextTarget="storyboard-body"
              x={x + cardWidth / 2}
              y={y + 61}
              value={bodyText}
              width={cardWidth - 24}
              height={42}
              readOnly={readOnly || !usesModelNodes || !onNodeLabelChange}
              className={
                body
                  ? 'fill-slate-600 text-[10px] font-medium'
                  : 'fill-slate-400 text-[10px] font-medium'
              }
              onCommit={(nextValue) =>
                onNodeLabelChange?.(
                  node.id,
                  joinSceneLabel(title, nextValue === '설명을 입력하세요' ? '' : nextValue)
                )
              }
            />
            {!readOnly && selected && usesModelNodes && onNodeCreate && (
              <g
                data-visual-storyboard-insert-before
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  createScene(Math.max(20, x - sceneStep), y);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x - 14}
                  cy={y + cardHeight / 2}
                  fill="#ffffff"
                  r={11}
                  stroke="#bfdbfe"
                />
                <text
                  className="fill-blue-700 text-[13px] font-bold"
                  textAnchor="middle"
                  x={x - 14}
                  y={y + cardHeight / 2 + 5}
                >
                  +
                </text>
              </g>
            )}
            {!readOnly && selected && usesModelNodes && onNodeCreate && (
              <g
                data-visual-storyboard-insert-after
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  createScene(x + sceneStep, y);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x + cardWidth + 14}
                  cy={y + cardHeight / 2}
                  fill="#ffffff"
                  r={11}
                  stroke="#bfdbfe"
                />
                <text
                  className="fill-blue-700 text-[13px] font-bold"
                  textAnchor="middle"
                  x={x + cardWidth + 14}
                  y={y + cardHeight / 2 + 5}
                >
                  +
                </text>
              </g>
            )}
            {!readOnly && selected && usesModelNodes && onNodeCreate && (
              <g
                data-visual-storyboard-duplicate
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  createScene(x + sceneStep, y, node.label);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <rect
                  fill="#ffffff"
                  height={18}
                  rx={6}
                  stroke="#bfdbfe"
                  width={28}
                  x={x + cardWidth - 58}
                  y={y + 8}
                />
                <text
                  className="fill-blue-700 text-[10px] font-bold"
                  textAnchor="middle"
                  x={x + cardWidth - 44}
                  y={y + 21}
                >
                  복제
                </text>
              </g>
            )}
            {!readOnly && selected && usesModelNodes && onNodeDelete && nodes.length > 1 && (
              <g
                data-visual-storyboard-delete
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onNodeDelete(node.id);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle
                  cx={x + cardWidth - 16}
                  cy={y + 17}
                  fill="#ffffff"
                  r={9}
                  stroke="#fecaca"
                />
                <text
                  className="fill-red-600 text-[11px] font-bold"
                  textAnchor="middle"
                  x={x + cardWidth - 16}
                  y={y + 21}
                >
                  ×
                </text>
              </g>
            )}
            {!readOnly && selected && usesModelNodes && onNodeMove && (
              <g data-visual-storyboard-position-tools>
                {[
                  { dx: -sceneStep, label: '←', name: 'left' },
                  { dx: sceneStep, label: '→', name: 'right' },
                ].map((action, actionIndex) => (
                  <g
                    data-visual-storyboard-move={action.name}
                    key={action.name}
                    role="button"
                    tabIndex={0}
                    onClick={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                      onNodeMove(node.id, Math.max(20, x + action.dx), y);
                    }}
                    onPointerDown={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                    }}
                  >
                    <rect
                      fill="#ffffff"
                      height={22}
                      rx={7}
                      stroke="#cbd5e1"
                      width={28}
                      x={x + 8 + actionIndex * 32}
                      y={y + cardHeight - 30}
                    />
                    <text
                      className="fill-slate-700 text-[13px] font-bold"
                      textAnchor="middle"
                      x={x + 22 + actionIndex * 32}
                      y={y + cardHeight - 14}
                    >
                      {action.label}
                    </text>
                  </g>
                ))}
              </g>
            )}
            {!readOnly && selected && usesModelNodes && onNodeStateChange && (
              <g data-visual-storyboard-state-tools>
                {stateOptions.map((option, optionIndex) => (
                  <g
                    data-visual-storyboard-state={option.state}
                    key={option.state}
                    role="button"
                    tabIndex={0}
                    onClick={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                      onNodeStateChange(node.id, option.state);
                    }}
                    onPointerDown={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                    }}
                  >
                    <rect
                      fill={node.state === option.state ? '#dbeafe' : '#ffffff'}
                      height={20}
                      rx={7}
                      stroke={node.state === option.state ? ACTIVE_STROKE : '#cbd5e1'}
                      width={31}
                      x={x + 8 + optionIndex * 35}
                      y={y - 54}
                    />
                    <text
                      className="fill-slate-700 text-[9px] font-bold"
                      textAnchor="middle"
                      x={x + 23.5 + optionIndex * 35}
                      y={y - 40}
                    >
                      {option.label}
                    </text>
                  </g>
                ))}
              </g>
            )}
          </g>
        );
      })}
    </Canvas>
  );
}

function LinkedListRenderer({
  document,
  onItemActiveChange,
  onItemCreate,
  onItemDelete,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: (itemIndex?: number, value?: string) => void;
  onItemDelete?: (itemIndex: number) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const nodeWidth = 82;
  const nodeHeight = 48;
  const gap = 54;
  const startX = 42;
  const y = 96;
  const width = Math.max(500, startX * 2 + items.length * (nodeWidth + gap));

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height: 230 }}>
      {!readOnly && onItemCreate && (
        <g
          data-visual-linked-list-add
          role="button"
          tabIndex={0}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            onItemCreate(items.length, '0');
          }}
          onPointerDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          <rect fill="#ffffff" height={26} rx={8} stroke="#cbd5e1" width={78} x={18} y={16} />
          <text x={57} y={33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
            Add node
          </text>
        </g>
      )}
      <text
        x={startX}
        y={y - 32}
        className="fill-slate-600 text-[12px] font-semibold"
      >
        head
      </text>
      {items.map((item, index) => {
        const x = startX + index * (nodeWidth + gap);
        const active = item.state === 'active';
        const itemIndex = item.index ?? index;
        const activateItem = () => onItemActiveChange?.([itemIndex]);

        return (
          <g
            className={
              !readOnly && (onItemActiveChange || onItemValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            key={item.id}
            onPointerDown={(event) => {
              if (readOnly || !onItemActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              activateItem();
            }}
            onClick={(event) => {
              if (readOnly || !onItemActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              activateItem();
            }}
          >
            <rect
              fill={active ? '#dbeafe' : '#ffffff'}
              height={nodeHeight}
              rx={8}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.2 : 1.4}
              width={nodeWidth}
              x={x}
              y={y}
            />
            <line
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={1.2}
              x1={x + nodeWidth - 24}
              x2={x + nodeWidth - 24}
              y1={y}
              y2={y + nodeHeight}
            />
            <EditableSvgText
              x={x + (nodeWidth - 24) / 2}
              y={y + 30}
              value={item.value}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-950 text-[14px] font-bold"
              onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue)}
            />
            {!readOnly && onItemDelete && items.length > 1 && (
              <g
                data-visual-linked-list-delete
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemDelete(itemIndex);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle cx={x + nodeWidth - 8} cy={y - 8} fill="#ffffff" r={9} stroke="#fecaca" />
                <text x={x + nodeWidth - 8} y={y - 4} textAnchor="middle" className="fill-red-600 text-[11px] font-bold">
                  ×
                </text>
              </g>
            )}
            {index < items.length - 1 ? (
              <line
                markerEnd="url(#arrow-small)"
                stroke="#64748b"
                strokeWidth={1.8}
                x1={x + nodeWidth - 12}
                x2={x + nodeWidth + gap - 12}
                y1={y + nodeHeight / 2}
                y2={y + nodeHeight / 2}
              />
            ) : (
              <text
                x={x + nodeWidth + 18}
                y={y + 30}
                className="fill-slate-500 text-[12px] font-semibold"
              >
                null
              </text>
            )}
          </g>
        );
      })}
      {!readOnly && onItemCreate && (
        <g data-visual-linked-list-insert-controls>
          {Array.from({ length: items.length + 1 }).map((_, insertIndex) => {
            const x = startX + insertIndex * (nodeWidth + gap) - gap / 2;
            const cy = y + nodeHeight / 2;

            return (
              <g
                className="cursor-pointer opacity-30 transition-opacity hover:opacity-100"
                data-visual-linked-list-insert={insertIndex}
                key={`linked-list-insert-${insertIndex}`}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemCreate(insertIndex, '0');
                }}
                onPointerDown={(event) => event.stopPropagation()}
              >
                <circle cx={x} cy={cy} fill="#ffffff" r={9} stroke={ACTIVE_STROKE} strokeWidth={1.4} />
                <path d={`M${x - 4} ${cy} H${x + 4} M${x} ${cy - 4} V${cy + 4}`} stroke={ACTIVE_STROKE} strokeWidth={1.4} />
                {insertIndex === 0 && (
                  <text x={x} y={y + nodeHeight + 24} textAnchor="middle" className="fill-blue-700 text-[11px] font-semibold">
                    Insert head
                  </text>
                )}
              </g>
            );
          })}
        </g>
      )}
    </Canvas>
  );
}

function StackQueueRenderer({
  document,
  mode = 'both',
  onItemActiveChange,
  onItemCreate,
  onItemDelete,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  mode?: 'both' | 'queue' | 'stack';
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: (itemIndex?: number, value?: string) => void;
  onItemDelete?: (itemIndex: number) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const stackItems =
    mode === 'queue' ? [] : mode === 'both' ? items.slice(0, Math.ceil(items.length / 2)) : items;
  const queueItems =
    mode === 'stack' ? [] : mode === 'both' ? items.slice(Math.ceil(items.length / 2)) : items;
  const cellWidth = 62;
  const cellHeight = 36;
  const stackX = mode === 'stack' ? 220 : 92;
  const stackBottom = Math.max(184, 72 + stackItems.length * cellHeight);
  const queueX = mode === 'queue' ? 80 : 280;
  const queueY = 112;
  const queueWidth = Math.max(260, queueItems.length * (cellWidth + 8) + 60);
  const width =
    mode === 'stack'
      ? 440
      : mode === 'queue'
        ? Math.max(440, queueX + queueWidth + 40)
        : Math.max(620, queueX + queueWidth);
  const height = Math.max(260, stackBottom + 72);

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {!readOnly && onItemCreate && (
        <g
          data-visual-stack-queue-add
          role="button"
          tabIndex={0}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            onItemCreate(items.length, '0');
          }}
          onPointerDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          <rect fill="#ffffff" height={26} rx={8} stroke="#cbd5e1" width={84} x={18} y={16} />
          <text x={60} y={33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
            {mode === 'queue' ? 'Enqueue' : mode === 'stack' ? 'Push' : 'Add value'}
          </text>
        </g>
      )}
      {mode !== 'queue' && (
        <>
          <text x={stackX} y={46} textAnchor="middle" className="fill-slate-700 text-[12px] font-bold">
            Stack
          </text>
          {stackItems.map((item, index) => {
            const x = stackX - cellWidth / 2;
            const y = stackBottom - (index + 1) * cellHeight;
            const active = item.state === 'active';
            const itemIndex = item.index ?? index;
            const activateItem = () => onItemActiveChange?.([itemIndex]);

            return (
              <g
                className={
                  !readOnly && (onItemActiveChange || onItemValueChange)
                    ? 'cursor-pointer'
                    : undefined
                }
                key={item.id}
                onPointerDown={(event) => {
                  if (readOnly || !onItemActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  activateItem();
                }}
                onClick={(event) => {
                  if (readOnly || !onItemActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  activateItem();
                }}
              >
                <rect
                  fill={active ? '#dbeafe' : '#ffffff'}
                  height={cellHeight - 4}
                  rx={6}
                  stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
                  strokeWidth={active ? 2.2 : 1.4}
                  width={cellWidth}
                  x={x}
                  y={y}
                />
                <EditableSvgText
                  x={stackX}
                  y={y + 22}
                  value={item.value}
                  readOnly={readOnly || !onItemValueChange}
                  className="fill-slate-950 text-[13px] font-bold"
                  onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue)}
                />
                {!readOnly && onItemDelete && items.length > 1 && (
                  <g
                    data-visual-stack-queue-delete
                    role="button"
                    tabIndex={0}
                    onClick={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                      onItemDelete(itemIndex);
                    }}
                    onPointerDown={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                    }}
                  >
                    <circle cx={x + cellWidth + 6} cy={y + 4} fill="#ffffff" r={8} stroke="#fecaca" />
                    <text x={x + cellWidth + 6} y={y + 8} textAnchor="middle" className="fill-red-600 text-[10px] font-bold">
                      ×
                    </text>
                  </g>
                )}
              </g>
            );
          })}
          {stackItems.length > 0 && (
            <>
              <line
                markerEnd="url(#arrow-small)"
                stroke={ACTIVE_STROKE}
                strokeWidth={1.8}
                x1={stackX + 68}
                x2={stackX + 38}
                y1={stackBottom - stackItems.length * cellHeight + 16}
                y2={stackBottom - stackItems.length * cellHeight + 16}
              />
              <text
                x={stackX + 74}
                y={stackBottom - stackItems.length * cellHeight + 20}
                className="fill-slate-700 text-[12px] font-semibold"
              >
                top
              </text>
            </>
          )}
        </>
      )}
      {mode !== 'stack' && (
        <>
          <text x={queueX + queueWidth / 2} y={46} textAnchor="middle" className="fill-slate-700 text-[12px] font-bold">
            Queue
          </text>
          {queueItems.map((item, index) => {
            const x = queueX + index * (cellWidth + 8);
            const active = item.state === 'active';
            const itemIndex = item.index ?? (mode === 'both' ? stackItems.length + index : index);
            const activateItem = () => onItemActiveChange?.([itemIndex]);

            return (
              <g
                className={
                  !readOnly && (onItemActiveChange || onItemValueChange)
                    ? 'cursor-pointer'
                    : undefined
                }
                key={item.id}
                onPointerDown={(event) => {
                  if (readOnly || !onItemActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  activateItem();
                }}
                onClick={(event) => {
                  if (readOnly || !onItemActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  activateItem();
                }}
              >
                <rect
                  fill={active ? '#dbeafe' : '#ffffff'}
                  height={cellHeight}
                  rx={7}
                  stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
                  strokeWidth={active ? 2.2 : 1.4}
                  width={cellWidth}
                  x={x}
                  y={queueY}
                />
                <EditableSvgText
                  x={x + cellWidth / 2}
                  y={queueY + 24}
                  value={item.value}
                  readOnly={readOnly || !onItemValueChange}
                  className="fill-slate-950 text-[13px] font-bold"
                  onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue)}
                />
                {!readOnly && onItemDelete && items.length > 1 && (
                  <g
                    data-visual-stack-queue-delete
                    role="button"
                    tabIndex={0}
                    onClick={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                      onItemDelete(itemIndex);
                    }}
                    onPointerDown={(event) => {
                      event.preventDefault();
                      event.stopPropagation();
                    }}
                  >
                    <circle cx={x + cellWidth - 2} cy={queueY - 4} fill="#ffffff" r={8} stroke="#fecaca" />
                    <text x={x + cellWidth - 2} y={queueY} textAnchor="middle" className="fill-red-600 text-[10px] font-bold">
                      ×
                    </text>
                  </g>
                )}
              </g>
            );
          })}
          {queueItems.length > 0 && (
            <>
              <text x={queueX} y={queueY + 58} className="fill-slate-600 text-[12px] font-semibold">
                front
              </text>
              <text x={queueX + (queueItems.length - 1) * (cellWidth + 8) + cellWidth - 24} y={queueY + 58} className="fill-slate-600 text-[12px] font-semibold">
                rear
              </text>
            </>
          )}
        </>
      )}
    </Canvas>
  );
}

function HashTableRenderer({
  document,
  onCommandChange,
  onItemActiveChange,
  onItemCreate,
  onItemDelete,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: (itemIndex?: number, value?: string) => void;
  onItemDelete?: (itemIndex: number) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const explicitBucketIndexes = items
    .map((item) => String(item.label ?? '').match(/^bucket:(\d+)$/)?.[1])
    .filter((value): value is string => value !== undefined)
    .map((value) => Number(value));
  const bucketCount = Math.max(
    4,
    Number(document.model.typeData?.bucketCount ?? 0) || 0,
    explicitBucketIndexes.length > 0 ? Math.max(...explicitBucketIndexes) + 1 : 0
  );
  const buckets = Array.from({ length: bucketCount }, (_, bucket) =>
    items.filter((item) => {
      const explicitBucket = String(item.label ?? '').match(/^bucket:(\d+)$/)?.[1];

      if (explicitBucket !== undefined) {
        return Number(explicitBucket) === bucket;
      }

      return Math.abs(Number(item.value ?? 0)) % bucketCount === bucket;
    })
  );
  const rowHeight = 42;
  const startX = 70;
  const startY = 54;
  // Collision-chain items lay out horizontally at startX + 96 + index*86;
  // a fixed canvas width meant chains of 5+ items in one bucket rendered
  // past the visible SVG viewBox and were silently clipped.
  const maxChainLength = Math.max(0, ...buckets.map((bucket) => bucket.length));
  const width = Math.max(560, startX + 96 + maxChainLength * 86 + 48);
  const height = Math.max(250, startY * 2 + bucketCount * rowHeight);
  const draggingHashItemRef = React.useRef<number | null>(null);
  const moveItemToBucket = React.useCallback(
    (itemIndex: number, bucketIndex: number) => {
      const item = items[itemIndex];

      if (!item || !onItemValueChange) {
        return;
      }

      const boundedBucket = clamp(bucketIndex, 0, bucketCount - 1);
      const currentBucket = String(item.label ?? '').match(/^bucket:(\d+)$/)?.[1];

      if (currentBucket !== undefined && Number(currentBucket) === boundedBucket) {
        return;
      }

      onItemActiveChange?.([itemIndex]);
      onItemValueChange(itemIndex, `${item.value}@${boundedBucket}`);
    },
    [bucketCount, items, onItemActiveChange, onItemValueChange]
  );
  const updateHashBucketFromPointer = React.useCallback(
    (svg: SVGSVGElement | null, itemIndex: number, clientX: number, clientY: number) => {
      const point = getSvgPoint(svg, clientX, clientY);

      if (!point) {
        return;
      }

      const bucketIndex = Math.round((point.y - startY - 16) / rowHeight);
      moveItemToBucket(itemIndex, bucketIndex);
    },
    [moveItemToBucket, rowHeight, startY]
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {!readOnly && onItemCreate && (
        <g
          data-visual-hash-table-add
          data-visual-hash-table-key-add-global
          role="button"
          tabIndex={0}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            onItemCreate(undefined, '0@0');
          }}
          onPointerDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          <rect fill="#ffffff" height={26} rx={8} stroke="#cbd5e1" width={78} x={18} y={16} />
          <text x={57} y={33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
            키 추가
          </text>
        </g>
      )}
      {!readOnly && onCommandChange && (
        <g
          className="cursor-pointer"
          data-visual-hash-table-bucket-add
          role="button"
          tabIndex={0}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            onCommandChange('bucket-count', String(bucketCount + 1));
          }}
          onPointerDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          <rect fill="#ffffff" height={26} rx={8} stroke="#cbd5e1" width={82} x={108} y={16} />
          <text x={149} y={33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
            버킷 추가
          </text>
        </g>
      )}
      {buckets.map((bucketItems, bucketIndex) => {
        const y = startY + bucketIndex * rowHeight;

        return (
          <g key={bucketIndex}>
            {!readOnly && onCommandChange && bucketCount > 1 && (
              <g
                className="cursor-pointer opacity-40 transition-opacity hover:opacity-100"
                data-visual-hash-table-bucket-delete={bucketIndex}
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onCommandChange('hash-remove-bucket', String(bucketIndex));
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle cx={startX - 40} cy={y + 16} fill="#ffffff" r={8} stroke="#fecaca" strokeWidth={1.3} />
                <path d={`M${startX - 44} ${y + 16} H${startX - 36}`} stroke="#dc2626" strokeLinecap="round" strokeWidth={1.5} />
              </g>
            )}
            {!readOnly && onItemCreate && (
              <g
                className="cursor-pointer opacity-40 transition-opacity hover:opacity-100"
                data-visual-hash-table-key-add={bucketIndex}
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemCreate(undefined, `0@${bucketIndex}`);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle cx={startX - 18} cy={y + 16} fill="#ffffff" r={8} stroke={ACTIVE_STROKE} strokeWidth={1.3} />
                <path d={`M${startX - 22} ${y + 16} H${startX - 14} M${startX - 18} ${y + 12} V${y + 20}`} stroke={ACTIVE_STROKE} strokeWidth={1.3} />
              </g>
            )}
            <rect
              fill="#ffffff"
              height={32}
              rx={7}
              stroke={NORMAL_STROKE}
              strokeWidth={1.4}
              width={58}
              x={startX}
              y={y}
            />
            <text x={startX + 29} y={y + 21} textAnchor="middle" className="fill-slate-950 text-[12px] font-bold">
              {bucketIndex}
            </text>
            {bucketItems.map((item, index) => {
              const x = startX + 96 + index * 86;
              const active = item.state === 'active';
              const itemIndex = item.index ?? items.indexOf(item);
              const activateItem = () => onItemActiveChange?.([itemIndex]);

              return (
                <g key={item.id}>
                  <g
                    className={
                      !readOnly && (onItemActiveChange || onItemValueChange)
                        ? 'cursor-pointer'
                        : undefined
                    }
                    onPointerDown={(event) => {
                      if (readOnly || !onItemActiveChange) return;

                      event.preventDefault();
                      event.stopPropagation();
                      draggingHashItemRef.current = itemIndex;
                      activateItem();
                      event.currentTarget.setPointerCapture(event.pointerId);
                    }}
                    onPointerMove={(event) => {
                      if (
                        readOnly ||
                        draggingHashItemRef.current !== itemIndex ||
                        !onItemValueChange
                      ) {
                        return;
                      }

                      updateHashBucketFromPointer(
                        event.currentTarget.ownerSVGElement,
                        itemIndex,
                        event.clientX,
                        event.clientY
                      );
                    }}
                    onPointerUp={() => {
                      draggingHashItemRef.current = null;
                    }}
                    onClick={(event) => {
                      if (readOnly || !onItemActiveChange) return;

                      event.preventDefault();
                      event.stopPropagation();
                      activateItem();
                    }}
                  >
                  {index === 0 && (
                    <line
                      markerEnd="url(#arrow-small)"
                      stroke="#64748b"
                      strokeWidth={1.6}
                      x1={startX + 58}
                      x2={x - 10}
                      y1={y + 16}
                      y2={y + 16}
                    />
                  )}
                  {index > 0 && (
                    <line
                      markerEnd="url(#arrow-small)"
                      stroke="#64748b"
                      strokeWidth={1.6}
                      x1={x - 40}
                      x2={x - 10}
                      y1={y + 16}
                      y2={y + 16}
                    />
                  )}
                  <rect
                    fill={active ? '#dbeafe' : '#ffffff'}
                    height={32}
                    rx={7}
                    stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
                    strokeWidth={active ? 2.2 : 1.4}
                    width={58}
                    x={x}
                    y={y}
                  />
                  <EditableSvgText
                    x={x + 29}
                    y={y + 21}
                    value={item.value}
                    readOnly={readOnly || !onItemValueChange}
                    className="fill-slate-950 text-[12px] font-bold"
                    onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue)}
                  />
                  {!readOnly && onItemDelete && items.length > 1 && (
                    <g
                      data-visual-hash-table-delete
                      role="button"
                      tabIndex={0}
                      onClick={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        onItemDelete(itemIndex);
                      }}
                      onPointerDown={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                      }}
                    >
                      <circle cx={x + 58} cy={y - 4} fill="#ffffff" r={8} stroke="#fecaca" />
                      <text x={x + 58} y={y} textAnchor="middle" className="fill-red-600 text-[10px] font-bold">
                        ×
                      </text>
                    </g>
                  )}
                  </g>
                </g>
              );
            })}
          </g>
        );
      })}
    </Canvas>
  );
}

function SearchingRenderer({
  document,
  onCommandChange,
  onItemActiveChange,
  onItemCreate,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: (itemIndex?: number, value?: string) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const activeIndex = items.findIndex((item) => item.state === 'active');
  const pointerData = document.model.typeData?.pointers as
    | { high?: number; low?: number; mid?: number }
    | undefined;
  const hasExplicitMid = Number.isInteger(pointerData?.mid);
  // Clamping low/high individually into range hides a "low > high" search
  // has actually finished (both just clamp to whatever valid index they're
  // nearest to) -- the mid marker then kept rendering at a plausible-looking
  // position as if a comparison were still in progress, with no visual cue
  // the search had ended. Check the exhausted state on the raw values first.
  const rawLow = Number(pointerData?.low ?? 0);
  const rawHigh = Number(pointerData?.high ?? Math.max(items.length - 1, 0));
  const isSearchExhausted = !hasExplicitMid && rawLow > rawHigh;
  const low = clamp(pointerData?.low ?? 0, 0, Math.max(items.length - 1, 0));
  const high = clamp(pointerData?.high ?? Math.max(items.length - 1, 0), 0, Math.max(items.length - 1, 0));
  const mid = clamp(pointerData?.mid ?? (activeIndex >= 0 ? activeIndex : Math.floor((low + high) / 2)), 0, Math.max(items.length - 1, 0));
  const cellWidth = 58;
  const startX = 52;
  const startY = 92;
  const width = Math.max(500, startX * 2 + items.length * cellWidth);
  const draggingPointerRef = React.useRef<'high' | 'low' | 'mid' | null>(null);
  const updatePointerCommand = React.useCallback(
    (kind: 'high' | 'low' | 'mid', index: number) => {
      const next = {
        high,
        low,
        mid,
        [kind]: index,
      };
      onCommandChange?.('pointers', `${next.low} ${next.mid} ${next.high}`);
      if (kind === 'mid') {
        onItemActiveChange?.([index]);
      }
    },
    [high, low, mid, onCommandChange, onItemActiveChange]
  );
  const updatePointerFromPointer = React.useCallback(
    (svg: SVGSVGElement | null, clientX: number, clientY: number) => {
      if (!draggingPointerRef.current || !onCommandChange || items.length === 0) {
        return;
      }

      const point = getSvgPoint(svg, clientX, clientY);

      if (!point) {
        return;
      }

      const nextIndex = Math.round(
        clamp((point.x - startX) / cellWidth, 0, items.length - 1)
      );

      updatePointerCommand(draggingPointerRef.current, nextIndex);
    },
    [cellWidth, items.length, onCommandChange, startX, updatePointerCommand]
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height: 230 }}>
      {!readOnly && onItemCreate && (
        <g data-visual-searching-insert-controls>
          {Array.from({ length: items.length + 1 }).map((_, insertIndex) => {
            const x = startX + insertIndex * cellWidth - 3;

            return (
              <g
                className="cursor-pointer opacity-30 transition-opacity hover:opacity-100"
                data-visual-searching-insert={insertIndex}
                key={`searching-insert-${insertIndex}`}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemCreate(insertIndex, '0');
                }}
                onPointerDown={(event) => event.stopPropagation()}
              >
                <circle cx={x} cy={startY + 23} fill="#ffffff" r={8} stroke={ACTIVE_STROKE} strokeWidth={1.3} />
                <path d={`M${x - 4} ${startY + 23} H${x + 4} M${x} ${startY + 19} V${startY + 27}`} stroke={ACTIVE_STROKE} strokeWidth={1.3} />
              </g>
            );
          })}
        </g>
      )}
      {items.map((item, index) => {
        const x = startX + index * cellWidth;
        const active = index === mid || (!hasExplicitMid && item.state === 'active');

        return (
          <g
            className={
              !readOnly && (onItemActiveChange || onItemValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            key={item.id}
            onPointerDown={(event) => {
              if (readOnly || !onItemActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              draggingPointerRef.current = 'mid';
              updatePointerCommand('mid', index);
              event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMove={(event) => {
              if (readOnly || !draggingPointerRef.current) {
                return;
              }

              updatePointerFromPointer(
                event.currentTarget.ownerSVGElement,
                event.clientX,
                event.clientY
              );
            }}
            onPointerUp={() => {
              draggingPointerRef.current = null;
            }}
          >
            <rect
              fill={active ? '#dbeafe' : '#ffffff'}
              height={46}
              rx={7}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.2 : 1.4}
              width={cellWidth - 6}
              x={x}
              y={startY}
              onPointerDown={(event) => {
                if (readOnly || !onItemActiveChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingPointerRef.current = 'mid';
                updatePointerCommand('mid', index);
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !draggingPointerRef.current) {
                  return;
                }

                updatePointerFromPointer(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );
              }}
              onPointerUp={() => {
                draggingPointerRef.current = null;
              }}
            />
            <EditableSvgText
              x={x + (cellWidth - 6) / 2}
              y={startY + 29}
              value={item.value}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-950 text-[13px] font-bold"
              onCommit={(nextValue) => onItemValueChange?.(item.index ?? 0, nextValue)}
            />
          </g>
        );
      })}
      {isSearchExhausted && (
        <text
          className="fill-slate-500 text-[11px] font-semibold"
          textAnchor="middle"
          x={startX + ((low + high) / 2) * cellWidth + cellWidth / 2}
          y={startY + 92 + 4}
        >
          검색 종료
        </text>
      )}
      {[
        { kind: 'low' as const, index: low, y: startY + 70, color: '#0f766e' },
        ...(isSearchExhausted
          ? []
          : [{ kind: 'mid' as const, index: mid, y: startY + 92, color: ACTIVE_STROKE }]),
        { kind: 'high' as const, index: high, y: startY + 114, color: '#d97706' },
      ].map((pointer) => {
        const x = startX + pointer.index * cellWidth + (cellWidth - 6) / 2;

        return (
          <g
            className={!readOnly && onCommandChange ? 'cursor-ew-resize' : undefined}
            data-visual-searching-pointer={pointer.kind}
            key={pointer.kind}
            onPointerDown={(event) => {
              if (readOnly || !onCommandChange) return;

              event.preventDefault();
              event.stopPropagation();
              draggingPointerRef.current = pointer.kind;
              event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMove={(event) => {
              if (readOnly || !draggingPointerRef.current) return;
              updatePointerFromPointer(event.currentTarget.ownerSVGElement, event.clientX, event.clientY);
            }}
            onPointerUp={() => {
              draggingPointerRef.current = null;
            }}
            onPointerCancel={() => {
              draggingPointerRef.current = null;
            }}
          >
            <rect
              fill="transparent"
              height={28}
              pointerEvents="all"
              width={70}
              x={x - 18}
              y={pointer.y - 16}
            />
            <path d={`M ${x - 7} ${pointer.y - 6} H ${x + 7} L ${x} ${pointer.y + 6} Z`} fill={pointer.color} opacity={0.9} />
            <text x={x + 14} y={pointer.y + 4} className="fill-slate-700 text-[12px] font-semibold">
              {pointer.kind}
            </text>
          </g>
        );
      })}
    </Canvas>
  );
}

function getNumericItems(document: VisualizationDocument, fallback: number[]) {
  const values = (document.model.items ?? [])
    .map((item) => Number(item.value ?? 0))
    .filter((value) => Number.isFinite(value));

  return values.length > 0 ? values : fallback;
}

function formatEditableNumber(value: number) {
  const rounded = Math.round(value * 100) / 100;

  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
}

function PlotFrame({
  children,
  height = 260,
  width = 560,
}: {
  children: React.ReactNode;
  height?: number;
  width?: number;
}) {
  const left = 54;
  const bottom = height - 42;
  const right = width - 32;
  const top = 32;

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      <line
        stroke="#94a3b8"
        strokeWidth={1.2}
        x1={left}
        x2={left}
        y1={top}
        y2={bottom}
      />
      <line
        stroke="#94a3b8"
        strokeWidth={1.2}
        x1={left}
        x2={right}
        y1={bottom}
        y2={bottom}
      />
      {children}
    </Canvas>
  );
}

function ScatterRenderer({
  document,
  onCommandChange,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const fallbackPoints = [
    { x: 0.5, y: 2.2 },
    { x: 1.4, y: 4.6 },
    { x: 2.1, y: 3.5 },
    { x: 3.2, y: 7.4 },
    { x: 4.1, y: 6.7 },
    { x: 5.0, y: 9.2 },
  ];
  const items: VisualizationItem[] = document.model.items?.length
    ? document.model.items
    : fallbackPoints.map((point, index) => ({
        id: `fallback-scatter-${index}`,
        index,
        value: `${point.x},${point.y}`,
      }));
  const points = items.map((item, index) => {
    const raw = String(item.value ?? '');
    const parts = raw.split(/[,;:]/).map((part) => Number(part.trim()));

    if (parts.length >= 2 && Number.isFinite(parts[0]) && Number.isFinite(parts[1])) {
      return { x: parts[0], y: parts[1] };
    }

    const numericY = Number(raw);

    return {
      x: fallbackPoints[index]?.x ?? index + 1,
      y: Number.isFinite(numericY) ? numericY : fallbackPoints[index]?.y ?? 0,
    };
  });
  const width = 560;
  const height = 260;
  const left = 54;
  const bottom = height - 42;
  const plotWidth = width - 92;
  const plotHeight = height - 76;
  const xValues = points.map((point) => point.x);
  const yValues = points.map((point) => point.y);
  const minX = Math.min(0, ...xValues);
  const maxX = Math.max(minX + 1, ...xValues);
  const minY = Math.min(0, ...yValues);
  const maxY = Math.max(minY + 1, ...yValues);
  const xScale = (value: number) =>
    left + ((value - minX) / Math.max(maxX - minX, 1)) * plotWidth;
  const yScale = (value: number) =>
    bottom - ((value - minY) / Math.max(maxY - minY, 1)) * plotHeight;
  const toDataPoint = (point: { x: number; y: number }) => ({
    x: minX + ((point.x - left) / plotWidth) * (maxX - minX),
    y: minY + ((bottom - point.y) / plotHeight) * (maxY - minY),
  });
  const showTrendLine = document.model.typeData?.trendLine !== false;
  const trendLine = React.useMemo(() => {
    if (points.length < 2) {
      return null;
    }

    const count = points.length;
    const sumX = points.reduce((sum, point) => sum + point.x, 0);
    const sumY = points.reduce((sum, point) => sum + point.y, 0);
    const sumXY = points.reduce((sum, point) => sum + point.x * point.y, 0);
    const sumXX = points.reduce((sum, point) => sum + point.x * point.x, 0);
    const denominator = count * sumXX - sumX * sumX;

    if (Math.abs(denominator) < 0.000001) {
      const x = xScale(points[0].x);

      return {
        x1: x,
        x2: x,
        y1: bottom,
        y2: bottom - plotHeight,
      };
    }

    const slope = (count * sumXY - sumX * sumY) / denominator;
    const intercept = (sumY - slope * sumX) / count;
    const yAtMin = slope * minX + intercept;
    const yAtMax = slope * maxX + intercept;

    return {
      x1: xScale(minX),
      x2: xScale(maxX),
      y1: clamp(yScale(yAtMin), bottom - plotHeight, bottom),
      y2: clamp(yScale(yAtMax), bottom - plotHeight, bottom),
    };
  }, [bottom, maxX, minX, plotHeight, points, xScale, yScale]);
  const formatPoint = (point: { x: number; y: number }) =>
    `${formatEditableNumber(point.x)},${formatEditableNumber(point.y)}`;
  const draggingIndexRef = React.useRef<number | null>(null);

  return (
    <PlotFrame height={height} width={width}>
      {!readOnly && onCommandChange && (
        <g
          className="cursor-pointer"
          data-visual-scatter-trend-toggle
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            onCommandChange('trend-line', showTrendLine ? 'hide' : 'show');
          }}
          onPointerDown={(event) => event.stopPropagation()}
        >
          <rect
            fill="#ffffff"
            height={28}
            rx={8}
            stroke={showTrendLine ? ACTIVE_STROKE : NORMAL_STROKE}
            strokeWidth={1.3}
            width={94}
            x={width - 138}
            y={14}
          />
          <text
            className="fill-slate-700 text-[11px] font-semibold"
            textAnchor="middle"
            x={width - 91}
            y={33}
          >
            {showTrendLine ? '추세선 숨기기' : '추세선 보이기'}
          </text>
        </g>
      )}
      {showTrendLine && trendLine && (
        <g data-visual-scatter-trend-line>
          <line
            stroke={ACTIVE_STROKE}
            strokeLinecap="round"
            strokeWidth={2}
            x1={trendLine.x1}
            x2={trendLine.x2}
            y1={trendLine.y1}
            y2={trendLine.y2}
          />
          <rect
            fill="#ffffff"
            height={20}
            rx={6}
            stroke="#dbe5f2"
            width={52}
            x={left + 12}
            y={44}
          />
          <text
            className="fill-slate-700 text-[11px] font-semibold"
            textAnchor="middle"
            x={left + 38}
            y={58}
          >
            trend
          </text>
        </g>
      )}
      {points.map((point, index) => {
        const x = xScale(point.x);
        const y = yScale(point.y);
        const active = items[index]?.state === 'active';
        const label = `${formatEditableNumber(point.x)}, ${formatEditableNumber(point.y)}`;
        const lineYAtPoint =
          showTrendLine && trendLine
            ? Math.abs(trendLine.x2 - trendLine.x1) < 0.001
              ? undefined
              : trendLine.y1 +
                ((x - trendLine.x1) / (trendLine.x2 - trendLine.x1)) *
                (trendLine.y2 - trendLine.y1)
            : undefined;
        const labelX = x > width - 120 ? x - 10 : x + 10;
        const minLabelY = bottom - plotHeight + 18;
        let labelY = y - 8;

        if (typeof lineYAtPoint === 'number') {
          if (y > lineYAtPoint) {
            labelY = Math.max(y + 24, lineYAtPoint + 18);
          } else {
            const aboveY = y - 18;
            labelY =
              aboveY < minLabelY
                ? Math.max(y + 52, lineYAtPoint + 44)
                : aboveY;
          }

          labelY = clamp(labelY, minLabelY, bottom - 8);
        }
        const labelAnchor = x > width - 120 ? 'end' : 'start';

        return (
          <g
            className={!readOnly && onItemValueChange ? 'cursor-move' : undefined}
            key={items[index]?.id ?? `point-${index}`}
          >
            <circle
              cx={x}
              cy={y}
              fill={active ? '#dbeafe' : '#ffffff'}
              r={active ? 7 : 5.5}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.2 : 1.5}
              onPointerDown={(event) => {
                if (readOnly || !onItemValueChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingIndexRef.current = index;
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                  return;
                }

                const svgPoint = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!svgPoint) {
                  return;
                }

                const nextPoint = toDataPoint({
                  x: Math.max(left, Math.min(left + plotWidth, svgPoint.x)),
                  y: Math.max(bottom - plotHeight, Math.min(bottom, svgPoint.y)),
                });

                onItemValueChange(index, formatPoint(nextPoint));
              }}
              onPointerUp={() => {
                draggingIndexRef.current = null;
              }}
            />
            <EditableSvgText
              x={labelX}
              y={labelY}
              textAnchor={labelAnchor}
              value={label}
              readOnly={readOnly || !onItemValueChange}
              width={108}
              className="fill-slate-600 text-[11px] font-semibold"
              onCommit={(nextValue) => onItemValueChange?.(index, nextValue)}
            />
          </g>
        );
      })}
    </PlotFrame>
  );
}

function HistogramRenderer({
  document,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const values = getNumericItems(document, [4, 9, 6, 11, 7]);
  const width = 560;
  const height = 260;
  const left = 58;
  const bottom = height - 42;
  const plotHeight = height - 84;
  const maxValue = Math.max(...values, 1);
  const scaleMax = Math.max(12, maxValue);
  const barWidth = Math.max(34, Math.min(64, (width - 124) / values.length - 12));
  const draggingIndexRef = React.useRef<number | null>(null);
  const toValue = React.useCallback(
    (y: number) => Math.max(0, Math.round(((bottom - y) / plotHeight) * scaleMax)),
    [bottom, plotHeight, scaleMax]
  );

  return (
    <PlotFrame height={height} width={width}>
      {values.map((value, index) => {
        const x = left + index * (barWidth + 18);
        const h = Math.max(10, (value / scaleMax) * plotHeight);
        const active = document.model.items?.[index]?.state === 'active';

        return (
          <g
            className={!readOnly && onItemValueChange ? 'cursor-ns-resize' : undefined}
            key={`histogram-${index}`}
          >
            <rect
              fill={active ? '#dbeafe' : '#ffffff'}
              height={h}
              rx={7}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.2 : 1.4}
              width={barWidth}
              x={x}
              y={bottom - h}
              onPointerDown={(event) => {
                if (readOnly || !onItemValueChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingIndexRef.current = index;
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                  return;
                }

                const point = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!point) return;

                onItemValueChange(index, String(toValue(clamp(point.y, bottom - plotHeight, bottom))));
              }}
              onPointerUp={() => {
                draggingIndexRef.current = null;
              }}
            />
            <circle
              data-visual-control
              cx={x + barWidth / 2}
              cy={bottom - h}
              r={5}
              fill="#ffffff"
              stroke={ACTIVE_STROKE}
              strokeWidth={1.6}
              className={!readOnly && onItemValueChange ? 'cursor-ns-resize' : undefined}
              onPointerDown={(event) => {
                if (readOnly || !onItemValueChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingIndexRef.current = index;
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                  return;
                }

                const point = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!point) return;

                onItemValueChange(index, String(toValue(clamp(point.y, bottom - plotHeight, bottom))));
              }}
              onPointerUp={() => {
                draggingIndexRef.current = null;
              }}
            />
            <EditableSvgText
              x={x + barWidth / 2}
              y={bottom - h - 8}
              value={value}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-800 text-[12px] font-semibold"
              onCommit={(nextValue) => onItemValueChange?.(index, nextValue)}
            />
            <text
              x={x + barWidth / 2}
              y={bottom + 20}
              textAnchor="middle"
              className="fill-slate-500 text-[11px] font-semibold"
            >
              bin {index + 1}
            </text>
          </g>
        );
      })}
    </PlotFrame>
  );
}

function LineGraphRenderer({
  document,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const values = getNumericItems(document, [2, 5, 4, 8, 7, 11]);
  const width = 560;
  const height = 260;
  const left = 54;
  const bottom = height - 42;
  const plotWidth = width - 92;
  const plotHeight = height - 76;
  const maxValue = Math.max(...values, 1);
  const scaleMax = Math.max(12, maxValue);
  const points = values.map((value, index) => ({
    x:
      left +
      (values.length <= 1 ? plotWidth / 2 : (index / (values.length - 1)) * plotWidth),
    y: bottom - (value / scaleMax) * plotHeight,
  }));
  const draggingIndexRef = React.useRef<number | null>(null);
  const toValue = React.useCallback(
    (y: number) =>
      Math.max(0, roundToStep(((bottom - y) / plotHeight) * scaleMax, 0.1)),
    [bottom, plotHeight, scaleMax]
  );

  return (
    <PlotFrame height={height} width={width}>
      <polyline
        fill="none"
        points={toPolylinePoints(points)}
        stroke={ACTIVE_STROKE}
        strokeLinejoin="round"
        strokeWidth={2}
      />
      {points.map((point, index) => {
        const active = document.model.items?.[index]?.state === 'active';
        const isFirst = index === 0;
        const isLast = index === points.length - 1;
        const labelX = isFirst ? point.x + 14 : isLast ? point.x - 10 : point.x;
        const labelY = isFirst ? point.y + 22 : point.y - 12;
        const labelAnchor = isFirst ? 'start' : isLast ? 'end' : 'middle';

        return (
          <g
            className={!readOnly && onItemValueChange ? 'cursor-move' : undefined}
            key={`line-point-${index}`}
          >
            <circle
              cx={point.x}
              cy={point.y}
              fill={active ? '#dbeafe' : '#ffffff'}
              r={active ? 6.5 : 5}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.2 : 1.4}
              onPointerDown={(event) => {
                if (readOnly || !onItemValueChange) return;

                event.preventDefault();
                event.stopPropagation();
                draggingIndexRef.current = index;
                event.currentTarget.setPointerCapture(event.pointerId);
              }}
              onPointerMove={(event) => {
                if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                  return;
                }

                const svgPoint = getSvgPoint(
                  event.currentTarget.ownerSVGElement,
                  event.clientX,
                  event.clientY
                );

                if (!svgPoint) return;

                onItemValueChange(
                  index,
                  formatEditableNumber(toValue(clamp(svgPoint.y, bottom - plotHeight, bottom)))
                );
              }}
              onPointerUp={() => {
                draggingIndexRef.current = null;
              }}
            />
            <EditableSvgText
              x={labelX}
              y={labelY}
              textAnchor={labelAnchor}
              value={values[index]}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-700 text-[11px] font-semibold"
              onCommit={(nextValue) => onItemValueChange?.(index, nextValue)}
            />
          </g>
        );
      })}
    </PlotFrame>
  );
}

function MatrixRenderer({
  colorMode = 'numeric',
  document,
  onCellActiveChange,
  onCellValueChange,
  readOnly,
}: {
  colorMode?: 'heat' | 'numeric' | 'path' | 'sieve';
  document: VisualizationDocument;
  onCellActiveChange?: (row: number, column: number) => void;
  onCellValueChange?: (row: number, column: number, value: string) => void;
  readOnly?: boolean;
}) {
  const cells = document.model.cells ?? [];
  const maxRow = Math.max(...cells.map((cell) => cell.row), 2);
  const maxColumn = Math.max(...cells.map((cell) => cell.column), 2);
  const cellSize = colorMode === 'path' ? 38 : 42;
  const startX = 70;
  const startY = 46;
  const width = Math.max(460, startX * 2 + (maxColumn + 1) * cellSize);
  const height = Math.max(240, startY * 2 + (maxRow + 1) * cellSize);
  const maxValue = Math.max(
    ...cells.map((cell) => Number(cell.value ?? 0)).filter(Number.isFinite),
    1
  );

  const cellFill = (cell: VisualizationCell | undefined) => {
    const raw = String(cell?.value ?? '');

    if (cell?.state === 'active') {
      return '#dbeafe';
    }

    if (colorMode === 'path') {
      if (raw === 'S') return '#dcfce7';
      if (raw === 'G') return '#fef3c7';
      if (raw === '#') return '#334155';
      if (raw === '*') return '#bfdbfe';

      return '#ffffff';
    }

    if (colorMode === 'sieve') {
      if (raw === 'x') return '#fee2e2';
      if (raw === 'p') return '#dcfce7';

      return '#ffffff';
    }

    if (colorMode === 'heat') {
      const parsedValue = Number(cell?.value ?? 0);
      const value = Number.isFinite(parsedValue) ? Math.max(0, parsedValue) : 0;
      const alpha = 0.18 + Math.min(0.62, (value / maxValue) * 0.62);

      return `rgba(37, 99, 235, ${alpha})`;
    }

    return raw === '1' ? '#dbeafe' : '#ffffff';
  };

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {Array.from({ length: maxRow + 1 }).flatMap((_, row) =>
        Array.from({ length: maxColumn + 1 }).map((__, column) => {
          const cell = cells.find(
            (item) => item.row === row && item.column === column
          );
          const x = startX + column * cellSize;
          const y = startY + row * cellSize;
          const textColor =
            colorMode === 'path' && String(cell?.value ?? '') === '#'
              ? 'fill-white'
              : 'fill-slate-950';

          return (
            <g
            className={
              !readOnly && (onCellActiveChange || onCellValueChange)
                ? 'cursor-pointer'
                : undefined
            }
            data-visual-matrix-cell={`${row}:${column}`}
            key={`${row}-${column}`}
            onPointerDown={(event) => {
              if (readOnly || !onCellActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              onCellActiveChange(row, column);
            }}
          >
              <rect
                fill={cellFill(cell)}
                height={cellSize}
                rx={6}
                stroke={cell?.state === 'active' ? ACTIVE_STROKE : '#cbd5e1'}
                strokeWidth={cell?.state === 'active' ? 2.2 : 1.2}
                width={cellSize}
                x={x}
                y={y}
                onPointerDown={(event) => {
                  if (readOnly || !onCellActiveChange) return;

                  event.preventDefault();
                  event.stopPropagation();
                  onCellActiveChange(row, column);
                }}
              />
              <EditableSvgText
                dataVisualTextTarget="matrix-cell"
                x={x + cellSize / 2}
                y={y + cellSize / 2 + 5}
                value={cell?.value}
                readOnly={readOnly || !onCellValueChange || !cell}
                className={`${textColor} text-[12px] font-semibold`}
                onCommit={(nextValue) => onCellValueChange?.(row, column, nextValue)}
              />
            </g>
          );
        })
      )}
    </Canvas>
  );
}

type SieveCellStatus = 'candidate' | 'marked' | 'prime';

function getSieveFallbackNumber(row: number, column: number, maxColumn: number) {
  return String(row * (maxColumn + 1) + column + 2);
}

function parseSieveCellValue(
  value: unknown,
  row: number,
  column: number,
  maxColumn: number
): { label: string; status: SieveCellStatus } {
  const fallback = getSieveFallbackNumber(row, column, maxColumn);
  const raw = String(value ?? '').trim();
  const normalized = raw.toLowerCase();

  if (!raw || raw === '.') {
    return { label: fallback, status: 'candidate' };
  }

  if (['x', 'marked', 'composite', 'removed'].includes(normalized)) {
    return { label: fallback, status: 'marked' };
  }

  if (['p', 'prime'].includes(normalized)) {
    return { label: fallback, status: 'prime' };
  }

  const statusSuffix = raw.match(/^(.+?)(?::(prime|p|marked|x|composite|candidate|c))$/i);

  if (statusSuffix) {
    const statusToken = statusSuffix[2].toLowerCase();

    return {
      label: statusSuffix[1] || fallback,
      status:
        statusToken === 'prime' || statusToken === 'p'
          ? 'prime'
          : statusToken === 'marked' || statusToken === 'x' || statusToken === 'composite'
            ? 'marked'
            : 'candidate',
    };
  }

  const compactSuffix = raw.match(/^(-?\d+(?:\.\d+)?)([pxc])$/i);

  if (compactSuffix) {
    const suffix = compactSuffix[2].toLowerCase();

    return {
      label: compactSuffix[1],
      status: suffix === 'p' ? 'prime' : suffix === 'x' ? 'marked' : 'candidate',
    };
  }

  return { label: raw, status: 'candidate' };
}

function toSieveCellToken(label: string, status: SieveCellStatus) {
  const normalizedLabel = label.trim() || '.';

  if (status === 'prime') {
    return `${normalizedLabel}p`;
  }

  if (status === 'marked') {
    return `${normalizedLabel}x`;
  }

  return normalizedLabel;
}

function SieveMatrixRenderer({
  document,
  onCellActiveChange,
  onCellValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCellActiveChange?: (row: number, column: number) => void;
  onCellValueChange?: (row: number, column: number, value: string) => void;
  readOnly?: boolean;
}) {
  const cells = document.model.cells ?? [];
  const maxRow = Math.max(...cells.map((cell) => cell.row), 3);
  const maxColumn = Math.max(...cells.map((cell) => cell.column), 5);
  // Blank cells display a "what number would this be" placeholder derived
  // from the grid's column count. If that stayed tied to the live
  // (growable) `maxColumn`, adding a cell in a new column would silently
  // reflow every other still-blank cell's placeholder number, even though
  // nothing about those cells changed. Freeze the width used for that
  // placeholder to whatever it was when this sieve was first opened.
  const fallbackNumberingColumnRef = React.useRef<number | null>(null);

  if (fallbackNumberingColumnRef.current === null) {
    fallbackNumberingColumnRef.current = maxColumn;
  }

  const fallbackNumberingColumn = fallbackNumberingColumnRef.current;
  const cellSize = 44;
  const startX = 58;
  const startY = 112;
  const rowCount = maxRow + 1;
  const columnCount = maxColumn + 1;
  const width = Math.max(560, startX * 2 + columnCount * cellSize + 28);
  const height = Math.max(360, startY + rowCount * cellSize + 82);
  const cellsByKey = new Map(cells.map((cell) => [`${cell.row}:${cell.column}`, cell]));
  const activeCell = cells.find((cell) => cell.state === 'active') ?? cells[0] ?? null;
  const activeKey = activeCell ? `${activeCell.row}:${activeCell.column}` : '';
  const currentDragRef = React.useRef(false);
  const lastDraggedCellRef = React.useRef('');
  const svgRef = React.useRef<SVGSVGElement>(null);

  const getCellRect = React.useCallback(
    (row: number, column: number) => ({
      x: startX + column * cellSize,
      y: startY + row * cellSize,
    }),
    [cellSize, startX, startY]
  );

  const getCellAtPoint = React.useCallback(
    (point: { x: number; y: number }) => {
      const column = Math.floor((point.x - startX) / cellSize);
      const row = Math.floor((point.y - startY) / cellSize);

      if (row < 0 || row > maxRow || column < 0 || column > maxColumn) {
        return null;
      }

      return { column, row };
    },
    [cellSize, maxColumn, maxRow, startX, startY]
  );

  const updateCurrentFromPointer = React.useCallback(
    (svg: SVGSVGElement | null, clientX: number, clientY: number) => {
      if (!onCellActiveChange) return;

      const point = getSvgPoint(svg, clientX, clientY);
      const target = (() => {
        if (!point) return null;

        const directTarget =
          getCellAtPoint(point) ??
          getCellAtPoint({ x: point.x, y: point.y + 32 }) ??
          getCellAtPoint({ x: point.x, y: point.y + 48 });

        if (directTarget) {
          return directTarget;
        }

        if (point.x < startX - cellSize || point.x > startX + (maxColumn + 1) * cellSize) {
          return null;
        }

        return {
          column: clamp(Math.floor((point.x - startX) / cellSize), 0, maxColumn),
          row: clamp(activeCell?.row ?? 0, 0, maxRow),
        };
      })();

      if (!target) return;

      const targetKey = `${target.row}:${target.column}`;

      if (targetKey === lastDraggedCellRef.current) {
        return;
      }

      lastDraggedCellRef.current = targetKey;
      onCellActiveChange(target.row, target.column);
    },
    [activeCell?.row, cellSize, getCellAtPoint, maxColumn, maxRow, onCellActiveChange, startX]
  );

  const setCellStatus = React.useCallback(
    (cell: VisualizationCell, status: SieveCellStatus) => {
      const parsed = parseSieveCellValue(cell.value, cell.row, cell.column, fallbackNumberingColumn);

      onCellValueChange?.(
        cell.row,
        cell.column,
        toSieveCellToken(parsed.label, status)
      );
    },
    [fallbackNumberingColumn, onCellValueChange]
  );

  const activePosition = activeCell ? getCellRect(activeCell.row, activeCell.column) : null;
  const menuWidth = 246;
  const menuX = activePosition
    ? clamp(activePosition.x + cellSize / 2 - menuWidth / 2, 12, width - menuWidth - 12)
    : 0;
  const menuY = activePosition
    ? activePosition.y + cellSize + 12 <= height - 42
      ? activePosition.y + cellSize + 12
      : activePosition.y - 44
    : 0;

  const legendItems: {
    fill: string;
    label: string;
    stroke: string;
    status?: SieveCellStatus;
  }[] = [
    { fill: '#ffffff', label: 'Candidate', status: 'candidate', stroke: '#cbd5e1' },
    { fill: '#dcfce7', label: 'Prime', status: 'prime', stroke: '#16a34a' },
    { fill: '#fee2e2', label: 'Marked multiple', status: 'marked', stroke: '#ef4444' },
    { fill: '#dbeafe', label: 'Current base', stroke: ACTIVE_STROKE },
  ];

  return (
    <Canvas
      bounds={{ minX: 0, minY: 0, width, height }}
      onPointerMove={(event) => {
        if (!currentDragRef.current) return;

        updateCurrentFromPointer(svgRef.current, event.clientX, event.clientY);
      }}
      onPointerUp={() => {
        currentDragRef.current = false;
        lastDraggedCellRef.current = '';
      }}
      svgRef={svgRef}
    >
      <text x={startX} y={30} className="fill-slate-900 text-[14px] font-bold">
        Sieve Matrix
      </text>
      <text x={startX} y={52} className="fill-slate-600 text-[12px]">
        Mark multiples of the base number and confirm the remaining values as Prime.
      </text>
      {legendItems.map((item, index) => {
        const x = startX + index * 118;
        const y = 70;

        return (
          <g data-visual-sieve-legend={item.status ?? 'current'} key={item.label}>
            <rect
              data-visual-label-plate
              fill={item.fill}
              height={22}
              rx={7}
              stroke={item.stroke}
              strokeWidth={1.3}
              width={102}
              x={x}
              y={y}
            />
            {item.status === 'marked' ? (
              <line
                data-visual-control
                stroke="#ef4444"
                strokeLinecap="round"
                strokeWidth={1.8}
                x1={x + 8}
                x2={x + 22}
                y1={y + 16}
                y2={y + 6}
              />
            ) : null}
            <text
              x={x + 30}
              y={y + 15}
              className="fill-slate-700 text-[11px] font-semibold"
            >
              {item.label}
            </text>
          </g>
        );
      })}
      {Array.from({ length: rowCount }).flatMap((_, row) =>
        Array.from({ length: columnCount }).map((__, column) => {
          const cell =
            cellsByKey.get(`${row}:${column}`) ??
            ({
              column,
              id: `cell-${row}-${column}`,
              row,
              value: getSieveFallbackNumber(row, column, fallbackNumberingColumn),
            } as VisualizationCell);
          const parsed = parseSieveCellValue(cell.value, row, column, fallbackNumberingColumn);
          const x = startX + column * cellSize;
          const y = startY + row * cellSize;
          const active = `${row}:${column}` === activeKey;
          const fill =
            active ? '#dbeafe' : parsed.status === 'prime' ? '#dcfce7' : parsed.status === 'marked' ? '#fee2e2' : '#ffffff';
          const stroke =
            active
              ? ACTIVE_STROKE
              : parsed.status === 'prime'
                ? '#16a34a'
                : parsed.status === 'marked'
                  ? '#ef4444'
                  : '#cbd5e1';

          return (
            <g
              className={!readOnly && onCellActiveChange ? 'cursor-pointer' : undefined}
              data-visual-matrix-cell={`${row}:${column}`}
              data-visual-sieve-cell={`${row}:${column}`}
              data-visual-sieve-current={active ? 'true' : undefined}
              data-visual-sieve-status={parsed.status}
              key={`${row}-${column}`}
              onPointerDown={(event) => {
                if (readOnly || !onCellActiveChange) return;

                event.preventDefault();
                event.stopPropagation();
                onCellActiveChange(row, column);
              }}
            >
              <rect
                fill={fill}
                height={cellSize}
                rx={8}
                stroke={stroke}
                strokeWidth={active ? 2.4 : 1.3}
                width={cellSize}
                x={x}
                y={y}
              />
              {parsed.status === 'marked' ? (
                <rect
                  data-visual-control
                  data-visual-sieve-crossed
                  pointerEvents="none"
                  fill="#dc2626"
                  height={2.4}
                  rx={1.2}
                  transform={`rotate(-45 ${x + 13} ${y + 13})`}
                  width={15}
                  x={x + 5.5}
                  y={y + 11.8}
                />
              ) : null}
              {parsed.status === 'prime' ? (
                <circle
                  data-visual-control
                  data-visual-sieve-prime
                  cx={x + cellSize - 9}
                  cy={y + 9}
                  fill="#16a34a"
                  r={3.4}
                />
              ) : null}
              <EditableSvgText
                dataVisualTextTarget="sieve-cell"
                x={x + cellSize / 2}
                y={y + cellSize / 2 + 5}
                value={parsed.label}
                readOnly={readOnly || !onCellValueChange}
                className="fill-slate-950 text-[12px] font-bold"
                width={cellSize - 8}
                onCommit={(nextValue) =>
                  onCellValueChange?.(
                    row,
                    column,
                    toSieveCellToken(nextValue, parsed.status)
                  )
                }
              />
            </g>
          );
        })
      )}
      {activeCell && activePosition ? (
        <g
          className={!readOnly && onCellActiveChange ? 'cursor-grab' : undefined}
          data-visual-control
          data-visual-sieve-current-marker
          transform={`translate(${activePosition.x + 3}, ${activePosition.y - 28})`}
          onPointerDown={(event) => {
            if (readOnly || !onCellActiveChange) return;

            event.preventDefault();
            event.stopPropagation();
            currentDragRef.current = true;
            lastDraggedCellRef.current = activeKey;
            event.currentTarget.setPointerCapture(event.pointerId);
            updateCurrentFromPointer(
              event.currentTarget.ownerSVGElement,
              event.clientX,
              event.clientY
            );
          }}
          onPointerMove={(event) => {
            if (!currentDragRef.current) return;

            updateCurrentFromPointer(
              event.currentTarget.ownerSVGElement,
              event.clientX,
              event.clientY
            );
          }}
          onPointerUp={(event) => {
            currentDragRef.current = false;
            lastDraggedCellRef.current = '';

            if (event.currentTarget.hasPointerCapture(event.pointerId)) {
              event.currentTarget.releasePointerCapture(event.pointerId);
            }
          }}
        >
          <rect
            fill={ACTIVE_STROKE}
            height={20}
            rx={10}
            width={cellSize - 6}
            x={0}
            y={0}
          />
          <path
            d={`M ${cellSize / 2 - 6} 20 L ${cellSize / 2} 27 L ${cellSize / 2 + 6} 20 Z`}
            fill={ACTIVE_STROKE}
          />
          <text
            x={(cellSize - 6) / 2}
            y={14}
            textAnchor="middle"
            className="fill-white text-[10px] font-bold"
          >
            Current
          </text>
        </g>
      ) : null}
      {!readOnly && activeCell && activePosition ? (
        <g data-visual-control data-visual-sieve-menu transform={`translate(${menuX}, ${menuY})`}>
          <rect
            data-visual-label-plate
            fill="#ffffff"
            height={34}
            rx={10}
            stroke="#cbd5e1"
            strokeWidth={1}
            width={menuWidth}
            x={0}
            y={0}
          />
          {([
            ['candidate', 'Candidate'],
            ['prime', 'Prime'],
            ['marked', 'Marked'],
          ] as const).map(([status, label], index) => (
            <g
              className="cursor-pointer"
              data-visual-sieve-action={status}
              key={status}
              transform={`translate(${8 + index * 58}, 6)`}
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setCellStatus(activeCell, status);
              }}
            >
              <rect
                data-visual-control
                fill={
                  status === 'prime'
                    ? '#dcfce7'
                    : status === 'marked'
                      ? '#fee2e2'
                      : '#f8fafc'
                }
                height={22}
                rx={8}
                stroke={
                  status === 'prime'
                    ? '#16a34a'
                    : status === 'marked'
                      ? '#ef4444'
                      : '#cbd5e1'
                }
                strokeWidth={1.2}
                width={50}
              />
              <text
                x={25}
                y={15}
                textAnchor="middle"
                className="fill-slate-700 text-[10px] font-bold"
              >
                {label}
              </text>
            </g>
          ))}
          <g
            className="cursor-pointer"
            data-visual-sieve-action="current"
            transform="translate(184, 6)"
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              onCellActiveChange?.(activeCell.row, activeCell.column);
            }}
          >
            <rect
              data-visual-control
              fill="#dbeafe"
              height={22}
              rx={8}
              stroke={ACTIVE_STROKE}
              strokeWidth={1.2}
              width={54}
            />
            <text
              x={27}
              y={15}
              textAnchor="middle"
              className="fill-slate-700 text-[10px] font-bold"
            >
              Current
            </text>
          </g>
        </g>
      ) : null}
    </Canvas>
  );
}

function ComplexityChartRenderer({
  document,
  onItemActiveChange,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const fallbackItems: VisualizationItem[] = [
    { id: 'curve-1', index: 0, value: 'O(n)' },
    { id: 'curve-2', index: 1, value: 'O(n_log_n)', state: 'active' },
    { id: 'curve-3', index: 2, value: 'O(n^2)' },
  ];
  const items =
    document.model.items && document.model.items.length > 0
      ? [0, 1, 2].map((index) => document.model.items?.[index] ?? fallbackItems[index])
      : fallbackItems;
  const labelForItem = (item: VisualizationItem, fallback: string) =>
    String(item.value ?? fallback).replace(/_/g, ' ');
  const width = 560;
  const height = 280;
  const left = 54;
  const bottom = height - 44;
  const scaleX = (n: number) => left + n * 42;
  const scaleY = (value: number) => bottom - value;
  const linear = Array.from({ length: 9 }, (_, index) => ({
    x: scaleX(index + 1),
    y: scaleY((index + 1) * 11),
  }));
  const nlogn = Array.from({ length: 9 }, (_, index) => {
    const n = index + 1;

    return { x: scaleX(n), y: scaleY(n * Math.log2(n + 1) * 8) };
  });
  const squared = Array.from({ length: 9 }, (_, index) => {
    const n = index + 1;

    return { x: scaleX(n), y: scaleY(n * n * 2.2) };
  });

  const curves = [
    { color: '#0f766e', item: items[0], label: labelForItem(items[0], 'O(n)'), points: linear, y: 48 },
    {
      color: ACTIVE_STROKE,
      item: items[1],
      label: labelForItem(items[1], 'O(n log n)'),
      points: nlogn,
      y: 70,
    },
    {
      color: '#d97706',
      item: items[2],
      label: labelForItem(items[2], 'O(n^2)'),
      points: squared,
      y: 92,
    },
  ];

  return (
    <PlotFrame height={height} width={width}>
      {curves.map((curve, index) => {
        const active = curve.item?.state === 'active';

        return (
          <polyline
            className={!readOnly && onItemActiveChange ? 'cursor-pointer' : undefined}
            fill="none"
            key={`curve-${index}`}
            points={toPolylinePoints(curve.points)}
            stroke={curve.color}
            strokeWidth={active ? 3.2 : 2}
            onPointerDown={(event) => {
              if (readOnly || !onItemActiveChange) return;

              event.preventDefault();
              event.stopPropagation();
              onItemActiveChange([index]);
            }}
          />
        );
      })}
      {curves.map((curve, index) => (
          <g
            className={!readOnly && onItemActiveChange ? 'cursor-pointer' : undefined}
            data-visual-curve-legend={index}
            pointerEvents="all"
            key={curve.label}
          onPointerDown={(event) => {
            if (readOnly || !onItemActiveChange) return;

            event.preventDefault();
            event.stopPropagation();
            onItemActiveChange([index]);
          }}
        >
          <rect
            fill="transparent"
            height={18}
            width={132}
            x={width - 148}
            y={curve.y - 11}
          />
          <line
            stroke={curve.color}
            strokeWidth={curve.item?.state === 'active' ? 3 : 2}
            x1={width - 142}
            x2={width - 112}
            y1={curve.y}
            y2={curve.y}
          />
          <EditableSvgText
            x={width - 104}
            y={curve.y + 4}
            textAnchor="start"
            value={curve.label}
            width={104}
            readOnly={readOnly || !onItemValueChange}
            className="fill-slate-700 text-[12px] font-semibold"
            onCommit={(nextValue) =>
              onItemValueChange?.(index, nextValue.replace(/\s+/g, '_'))
            }
          />
        </g>
      ))}
    </PlotFrame>
  );
}

function ConvergenceGraphRenderer({
  document,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const values = getNumericItems(document, [42, 24, 15, 9, 5, 3, 2]);
  const width = 560;
  const height = 260;
  const left = 54;
  const bottom = height - 42;
  const plotWidth = width - 92;
  const plotHeight = height - 76;
  // Plotting Math.abs(value) here used to draw every point as if it were
  // converging smoothly from above, even while the label right next to it
  // (which shows the real signed value) reported a negative overshoot --
  // the shape of the curve and the numbers on it visibly contradicted each
  // other for any oscillating/damped-oscillation sequence. Scale by the
  // actual signed range instead, and let the target line sit wherever 0
  // actually falls in that range (usually near the bottom, same as before,
  // for the common all-positive-shrinking case).
  const maxValue = Math.max(0, ...values);
  const minValue = Math.min(0, ...values);
  const valueSpan = Math.max(12, maxValue - minValue);
  const points = values.map((value, index) => ({
    x:
      left +
      (values.length <= 1 ? plotWidth / 2 : (index / (values.length - 1)) * plotWidth),
    y: bottom - ((value - minValue) / valueSpan) * plotHeight,
  }));
  const targetY = bottom - ((0 - minValue) / valueSpan) * plotHeight;
  const draggingIndexRef = React.useRef<number | null>(null);
  const toValue = React.useCallback(
    (y: number) => roundToStep(minValue + ((bottom - y) / plotHeight) * valueSpan, 0.1),
    [bottom, minValue, plotHeight, valueSpan]
  );

  return (
    <PlotFrame height={height} width={width}>
      <line
        stroke="#14b8a6"
        strokeDasharray="5 5"
        strokeWidth={1.4}
        x1={left}
        x2={width - 32}
        y1={targetY}
        y2={targetY}
      />
      <polyline
        fill="none"
        points={toPolylinePoints(points)}
        stroke={ACTIVE_STROKE}
        strokeLinejoin="round"
        strokeWidth={2}
      />
      {points.map((point, index) => (
        <g
          className={!readOnly && onItemValueChange ? 'cursor-move' : undefined}
          key={`${point.x}-${point.y}`}
        >
          <circle
            cx={point.x}
            cy={point.y}
            fill={index === points.length - 1 ? '#dcfce7' : '#ffffff'}
            r={5}
            stroke={index === points.length - 1 ? '#0f766e' : ACTIVE_STROKE}
            strokeWidth={1.6}
            onPointerDown={(event) => {
              if (readOnly || !onItemValueChange) return;

              event.preventDefault();
              event.stopPropagation();
              draggingIndexRef.current = index;
              event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMove={(event) => {
              if (readOnly || !onItemValueChange || draggingIndexRef.current !== index) {
                return;
              }

              const svgPoint = getSvgPoint(
                event.currentTarget.ownerSVGElement,
                event.clientX,
                event.clientY
              );

              if (!svgPoint) return;

              onItemValueChange(
                index,
                formatEditableNumber(toValue(clamp(svgPoint.y, bottom - plotHeight, bottom)))
              );
            }}
            onPointerUp={() => {
              draggingIndexRef.current = null;
            }}
          />
          <EditableSvgText
            dataVisualEdgeLabel
            x={point.x}
            y={point.y - 10}
            value={values[index]}
            readOnly={readOnly || !onItemValueChange}
            className="fill-slate-600 text-[11px] font-semibold"
            onCommit={(nextValue) => onItemValueChange?.(index, nextValue)}
          />
        </g>
      ))}
      <g>
        <rect
          data-visual-label-plate
          fill="#ffffff"
          height={20}
          rx={6}
          stroke="#ccfbf1"
          strokeWidth={1}
          width={54}
          x={width - 112}
          y={targetY - 26}
        />
        <text
          data-visual-edge-label
          x={width - 85}
          y={targetY - 12}
          textAnchor="middle"
          className="fill-teal-700 text-[12px] font-semibold"
        >
          target
        </text>
      </g>
    </PlotFrame>
  );
}

function EvolutionPathRenderer({
  document,
  onEdgeLabelChange,
  onNodeLabelChange,
  onNodeMove,
  readOnly,
}: {
  document: VisualizationDocument;
  onEdgeLabelChange?: (edge: VisualizationEdge, label: string) => void;
  onNodeLabelChange?: (nodeId: string, label: string) => void;
  onNodeMove?: (nodeId: string, x: number, y: number) => void;
  readOnly?: boolean;
}) {
  const nodes = document.model.nodes ?? [];
  const edges = document.model.edges ?? [];
  const nodesById = new Map(nodes.map((node) => [node.id, node]));
  const bounds = getBounds(nodes, { minX: 0, minY: 0, width: 560, height: 260 });
  const centerY = bounds.minY + bounds.height / 2;
  const dragRef = React.useRef<{
    id: string;
    pointerX: number;
    pointerY: number;
    startX: number;
    startY: number;
  } | null>(null);
  const updateNodeFromPointer = React.useCallback(
    (event: React.PointerEvent<SVGElement>, node: VisualizationNode) => {
      if (readOnly || !onNodeMove || dragRef.current?.id !== node.id) {
        return;
      }

      const point = getSvgPoint(
        event.currentTarget.ownerSVGElement,
        event.clientX,
        event.clientY
      );

      if (!point) return;

      onNodeMove(
        node.id,
        Math.round(dragRef.current.startX + point.x - dragRef.current.pointerX),
        Math.round(dragRef.current.startY + point.y - dragRef.current.pointerY)
      );
    },
    [onNodeMove, readOnly]
  );

  return (
    <Canvas bounds={bounds}>
      {edges.map((edge) => {
        const from = nodesById.get(edge.from);
        const to = nodesById.get(edge.to);

        if (!from || !to) {
          return null;
        }

        return (
          <g key={edge.id}>
            <line
              markerEnd="url(#arrow-small)"
              stroke={edge.state === 'active' ? ACTIVE_STROKE : '#94a3b8'}
              strokeWidth={edge.state === 'active' ? 2.2 : 1.5}
              x1={from.position.x}
              x2={to.position.x}
              y1={from.position.y}
              y2={to.position.y}
            />
            {edge.label && (
              <EditableSvgText
                dataVisualEdgeLabel
                x={(from.position.x + to.position.x) / 2}
                y={(from.position.y + to.position.y) / 2 - 10}
                value={edge.label}
                readOnly={readOnly || !onEdgeLabelChange}
                className="fill-slate-700 text-[11px] font-semibold"
                onCommit={(nextValue) => onEdgeLabelChange?.(edge, nextValue)}
              />
            )}
          </g>
        );
      })}
      {nodes.map((node) => {
        const labelY =
          node.label === 'candidate'
            ? node.position.y + 54
            : node.position.y <= centerY
              ? node.position.y - 22
              : node.position.y + 28;

        return (
          <g
            className={!readOnly && onNodeMove ? 'cursor-move' : undefined}
            key={node.id}
            onPointerDown={(event) => {
              if (readOnly || !onNodeMove) return;

              const point = getSvgPoint(
                event.currentTarget.ownerSVGElement,
                event.clientX,
                event.clientY
              );

              if (!point) return;

              event.preventDefault();
              event.stopPropagation();
              dragRef.current = {
                id: node.id,
                pointerX: point.x,
                pointerY: point.y,
                startX: node.position.x,
                startY: node.position.y,
              };
              event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMove={(event) => updateNodeFromPointer(event, node)}
            onPointerUp={() => {
              dragRef.current = null;
            }}
          >
            <circle
              cx={node.position.x}
              cy={node.position.y}
              fill={node.state === 'active' ? '#dbeafe' : '#ffffff'}
              r={node.state === 'active' ? 8 : 5.5}
              stroke={node.state === 'active' ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={node.state === 'active' ? 2.2 : 1.4}
            />
            <EditableSvgText
              x={node.position.x}
              y={labelY}
              value={node.label}
              readOnly={readOnly || !onNodeLabelChange}
              className="fill-slate-700 text-[11px] font-semibold"
              onCommit={(nextValue) => onNodeLabelChange?.(node.id, nextValue)}
            />
          </g>
        );
      })}
    </Canvas>
  );
}

function GanttRenderer({
  document,
  onCommandChange,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCommandChange?: (command: string, value: string) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const tasks =
    items.length > 0
      ? items
      : [
          { id: 'task-1', label: 'Plan', value: 2 },
          { id: 'task-2', label: 'Build', value: 3, state: 'active' as const },
          { id: 'task-3', label: 'Review', value: 1 },
        ];
  const width = 600;
  const height = Math.max(250, 86 + tasks.length * 48);
  const left = 132;
  const top = 54;
  const getTaskName = React.useCallback(
    (task: VisualizationItem, index: number) =>
      String(task.label ?? `Task ${index + 1}`).replace(/_/g, ' '),
    []
  );
  const taskRanges = tasks.map((task, index) => {
    const raw = String(task.value ?? '');
    const [startValue, durationValue] = raw
      .split(/[,;:]/)
      .map((part) => Number(part.trim()));
    const fallbackDuration = Math.max(1, Number(task.value ?? 1));

    if (Number.isFinite(startValue) && Number.isFinite(durationValue)) {
      return {
        duration: Math.max(0.25, durationValue),
        start: Math.max(0, startValue),
      };
    }

    return {
      duration: Number.isFinite(fallbackDuration) ? fallbackDuration : 1,
      start: index * 0.55,
    };
  });
  const maxEnd = Math.max(
    5,
    ...taskRanges.map((range) => range.start + range.duration)
  );
  const unit = (width - left - 42) / maxEnd;
  const tickCount = Math.ceil(maxEnd) + 1;
  const columns = document.model.typeData?.columns;
  const axisLabels = Array.from({ length: tickCount }).map((_, index) =>
    String((Array.isArray(columns) ? columns[index] : undefined) ?? index + 1).replace(/_/g, ' ')
  );
  const dragRef = React.useRef<{
    duration: number;
    index: number;
    mode: 'end' | 'move' | 'start';
    pointerX: number;
    start: number;
  } | null>(null);
  const formatTaskValue = React.useCallback(
    (start: number, duration: number) =>
      `${formatEditableNumber(Math.max(0, start))},${formatEditableNumber(
        Math.max(0.25, duration)
      )}`,
    []
  );
  const formatTaskToken = React.useCallback(
    (task: VisualizationItem, index: number, start: number, duration: number) =>
      `${getTaskName(task, index).trim().replace(/\s+/g, '_')}=${formatTaskValue(
        start,
        duration
      )}`,
    [formatTaskValue, getTaskName]
  );
  const updateAxisLabel = React.useCallback(
    (index: number, label: string) => {
      const nextLabels = [...axisLabels];
      nextLabels[index] = label.trim() ? label.trim().replace(/\s+/g, '_') : `${index + 1}`;
      onCommandChange?.('columns', nextLabels.join(' '));
    },
    [axisLabels, onCommandChange]
  );
  const updateTaskFromPointer = React.useCallback(
    (
      event: React.PointerEvent<SVGElement>,
      index: number,
      mode: 'end' | 'move' | 'start'
    ) => {
      if (readOnly || !onItemValueChange || dragRef.current?.index !== index) {
        return;
      }

      const point = getSvgPoint(
        event.currentTarget.ownerSVGElement,
        event.clientX,
        event.clientY
      );

      if (!point) return;

      const drag = dragRef.current;
      const delta = (point.x - drag.pointerX) / unit;
      let nextStart = drag.start;
      let nextDuration = drag.duration;

      if (mode === 'move') {
        nextStart = Math.max(0, drag.start + delta);
      }

      if (mode === 'start') {
        const nextLeft = clamp(
          drag.start + delta,
          0,
          drag.start + drag.duration - 0.25
        );
        nextDuration = drag.duration + drag.start - nextLeft;
        nextStart = nextLeft;
      }

      if (mode === 'end') {
        nextDuration = Math.max(0.25, drag.duration + delta);
      }

      onItemValueChange(
        index,
        formatTaskToken(
          tasks[index],
          index,
          roundToStep(nextStart, 0.1),
          roundToStep(nextDuration, 0.1)
        )
      );
    },
    [formatTaskToken, onItemValueChange, readOnly, tasks, unit]
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      <line
        stroke="#cbd5e1"
        strokeWidth={1.2}
        x1={left}
        x2={width - 34}
        y1={top - 12}
        y2={top - 12}
      />
      {Array.from({ length: tickCount }).map((_, tick) => (
        <g key={tick}>
          <line
            stroke="#e2e8f0"
            strokeWidth={1}
            x1={left + tick * unit}
            x2={left + tick * unit}
            y1={top - 16}
            y2={height - 34}
          />
          <rect
            data-visual-label-plate
            fill="#ffffff"
            height={22}
            rx={7}
            stroke="#e2e8f0"
            width={54}
            x={left + tick * unit - 27}
            y={top - 39}
          />
          <EditableSvgText
            dataVisualTextTarget="gantt-axis-label"
            x={left + tick * unit}
            y={top - 23}
            value={axisLabels[tick]}
            width={72}
            readOnly={readOnly || !onCommandChange}
            className="fill-slate-500 text-[11px] font-semibold"
            onCommit={(nextValue) => updateAxisLabel(tick, nextValue)}
          />
        </g>
      ))}
      {tasks.map((task, index) => {
        const { duration, start } = taskRanges[index] ?? { duration: 1, start: 0 };
        const y = top + index * 48;
        const x = left + start * unit;
        const barWidth = duration * unit;
        const active = task.state === 'active';
        const startDrag = (
          event: React.PointerEvent<SVGElement>,
          mode: 'end' | 'move' | 'start'
        ) => {
          if (readOnly || !onItemValueChange) return;

          event.preventDefault();
          event.stopPropagation();
          dragRef.current = {
            duration,
            index,
            mode,
            pointerX:
              getSvgPoint(
                event.currentTarget.ownerSVGElement,
                event.clientX,
                event.clientY
              )?.x ?? event.clientX,
            start,
          };
          event.currentTarget.setPointerCapture(event.pointerId);
        };

        return (
          <g
            className={!readOnly && onItemValueChange ? 'cursor-move' : undefined}
            key={task.id}
          >
            <EditableSvgText
              dataVisualTextTarget="gantt-task-label"
              x={24}
              y={y + 22}
              textAnchor="start"
              value={getTaskName(task, index)}
              width={96}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-700 text-[12px] font-semibold"
              onCommit={(nextValue) =>
                onItemValueChange?.(
                  index,
                  formatTaskToken(task, index, start, duration).replace(
                    /^[^=]+=/,
                    `${nextValue.trim().replace(/\s+/g, '_') || `task_${index + 1}`}=`
                  )
                )
              }
            />
            <rect
              fill={active ? '#dbeafe' : '#ffffff'}
              height={28}
              rx={7}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2.2 : 1.4}
              width={barWidth}
              x={x}
              y={y}
              onPointerDown={(event) => startDrag(event, 'move')}
              onPointerMove={(event) => updateTaskFromPointer(event, index, 'move')}
              onPointerUp={() => {
                dragRef.current = null;
              }}
            />
            <rect
              data-visual-control
              x={x - 4}
              y={y + 4}
              width={8}
              height={20}
              rx={4}
              fill="#ffffff"
              stroke={ACTIVE_STROKE}
              strokeWidth={1.4}
              className={!readOnly && onItemValueChange ? 'cursor-ew-resize' : undefined}
              onPointerDown={(event) => startDrag(event, 'start')}
              onPointerMove={(event) => updateTaskFromPointer(event, index, 'start')}
              onPointerUp={() => {
                dragRef.current = null;
              }}
            />
            <rect
              data-visual-control
              x={x + barWidth - 4}
              y={y + 4}
              width={8}
              height={20}
              rx={4}
              fill="#ffffff"
              stroke={ACTIVE_STROKE}
              strokeWidth={1.4}
              className={!readOnly && onItemValueChange ? 'cursor-ew-resize' : undefined}
              onPointerDown={(event) => startDrag(event, 'end')}
              onPointerMove={(event) => updateTaskFromPointer(event, index, 'end')}
              onPointerUp={() => {
                dragRef.current = null;
              }}
            />
            <EditableSvgText
              x={x + barWidth / 2}
              y={y + 19}
              value={`${formatEditableNumber(start)}-${formatEditableNumber(
                start + duration
              )}`}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-950 text-[12px] font-semibold"
              onCommit={(nextValue) => {
                const [nextStart, nextEnd] = nextValue
                  .split(/[-~]/)
                  .map((part) => Number(part.trim()));

                if (Number.isFinite(nextStart) && Number.isFinite(nextEnd)) {
                  onItemValueChange?.(
                    index,
                    formatTaskToken(
                      task,
                      index,
                      nextStart,
                      Math.max(0.25, nextEnd - nextStart)
                    )
                  );
                }
              }}
            />
          </g>
        );
      })}
    </Canvas>
  );
}

function MemoryPageFlowRenderer({
  document,
  onCellValueChange,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onCellValueChange?: (row: number, column: number, value: string) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const references = document.model.items ?? [];
  const cells = document.model.cells ?? [];
  const columns = Math.max(references.length, 1);
  const rows = Math.max(...cells.map((cell) => cell.row), 2) + 1;
  const cellWidth = 48;
  const cellHeight = 34;
  const startX = 120;
  const startY = 58;
  const width = Math.max(560, startX * 2 + columns * cellWidth);
  const height = Math.max(250, startY + 72 + rows * cellHeight);

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      <text x={40} y={startY + 22} className="fill-slate-700 text-[12px] font-semibold">
        reference
      </text>
      {references.map((item, index) => {
        const x = startX + index * cellWidth;
        const active = item.state === 'active';

        return (
          <g
            className={!readOnly && onItemValueChange ? 'cursor-text' : undefined}
            key={item.id}
          >
            <rect
              fill={active ? '#fee2e2' : '#dbeafe'}
              height={30}
              rx={7}
              stroke={active ? '#be123c' : ACTIVE_STROKE}
              strokeWidth={active ? 2 : 1.5}
              width={38}
              x={x}
              y={startY}
            />
            <EditableSvgText
              x={x + 19}
              y={startY + 20}
              value={item.value}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-950 text-[12px] font-bold"
              onCommit={(nextValue) => onItemValueChange?.(item.index ?? 0, nextValue)}
            />
          </g>
        );
      })}
      <text x={40} y={startY + 70} className="fill-slate-700 text-[12px] font-semibold">
        frames
      </text>
      {cells.map((cell) => {
        const x = startX + cell.column * cellWidth;
        const y = startY + 50 + cell.row * cellHeight;

        return (
          <g
            className={!readOnly && onCellValueChange ? 'cursor-text' : undefined}
            key={cell.id}
          >
            <rect
              fill={cell.state === 'active' ? '#dbeafe' : '#ffffff'}
              height={cellHeight}
              rx={4}
              stroke={cell.state === 'active' ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={cell.state === 'active' ? 2 : 1.2}
              width={cellWidth}
              x={x}
              y={y}
            />
            <EditableSvgText
              x={x + cellWidth / 2}
              y={y + 22}
              value={cell.value}
              readOnly={readOnly || !onCellValueChange}
              className="fill-slate-700 text-[11px] font-semibold"
              onCommit={(nextValue) => onCellValueChange?.(cell.row, cell.column, nextValue)}
            />
          </g>
        );
      })}
      <text x={startX + columns * cellWidth + 10} y={startY + 72} className="fill-rose-700 text-[12px] font-semibold">
        page fault
      </text>
    </Canvas>
  );
}

function CompositeRenderer({
  document,
  onItemActiveChange,
  onItemCreate,
  onItemDelete,
  onItemValueChange,
  readOnly,
}: {
  document: VisualizationDocument;
  onItemActiveChange?: (itemIndexes: number[]) => void;
  onItemCreate?: () => void;
  onItemDelete?: (itemIndex: number) => void;
  onItemValueChange?: (itemIndex: number, value: string) => void;
  readOnly?: boolean;
}) {
  const items = document.model.items ?? [];
  const graphA = String(items[0]?.value ?? 'A').replace(/_/g, ' ');
  const graphB = String(items[1]?.value ?? 'B').replace(/_/g, ' ');
  const syncedNote = String(
    items[2]?.value ?? 'visual + table + explanation in one synced step'
  ).replace(/_/g, ' ');
  const traceValues = Array.from({ length: 4 }, (_, index) =>
    String(items[3 + index]?.value ?? (index === 2 ? '1' : '0')).replace(/_/g, ' ')
  );
  const extraItems = items.slice(7);
  const width = 620;
  const height = 270;
  const activateItem = React.useCallback(
    (itemIndex: number) => {
      if (readOnly || !onItemActiveChange) return;

      onItemActiveChange([itemIndex]);
    },
    [onItemActiveChange, readOnly]
  );

  return (
    <Canvas bounds={{ minX: 0, minY: 0, width, height }}>
      {!readOnly && onItemCreate && (
        <g
          data-visual-composite-add
          role="button"
          tabIndex={0}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
            onItemCreate();
          }}
          onPointerDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          <rect fill="#ffffff" height={26} rx={8} stroke="#cbd5e1" width={78} x={18} y={16} />
          <text x={57} y={33} textAnchor="middle" className="fill-slate-700 text-[11px] font-semibold">
            Add value
          </text>
        </g>
      )}
      <rect fill="#ffffff" height={86} rx={10} stroke={NORMAL_STROKE} strokeWidth={1.4} width={170} x={74} y={58} />
      <text x={159} y={86} textAnchor="middle" className="fill-slate-950 text-[13px] font-bold">
        Graph
      </text>
      <g
        data-visual-composite-item="0"
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
          activateItem(0);
        }}
        onPointerDown={(event) => {
          event.preventDefault();
          event.stopPropagation();
          activateItem(0);
        }}
      >
        <circle
          cx={130}
          cy={118}
          fill={items[0]?.state === 'active' ? '#bbf7d0' : '#dcfce7'}
          r={24}
          stroke={items[0]?.state === 'active' ? ACTIVE_STROKE : '#0f766e'}
          strokeWidth={items[0]?.state === 'active' ? 2.2 : 1.8}
        />
        <EditableSvgText
          x={130}
          y={123}
          value={graphA}
          readOnly={readOnly || !onItemValueChange}
          className="fill-slate-950 text-[12px] font-bold"
          onCommit={(nextValue) => onItemValueChange?.(0, nextValue.replace(/\s+/g, '_'))}
        />
      </g>
      <g
        data-visual-composite-item="1"
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
          activateItem(1);
        }}
        onPointerDown={(event) => {
          event.preventDefault();
          event.stopPropagation();
          activateItem(1);
        }}
      >
        <circle
          cx={190}
          cy={118}
          fill="#dbeafe"
          r={24}
          stroke={items[1]?.state === 'active' ? ACTIVE_STROKE : NORMAL_STROKE}
          strokeWidth={items[1]?.state === 'active' ? 2.2 : 1.8}
        />
        <EditableSvgText
          x={190}
          y={123}
          value={graphB}
          readOnly={readOnly || !onItemValueChange}
          className="fill-slate-950 text-[12px] font-bold"
          onCommit={(nextValue) => onItemValueChange?.(1, nextValue.replace(/\s+/g, '_'))}
        />
      </g>
      <line markerEnd="url(#arrow-small)" stroke={ACTIVE_STROKE} strokeWidth={1.6} x1={154} x2={166} y1={118} y2={118} />
      <rect fill="#ffffff" height={108} rx={10} stroke={NORMAL_STROKE} strokeWidth={1.4} width={170} x={330} y={58} />
      <text x={415} y={86} textAnchor="middle" className="fill-slate-950 text-[13px] font-bold">
        Trace
      </text>
      {traceValues.map((value, index) => {
        const itemIndex = 3 + index;
        const active = items[itemIndex]?.state === 'active';
        const x = 382 + (index % 2) * 52;
        const y = 100 + Math.floor(index / 2) * 30;

        return (
          <g
            data-visual-composite-item={itemIndex}
            key={itemIndex}
            onClick={(event) => {
              event.preventDefault();
              event.stopPropagation();
              activateItem(itemIndex);
            }}
            onPointerDown={(event) => {
              event.preventDefault();
              event.stopPropagation();
              activateItem(itemIndex);
            }}
          >
            <rect
              fill={active ? '#dbeafe' : '#ffffff'}
              height={24}
              rx={4}
              stroke={active ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={active ? 2 : 1.5}
              width={42}
              x={x}
              y={y}
            />
            <EditableSvgText
              x={x + 21}
              y={y + 17}
              value={value}
              width={36}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-900 text-[10px] font-semibold"
              onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue.replace(/\s+/g, '_'))}
            />
          </g>
        );
      })}
      <g
        data-visual-composite-item="2"
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
          activateItem(2);
        }}
        onPointerDown={(event) => {
          event.preventDefault();
          event.stopPropagation();
          activateItem(2);
        }}
      >
        <rect
          fill={items[2]?.state === 'active' ? '#dbeafe' : '#eff6ff'}
          height={48}
          rx={10}
          stroke={items[2]?.state === 'active' ? ACTIVE_STROKE : NORMAL_STROKE}
          strokeWidth={items[2]?.state === 'active' ? 2 : 1.4}
          width={340}
          x={140}
          y={184}
        />
        <EditableSvgText
          x={310}
          y={214}
          value={syncedNote}
          width={320}
          readOnly={readOnly || !onItemValueChange}
          className="fill-slate-950 text-[13px] font-semibold"
          onCommit={(nextValue) => onItemValueChange?.(2, nextValue.replace(/\s+/g, '_'))}
        />
      </g>
      {extraItems.map((item, index) => {
        const itemIndex = index + 7;
        const x = 110 + index * 82;
        const y = 242;

        return (
          <g key={item.id}>
            <rect
              data-visual-composite-item={itemIndex}
              fill={item.state === 'active' ? '#dbeafe' : '#ffffff'}
              height={22}
              rx={7}
              stroke={item.state === 'active' ? ACTIVE_STROKE : NORMAL_STROKE}
              strokeWidth={item.state === 'active' ? 2 : 1.2}
              width={70}
              x={x}
              y={y}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                activateItem(itemIndex);
              }}
              onPointerDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
                activateItem(itemIndex);
              }}
            />
            <EditableSvgText
              x={x + 35}
              y={y + 15}
              value={String(item.value ?? '').replace(/_/g, ' ')}
              width={60}
              readOnly={readOnly || !onItemValueChange}
              className="fill-slate-800 text-[10px] font-semibold"
              onCommit={(nextValue) => onItemValueChange?.(itemIndex, nextValue.replace(/\s+/g, '_'))}
            />
            {!readOnly && onItemDelete && (
              <g
                data-visual-composite-delete
                role="button"
                tabIndex={0}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  onItemDelete(itemIndex);
                }}
                onPointerDown={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                }}
              >
                <circle cx={x + 68} cy={y} fill="#ffffff" r={8} stroke="#fecaca" />
                <text x={x + 68} y={y + 4} textAnchor="middle" className="fill-red-600 text-[10px] font-bold">
                  ×
                </text>
              </g>
            )}
          </g>
        );
      })}
    </Canvas>
  );
}

function UnsupportedRenderer({
  document,
}: {
  document: VisualizationDocument;
}) {
  return (
    <div className="flex min-h-[260px] items-center justify-center rounded-lg border border-dashed bg-muted/30 p-6 text-center">
      <div>
        <div className="text-sm font-semibold text-foreground">
          {document.visualType} renderer is not ready
        </div>
        <p className="mt-2 max-w-sm text-xs leading-5 text-muted-foreground">
          This type is registered in the visualization engine, but its SVG renderer is not
          connected yet.
        </p>
      </div>
    </div>
  );
}

export function VisualizationRenderer({
  currentStepId,
  document,
  onCellValueChange,
  onEdgeAnchorChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeEndpointsChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onEdgeRouteChange,
  onEdgeStateChange,
  onItemCreate,
  onItemDelete,
  onItemActiveChange,
  onItemValueChange,
  onCellActiveChange,
  onCommandChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  readOnly,
}: VisualizationRendererProps) {
  const parsedDocument = React.useMemo(() => {
    const parsed = parseSource(document);

    return applyVisualizationStepOverlay(parsed, currentStepId);
  }, [currentStepId, document]);

  switch (parsedDocument.visualType) {
    case 'flowchart':
      return (
        <FlowchartRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onEdgeAnchorChange={onEdgeAnchorChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeShapeChange={onNodeShapeChange}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'network':
      return (
        <NetworkRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeDirectionChange={onEdgeDirectionChange}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeShapeChange={onNodeShapeChange}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'state-diagram':
      return (
        <NodeEdgeRenderer
          document={parsedDocument}
          forceEdgeKind="straight"
          allowSelfConnection
          nodeShape="circle"
          nodeSize={{ width: 72, height: 72 }}
          readOnly={readOnly}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeShapeChange={onNodeShapeChange}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'tree':
      return (
        <NodeEdgeRenderer
          anchorMode="hierarchical"
          directedEdges
          document={parsedDocument}
          edgeKind="straight"
          nodeShape="circle"
          nodeSize={{ width: 64, height: 64 }}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeDirectionChange={onEdgeDirectionChange}
          onEdgeLabelChange={onEdgeLabelChange}
          onEdgeLineStyleChange={onEdgeLineStyleChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeShapeChange={onNodeShapeChange}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'trie':
    case 'segment-tree':
    case 'recursion-tree':
    case 'divide-conquer':
    case 'state-space-tree':
    case 'decision-tree':
      return (
        <NodeEdgeRenderer
          directedEdges={false}
          document={parsedDocument}
          edgeKind="straight"
          nodeShape="rounded-rectangle"
          nodeSize={{ width: 92, height: 44 }}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeDirectionChange={onEdgeDirectionChange}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeShapeChange={onNodeShapeChange}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'geometry-plane':
      return (
        <GeometryPlaneRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'vector-space':
      return (
        <VectorSpaceRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
        />
      );
    case 'neural-network':
      return (
        <NeuralNetworkRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeDirectionChange={onEdgeDirectionChange}
          onEdgeLabelChange={onEdgeLabelChange}
          onEdgeStateChange={onEdgeStateChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'trace-table':
      return (
        <TraceTableRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCellActiveChange={onCellActiveChange}
          onCellValueChange={onCellValueChange}
        />
      );
    case 'sequence-diagram':
      return (
        <SequenceDiagramRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onEdgeCreate={onEdgeCreate}
          onEdgeDelete={onEdgeDelete}
          onEdgeEndpointsChange={onEdgeEndpointsChange}
          onEdgeLabelChange={onEdgeLabelChange}
          onEdgeRouteChange={onEdgeRouteChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'storyboard':
      return (
        <StoryboardRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemValueChange={onItemValueChange}
          onNodeCreate={onNodeCreate}
          onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeStateChange={onNodeStateChange}
        />
      );
    case 'array-grid':
      return (
        <ArrayGridRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'linked-list':
      return (
        <LinkedListRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'stack':
      return (
        <StackQueueRenderer
          document={parsedDocument}
          mode="stack"
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'queue':
      return (
        <StackQueueRenderer
          document={parsedDocument}
          mode="queue"
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'stack-queue':
      return (
        <StackQueueRenderer
          document={parsedDocument}
          mode="both"
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'hash-table':
      return (
        <HashTableRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'sorting':
      return (
        <SortingRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'searching':
      return (
        <SearchingRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'dp-table':
      return (
        <DpTableRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onCellActiveChange={onCellActiveChange}
          onCellValueChange={onCellValueChange}
        />
      );
    case 'scatter':
      return (
        <ScatterRenderer
          document={parsedDocument}
          onCommandChange={onCommandChange}
          readOnly={readOnly}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'histogram':
      return (
        <HistogramRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'line-graph':
      return (
        <LineGraphRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'heatmap':
      return (
        <MatrixRenderer
          colorMode="heat"
          document={parsedDocument}
          readOnly={readOnly}
          onCellActiveChange={onCellActiveChange}
          onCellValueChange={onCellValueChange}
        />
      );
    case 'complexity-chart':
      return (
        <ComplexityChartRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'path-map':
      return (
        <MatrixRenderer
          colorMode="path"
          document={parsedDocument}
          readOnly={readOnly}
          onCellActiveChange={onCellActiveChange}
          onCellValueChange={onCellValueChange}
        />
      );
    case 'convergence-graph':
      return (
        <ConvergenceGraphRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'bit-matrix':
      return (
        <MatrixRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCellActiveChange={onCellActiveChange}
          onCellValueChange={onCellValueChange}
        />
      );
    case 'sieve-matrix':
      return (
        <SieveMatrixRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCellActiveChange={onCellActiveChange}
          onCellValueChange={onCellValueChange}
        />
      );
    case 'evolution-path':
      return (
        <EvolutionPathRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onEdgeLabelChange={onEdgeLabelChange}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
        />
      );
    case 'gantt':
      return (
        <GanttRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCommandChange={onCommandChange}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'memory-page-flow':
      return (
        <MemoryPageFlowRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onCellValueChange={onCellValueChange}
          onItemValueChange={onItemValueChange}
        />
      );
    case 'composite':
      return (
        <CompositeRenderer
          document={parsedDocument}
          readOnly={readOnly}
          onItemActiveChange={onItemActiveChange}
          onItemCreate={onItemCreate}
          onItemDelete={onItemDelete}
          onItemValueChange={onItemValueChange}
        />
      );
    default:
      return <UnsupportedRenderer document={parsedDocument} />;
  }
}

import type { VisualizationType } from './visualization-types';

export type ActiveVisualizationType = Exclude<VisualizationType, 'stack-queue'>;

export type VisualizationEditTargetKind =
  | 'bar'
  | 'cell'
  | 'container'
  | 'edge'
  | 'item'
  | 'label'
  | 'node'
  | 'point'
  | 'range'
  | 'step';

export type VisualizationMenuScope = 'canvas' | 'selection';

export type VisualizationMenuAction = {
  id: string;
  label: string;
  objectKinds?: readonly VisualizationEditTargetKind[];
  scope: VisualizationMenuScope;
};

export type VisualizationEdgePolicy = {
  allowBendPoints: boolean;
  defaultSourceAnchors?: readonly ('bottom' | 'left' | 'right' | 'top')[];
  defaultTargetAnchors?: readonly ('bottom' | 'left' | 'right' | 'top')[];
  directed: boolean;
  labelAvoidsEdge: boolean;
  lineStyle: 'none' | 'orthogonal' | 'straight';
  showCrossingMarker: boolean;
};

export type VisualizationEditingContract = {
  canvasMenu: readonly VisualizationMenuAction[];
  editTargets: readonly VisualizationEditTargetKind[];
  edgePolicy: VisualizationEdgePolicy;
  selectedMenu: readonly VisualizationMenuAction[];
  summary: string;
  type: ActiveVisualizationType;
};

export type VisualizationFloatingMenuLabels = {
  addColumn: string;
  addItem: string;
  addRow: string;
  nodeHint: string;
  removeColumn: string;
  removeItem: string;
  removeRow: string;
};

export type VisualizationFloatingActionKind =
  | 'column-add'
  | 'column-delete'
  | 'hash-bucket-add'
  | 'hash-bucket-delete'
  | 'hash-key-add'
  | 'hash-key-delete'
  | 'item-add'
  | 'item-delete'
  | 'node-hint'
  | 'row-add'
  | 'row-delete'
  | 'vector-add'
  | 'vector-boundary-add'
  | 'vector-point-add'
  | 'vector-projection-toggle';

const deleteAction: VisualizationMenuAction = {
  id: 'delete',
  label: 'Delete',
  scope: 'selection',
};

const duplicateAction: VisualizationMenuAction = {
  id: 'duplicate',
  label: 'Duplicate',
  scope: 'selection',
};

const editLabelAction: VisualizationMenuAction = {
  id: 'edit-label',
  label: 'Edit text',
  objectKinds: ['label', 'node', 'edge', 'item', 'cell'],
  scope: 'selection',
};

const directedStraightEdge: VisualizationEdgePolicy = {
  allowBendPoints: false,
  directed: true,
  labelAvoidsEdge: true,
  lineStyle: 'straight',
  showCrossingMarker: true,
};

const undirectedStraightEdge: VisualizationEdgePolicy = {
  allowBendPoints: false,
  directed: false,
  labelAvoidsEdge: true,
  lineStyle: 'straight',
  showCrossingMarker: false,
};

const treeEdge: VisualizationEdgePolicy = {
  allowBendPoints: false,
  directed: true,
  labelAvoidsEdge: true,
  lineStyle: 'straight',
  showCrossingMarker: false,
};

const noEdge: VisualizationEdgePolicy = {
  allowBendPoints: false,
  directed: false,
  labelAvoidsEdge: false,
  lineStyle: 'none',
  showCrossingMarker: false,
};

const flowchartEdge: VisualizationEdgePolicy = {
  allowBendPoints: true,
  defaultSourceAnchors: ['bottom', 'right'],
  defaultTargetAnchors: ['top', 'left'],
  directed: true,
  labelAvoidsEdge: true,
  lineStyle: 'orthogonal',
  showCrossingMarker: true,
};

const nodeSelectionMenu = [
  { id: 'connect', label: 'Start connection', objectKinds: ['node'], scope: 'selection' },
  editLabelAction,
  duplicateAction,
  deleteAction,
] as const satisfies readonly VisualizationMenuAction[];

const itemSelectionMenu = [
  editLabelAction,
  { id: 'highlight', label: 'Highlight', objectKinds: ['item'], scope: 'selection' },
  deleteAction,
] as const satisfies readonly VisualizationMenuAction[];

const cellSelectionMenu = [
  editLabelAction,
  { id: 'set-current', label: 'Mark current cell', objectKinds: ['cell'], scope: 'selection' },
  deleteAction,
] as const satisfies readonly VisualizationMenuAction[];

const pointSelectionMenu = [
  editLabelAction,
  { id: 'show-coordinate', label: 'Show coordinates', objectKinds: ['point'], scope: 'selection' },
  deleteAction,
] as const satisfies readonly VisualizationMenuAction[];

export const ACTIVE_VISUALIZATION_TYPES = [
  'flowchart',
  'state-diagram',
  'trace-table',
  'sequence-diagram',
  'storyboard',
  'array-grid',
  'linked-list',
  'stack',
  'queue',
  'hash-table',
  'tree',
  'network',
  'trie',
  'segment-tree',
  'sorting',
  'searching',
  'recursion-tree',
  'divide-conquer',
  'dp-table',
  'state-space-tree',
  'scatter',
  'histogram',
  'line-graph',
  'heatmap',
  'complexity-chart',
  'path-map',
  'geometry-plane',
  'convergence-graph',
  'bit-matrix',
  'sieve-matrix',
  'vector-space',
  'neural-network',
  'decision-tree',
  'evolution-path',
  'gantt',
  'memory-page-flow',
  'composite',
] as const satisfies readonly ActiveVisualizationType[];

export const LEGACY_VISUALIZATION_TYPES = [
  'stack-queue',
] as const satisfies readonly VisualizationType[];

export const VISUALIZATION_EDITING_CONTRACTS = {
  flowchart: {
    canvasMenu: [
      { id: 'add-terminator', label: 'Add start/end shape', scope: 'canvas' },
      { id: 'add-process', label: 'Add process shape', scope: 'canvas' },
      { id: 'add-input', label: 'Add input shape', scope: 'canvas' },
      { id: 'add-decision', label: 'Add decision shape', scope: 'canvas' },
      { id: 'add-output', label: 'Add output shape', scope: 'canvas' },
      { id: 'add-flow-line', label: 'Connect flow line', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: flowchartEdge,
    selectedMenu: [
      { id: 'change-shape', label: 'Change shape type', objectKinds: ['node'], scope: 'selection' },
      { id: 'change-anchor', label: 'Adjust flow-line anchors', objectKinds: ['edge'], scope: 'selection' },
      editLabelAction,
      duplicateAction,
      deleteAction,
    ],
    summary:
      '도형은 드래그로 이동하고, Shift 드래그로 아래/오른쪽에서 나가 위/왼쪽으로 들어오는 직각 흐름선을 연결합니다.',
    type: 'flowchart',
  },
  'state-diagram': {
    canvasMenu: [
      { id: 'add-state', label: 'Add state', scope: 'canvas' },
      { id: 'add-transition', label: 'Connect transition', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'add-self-transition', label: 'Add self transition', objectKinds: ['node'], scope: 'selection' },
      { id: 'set-active-state', label: 'Mark active state', objectKinds: ['node'], scope: 'selection' },
      { id: 'set-final-state', label: 'Mark final state', objectKinds: ['node'], scope: 'selection' },
    ],
    summary: '상태 노드를 드래그로 이동하고 전이 라벨을 더블클릭으로 수정합니다.',
    type: 'state-diagram',
  },
  'trace-table': {
    canvasMenu: [
      { id: 'add-row', label: 'Add row', scope: 'canvas' },
      { id: 'add-column', label: 'Add column', scope: 'canvas' },
    ],
    editTargets: ['cell', 'label', 'step'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-current-row', label: 'Mark current row', objectKinds: ['cell', 'step'], scope: 'selection' },
      { id: 'delete-row-column', label: 'Delete row/column', objectKinds: ['cell'], scope: 'selection' },
    ],
    summary: '변수 값 셀을 더블클릭해 수정하고 현재 실행 행을 클릭으로 지정합니다.',
    type: 'trace-table',
  },
  'sequence-diagram': {
    canvasMenu: [
      { id: 'add-actor', label: 'Add actor', scope: 'canvas' },
      { id: 'add-message', label: 'Connect message', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'toggle-return-message', label: 'Show return message', objectKinds: ['edge'], scope: 'selection' },
      duplicateAction,
      deleteAction,
    ],
    summary: '참여자를 좌우로 드래그하고 메시지 라벨을 직접 수정합니다.',
    type: 'sequence-diagram',
  },
  storyboard: {
    canvasMenu: [
      { id: 'add-scene', label: 'Add scene', scope: 'canvas' },
      { id: 'insert-scene', label: 'Insert scene before/after', scope: 'canvas' },
    ],
    editTargets: ['node', 'label', 'step'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'edit-description', label: 'Edit description', objectKinds: ['label', 'node'], scope: 'selection' },
      { id: 'set-scene-state', label: 'Mark scene state', objectKinds: ['node', 'step'], scope: 'selection' },
      duplicateAction,
      { id: 'insert-before', label: 'Insert before', objectKinds: ['node', 'step'], scope: 'selection' },
      { id: 'insert-after', label: 'Insert after', objectKinds: ['node', 'step'], scope: 'selection' },
      { id: 'reorder-scene', label: 'Reorder scene', objectKinds: ['node', 'step'], scope: 'selection' },
      deleteAction,
    ],
    summary: '학습 장면 카드를 드래그로 재배치하고 제목과 설명을 더블클릭으로 수정합니다.',
    type: 'storyboard',
  },
  'array-grid': {
    canvasMenu: [
      { id: 'add-item', label: 'Add item', scope: 'canvas' },
      { id: 'set-range', label: 'Show search range', scope: 'canvas' },
    ],
    editTargets: ['item', 'label', 'range'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-pointer', label: 'Set pointer', objectKinds: ['item'], scope: 'selection' },
      { id: 'insert-before', label: 'Insert before', objectKinds: ['item'], scope: 'selection' },
      deleteAction,
    ],
    summary: '배열 값을 더블클릭해 수정하고 드래그로 low/mid/high 같은 포인터 위치를 조정합니다.',
    type: 'array-grid',
  },
  'linked-list': {
    canvasMenu: [
      { id: 'add-node', label: 'Add node', scope: 'canvas' },
      { id: 'insert-head', label: 'Insert before head', scope: 'canvas' },
      { id: 'insert-between', label: 'Insert between', scope: 'canvas' },
      { id: 'connect-next', label: 'Connect next pointer', scope: 'canvas' },
    ],
    editTargets: ['item', 'edge', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-head', label: 'Mark head', objectKinds: ['item'], scope: 'selection' },
      { id: 'set-tail', label: 'Mark tail', objectKinds: ['item'], scope: 'selection' },
      { id: 'insert-before', label: 'Insert before', objectKinds: ['item'], scope: 'selection' },
      { id: 'insert-after', label: 'Insert after', objectKinds: ['item'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'head 삽입, 중간 삽입, 값 수정, next 연결을 화면에서 직접 수행합니다.',
    type: 'linked-list',
  },
  stack: {
    canvasMenu: [
      { id: 'push', label: 'Push item', scope: 'canvas' },
      { id: 'pop', label: 'Pop item', scope: 'canvas' },
    ],
    editTargets: ['item', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-top', label: 'Mark top', objectKinds: ['item'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'Stack 항목을 위아래로 표시하고 top 위치를 클릭으로 지정합니다.',
    type: 'stack',
  },
  queue: {
    canvasMenu: [
      { id: 'enqueue', label: 'Enqueue item', scope: 'canvas' },
      { id: 'dequeue', label: 'Dequeue item', scope: 'canvas' },
    ],
    editTargets: ['item', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-front', label: 'Mark front', objectKinds: ['item'], scope: 'selection' },
      { id: 'set-rear', label: 'Mark rear', objectKinds: ['item'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'Queue 항목을 좌우로 표시하고 front/rear 위치를 클릭으로 지정합니다.',
    type: 'queue',
  },
  'hash-table': {
    canvasMenu: [
      { id: 'add-bucket', label: 'Add bucket', scope: 'canvas' },
      { id: 'add-key', label: 'Add key', scope: 'canvas' },
      { id: 'add-chain', label: 'Connect collision chain', scope: 'canvas' },
    ],
    editTargets: ['item', 'edge', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'move-to-bucket', label: 'Move to bucket', objectKinds: ['item'], scope: 'selection' },
      { id: 'mark-collision', label: 'Mark collision', objectKinds: ['item', 'edge'], scope: 'selection' },
      deleteAction,
    ],
    summary: '버킷 수를 정하고 key를 원하는 버킷에 직접 추가해 collision chain을 표현합니다.',
    type: 'hash-table',
  },
  tree: {
    canvasMenu: [
      { id: 'add-node', label: 'Add node', scope: 'canvas' },
      { id: 'add-child', label: 'Connect child node', scope: 'canvas' },
      { id: 'select-subtype', label: 'Select tree type', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: treeEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'set-root', label: 'Mark root', objectKinds: ['node'], scope: 'selection' },
      { id: 'set-traversal-direction', label: 'Choose traversal arrow', objectKinds: ['edge'], scope: 'selection' },
      { id: 'set-child-dashed-edge', label: 'Use dashed child edge', objectKinds: ['edge'], scope: 'selection' },
      { id: 'set-balance-info', label: 'Show balance/color info', objectKinds: ['node'], scope: 'selection' },
      { id: 'mark-red', label: 'Red node', objectKinds: ['node'], scope: 'selection' },
      { id: 'mark-black', label: 'Black/default node', objectKinds: ['node'], scope: 'selection' },
    ],
    summary: 'Binary Tree, B-Tree, B+Tree, AVL, Red-Black Tree를 직선 간선으로 편집합니다.',
    type: 'tree',
  },
  network: {
    canvasMenu: [
      { id: 'add-node', label: 'Add node', scope: 'canvas' },
      { id: 'add-edge', label: 'Connect edge', scope: 'canvas' },
      { id: 'toggle-directed', label: 'Toggle direction', scope: 'canvas' },
      { id: 'toggle-weighted', label: 'Toggle weights', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'edit-weight', label: 'Edit weight', objectKinds: ['edge', 'label'], scope: 'selection' },
      { id: 'toggle-direction', label: 'Toggle direction', objectKinds: ['edge'], scope: 'selection' },
      { id: 'toggle-weight', label: 'Show/hide weight', objectKinds: ['edge', 'label'], scope: 'selection' },
    ],
    summary: '정점 위치를 드래그하고 간선 가중치는 선과 겹치지 않는 위치에서 수정합니다.',
    type: 'network',
  },
  trie: {
    canvasMenu: [
      { id: 'add-character-node', label: 'Add character node', scope: 'canvas' },
      { id: 'add-prefix-edge', label: 'Connect prefix edge', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'toggle-terminal', label: 'Toggle terminal', objectKinds: ['node'], scope: 'selection' },
      { id: 'edit-edge-label', label: 'Edit edge label', objectKinds: ['edge', 'label'], scope: 'selection' },
    ],
    summary: '문자 노드와 prefix 간선 라벨을 직접 수정하고 terminal 표시를 전환합니다.',
    type: 'trie',
  },
  'segment-tree': {
    canvasMenu: [
      { id: 'add-range-node', label: 'Add range node', scope: 'canvas' },
      { id: 'add-query-range', label: 'Show query range', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label', 'range'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'edit-range-value', label: 'Edit range/value', objectKinds: ['node', 'label'], scope: 'selection' },
      { id: 'mark-query-hit', label: 'Mark query hit', objectKinds: ['node'], scope: 'selection' },
    ],
    summary: '구간 노드와 query 범위를 직접 선택해 수정합니다.',
    type: 'segment-tree',
  },
  sorting: {
    canvasMenu: [
      { id: 'add-value', label: 'Add value', scope: 'canvas' },
      { id: 'set-compare', label: 'Set compare marker', scope: 'canvas' },
      { id: 'set-swap', label: 'Show swap arrow', scope: 'canvas' },
      { id: 'set-sort-mode', label: 'Choose compare/swap mode', scope: 'canvas' },
    ],
    editTargets: ['bar', 'item', 'label', 'step'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-compare', label: 'Set compare marker', objectKinds: ['bar', 'item'], scope: 'selection' },
      { id: 'set-swap', label: 'Show swap arrow', objectKinds: ['bar', 'item'], scope: 'selection' },
      { id: 'set-sorted', label: 'Set Sorted', objectKinds: ['bar', 'item'], scope: 'selection' },
      deleteAction,
    ],
    summary: '비교만, 교환만, 비교+교환 모드를 고르고 두 막대를 마우스로 선택해 표시합니다.',
    type: 'sorting',
  },
  searching: {
    canvasMenu: [
      { id: 'add-value', label: 'Add value', scope: 'canvas' },
      { id: 'insert-value', label: 'Insert value', scope: 'canvas' },
      { id: 'set-low-mid-high', label: 'Set low/mid/high', scope: 'canvas' },
    ],
    editTargets: ['item', 'label', 'range'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-low', label: 'Mark low', objectKinds: ['item'], scope: 'selection' },
      { id: 'set-mid', label: 'Mark mid', objectKinds: ['item'], scope: 'selection' },
      { id: 'set-high', label: 'Mark high', objectKinds: ['item'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'low, mid, high 포인터를 각각 드래그하고 값을 중간 위치에 삽입합니다.',
    type: 'searching',
  },
  'recursion-tree': {
    canvasMenu: [
      { id: 'add-call-node', label: 'Add Call Node', scope: 'canvas' },
      { id: 'add-return-edge', label: 'Connect return edge', scope: 'canvas' },
      { id: 'add-stack-frame', label: 'Add call stack item', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'item', 'label'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'mark-active-call', label: 'Mark active call', objectKinds: ['node'], scope: 'selection' },
      { id: 'mark-duplicate-call', label: 'Mark duplicate call', objectKinds: ['node'], scope: 'selection' },
      { id: 'mark-return', label: 'Mark returned', objectKinds: ['node'], scope: 'selection' },
    ],
    summary: '호출 트리 노드와 Call Stack 항목을 함께 편집합니다.',
    type: 'recursion-tree',
  },
  'divide-conquer': {
    canvasMenu: [
      { id: 'add-problem-node', label: 'Add problem node', scope: 'canvas' },
      { id: 'add-split', label: 'Connect split', scope: 'canvas' },
      { id: 'add-merge', label: 'Show merge', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label', 'step'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'mark-split', label: 'Mark split step', objectKinds: ['node', 'edge'], scope: 'selection' },
      { id: 'mark-merge', label: 'Mark merge step', objectKinds: ['node', 'edge'], scope: 'selection' },
    ],
    summary: '문제 분할과 병합 단계를 노드/간선으로 편집합니다.',
    type: 'divide-conquer',
  },
  'dp-table': {
    canvasMenu: [
      { id: 'add-row', label: 'Add row', scope: 'canvas' },
      { id: 'add-column', label: 'Add column', scope: 'canvas' },
      { id: 'set-recurrence', label: 'Show recurrence', scope: 'canvas' },
    ],
    editTargets: ['cell', 'label', 'step'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-current-cell', label: 'Set current cell', objectKinds: ['cell'], scope: 'selection' },
      { id: 'set-reference-cell', label: 'Set reference cell', objectKinds: ['cell'], scope: 'selection' },
      { id: 'edit-formula', label: 'Edit recurrence', objectKinds: ['label'], scope: 'selection' },
      deleteAction,
    ],
    summary: '현재 칸과 참조 칸을 선택하면 점화식과 마커가 함께 표시됩니다.',
    type: 'dp-table',
  },
  'state-space-tree': {
    canvasMenu: [
      { id: 'add-candidate', label: 'Add candidate', scope: 'canvas' },
      { id: 'add-branch', label: 'Connect branch', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'toggle-prune', label: 'Mark prune', objectKinds: ['node'], scope: 'selection' },
      { id: 'toggle-choose', label: 'Mark choose', objectKinds: ['node'], scope: 'selection' },
    ],
    summary: '탐색 후보, 가지치기, 선택 경로를 노드 상태로 편집합니다.',
    type: 'state-space-tree',
  },
  scatter: {
    canvasMenu: [
      { id: 'add-point', label: 'Add point', scope: 'canvas' },
      { id: 'edit-axis', label: 'Edit axis range', scope: 'canvas' },
    ],
    editTargets: ['point', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'edit-coordinate', label: 'Edit coordinates', objectKinds: ['point'], scope: 'selection' },
      { id: 'set-active-point', label: 'Highlight point', objectKinds: ['point'], scope: 'selection' },
      deleteAction,
    ],
    summary: '점은 x,y 소수 좌표를 가지며 드래그와 직접 입력으로 수정합니다.',
    type: 'scatter',
  },
  histogram: {
    canvasMenu: [
      { id: 'add-bin', label: 'Add bin', scope: 'canvas' },
      { id: 'edit-scale', label: 'Edit axis scale', scope: 'canvas' },
    ],
    editTargets: ['bar', 'item', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-active-bin', label: 'Highlight bin', objectKinds: ['bar', 'item'], scope: 'selection' },
      deleteAction,
    ],
    summary: '구간 높이를 드래그로 조정하고 구간 라벨을 직접 수정합니다.',
    type: 'histogram',
  },
  'line-graph': {
    canvasMenu: [
      { id: 'add-point', label: 'Add point', scope: 'canvas' },
      { id: 'add-series', label: 'Add line series', scope: 'canvas' },
    ],
    editTargets: ['point', 'item', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-active-point', label: 'Highlight point', objectKinds: ['point'], scope: 'selection' },
      { id: 'edit-coordinate', label: 'Edit coordinates', objectKinds: ['point'], scope: 'selection' },
      deleteAction,
    ],
    summary: '데이터 점을 드래그해 선 그래프 값을 조정합니다.',
    type: 'line-graph',
  },
  heatmap: {
    canvasMenu: [
      { id: 'add-row', label: 'Add row', scope: 'canvas' },
      { id: 'add-column', label: 'Add column', scope: 'canvas' },
      { id: 'edit-color-scale', label: 'Edit color scale', scope: 'canvas' },
    ],
    editTargets: ['cell', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'edit-cell-value', label: 'Edit value', objectKinds: ['cell'], scope: 'selection' },
      { id: 'set-active-cell', label: 'Highlight cell', objectKinds: ['cell'], scope: 'selection' },
      deleteAction,
    ],
    summary: '셀 값을 직접 수정하고 색상 강도를 자동 반영합니다.',
    type: 'heatmap',
  },
  'complexity-chart': {
    canvasMenu: [
      { id: 'add-curve', label: 'Add complexity curve', scope: 'canvas' },
      { id: 'edit-axis', label: 'Edit axis range', scope: 'canvas' },
    ],
    editTargets: ['item', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      { id: 'highlight-curve', label: 'Highlight curve', objectKinds: ['item'], scope: 'selection' },
      { id: 'move-legend', label: 'Move legend', objectKinds: ['label'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'Big-O 곡선과 범례를 선택해 비교 상태를 표시합니다.',
    type: 'complexity-chart',
  },
  'path-map': {
    canvasMenu: [
      { id: 'add-row', label: 'Add row', scope: 'canvas' },
      { id: 'add-column', label: 'Add column', scope: 'canvas' },
      { id: 'set-start-goal', label: 'Set start/goal', scope: 'canvas' },
    ],
    editTargets: ['cell', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      { id: 'set-start', label: 'Mark start', objectKinds: ['cell'], scope: 'selection' },
      { id: 'set-goal', label: 'Mark goal', objectKinds: ['cell'], scope: 'selection' },
      { id: 'toggle-obstacle', label: 'Toggle obstacle', objectKinds: ['cell'], scope: 'selection' },
      { id: 'set-path', label: 'Mark path', objectKinds: ['cell'], scope: 'selection' },
      deleteAction,
    ],
    summary: '격자 셀을 클릭해 시작, 목표, 장애물, 경로 상태를 전환합니다.',
    type: 'path-map',
  },
  'geometry-plane': {
    canvasMenu: [
      { id: 'add-point', label: 'Add point', scope: 'canvas' },
      { id: 'add-segment', label: 'Connect segment', scope: 'canvas' },
      { id: 'add-polygon', label: 'Create polygon', scope: 'canvas' },
    ],
    editTargets: ['point', 'edge', 'label'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'edit-coordinate', label: 'Edit coordinates', objectKinds: ['point'], scope: 'selection' },
      { id: 'show-angle', label: 'Show angle', objectKinds: ['edge', 'label'], scope: 'selection' },
      deleteAction,
    ],
    summary: '점과 선분을 드래그로 조정하고 좌표/각도 라벨을 표시합니다.',
    type: 'geometry-plane',
  },
  'convergence-graph': {
    canvasMenu: [
      { id: 'add-point', label: 'Add point', scope: 'canvas' },
      { id: 'set-target', label: 'Show convergence target', scope: 'canvas' },
    ],
    editTargets: ['point', 'item', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-active-iteration', label: 'Highlight current iteration', objectKinds: ['point', 'item'], scope: 'selection' },
      deleteAction,
    ],
    summary: '반복 점을 드래그해 수렴 경로와 목표값을 조정합니다.',
    type: 'convergence-graph',
  },
  'bit-matrix': {
    canvasMenu: [
      { id: 'add-row', label: 'Add row', scope: 'canvas' },
      { id: 'add-column', label: 'Add column', scope: 'canvas' },
      { id: 'set-mask', label: 'Show mask range', scope: 'canvas' },
    ],
    editTargets: ['cell', 'range', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      { id: 'toggle-bit', label: 'Toggle 0/1', objectKinds: ['cell'], scope: 'selection' },
      { id: 'set-mask', label: 'Show mask range', objectKinds: ['cell', 'range'], scope: 'selection' },
      deleteAction,
    ],
    summary: '비트 셀을 클릭해 0/1과 mask 상태를 전환합니다.',
    type: 'bit-matrix',
  },
  'sieve-matrix': {
    canvasMenu: [
      { id: 'add-row', label: 'Add row', scope: 'canvas' },
      { id: 'add-column', label: 'Add column', scope: 'canvas' },
      { id: 'set-current-number', label: 'Set current number', scope: 'canvas' },
    ],
    editTargets: ['cell', 'label', 'step'],
    edgePolicy: noEdge,
    selectedMenu: [
      { id: 'set-candidate', label: 'Mark candidate number', objectKinds: ['cell'], scope: 'selection' },
      { id: 'toggle-marked', label: 'Mark removed multiple', objectKinds: ['cell'], scope: 'selection' },
      { id: 'toggle-prime', label: 'Mark confirmed prime', objectKinds: ['cell'], scope: 'selection' },
      { id: 'set-current', label: 'Mark current number', objectKinds: ['cell'], scope: 'selection' },
    ],
    summary: '숫자 셀을 선택한 뒤 후보, 제거된 배수, 확정 소수, 현재 기준 수 상태를 화면에서 직접 전환합니다.',
    type: 'sieve-matrix',
  },
  'vector-space': {
    canvasMenu: [
      { id: 'add-vector', label: 'Add vector', scope: 'canvas' },
      { id: 'add-point', label: 'Add point', scope: 'canvas' },
      { id: 'add-boundary', label: 'Add boundary', scope: 'canvas' },
      { id: 'toggle-projection', label: 'Show/hide projection', scope: 'canvas' },
    ],
    editTargets: ['point', 'edge', 'label', 'node'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-projection', label: 'Show projection', objectKinds: ['point', 'edge'], scope: 'selection' },
      { id: 'move-boundary', label: 'Move boundary', objectKinds: ['edge'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'Vector 끝점, Point, Projection, Boundary를 좌표 평면에서 직접 드래그하고 라벨을 수정합니다.',
    type: 'vector-space',
  },
  'neural-network': {
    canvasMenu: [
      { id: 'add-neuron', label: 'Add neuron', scope: 'canvas' },
      { id: 'add-layer', label: 'Add layer', scope: 'canvas' },
      { id: 'add-weight', label: 'Connect weight', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'set-active-path', label: 'Show active path', objectKinds: ['node', 'edge'], scope: 'selection' },
      { id: 'edit-weight', label: 'Edit weight', objectKinds: ['edge', 'label'], scope: 'selection' },
    ],
    summary: 'Neuron 위치와 layer, weight, active path를 직접 조정합니다.',
    type: 'neural-network',
  },
  'decision-tree': {
    canvasMenu: [
      { id: 'add-decision-node', label: 'Add decision node', scope: 'canvas' },
      { id: 'add-leaf', label: 'Add leaf', scope: 'canvas' },
      { id: 'add-condition-edge', label: 'Connect condition edge', scope: 'canvas' },
    ],
    editTargets: ['node', 'edge', 'label'],
    edgePolicy: undirectedStraightEdge,
    selectedMenu: [
      ...nodeSelectionMenu,
      { id: 'edit-condition', label: 'Edit condition', objectKinds: ['node', 'edge', 'label'], scope: 'selection' },
      { id: 'mark-leaf', label: 'Mark leaf', objectKinds: ['node'], scope: 'selection' },
    ],
    summary: '조건 분기와 leaf 라벨을 트리 구조로 직접 편집합니다.',
    type: 'decision-tree',
  },
  'evolution-path': {
    canvasMenu: [
      { id: 'add-candidate', label: 'Add candidate', scope: 'canvas' },
      { id: 'add-generation', label: 'Add generation label', scope: 'canvas' },
      { id: 'set-best-path', label: 'Show best path', scope: 'canvas' },
    ],
    editTargets: ['point', 'item', 'label'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-best', label: 'Mark best candidate', objectKinds: ['point', 'item'], scope: 'selection' },
      deleteAction,
    ],
    summary: '후보 점과 세대 라벨을 드래그로 조정하고 best path를 표시합니다.',
    type: 'evolution-path',
  },
  gantt: {
    canvasMenu: [
      { id: 'add-task', label: 'Add task bar', scope: 'canvas' },
      { id: 'edit-timeline', label: 'Edit timeline axis', scope: 'canvas' },
      { id: 'add-dependency', label: 'Show dependency', scope: 'canvas' },
    ],
    editTargets: ['bar', 'item', 'label'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'resize-task', label: 'Resize duration', objectKinds: ['bar', 'item'], scope: 'selection' },
      { id: 'mark-critical', label: 'Mark critical task', objectKinds: ['bar', 'item'], scope: 'selection' },
      deleteAction,
    ],
    summary: '작업 막대를 드래그해 시작/종료 위치를 조정합니다.',
    type: 'gantt',
  },
  'memory-page-flow': {
    canvasMenu: [
      { id: 'add-page', label: 'Add page reference', scope: 'canvas' },
      { id: 'add-frame', label: 'Add frame row', scope: 'canvas' },
      { id: 'mark-page-fault', label: 'Mark page fault', scope: 'canvas' },
    ],
    editTargets: ['cell', 'item', 'label', 'step'],
    edgePolicy: noEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'set-page-fault', label: 'Mark page fault', objectKinds: ['cell', 'item'], scope: 'selection' },
      { id: 'set-current-reference', label: 'Mark current reference', objectKinds: ['item'], scope: 'selection' },
      deleteAction,
    ],
    summary: 'Page reference와 frame 상태를 단계별로 수정하고 page fault를 표시합니다.',
    type: 'memory-page-flow',
  },
  composite: {
    canvasMenu: [
      { id: 'add-child-visual', label: 'Add visualization', scope: 'canvas' },
      { id: 'add-table', label: 'Add table', scope: 'canvas' },
      { id: 'add-code-note', label: 'Add code note', scope: 'canvas' },
    ],
    editTargets: ['container', 'item', 'label', 'step'],
    edgePolicy: directedStraightEdge,
    selectedMenu: [
      editLabelAction,
      { id: 'sync-step', label: 'Sync step', objectKinds: ['container', 'step'], scope: 'selection' },
      duplicateAction,
      deleteAction,
    ],
    summary: '여러 시각화, 표, 코드 설명을 한 단계 화면에서 묶어 편집합니다.',
    type: 'composite',
  },
} as const satisfies Record<ActiveVisualizationType, VisualizationEditingContract>;

export function getVisualizationEditingContract(type: VisualizationType) {
  if (type === 'stack-queue') {
    return null;
  }

  return VISUALIZATION_EDITING_CONTRACTS[type];
}

export function isVisualizationEdgeDirected(type: VisualizationType) {
  return getVisualizationEditingContract(type)?.edgePolicy.directed ?? false;
}

export function isVisualizationEdgeBendable(type: VisualizationType) {
  return getVisualizationEditingContract(type)?.edgePolicy.allowBendPoints ?? false;
}

export function shouldShowVisualizationEdgeCrossingMarker(type: VisualizationType) {
  return (
    getVisualizationEditingContract(type)?.edgePolicy.showCrossingMarker ?? false
  );
}

const itemTargetKinds = new Set<VisualizationEditTargetKind>([
  'bar',
  'container',
  'item',
  'point',
  'range',
]);

const nodeTargetKinds = new Set<VisualizationEditTargetKind>(['edge', 'node']);

const cellTargetKinds = new Set<VisualizationEditTargetKind>(['cell']);

const itemAddActionIds = new Set([
  'add-bar',
  'add-bin',
  'add-bucket',
  'add-candidate',
  'add-child-visual',
  'add-column-value',
  'add-curve',
  'add-frame',
  'add-generation',
  'add-item',
  'add-key',
  'add-page',
  'add-point',
  'add-task',
  'add-value',
  'enqueue',
  'push',
]);

function hasAnyTarget(
  contract: VisualizationEditingContract | null,
  targets: ReadonlySet<VisualizationEditTargetKind>
) {
  return Boolean(
    contract?.editTargets.some((target) => targets.has(target))
  );
}

function findActionLabel(
  actions: readonly VisualizationMenuAction[],
  actionIds: ReadonlySet<string>,
  fallback: string
) {
  const label = actions.find((action) => actionIds.has(action.id))?.label ?? fallback;

  return translateFloatingMenuLabel(label);
}

function translateFloatingMenuLabel(label: string) {
  const labels: Record<string, string> = {
    'Add actor': 'Actor 추가',
    'Add bin': '구간 추가',
    'Add boundary': '경계 추가',
    'Add bucket': '버킷 추가',
    'Add call stack item': 'Call Stack 항목 추가',
    'Add Call Node': '호출 노드 추가',
    'Add candidate': '후보 추가',
    'Add character node': '문자 노드 추가',
    'Add child visual': '하위 시각화 추가',
    'Add code note': '코드 설명 추가',
    'Add column': '열 추가',
    'Add complexity curve': '복잡도 곡선 추가',
    'Add curve': '곡선 추가',
    'Add decision node': '판단 노드 추가',
    'Add decision shape': '판단 도형 추가',
    'Add frame row': '프레임 행 추가',
    'Add generation label': '세대 라벨 추가',
    'Add input shape': '입력 도형 추가',
    'Add item': '항목 추가',
    'Add key': '키 추가',
    'Add layer': '레이어 추가',
    'Add leaf': 'Leaf 추가',
    'Add line series': '선 그래프 추가',
    'Add neuron': '뉴런 추가',
    'Add node': '노드 추가',
    'Add output shape': '출력 도형 추가',
    'Add page reference': '페이지 참조 추가',
    'Add point': '점 추가',
    'Add problem node': '문제 노드 추가',
    'Add process shape': '처리 도형 추가',
    'Add range node': '구간 노드 추가',
    'Add row': '행 추가',
    'Add scene': '장면 추가',
    'Add start/end shape': '시작/끝 도형 추가',
    'Add state': '상태 추가',
    'Add table': '표 추가',
    'Add task bar': '작업 막대 추가',
    'Add value': '값 추가',
    'Add vector': '벡터 추가',
    'Add visualization': '시각화 추가',
  };

  return labels[label] ?? label;
}

export function getVisualizationFloatingActionKinds(
  type: VisualizationType
): VisualizationFloatingActionKind[] {
  const contract = getVisualizationEditingContract(type);
  const actions: VisualizationFloatingActionKind[] = [];

  if (!contract) {
    return actions;
  }

  if (type === 'vector-space') {
    actions.push(
      'vector-add',
      'vector-point-add',
      'vector-boundary-add',
      'vector-projection-toggle',
      'node-hint'
    );
    return actions;
  }

  if (type === 'hash-table') {
    actions.push(
      'hash-bucket-add',
      'hash-bucket-delete',
      'hash-key-add',
      'hash-key-delete'
    );
  } else if (hasAnyTarget(contract, itemTargetKinds)) {
    actions.push('item-add', 'item-delete');
  }

  if (hasAnyTarget(contract, cellTargetKinds)) {
    actions.push('row-add', 'column-add', 'row-delete', 'column-delete');
  }

  if (hasAnyTarget(contract, nodeTargetKinds)) {
    actions.push('node-hint');
  }

  return actions;
}

export function getVisualizationFloatingMenuLabels(
  type: VisualizationType
): VisualizationFloatingMenuLabels {
  const contract = getVisualizationEditingContract(type);

  return {
    addColumn: findActionLabel(
      contract?.canvasMenu ?? [],
      new Set(['add-column']),
      '열 추가'
    ),
    addItem: findActionLabel(
      contract?.canvasMenu ?? [],
      itemAddActionIds,
      '항목 추가'
    ),
    addRow: findActionLabel(
      contract?.canvasMenu ?? [],
      new Set(['add-row']),
      '행 추가'
    ),
    nodeHint: hasAnyTarget(contract ?? null, nodeTargetKinds)
      ? '더블클릭: 추가 / 드래그: 이동 / Shift+드래그: 연결 / Delete 키: 삭제'
      : '선택한 요소를 화면에서 직접 수정합니다.',
    removeColumn: '마지막 열 삭제',
    removeItem: '마지막 항목 삭제',
    removeRow: '마지막 행 삭제',
  };
}

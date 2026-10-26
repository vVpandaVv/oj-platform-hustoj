export type VisualizationCategory =
  | 'flow-process'
  | 'linear-data-structure'
  | 'nonlinear-data-structure'
  | 'algorithm-behavior'
  | 'data-performance'
  | 'space-math'
  | 'ai-system'
  | 'composite';

export type VisualizationType =
  | 'flowchart'
  | 'state-diagram'
  | 'trace-table'
  | 'sequence-diagram'
  | 'storyboard'
  | 'array-grid'
  | 'linked-list'
  | 'stack'
  | 'queue'
  | 'stack-queue'
  | 'hash-table'
  | 'tree'
  | 'network'
  | 'trie'
  | 'segment-tree'
  | 'sorting'
  | 'searching'
  | 'recursion-tree'
  | 'divide-conquer'
  | 'dp-table'
  | 'state-space-tree'
  | 'scatter'
  | 'histogram'
  | 'line-graph'
  | 'heatmap'
  | 'complexity-chart'
  | 'path-map'
  | 'geometry-plane'
  | 'convergence-graph'
  | 'bit-matrix'
  | 'sieve-matrix'
  | 'vector-space'
  | 'neural-network'
  | 'decision-tree'
  | 'evolution-path'
  | 'gantt'
  | 'memory-page-flow'
  | 'composite';

export type VisualizationTypeDefinition = {
  category: VisualizationCategory;
  categoryLabel: string;
  description: string;
  label: string;
  priority: 1 | 2 | 3;
  sourceName: VisualizationType;
  type: VisualizationType;
  wysiwygElements: readonly string[];
};

type VisualizationTypeGroup = {
  category: VisualizationCategory;
  label: string;
  types: readonly VisualizationType[];
};

export const VISUALIZATION_TYPE_GROUPS = [
  {
    category: 'flow-process',
    label: 'Flow & Process',
    types: [
      'flowchart',
      'state-diagram',
      'trace-table',
      'sequence-diagram',
      'storyboard',
    ],
  },
  {
    category: 'linear-data-structure',
    label: 'Linear Data Structure',
    types: ['array-grid', 'linked-list', 'stack', 'queue', 'hash-table'],
  },
  {
    category: 'nonlinear-data-structure',
    label: 'Nonlinear Data Structure',
    types: ['tree', 'network', 'trie', 'segment-tree'],
  },
  {
    category: 'algorithm-behavior',
    label: 'Algorithm Behavior',
    types: [
      'sorting',
      'searching',
      'recursion-tree',
      'divide-conquer',
      'dp-table',
      'state-space-tree',
    ],
  },
  {
    category: 'data-performance',
    label: 'Data & Performance',
    types: [
      'scatter',
      'histogram',
      'line-graph',
      'heatmap',
      'complexity-chart',
    ],
  },
  {
    category: 'space-math',
    label: 'Space & Math',
    types: [
      'path-map',
      'geometry-plane',
      'convergence-graph',
      'bit-matrix',
      'sieve-matrix',
      'vector-space',
    ],
  },
  {
    category: 'ai-system',
    label: 'AI & System',
    types: [
      'neural-network',
      'decision-tree',
      'evolution-path',
      'gantt',
      'memory-page-flow',
    ],
  },
  {
    category: 'composite',
    label: 'Composite',
    types: ['composite'],
  },
] as const satisfies readonly VisualizationTypeGroup[];

const CATEGORY_LABELS = new Map(
  VISUALIZATION_TYPE_GROUPS.map((group) => [group.category, group.label])
);

const typeDefinition = (
  type: VisualizationType,
  category: VisualizationCategory,
  label: string,
  description: string,
  wysiwygElements: readonly string[],
  priority: 1 | 2 | 3 = 2
): VisualizationTypeDefinition => ({
  type,
  sourceName: type,
  label,
  category,
  categoryLabel: CATEGORY_LABELS.get(category) ?? category,
  description,
  wysiwygElements,
  priority,
});

export const VISUALIZATION_TYPE_DEFINITIONS = [
  typeDefinition('flowchart', 'flow-process', 'Flowchart', '처리, 입력, 판단, 출력 도형과 직선/꺾은 흐름선으로 순서를 표현합니다.', ['도형', '흐름선', '라벨', '단계'], 1),
  typeDefinition('state-diagram', 'flow-process', 'State Diagram', '상태 노드와 전이선을 이용해 상태 변화와 조건을 표현합니다.', ['상태', '전이', '조건 라벨']),
  typeDefinition('trace-table', 'flow-process', 'Trace Table', '변수 값과 실행 단계를 표로 추적합니다.', ['행', '열', '현재 값', '강조']),
  typeDefinition('sequence-diagram', 'flow-process', 'Sequence Diagram', 'Actor 사이의 호출 순서와 반환 흐름을 표현합니다.', ['Actor', 'Lifeline', 'Message', 'Return']),
  typeDefinition('storyboard', 'flow-process', 'Storyboard', '개념 흐름을 설명하기 위한 학습 장면을 순서대로 배치합니다.', ['장면', '단계 번호', '설명']),
  typeDefinition('array-grid', 'linear-data-structure', 'Array Grid', 'Array의 index, value, pointer를 grid로 표현합니다.', ['Cell', 'Index', 'Value', 'Pointer'], 1),
  typeDefinition('linked-list', 'linear-data-structure', 'Linked List', 'Node와 next pointer 연결을 표현합니다.', ['Node', 'Pointer', 'Head', 'Tail']),
  typeDefinition('stack', 'linear-data-structure', 'Stack', 'LIFO 구조와 top, push, pop 흐름을 표현합니다.', ['Item', 'top', 'push', 'pop']),
  typeDefinition('queue', 'linear-data-structure', 'Queue', 'FIFO 구조와 front, rear, enqueue, dequeue 흐름을 표현합니다.', ['Item', 'front', 'rear', 'enqueue', 'dequeue']),
  typeDefinition('hash-table', 'linear-data-structure', 'Hash Table', 'Bucket, key, collision chain을 표현합니다.', ['Bucket', 'Key', 'Chain', 'Collision']),
  typeDefinition('tree', 'nonlinear-data-structure', 'Tree', 'Binary Tree, general Tree, B-Tree, B+Tree, AVL, Red-Black Tree를 직선 간선으로 표현합니다.', ['Node', 'Edge', 'Root', 'Level', 'Balance'], 1),
  typeDefinition('network', 'nonlinear-data-structure', 'Graph / Network', 'Graph의 vertex, edge, weight, direction을 직선 간선과 라벨로 표현합니다.', ['Node', 'Edge', 'Weight', 'Direction'], 1),
  typeDefinition('trie', 'nonlinear-data-structure', 'Trie', '문자 prefix와 terminal marker를 tree 형태로 표현합니다.', ['Node', 'Character Edge', 'Terminal']),
  typeDefinition('segment-tree', 'nonlinear-data-structure', 'Segment Tree', 'Range node와 query range를 계층 구조로 표현합니다.', ['Range Node', 'Query Range', 'Value']),
  typeDefinition('sorting', 'algorithm-behavior', 'Sorting', '비교 marker, 교환선, 정렬 완료 구간을 표현합니다.', ['Bar', 'Compare Marker', 'Swap Arrow', 'Sorted'], 1),
  typeDefinition('searching', 'algorithm-behavior', 'Searching', 'low, mid, high와 현재 탐색 범위 변화를 표현합니다.', ['Range', 'Pointer', 'Current', 'Result']),
  typeDefinition('recursion-tree', 'algorithm-behavior', 'Recursion Tree', '재귀 호출 분기와 active call stack을 표현합니다.', ['Call Node', 'Return', 'Call Stack']),
  typeDefinition('divide-conquer', 'algorithm-behavior', 'Divide & Conquer', '분할과 병합 단계를 tree 형태로 표현합니다.', ['Problem Node', 'Split Edge', 'Merge']),
  typeDefinition('dp-table', 'algorithm-behavior', 'DP Table', '점화식, 참조 cell, 현재 cell을 marker와 함께 표현합니다.', ['Cell', 'Reference Marker', 'Formula'], 1),
  typeDefinition('state-space-tree', 'algorithm-behavior', 'State Space Tree', '탐색 후보와 pruning 상태를 tree로 표현합니다.', ['State Node', 'Branch', 'Prune']),
  typeDefinition('scatter', 'data-performance', 'Scatter', '소수점 x,y 좌표의 점과 선택적 추세선을 표현합니다.', ['Point', 'Axis', 'Label']),
  typeDefinition('histogram', 'data-performance', 'Histogram', '구간별 빈도를 bar로 표현합니다.', ['Bin', 'Bar', 'Axis']),
  typeDefinition('line-graph', 'data-performance', 'Line Graph', '연속적인 값 변화를 line graph로 표현합니다.', ['Point', 'Line', 'Axis']),
  typeDefinition('heatmap', 'data-performance', 'Heatmap', '2차원 값을 색상 강도로 표현합니다.', ['Cell', 'Color Scale', 'Legend']),
  typeDefinition('complexity-chart', 'data-performance', 'Complexity Chart', 'Big-O 증가 곡선을 비교합니다.', ['Curve', 'Axis', 'Legend']),
  typeDefinition('path-map', 'space-math', 'Path Map', 'Grid나 map 위에서 pathfinding 과정을 표현합니다.', ['Grid Cell', 'Obstacle', 'Path', 'Start', 'Goal']),
  typeDefinition('geometry-plane', 'space-math', 'Geometry Plane', '좌표평면 위의 point, line segment, polygon, angle, convex hull을 표현합니다.', ['Point', 'Line', 'Polygon', 'Angle']),
  typeDefinition('convergence-graph', 'space-math', 'Convergence Graph', '반복 계산이 target으로 수렴하는 과정을 표현합니다.', ['Point', 'Curve', 'Target']),
  typeDefinition('bit-matrix', 'space-math', 'Bit Matrix', '0/1 matrix와 bit operation을 표현합니다.', ['Bit Cell', 'Mask', 'Operation']),
  typeDefinition('sieve-matrix', 'space-math', 'Sieve Matrix', 'Sieve matrix에서 제거되는 수와 prime 상태를 표현합니다.', ['Number Cell', 'Marked', 'Prime']),
  typeDefinition('vector-space', 'space-math', 'Vector Space', 'Vector, projection, direction, coordinate 관계를 표현합니다.', ['Vector', 'Axis', 'Projection']),
  typeDefinition('neural-network', 'ai-system', 'Neural Network', 'Layer, node, weight, active path를 표현합니다.', ['Layer', 'Neuron', 'Weight', 'Active Path']),
  typeDefinition('decision-tree', 'ai-system', 'Decision Tree', '조건 분기와 leaf label을 tree로 표현합니다.', ['Decision Node', 'Leaf', 'Condition']),
  typeDefinition('evolution-path', 'ai-system', 'Evolution Path', '세대별 후보 개선 경로를 표현합니다.', ['Candidate', 'Generation', 'Best Path']),
  typeDefinition('gantt', 'ai-system', 'Gantt Chart', '작업 기간과 의존 관계를 timeline bar로 표현합니다.', ['Task Bar', 'Timeline', 'Dependency']),
  typeDefinition('memory-page-flow', 'ai-system', 'Memory Page Flow', 'Page reference, frame, page fault 상태를 표현합니다.', ['Page', 'Frame', 'Fault']),
  typeDefinition('composite', 'composite', 'Composite', '시각화, 표, 코드, 설명을 하나의 canvas에 묶어 표현합니다.', ['Container', 'Child Visual', 'Sync Step']),
] as const satisfies readonly VisualizationTypeDefinition[];

export const VISUALIZATION_TYPES = VISUALIZATION_TYPE_DEFINITIONS.map(
  (definition) => definition.type
) as VisualizationType[];

export const VISUALIZATION_TYPE_OPTIONS = VISUALIZATION_TYPE_DEFINITIONS.map(
  ({ category, categoryLabel, description, label, priority, type }) => ({
    category,
    categoryLabel,
    description,
    label,
    priority,
    value: type,
  })
);

export const VISUALIZATION_TYPE_SET = new Set<VisualizationType>([
  ...VISUALIZATION_TYPES,
  'stack-queue',
]);

export function isVisualizationType(value: string): value is VisualizationType {
  return VISUALIZATION_TYPE_SET.has(value as VisualizationType);
}

export function getVisualizationTypeDefinition(
  type: VisualizationType
): VisualizationTypeDefinition {
  if (type === 'stack-queue') {
    return {
      type,
      sourceName: type,
      label: 'Stack / Queue',
      category: 'linear-data-structure',
      categoryLabel: CATEGORY_LABELS.get('linear-data-structure') ?? 'Linear Data Structure',
      description:
        'Legacy combined type. Use Stack or Queue for new content.',
      wysiwygElements: ['Item', 'top', 'front', 'rear'],
      priority: 3,
    };
  }

  const definition = VISUALIZATION_TYPE_DEFINITIONS.find(
    (item) => item.type === type
  );

  if (!definition) {
    throw new Error(`Unknown visualization type: ${type}`);
  }

  return definition;
}

export function getVisualizationTypeLabel(type: VisualizationType): string {
  return getVisualizationTypeDefinition(type).label;
}

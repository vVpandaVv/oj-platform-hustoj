import type { TElement } from 'platejs';

import {
  createEmptyVisualizationDocument,
  type VisualizationDocument,
} from './visualization-model';
import type { VisualizationType } from './visualization-types';
import { getVisualizationTypeLabel } from './visualization-types';

export const VISUALIZATION_BLOCK_TYPE = 'visualization';

export type TVisualizationElement = TElement & {
  data?: VisualizationDocument;
  type: typeof VISUALIZATION_BLOCK_TYPE;
};

type CreateVisualizationBlockOptions = {
  title?: string;
  visualId?: string;
  visualType?: VisualizationType;
};

export type VisualizationStartMode = 'blank' | 'sample';

const createVisualId = () =>
  `visual-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

export function createStarterVisualizationDsl(
  visualType: VisualizationType,
  title = getVisualizationTypeLabel(visualType)
) {
  switch (visualType) {
    case 'flowchart':
      return [
        'visual flowchart',
        `title "${title}"`,
        'node Start "Start" start at 260 50',
        'node Input "Input" input at 260 135',
        'node Check "Valid?" decision at 260 230 active',
        'node Process "Process" process at 260 325',
        'node Output "Output" output at 260 420',
        'node Error "Error" output at 470 230 warning',
        'edge Start Input from bottom to top',
        'edge Input Check from bottom to top',
        'edge Check Process from bottom to top label "yes"',
        'edge Process Output from bottom to top',
        'edge Check Error from right to left label "no" route right offset 84',
      ].join('\n');
    case 'network':
      return [
        'visual network',
        `title "${title}"`,
        'node A "A" circle at 90 90',
        'node B "B" circle at 235 145 active',
        'node C "C" circle at 380 80',
        'node D "D" circle at 160 245',
        'edge A B label "4" straight',
        'edge A C label "9" straight',
        'edge B C label "7" straight active',
        'edge B D label "2" straight',
        'graph directed weighted',
      ].join('\n');
    case 'state-diagram':
      return [
        'visual state-diagram',
        `title "${title}"`,
        'node Idle "Idle" circle at 90 150',
        'node Check "Check" circle at 250 90 active',
        'node Done "Done" circle at 410 150 success',
        'node Retry "Retry" circle at 250 230 warning',
        'edge Idle Check label "submit" straight',
        'edge Check Done label "ok" straight',
        'edge Check Retry label "fail" straight',
        'edge Retry Retry label "wait" straight',
        'edge Retry Idle label "retry" straight',
      ].join('\n');
    case 'trace-table':
      return [
        'visual trace-table',
        `title "${title}"`,
        'columns step low high mid value',
        'row 0: 1 0 6 3 9',
        'row 1: 2 0 2 1 4',
        'row 2: 3 2 2 2 7',
        'active 1 3',
      ].join('\n');
    case 'sequence-diagram':
      return [
        'visual sequence-diagram',
        `title "${title}"`,
        'node User "User" at 80 50',
        'node App "App" at 240 50',
        'node DB "DB" at 400 50',
        'edge User App label "request"',
        'edge App DB label "query"',
        'edge DB App label "result"',
        'edge App User label "response"',
      ].join('\n');
    case 'storyboard':
      return [
        'visual storyboard',
        `title "${title}"`,
        'node A "문제\\n무엇을 해결할지 보여줍니다" at 80 90 active',
        'node B "분해\\n핵심 단계를 나눕니다" at 250 90',
        'node C "시각화\\n학습자가 볼 장면을 정리합니다" at 420 90',
      ].join('\n');
    case 'array-grid':
      return [
        'visual array-grid',
        `title "${title}"`,
        'data 3 7 9 12 18',
        'active 2',
      ].join('\n');
    case 'linked-list':
      return [
        'visual linked-list',
        `title "${title}"`,
        'data 12 7 31 4',
        'active 1',
      ].join('\n');
    case 'stack':
      return [
        'visual stack',
        `title "${title}"`,
        'data 18 12 7',
        'active 2',
      ].join('\n');
    case 'queue':
      return [
        'visual queue',
        `title "${title}"`,
        'data 3 5 8 13',
        'active 0',
      ].join('\n');
    case 'stack-queue':
      return [
        'visual stack-queue',
        `title "${title}"`,
        'data 18 12 7 3 5 8 13',
        'active 2',
      ].join('\n');
    case 'hash-table':
      return [
        'visual hash-table',
        `title "${title}"`,
        'bucket-count 4',
        'data 21@1 33@1 14@2 8@0 29@1 5@1',
        'active 1',
      ].join('\n');
    case 'tree':
      return [
        'visual tree',
        `title "${title}"`,
        'tree-type binary',
        'node A "root" at 280 50 active',
        'node B "left" at 160 145',
        'node C "right" at 400 145',
        'node D "leaf" at 90 245',
        'node E "leaf" at 230 245',
        'node F "leaf" at 400 245',
        'edge A B none',
        'edge A C none',
        'edge B D none',
        'edge B E none',
        'edge C F none',
      ].join('\n');
    case 'trie':
      return [
        'visual trie',
        `title "${title}"`,
        'node R "root" at 300 45',
        'node C "c" at 150 145',
        'node A "a" at 320 145 active',
        'node T "t\\nend" at 230 255 success',
        'node N "r\\nend" at 430 255',
        'edge R C label "c"',
        'edge R A label "a"',
        'edge A T label "t"',
        'edge A N label "r"',
      ].join('\n');
    case 'segment-tree':
      return [
        'visual segment-tree',
        `title "${title}"`,
        'node A "0-7\\n36" at 300 55 active',
        'node B "0-3\\n14" at 180 145',
        'node C "4-7\\n22" at 420 145',
        'node D "0-1\\n7" at 90 245',
        'node E "2-3\\n7" at 220 245',
        'node F "4-5\\n10" at 380 245',
        'node G "6-7\\n12" at 510 245 warning',
        'edge A B none',
        'edge A C none',
        'edge B D none',
        'edge B E none',
        'edge C F none',
        'edge C G none',
      ].join('\n');
    case 'sorting':
      return [
        'visual sorting',
        `title "${title}"`,
        'data 7 11 15 9 13 5',
        'sort-mode compare',
        'active 1 2',
      ].join('\n');
    case 'searching':
      return [
        'visual searching',
        `title "${title}"`,
        'data 2 4 7 9 13 18 21',
        'pointers 0 3 6',
        'active 3',
      ].join('\n');
    case 'recursion-tree':
      return [
        'visual recursion-tree',
        `title "${title}"`,
        'node A "f(4)" at 210 50 active',
        'node B "f(3)" at 120 145',
        'node C "x4" at 300 145 muted',
        'node D "f(2)" at 70 245',
        'node E "x3" at 220 245 muted',
        'node S1 "Call\\nStack" at 430 95 muted',
        'node S2 "f(4)\\nf(3)\\nf(2)" at 430 205',
        'edge A B none',
        'edge A C none',
        'edge B D none',
        'edge B E none',
      ].join('\n');
    case 'divide-conquer':
      return [
        'visual divide-conquer',
        `title "${title}"`,
        'node A "[8,3,6,2]" at 300 50 active',
        'node B "[8,3]" at 180 140',
        'node C "[6,2]" at 420 140',
        'node D "[3,8]" at 180 235 success',
        'node E "[2,6]" at 420 235 success',
        'edge A B',
        'edge A C',
        'edge B D',
        'edge C E',
        'edge D E none straight label "merge"',
      ].join('\n');
    case 'dp-table':
      return [
        'visual dp-table',
        `title "${title}"`,
        'columns 0 1 2 3',
        'row 0: 0 0 0 0',
        'row 1: 0 1 1 1',
        'row 2: 0 1 2 2',
        'row 3: 0 1 2 3',
        'formula "dp[i][j] = dp[i-1][j] + dp[i][j-1]"',
        'references 1:2 2:1',
        'active 2 2',
      ].join('\n');
    case 'state-space-tree':
      return [
        'visual state-space-tree',
        `title "${title}"`,
        'node A "{}" at 300 50',
        'node B "A" at 150 145 warning',
        'node C "B" at 300 145 active',
        'node D "C" at 450 145',
        'node E "B1" at 235 245',
        'node F "B2" at 365 245',
        'edge A B none label "prune"',
        'edge A C none',
        'edge A D none',
        'edge C E none',
        'edge C F none',
      ].join('\n');
    case 'decision-tree':
      return [
        'visual decision-tree',
        `title "${title}"`,
        'node A "x > 5?" at 280 55 active',
        'node B "A" at 155 155',
        'node C "y < 3?" at 405 155 active',
        'node D "B" at 335 260',
        'node E "C" at 485 260',
        'edge A B none label "no"',
        'edge A C none label "yes"',
        'edge C D none',
        'edge C E none',
      ].join('\n');
    case 'scatter':
      return [
        'visual scatter',
        `title "${title}"`,
        'data 0.5,2.2 1.4,4.6 2.1,3.5 3.2,7.4 4.1,6.7 5.0,9.2',
        'trend-line show',
        'active 3',
      ].join('\n');
    case 'histogram':
      return [
        'visual histogram',
        `title "${title}"`,
        'data 4 9 6 11 7',
        'active 3',
      ].join('\n');
    case 'line-graph':
      return [
        'visual line-graph',
        `title "${title}"`,
        'data 2 5 4 8 7 11',
        'active 4',
      ].join('\n');
    case 'heatmap':
      return [
        'visual heatmap',
        `title "${title}"`,
        'row 0: 1 3 5 2',
        'row 1: 2 6 9 4',
        'row 2: 1 4 7 8',
        'active 1 2',
      ].join('\n');
    case 'complexity-chart':
      return [
        'visual complexity-chart',
        `title "${title}"`,
        'data O(n) O(n_log_n) O(n^2)',
        'active 1',
      ].join('\n');
    case 'path-map':
      return [
        'visual path-map',
        `title "${title}"`,
        'row 0: S . . # .',
        'row 1: * * . # .',
        'row 2: # * * * G',
        'row 3: . . # . .',
        'active 2 2',
      ].join('\n');
    case 'geometry-plane':
      return [
        'visual geometry-plane',
        `title "${title}"`,
        'hull show',
        'node A "A" circle at 150 250',
        'node B "B" circle at 250 130 active',
        'node C "C" circle at 405 190',
        'node D "D" circle at 355 305',
        'node E "E" circle at 205 335 warning',
        'node P "P" circle at 285 235 muted',
        'edge A B none straight',
      ].join('\n');
    case 'convergence-graph':
      return [
        'visual convergence-graph',
        `title "${title}"`,
        'data 42 24 15 9 5 3 2',
        'active 6',
      ].join('\n');
    case 'bit-matrix':
      return [
        'visual bit-matrix',
        `title "${title}"`,
        'row 0: 1 0 1 0',
        'row 1: 0 1 1 0',
        'row 2: 1 1 0 1',
        'row 3: 0 0 1 1',
        'active 1 2',
      ].join('\n');
    case 'sieve-matrix':
      return [
        'visual sieve-matrix',
        `title "${title}"`,
        'row 0: 2p 3p 4x 5p 6x 7p',
        'row 1: 8x 9x 10x 11p 12x 13p',
        'row 2: 14x 15x 16x 17p 18x 19p',
        'row 3: 20x 21x 22x 23p 24x 25x',
        'row 4: 26x 27x 28x 29p 30x 31p',
        'active 0 0',
      ].join('\n');
    case 'vector-space':
      return [
        'visual vector-space',
        `title "${title}"`,
        'node O "O" circle at 90 225',
        'node A "v1" circle at 275 90 active',
        'node B "v2" circle at 420 155',
        'node C "b1" circle at 185 80 muted',
        'node D "b2" circle at 465 235 muted',
        'edge O A label "v1" straight',
        'edge O B label "v2" straight',
        'edge C D none label "boundary" straight dashed',
        'projection show',
      ].join('\n');
    case 'neural-network':
      return [
        'visual neural-network',
        `title "${title}"`,
        'node I1 "x1" circle at 95 92',
        'node I2 "x2" circle at 95 176 active',
        'node I3 "x3" circle at 95 260',
        'node H1 "h1" circle at 270 72',
        'node H2 "h2" circle at 270 156 active',
        'node H3 "h3" circle at 270 240',
        'node O1 "y1" circle at 445 176 active',
        'edge I1 H1 none label "0.32" straight',
        'edge I1 H2 none label "0.18" straight',
        'edge I2 H2 none label "0.84" straight active',
        'edge I2 H3 none label "0.27" straight',
        'edge I3 H2 none label "0.41" straight',
        'edge I3 H3 none label "0.66" straight',
        'edge H1 O1 none label "0.52" straight',
        'edge H2 O1 none label "0.91" straight active',
        'edge H3 O1 none label "0.38" straight',
      ].join('\n');
    case 'evolution-path':
      return [
        'visual evolution-path',
        `title "${title}"`,
        'node A "gen 1" circle at 80 210',
        'node B "candidate" circle at 170 135',
        'node C "best" circle at 300 110 active',
        'node D "gen 6" circle at 455 70',
        'edge A B none straight',
        'edge B C none straight active',
        'edge C D straight active',
      ].join('\n');
    case 'gantt':
      return [
        'visual gantt',
        `title "${title}"`,
        'columns 시작 설계 제작 검수 완료',
        'data 설계=0.5,1.2 제작=1.2,1.8 검수=3.1,0.8',
        'active 1',
      ].join('\n');
    case 'memory-page-flow':
      return [
        'visual memory-page-flow',
        `title "${title}"`,
        'data 7 0 1 2 0 3',
        'row 0: 7 7 7 2 2 2',
        'row 1: . 0 0 0 0 3',
        'row 2: . . 1 1 1 1',
        'active 3',
      ].join('\n');
    case 'composite':
      return [
        'visual composite',
        `title "${title}"`,
        'data A B visual_table_explanation_synced 1 0 1 0',
        'active 3',
      ].join('\n');
    default:
      return ['visual ' + visualType, `title "${title}"`].join('\n');
  }
}

export function createVisualizationSourceCode(
  visualType: VisualizationType,
  title = getVisualizationTypeLabel(visualType),
  startMode: VisualizationStartMode = 'sample'
) {
  if (startMode === 'blank') {
    return ['visual ' + visualType, `title "${title}"`].join('\n');
  }

  return createStarterVisualizationDsl(visualType, title);
}

export function createVisualizationDocumentForType(
  baseDocument: VisualizationDocument,
  visualType: VisualizationType,
  startMode: VisualizationStartMode = 'sample'
): VisualizationDocument {
  const title = getVisualizationTypeLabel(visualType);

  return {
    ...baseDocument,
    title,
    visualType,
    metadata: {
      ...baseDocument.metadata,
      needsTypeSelection: false,
      viewMode: 'render',
    },
    source: {
      format: 'algoviz-dsl',
      code: createVisualizationSourceCode(visualType, title, startMode),
    },
  };
}

export function createVisualizationBlock({
  title,
  visualId = createVisualId(),
  visualType = 'flowchart',
}: CreateVisualizationBlockOptions = {}): TVisualizationElement {
  const resolvedTitle = title ?? 'Visualization';
  const document = createEmptyVisualizationDocument(
    visualType,
    visualId,
    resolvedTitle
  );

  document.metadata = {
    ...document.metadata,
    needsTypeSelection: true,
  };

  return {
    type: VISUALIZATION_BLOCK_TYPE,
    data: document,
    children: [{ text: '' }],
  };
}

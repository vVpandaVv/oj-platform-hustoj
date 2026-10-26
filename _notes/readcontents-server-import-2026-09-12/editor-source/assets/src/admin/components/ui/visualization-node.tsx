'use client';

import * as React from 'react';

import { Pencil, Trash2 } from 'lucide-react';
import type { PlateElementProps } from 'platejs/react';
import {
  PlateElement,
  useEditorRef,
  useElement,
  useReadOnly,
  useSelected,
} from 'platejs/react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  createVisualizationDocumentForType,
  type TVisualizationElement,
  type VisualizationStartMode,
} from '@/visualization/visualization-block';
import {
  appendVisualizationNodeInDsl,
  appendVisualizationEdgeInDsl,
  appendVisualizationDataItemInDsl,
  appendVisualizationTableColumnInDsl,
  appendVisualizationTableRowInDsl,
  appendVisualizationStepInDsl,
  duplicateVisualizationStepInDsl,
  insertVisualizationDataItemInDsl,
  parseVisualizationDsl,
  removeVisualizationDataItemInDsl,
  removeVisualizationNodeInDsl,
  removeVisualizationEdgeInDsl,
  removeVisualizationStepInDsl,
  removeVisualizationTableColumnInDsl,
  removeVisualizationTableRowInDsl,
  updateVisualizationActiveCellInDsl,
  updateVisualizationActiveItemsInDsl,
  updateVisualizationCellValueInDsl,
  updateVisualizationCommandInDsl,
  updateVisualizationDataItemInDsl,
  updateVisualizationEdgeAnchorsInDsl,
  updateVisualizationEdgeDirectionInDsl,
  updateVisualizationEdgeEndpointsInDsl,
  updateVisualizationEdgeLabelInDsl,
  updateVisualizationEdgeLineStyleInDsl,
  updateVisualizationEdgeRouteInDsl,
  updateVisualizationEdgeStateInDsl,
  updateVisualizationNodeLabelInDsl,
  updateVisualizationNodePositionInDsl,
  updateVisualizationNodeShapeInDsl,
  updateVisualizationNodeStateInDsl,
  updateVisualizationStepFocusInDsl,
  appendVisualizationStepStateInDsl,
} from '@/visualization/visualization-dsl';
import type {
  VisualizationAnchorSide,
  VisualizationDirection,
  VisualizationEdge,
  VisualizationEdgeRouteSide,
  VisualizationElementState,
  VisualizationShapeKind,
  VisualizationStep,
} from '@/visualization/visualization-model';
import { getOrderedVisualizationSteps } from '@/visualization/visualization-model';
import { VisualizationFloatingMenu } from '@/visualization/visualization-floating-menu';
import { isVisualizationEdgeDirected } from '@/visualization/visualization-editing-contract';
import { VisualizationRenderer } from '@/visualization/visualization-renderer';
import {
  getVisualizationTypeDefinition,
  VISUALIZATION_TYPE_DEFINITIONS,
  VISUALIZATION_TYPE_GROUPS,
  type VisualizationCategory,
  type VisualizationType,
} from '@/visualization/visualization-types';

type VisualizationViewMode = 'render' | 'split' | 'source';

const VIEW_MODE_OPTIONS = [
  { label: '결과만', value: 'render' },
  { label: '결과 + 소스', value: 'split' },
  { label: '소스만', value: 'source' },
] as const satisfies readonly {
  label: string;
  value: VisualizationViewMode;
}[];

const VISUALIZATION_CATEGORY_LABELS: Record<VisualizationCategory, string> = {
  'ai-system': 'AI 및 시스템',
  'algorithm-behavior': '알고리즘 동작',
  composite: '통합 시각화',
  'data-performance': '데이터 및 성능',
  'flow-process': '흐름 및 프로세스',
  'linear-data-structure': '선형 Data Structure',
  'nonlinear-data-structure': '비선형 Data Structure',
  'space-math': '공간 및 수학',
};

const visualizationDefinitionsByType = new Map(
  VISUALIZATION_TYPE_DEFINITIONS.map((definition) => [
    definition.type,
    definition,
  ])
);

const VIEW_MODE_LABELS: Record<VisualizationViewMode, string> = {
  render: '결과만',
  split: '결과 + 소스',
  source: '소스만',
};

function getVisualizationViewMode(element: TVisualizationElement) {
  const value = element.data?.metadata?.viewMode;

  if (value === 'split' || value === 'source' || value === 'render') {
    return value;
  }

  return 'render';
}

const TYPE_PICKER_TABS = [
  { label: '추천', value: 'recommended' },
  ...VISUALIZATION_TYPE_GROUPS.map((group) => ({
    label: VISUALIZATION_CATEGORY_LABELS[group.category],
    value: group.category,
  })),
] as const;

function isVisualizationTypePickerOpen(element: TVisualizationElement) {
  return element.data?.metadata?.needsTypeSelection === true;
}

function VisualizationTypeMiniPreview({ type }: { type: VisualizationType }) {
  const markerId = `visual-picker-arrow-${type}`;
  const markerUrl = `url(#${markerId})`;
  const muted = '#8aa2c2';
  const stroke = '#64748b';
  const active = '#2563eb';
  const activeFill = '#dbeafe';
  const warn = '#d97706';
  const warnFill = '#fff7ed';
  const success = '#0f766e';
  const successFill = '#ccfbf1';

  const renderPreview = () => {
    switch (type) {
      case 'flowchart':
        return (
          <>
            <rect x="18" y="8" width="54" height="20" rx="10" fill="#fff" stroke={muted} strokeWidth="1.4" />
            <path d="M72 18 H94" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
            <path d="M116 5 L150 18 L116 31 L82 18 Z" fill={warnFill} stroke={warn} strokeWidth="1.4" />
            <path d="M150 18 H172 V41 H196" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
            <rect x="78" y="38" width="60" height="14" rx="3" fill="#fff" stroke={muted} strokeWidth="1.2" />
          </>
        );
      case 'state-diagram':
        return (
          <>
            <circle cx="55" cy="30" r="14" fill={successFill} stroke={success} strokeWidth="1.4" />
            <circle cx="112" cy="18" r="15" fill={activeFill} stroke={active} strokeWidth="1.8" />
            <circle cx="168" cy="30" r="14" fill="#fff" stroke={muted} strokeWidth="1.4" />
            <path d="M69 27 L96 21" fill="none" stroke={active} strokeWidth="1.5" markerEnd={markerUrl} />
            <path d="M128 21 L154 27" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
          </>
        );
      case 'trace-table':
        return (
          <g transform="translate(44 8)">
            {[0, 1, 2, 3].map((column) => (
              <rect key={`h-${column}`} x={column * 34} y="0" width="34" height="14" fill={column === 2 ? activeFill : '#eef6ff'} stroke={muted} />
            ))}
            {[0, 1, 2].map((row) =>
              [0, 1, 2, 3].map((column) => (
                <rect
                  key={`${row}-${column}`}
                  x={column * 34}
                  y={14 + row * 11}
                  width="34"
                  height="11"
                  fill={row === 1 && column === 2 ? activeFill : '#fff'}
                  stroke={row === 1 && column === 2 ? active : muted}
                  strokeWidth={row === 1 && column === 2 ? 1.6 : 1}
                />
              ))
            )}
          </g>
        );
      case 'sequence-diagram':
        return (
          <>
            {[52, 110, 168].map((x) => (
              <g key={x}>
                <rect x={x - 18} y="6" width="36" height="14" rx="4" fill="#fff" stroke={muted} />
                <line x1={x} y1="20" x2={x} y2="52" stroke={muted} strokeDasharray="3 3" />
              </g>
            ))}
            <path d="M52 28 H110" fill="none" stroke={active} strokeWidth="1.5" markerEnd={markerUrl} />
            <path d="M110 39 H168" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
          </>
        );
      case 'storyboard':
        return (
          <>
            {[34, 92, 150].map((x, index) => (
              <g key={x}>
                <rect x={x} y="13" width="42" height="28" rx="5" fill={index === 1 ? activeFill : '#fff'} stroke={index === 1 ? active : muted} />
                <circle cx={x + 8} cy="21" r="5" fill={activeFill} stroke={active} />
                <text x={x + 8} y="24" textAnchor="middle" fontSize="7" fill="#0f172a">{index + 1}</text>
              </g>
            ))}
          </>
        );
      case 'array-grid':
        return (
          <g transform="translate(34 10)">
            {[3, 7, 9, 12, 18].map((value, index) => (
              <g key={value}>
                <rect x={index * 30} y="8" width="30" height="24" rx="3" fill={index === 2 ? activeFill : '#fff'} stroke={index === 2 ? active : muted} strokeWidth={index === 2 ? 1.8 : 1.1} />
                <text x={index * 30 + 15} y="24" textAnchor="middle" fontSize="10" fill="#0f172a">{value}</text>
                <text x={index * 30 + 15} y="6" textAnchor="middle" fontSize="7" fill="#64748b">{index}</text>
              </g>
            ))}
          </g>
        );
      case 'linked-list':
        return (
          <>
            {[30, 92, 154].map((x, index) => (
              <g key={x}>
                <rect x={x} y="18" width="42" height="24" rx="5" fill={index === 1 ? activeFill : '#fff'} stroke={index === 1 ? active : muted} strokeWidth="1.4" />
                <line x1={x + 26} y1="18" x2={x + 26} y2="42" stroke={muted} />
              </g>
            ))}
            <path d="M72 30 H91" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
            <path d="M134 30 H153" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
          </>
        );
      case 'stack':
        return (
          <>
            {[0, 1, 2].map((row) => (
              <rect key={row} x="46" y={12 + row * 12} width="34" height="12" rx="2" fill={row === 0 ? activeFill : '#fff'} stroke={row === 0 ? active : muted} />
            ))}
            <path d="M28 18 H45" fill="none" stroke={active} strokeWidth="1.4" markerEnd={markerUrl} />
            <text x="90" y="21" fontSize="8" fill="#334155">top</text>
          </>
        );
      case 'queue':
        return (
          <>
            {[0, 1, 2, 3].map((column) => (
              <rect key={column} x={40 + column * 34} y="22" width="30" height="20" rx="4" fill={column === 2 ? activeFill : '#fff'} stroke={column === 2 ? active : muted} />
            ))}
            <text x="40" y="54" fontSize="8" fill="#334155">front</text>
            <text x="142" y="54" fontSize="8" fill="#334155">rear</text>
          </>
        );
      case 'stack-queue':
        return (
          <>
            {[0, 1, 2].map((row) => (
              <rect key={row} x="46" y={12 + row * 12} width="34" height="12" rx="2" fill={row === 0 ? activeFill : '#fff'} stroke={row === 0 ? active : muted} />
            ))}
            <path d="M28 18 H45" fill="none" stroke={active} strokeWidth="1.4" markerEnd={markerUrl} />
            {[0, 1, 2, 3].map((column) => (
              <rect key={column} x={104 + column * 24} y="24" width="24" height="18" rx="4" fill={column === 2 ? activeFill : '#fff'} stroke={column === 2 ? active : muted} />
            ))}
          </>
        );
      case 'hash-table':
        return (
          <>
            {[0, 1, 2].map((row) => (
              <rect key={row} x="46" y={9 + row * 14} width="36" height="12" rx="3" fill={row === 1 ? activeFill : '#fff'} stroke={row === 1 ? active : muted} />
            ))}
            <path d="M82 29 H106" fill="none" stroke={active} strokeWidth="1.5" markerEnd={markerUrl} />
            <rect x="108" y="21" width="34" height="18" rx="4" fill={activeFill} stroke={active} />
            <path d="M142 30 H164" fill="none" stroke={stroke} strokeWidth="1.5" markerEnd={markerUrl} />
            <rect x="166" y="21" width="34" height="18" rx="4" fill="#fff" stroke={muted} />
          </>
        );
      case 'tree':
        return (
          <>
            <g stroke={stroke} strokeWidth="1.4">
              <line x1="110" y1="12" x2="72" y2="34" />
              <line x1="110" y1="12" x2="148" y2="34" />
              <line x1="72" y1="34" x2="52" y2="50" />
              <line x1="72" y1="34" x2="92" y2="50" />
            </g>
            {[['110', '12', activeFill, active], ['72', '34', '#fff', muted], ['148', '34', '#fff', muted], ['52', '50', '#fff', muted], ['92', '50', '#fff', muted]].map(([cx, cy, fill, line]) => (
              <circle key={`${cx}-${cy}`} cx={Number(cx)} cy={Number(cy)} r="8" fill={fill} stroke={line} strokeWidth="1.4" />
            ))}
          </>
        );
      case 'network':
        return (
          <>
            <line x1="62" y1="18" x2="118" y2="35" stroke={stroke} strokeWidth="1.4" />
            <line x1="62" y1="18" x2="164" y2="17" stroke={stroke} strokeWidth="1.4" />
            <line x1="118" y1="35" x2="164" y2="17" stroke={active} strokeWidth="1.8" />
            <text x="136" y="23" fontSize="8" fill="#334155">7</text>
            {[[62, 18], [118, 35], [164, 17], [88, 45]].map(([cx, cy], index) => (
              <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r="10" fill={index === 1 ? activeFill : '#fff'} stroke={index === 1 ? active : muted} strokeWidth="1.4" />
            ))}
          </>
        );
      case 'trie':
        return (
          <>
            <g stroke={stroke} strokeWidth="1.4">
              <line x1="110" y1="10" x2="78" y2="31" />
              <line x1="110" y1="10" x2="138" y2="31" />
              <line x1="138" y1="31" x2="162" y2="49" />
            </g>
            <text x="87" y="19" fontSize="8" fill="#334155">c</text>
            <text x="126" y="20" fontSize="8" fill="#334155">a</text>
            <text x="154" y="38" fontSize="8" fill="#334155">t</text>
            {[[110, 10], [78, 31], [138, 31], [162, 49]].map(([cx, cy], index) => (
              <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r="8" fill={index === 3 ? successFill : index === 2 ? activeFill : '#fff'} stroke={index === 3 ? success : index === 2 ? active : muted} strokeWidth="1.4" />
            ))}
          </>
        );
      case 'segment-tree':
        return (
          <>
            <g stroke={stroke} strokeWidth="1.2">
              <line x1="110" y1="13" x2="72" y2="34" />
              <line x1="110" y1="13" x2="148" y2="34" />
              <line x1="72" y1="34" x2="52" y2="49" />
              <line x1="72" y1="34" x2="92" y2="49" />
              <line x1="148" y1="34" x2="128" y2="49" />
              <line x1="148" y1="34" x2="168" y2="49" />
            </g>
            {[[86, 5, 48, 16, activeFill, active], [50, 28, 44, 16, '#fff', muted], [126, 28, 44, 16, '#fff', muted], [36, 45, 32, 11, '#fff', muted], [76, 45, 32, 11, '#fff', muted], [112, 45, 32, 11, activeFill, active], [152, 45, 32, 11, warnFill, warn]].map(([x, y, w, h, fill, line], index) => (
              <rect key={index} x={Number(x)} y={Number(y)} width={Number(w)} height={Number(h)} rx="4" fill={String(fill)} stroke={String(line)} strokeWidth="1.2" />
            ))}
          </>
        );
      case 'sorting':
        return (
          <>
            {[30, 58, 86, 114, 142, 170].map((x, index) => (
              <rect key={x} x={x} y={42 - [18, 28, 14, 34, 22, 30][index]} width="18" height={[18, 28, 14, 34, 22, 30][index]} rx="3" fill={index === 1 || index === 2 ? activeFill : '#fff'} stroke={index === 1 || index === 2 ? active : muted} />
            ))}
            <path d="M67 48 V52 H95 V48" fill="none" stroke={active} strokeWidth="1.5" markerStart={markerUrl} markerEnd={markerUrl} />
          </>
        );
      case 'searching':
        return (
          <>
            {[0, 1, 2, 3, 4, 5].map((index) => (
              <rect key={index} x={34 + index * 25} y="20" width="25" height="20" rx="3" fill={index === 3 ? activeFill : '#fff'} stroke={index === 3 ? active : muted} />
            ))}
            {[[38, 'low'], [111, 'mid'], [166, 'high']].map(([x, label]) => (
              <g key={label}>
                <line x1={Number(x)} y1="13" x2={Number(x)} y2="20" stroke={active} strokeWidth="1.4" />
                <text x={Number(x)} y="11" fontSize="7" textAnchor="middle" fill="#334155">{label}</text>
              </g>
            ))}
          </>
        );
      case 'recursion-tree':
        return (
          <>
            <g stroke={stroke} strokeWidth="1.4">
              <line x1="92" y1="13" x2="62" y2="34" />
              <line x1="92" y1="13" x2="120" y2="34" />
              <line x1="62" y1="34" x2="42" y2="50" />
            </g>
            {[[92, 13], [62, 34], [120, 34], [42, 50]].map(([cx, cy], index) => (
              <rect key={`${cx}-${cy}`} x={cx - 17} y={cy - 8} width="34" height="16" rx="8" fill={index === 0 ? activeFill : '#fff'} stroke={index === 0 ? active : muted} />
            ))}
            <rect x="150" y="14" width="36" height="32" rx="5" fill="#fff" stroke={muted} />
            <line x1="158" y1="24" x2="178" y2="24" stroke={muted} />
            <line x1="158" y1="33" x2="178" y2="33" stroke={muted} />
          </>
        );
      case 'divide-conquer':
        return (
          <>
            <rect x="84" y="6" width="52" height="15" rx="4" fill={activeFill} stroke={active} />
            <path d="M100 21 L70 35" stroke={stroke} strokeWidth="1.4" markerEnd={markerUrl} />
            <path d="M120 21 L150 35" stroke={stroke} strokeWidth="1.4" markerEnd={markerUrl} />
            <rect x="46" y="34" width="50" height="14" rx="4" fill="#fff" stroke={muted} />
            <rect x="124" y="34" width="50" height="14" rx="4" fill="#fff" stroke={muted} />
            <path d="M72 50 H148" stroke={success} strokeWidth="1.6" markerEnd={markerUrl} />
          </>
        );
      case 'dp-table':
        return (
          <>
            <g transform="translate(58 6)">
              {[0, 1, 2, 3].map((row) =>
                [0, 1, 2, 3].map((column) => (
                  <rect
                    key={`${row}-${column}`}
                    x={column * 24}
                    y={row * 12}
                    width="24"
                    height="12"
                    fill={row === 2 && column === 2 ? activeFill : row === 0 || column === 0 ? '#eef6ff' : '#fff'}
                    stroke={row === 2 && column === 2 ? active : muted}
                    strokeWidth={row === 2 && column === 2 ? 1.5 : 1}
                  />
                ))
              )}
            </g>
            <path d="M94 24 V31 H106" fill="none" stroke={active} strokeWidth="1.4" markerEnd={markerUrl} />
            <path d="M119 18 V31 H106" fill="none" stroke={success} strokeWidth="1.4" markerEnd={markerUrl} />
          </>
        );
      case 'state-space-tree':
        return (
          <>
            <g stroke={stroke} strokeWidth="1.3">
              <line x1="110" y1="11" x2="64" y2="34" />
              <line x1="110" y1="11" x2="110" y2="34" />
              <line x1="110" y1="11" x2="156" y2="34" />
            </g>
            <rect x="88" y="4" width="44" height="16" rx="8" fill="#fff" stroke={muted} />
            <rect x="42" y="29" width="44" height="16" rx="8" fill="#fee2e2" stroke="#dc2626" />
            <rect x="88" y="29" width="44" height="16" rx="8" fill={activeFill} stroke={active} />
            <rect x="134" y="29" width="44" height="16" rx="8" fill="#fff" stroke={muted} />
          </>
        );
      case 'scatter':
        return (
          <>
            <path d="M34 45 H188 M34 45 V10" stroke={muted} strokeWidth="1.2" />
            {[[58, 34], [78, 22], [108, 37], [136, 18], [168, 28]].map(([cx, cy], index) => (
              <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r={index === 3 ? 4 : 3} fill={index === 3 ? activeFill : '#fff'} stroke={index === 3 ? active : muted} strokeWidth="1.4" />
            ))}
          </>
        );
      case 'histogram':
        return (
          <>
            <path d="M34 46 H188 M34 46 V10" stroke={muted} strokeWidth="1.2" />
            {[20, 31, 17, 38, 25].map((height, index) => (
              <rect key={index} x={52 + index * 25} y={46 - height} width="17" height={height} fill={index === 3 ? activeFill : '#fff'} stroke={index === 3 ? active : muted} />
            ))}
          </>
        );
      case 'line-graph':
        return (
          <>
            <path d="M34 46 H188 M34 46 V10" stroke={muted} strokeWidth="1.2" />
            <polyline points="46,39 76,31 106,34 136,18 174,23" fill="none" stroke={active} strokeWidth="2" />
          </>
        );
      case 'heatmap':
        return (
          <g transform="translate(58 8)">
            {[0, 1, 2, 3].map((row) =>
              [0, 1, 2, 3, 4].map((column) => {
                const level = (row + column) % 4;
                return (
                  <rect
                    key={`${row}-${column}`}
                    x={column * 20}
                    y={row * 11}
                    width="20"
                    height="11"
                    fill={['#eff6ff', '#bfdbfe', '#60a5fa', '#2563eb'][level]}
                    stroke="#ffffff"
                  />
                );
              })
            )}
          </g>
        );
      case 'complexity-chart':
        return (
          <>
            <path d="M34 46 H188 M34 46 V10" stroke={muted} strokeWidth="1.2" />
            <path d="M42 42 C88 39 126 33 176 18" fill="none" stroke={active} strokeWidth="1.8" />
            <path d="M42 43 C76 43 116 38 176 30" fill="none" stroke={success} strokeWidth="1.6" />
            <text x="145" y="17" fontSize="8" fill="#334155">O(n²)</text>
          </>
        );
      case 'path-map':
        return (
          <>
            <g transform="translate(52 8)" stroke={muted}>
              {[0, 1, 2, 3, 4].map((index) => (
                <React.Fragment key={index}>
                  <line x1={index * 24} y1="0" x2={index * 24} y2="48" />
                  <line x1="0" y1={index * 12} x2="120" y2={index * 12} />
                </React.Fragment>
              ))}
            </g>
            <polyline points="58,44 82,44 82,32 106,32 106,20 154,20" fill="none" stroke={active} strokeWidth="2.3" markerEnd={markerUrl} />
          </>
        );
      case 'geometry-plane':
        return (
          <>
            <path d="M36 44 H188 M110 50 V8" stroke={muted} strokeWidth="1.2" />
            <polygon points="84,38 142,34 122,14" fill={activeFill} stroke={active} strokeWidth="1.5" />
            <circle cx="122" cy="14" r="3" fill={active} />
          </>
        );
      case 'convergence-graph':
        return (
          <>
            <path d="M34 46 H188 M34 46 V10" stroke={muted} strokeWidth="1.2" />
            <line x1="42" y1="18" x2="184" y2="18" stroke={warn} strokeDasharray="4 3" />
            <path d="M42 42 C76 28 108 21 178 18" fill="none" stroke={active} strokeWidth="2" />
          </>
        );
      case 'bit-matrix':
        return (
          <g transform="translate(58 8)">
            {[0, 1, 2, 3].map((row) =>
              [0, 1, 2, 3, 4].map((column) => (
                <g key={`${row}-${column}`}>
                  <rect x={column * 20} y={row * 11} width="20" height="11" fill={row === 1 && column === 3 ? activeFill : '#fff'} stroke={muted} />
                  <text x={column * 20 + 10} y={row * 11 + 8} textAnchor="middle" fontSize="7" fill="#334155">{(row + column) % 2}</text>
                </g>
              ))
            )}
          </g>
        );
      case 'sieve-matrix':
        return (
          <g transform="translate(48 8)">
            {[2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13].map((value, index) => (
              <g key={value}>
                <rect x={(index % 6) * 21} y={Math.floor(index / 6) * 18} width="20" height="16" rx="3" fill={[4, 6, 8, 9, 10, 12].includes(value) ? '#fee2e2' : activeFill} stroke={[4, 6, 8, 9, 10, 12].includes(value) ? '#dc2626' : active} />
                <text x={(index % 6) * 21 + 10} y={Math.floor(index / 6) * 18 + 11} textAnchor="middle" fontSize="7" fill="#334155">{value}</text>
              </g>
            ))}
          </g>
        );
      case 'vector-space':
        return (
          <>
            <path d="M38 44 H184 M58 50 V8" stroke={muted} strokeWidth="1.2" />
            <path d="M58 44 L138 20" stroke={active} strokeWidth="2" markerEnd={markerUrl} />
            <path d="M58 44 L112 34" stroke={success} strokeWidth="2" markerEnd={markerUrl} />
          </>
        );
      case 'neural-network':
        return (
          <>
            {[54, 110, 166].map((x, layer) =>
              [18, 31, 44].slice(layer === 2 ? 1 : 0, layer === 2 ? 2 : 3).map((y) => (
                <circle key={`${x}-${y}`} cx={x} cy={y} r="7" fill={x === 110 && y === 31 ? activeFill : '#fff'} stroke={x === 110 && y === 31 ? active : muted} strokeWidth="1.4" />
              ))
            )}
            <g stroke={stroke} strokeWidth="1">
              <line x1="61" y1="18" x2="103" y2="18" />
              <line x1="61" y1="18" x2="103" y2="31" />
              <line x1="61" y1="31" x2="103" y2="31" />
              <line x1="117" y1="31" x2="159" y2="31" />
              <line x1="117" y1="18" x2="159" y2="31" />
              <line x1="117" y1="44" x2="159" y2="31" />
            </g>
          </>
        );
      case 'decision-tree':
        return (
          <>
            <g stroke={stroke} strokeWidth="1.4">
              <line x1="110" y1="13" x2="72" y2="36" />
              <line x1="110" y1="13" x2="148" y2="36" />
            </g>
            <rect x="82" y="4" width="56" height="18" rx="9" fill={activeFill} stroke={active} />
            <rect x="48" y="33" width="48" height="17" rx="8" fill="#fff" stroke={muted} />
            <rect x="124" y="33" width="48" height="17" rx="8" fill="#fff" stroke={muted} />
            <text x="76" y="28" fontSize="7" fill="#334155">no</text>
            <text x="136" y="28" fontSize="7" fill="#334155">yes</text>
          </>
        );
      case 'evolution-path':
        return (
          <>
            <path d="M36 46 C66 24 98 36 126 22 S168 16 190 9" fill="none" stroke={active} strokeWidth="2" markerEnd={markerUrl} />
            {[[50, 39], [92, 32], [128, 22], [168, 17]].map(([cx, cy], index) => (
              <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r={index === 2 ? 4 : 3.5} fill={index === 2 ? activeFill : '#fff'} stroke={index === 2 ? active : muted} />
            ))}
          </>
        );
      case 'gantt':
        return (
          <>
            <line x1="42" y1="14" x2="184" y2="14" stroke={muted} />
            {[58, 100, 142].map((x) => <line key={x} x1={x} y1="10" x2={x} y2="48" stroke="#e2e8f0" />)}
            <rect x="50" y="20" width="68" height="12" rx="4" fill={successFill} stroke={success} />
            <rect x="92" y="34" width="78" height="12" rx="4" fill={activeFill} stroke={active} />
            <rect x="150" y="47" width="34" height="8" rx="3" fill={warnFill} stroke={warn} />
          </>
        );
      case 'memory-page-flow':
        return (
          <>
            {[7, 0, 1, 2, 0].map((value, index) => (
              <g key={`${value}-${index}`}>
                <rect x={38 + index * 28} y="8" width="22" height="15" rx="4" fill={index === 3 ? '#fee2e2' : activeFill} stroke={index === 3 ? '#dc2626' : active} />
                <text x={49 + index * 28} y="19" textAnchor="middle" fontSize="8" fill="#0f172a">{value}</text>
              </g>
            ))}
            {[0, 1, 2].map((row) =>
              [0, 1, 2, 3].map((column) => (
                <rect key={`${row}-${column}`} x={70 + column * 24} y={29 + row * 8} width="24" height="8" fill={row === 0 && column === 3 ? activeFill : '#fff'} stroke={muted} />
              ))
            )}
          </>
        );
      case 'composite':
        return (
          <>
            <rect x="32" y="12" width="70" height="32" rx="6" fill="#fff" stroke={muted} />
            <circle cx="58" cy="29" r="10" fill={successFill} stroke={success} />
            <circle cx="80" cy="29" r="10" fill={activeFill} stroke={active} />
            <path d="M68 29 H70" stroke={active} strokeWidth="1.5" markerEnd={markerUrl} />
            <rect x="122" y="12" width="68" height="32" rx="6" fill="#fff" stroke={muted} />
            <rect x="138" y="22" width="18" height="10" rx="2" fill={successFill} stroke={success} />
            <rect x="160" y="22" width="18" height="10" rx="2" fill={activeFill} stroke={active} />
          </>
        );
      default:
        return null;
    }
  };

  return (
    <svg viewBox="0 0 220 56" role="img" aria-hidden="true">
      <defs>
        <marker
          id={markerId}
          markerHeight="5"
          markerWidth="5"
          orient="auto"
          refX="4.6"
          refY="2.5"
        >
          <path d="M0,0 L5,2.5 L0,5 Z" fill={stroke} />
        </marker>
      </defs>
      {renderPreview()}
    </svg>
  );
}

function VisualizationTypePicker({
  element,
  onVisualTypeChange,
}: {
  element: TVisualizationElement;
  onVisualTypeChange: (
    visualType: VisualizationType,
    startMode?: VisualizationStartMode
  ) => void;
}) {
  const initialType = element.data?.visualType ?? 'flowchart';
  const [query, setQuery] = React.useState('');
  const [activeTab, setActiveTab] = React.useState<
    'recommended' | VisualizationCategory
  >('recommended');
  const [selectedType, setSelectedType] =
    React.useState<VisualizationType>(initialType);
  const selectedDefinition = getVisualizationTypeDefinition(selectedType);

  const normalizedQuery = query.trim().toLowerCase();
  const filteredDefinitions = VISUALIZATION_TYPE_DEFINITIONS.filter(
    (definition) => {
      const matchesTab =
        activeTab === 'recommended'
          ? definition.priority === 1
          : definition.category === activeTab;

      if (!matchesTab) return false;
      if (!normalizedQuery) return true;

      return [
        definition.type,
        definition.label,
        definition.categoryLabel,
        definition.description,
        ...definition.wysiwygElements,
      ]
        .join(' ')
        .toLowerCase()
        .includes(normalizedQuery);
    }
  );

  const previewDocument = React.useMemo(() => {
    if (!element.data) return null;

    return createVisualizationDocumentForType(
      element.data,
      selectedType,
      'sample'
    );
  }, [element.data, selectedType]);

  return (
    <div className="group my-4 overflow-hidden rounded-xl border bg-background shadow-sm">
      <div className="border-b bg-slate-50/70 p-5">
        <div className="mb-2 flex flex-wrap items-center gap-2">
          <span className="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">
            시각화
          </span>
          <strong className="text-lg">시각화 유형 선택</strong>
        </div>
        <p className="m-0 text-sm text-muted-foreground">
          바로 수정할 수 있는 샘플에서 시작합니다. 삽입된 블록은 편집 화면과
          학습자 화면에서 같은 렌더러를 사용합니다.
        </p>
      </div>

      <div className="grid gap-0 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="min-w-0 border-r">
          <div className="grid gap-3 border-b p-4">
            <label className="flex min-w-0 items-center gap-2 rounded-lg border bg-slate-50 px-3 py-2 text-sm">
              <span className="font-bold text-slate-400">검색</span>
              <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                className="min-w-0 flex-1 border-0 bg-transparent outline-none"
                placeholder="Flowchart, Array, Tree, DP..."
              />
            </label>
            <div className="flex gap-1 overflow-x-auto pb-1">
              {TYPE_PICKER_TABS.map((tab) => (
                <button
                  key={tab.value}
                  type="button"
                  onClick={() =>
                    setActiveTab(tab.value as 'recommended' | VisualizationCategory)
                  }
                  className={
                    activeTab === tab.value
                      ? 'shrink-0 rounded-full border border-blue-300 bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700'
                      : 'shrink-0 rounded-full border bg-background px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50'
                  }
                >
                  {tab.label}
                </button>
              ))}
            </div>
          </div>

          <div className="grid gap-3 p-4 sm:grid-cols-2">
            {filteredDefinitions.map((definition) => (
              <button
                key={definition.type}
                type="button"
                onClick={() => setSelectedType(definition.type)}
                onDoubleClick={() => onVisualTypeChange(definition.type)}
                className={
                  selectedType === definition.type
                    ? 'min-h-[148px] rounded-xl border border-blue-500 bg-white p-3 text-left shadow-[0_0_0_3px_rgba(37,99,235,0.14)]'
                    : 'min-h-[148px] rounded-xl border bg-white p-3 text-left transition hover:-translate-y-0.5 hover:border-blue-300'
                }
              >
                <div className="mb-2 flex items-start justify-between gap-2">
                  <strong className="text-sm">{definition.label}</strong>
                  <span className="rounded-full border px-2 py-0.5 text-[11px] font-bold text-slate-500">
                    {VISUALIZATION_CATEGORY_LABELS[definition.category]}
                  </span>
                </div>
                <p className="mb-3 line-clamp-2 text-xs leading-5 text-slate-600">
                  {definition.description}
                </p>
                <div className="h-14 overflow-hidden rounded-lg border border-blue-100 bg-[linear-gradient(#dbeafe_1px,transparent_1px),linear-gradient(90deg,#dbeafe_1px,transparent_1px)] bg-[length:18px_18px]">
                  <VisualizationTypeMiniPreview type={definition.type} />
                </div>
              </button>
            ))}
            {filteredDefinitions.length === 0 && (
              <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground sm:col-span-2">
                검색 조건에 맞는 시각화 유형이 없습니다.
              </div>
            )}
          </div>
        </div>

        <aside className="flex min-w-0 flex-col bg-slate-50/70 p-4">
          <h3 className="mb-1 text-base font-bold">
            {selectedDefinition.label} 미리보기
          </h3>
          <p className="mb-3 text-xs leading-5 text-slate-600">
            레슨에 삽입될 샘플입니다.
          </p>
          <div className="mb-3 h-[210px] overflow-hidden rounded-xl border bg-white">
            {previewDocument ? (
              <VisualizationRenderer document={previewDocument} readOnly />
            ) : null}
          </div>
          <ul className="mb-4 grid gap-2 p-0 text-xs text-slate-700">
            {selectedDefinition.wysiwygElements.slice(0, 4).map((item) => (
              <li key={item} className="flex list-none items-center gap-2">
                <span className="size-1.5 rounded-full bg-teal-600" />
                {item}을 캔버스에서 직접 수정합니다.
              </li>
            ))}
          </ul>
          <div className="mt-auto flex flex-wrap gap-2">
            <Button type="button" size="sm" onClick={() => onVisualTypeChange(selectedType)}>
              샘플로 시작
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => onVisualTypeChange(selectedType, 'blank')}>
              빈 캔버스
            </Button>
          </div>
        </aside>
      </div>
    </div>
  );
}

export function VisualizationRenderPanel({
  currentStepId,
  element,
  onCellColumnCreate,
  onCellColumnDelete,
  onCellRowCreate,
  onCellRowDelete,
  onCommandChange,
  onEdgeAnchorChange,
  onCellValueChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeEndpointsChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onEdgeRouteChange,
  onEdgeStateChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  onItemCreate,
  onItemDelete,
  onItemActiveChange,
  onItemValueChange,
  onCellActiveChange,
  readOnly,
}: {
  currentStepId?: string | null;
  element: TVisualizationElement;
  onCellColumnCreate: () => void;
  onCellColumnDelete: (column: number) => void;
  onCellRowCreate: () => void;
  onCellRowDelete: (row: number) => void;
  onCellValueChange: (row: number, column: number, value: string) => void;
  onEdgeAnchorChange: (
    edge: VisualizationEdge,
    fromAnchor: VisualizationAnchorSide,
    toAnchor: VisualizationAnchorSide
  ) => void;
  onEdgeCreate: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeEndpointsChange: (
    edge: VisualizationEdge,
    nextFromNodeId: string,
    nextToNodeId: string
  ) => void;
  onEdgeLabelChange: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onEdgeRouteChange: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  onEdgeStateChange: (
    edge: VisualizationEdge,
    state: VisualizationElementState
  ) => void;
  onNodeCreate: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete: (nodeId: string) => void;
  onNodeLabelChange: (nodeId: string, label: string) => void;
  onNodeMove: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  onCommandChange: (command: string, value: string) => void;
  onItemCreate: (itemIndex?: number, value?: string) => void;
  onItemDelete: (itemIndex: number) => void;
  onItemActiveChange: (itemIndexes: number[]) => void;
  onItemValueChange: (itemIndex: number, value: string) => void;
  onCellActiveChange: (row: number, column: number) => void;
  readOnly: boolean;
}) {
  const document = element.data;

  if (!document) {
    return (
      <div className="flex min-h-[260px] items-center justify-center rounded-lg border border-dashed bg-muted/30 p-6 text-center text-sm text-muted-foreground">
        시각화 데이터를 불러오지 못했습니다.
      </div>
    );
  }

  const parsedDocument =
    document.source.format === 'algoviz-dsl'
      ? parseVisualizationDsl(document.source.code, document.visualType, document.title)
      : document;
  return (
    <div
      className="group/visual-panel relative min-h-[260px] min-w-0 rounded-lg bg-background"
      data-visualization-render-panel
      tabIndex={readOnly ? undefined : -1}
      onPointerDownCapture={(event) => {
        if (readOnly) {
          return;
        }

        event.currentTarget.focus({ preventScroll: true });
      }}
    >
      <VisualizationFloatingMenu
        document={parsedDocument}
        readOnly={readOnly}
        onCellColumnCreate={onCellColumnCreate}
        onCellColumnDelete={onCellColumnDelete}
        onCellRowCreate={onCellRowCreate}
        onCellRowDelete={onCellRowDelete}
        onCommandChange={onCommandChange}
        onItemCreate={onItemCreate}
        onItemDelete={onItemDelete}
      />
      <VisualizationRenderer
        currentStepId={currentStepId}
        document={document}
        readOnly={readOnly}
        onCommandChange={onCommandChange}
        onCellValueChange={onCellValueChange}
        onEdgeAnchorChange={onEdgeAnchorChange}
        onEdgeCreate={onEdgeCreate}
        onEdgeDelete={onEdgeDelete}
        onEdgeDirectionChange={onEdgeDirectionChange}
        onEdgeEndpointsChange={onEdgeEndpointsChange}
        onEdgeLabelChange={onEdgeLabelChange}
        onEdgeLineStyleChange={onEdgeLineStyleChange}
        onEdgeRouteChange={onEdgeRouteChange}
        onEdgeStateChange={onEdgeStateChange}
        onNodeCreate={onNodeCreate}
        onNodeDelete={onNodeDelete}
        onNodeLabelChange={onNodeLabelChange}
        onNodeMove={onNodeMove}
        onNodeShapeChange={onNodeShapeChange}
        onNodeStateChange={onNodeStateChange}
        onItemCreate={onItemCreate}
        onItemDelete={onItemDelete}
        onItemActiveChange={onItemActiveChange}
        onItemValueChange={onItemValueChange}
        onCellActiveChange={onCellActiveChange}
      />
    </div>
  );
}

function VisualizationSourcePanel({
  code,
  onCodeChange,
  readOnly,
}: {
  code: string;
  onCodeChange: (code: string) => void;
  readOnly: boolean;
}) {
  const [internalCode, setInternalCode] = React.useState(code);
  const lastExternalCodeRef = React.useRef(code);

  React.useEffect(() => {
    if (code !== lastExternalCodeRef.current) {
      lastExternalCodeRef.current = code;
      setInternalCode(code);
    }
  }, [code]);

  const handleChange = React.useCallback(
    (event: React.ChangeEvent<HTMLTextAreaElement>) => {
      const nextCode = event.target.value;
      setInternalCode(nextCode);
      onCodeChange(nextCode);
    },
    [onCodeChange]
  );

  return (
    <div className="flex min-h-[240px] min-w-0 flex-col rounded-lg border bg-muted/40">
      <div className="border-b px-3 py-2 text-xs font-semibold text-muted-foreground">
        시각화 소스
      </div>
      {readOnly ? (
        <pre className="m-0 min-h-[200px] overflow-auto bg-background p-4 text-xs leading-5">
          <code>{code}</code>
        </pre>
      ) : (
        <textarea
          value={internalCode}
          onChange={handleChange}
          className="min-h-[200px] flex-1 resize-y border-0 bg-background p-4 font-mono text-xs leading-5 outline-none"
          placeholder="시각화 소스를 입력하세요."
          spellCheck={false}
        />
      )}
    </div>
  );
}

function VisualizationStepPlayer({
  canEdit,
  currentStepId,
  onStepAdd,
  onStepDelete,
  onStepDuplicate,
  onStepSelect,
  playing,
  setPlaying,
  steps,
}: {
  canEdit: boolean;
  currentStepId: string | null;
  onStepAdd: () => void;
  onStepDelete: (stepId: string) => void;
  onStepDuplicate: (stepId: string) => void;
  onStepSelect: (stepId: string) => void;
  playing: boolean;
  setPlaying: (playing: boolean) => void;
  steps: VisualizationStep[];
}) {
  const currentIndex = Math.max(
    0,
    steps.findIndex((step) => step.id === currentStepId)
  );
  const currentStep = steps[currentIndex] ?? null;
  const hasSteps = steps.length > 0;

  if (!hasSteps && !canEdit) {
    return null;
  }

  return (
    <div
      className="border-b bg-muted/30 px-4 py-3"
      data-visualization-step-player
    >
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="xs"
          disabled={!hasSteps || currentIndex <= 0}
          onClick={() => onStepSelect(steps[0].id)}
        >
          처음
        </Button>
        <Button
          type="button"
          variant="outline"
          size="xs"
          disabled={!hasSteps || currentIndex <= 0}
          onClick={() => onStepSelect(steps[currentIndex - 1].id)}
        >
          이전
        </Button>
        <Button
          type="button"
          variant="outline"
          size="xs"
          disabled={!hasSteps}
          onClick={() => setPlaying(!playing)}
        >
          {playing ? '일시정지' : '재생'}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="xs"
          disabled={!hasSteps || currentIndex >= steps.length - 1}
          onClick={() => onStepSelect(steps[currentIndex + 1].id)}
        >
          다음
        </Button>
        <Button
          type="button"
          variant="outline"
          size="xs"
          disabled={!hasSteps || currentIndex >= steps.length - 1}
          onClick={() => onStepSelect(steps[steps.length - 1].id)}
        >
          끝
        </Button>

        {hasSteps && (
          <span className="rounded-full border bg-background px-2.5 py-1 text-xs font-semibold text-primary">
            {currentIndex + 1} / {steps.length} 단계
          </span>
        )}

        {canEdit && (
          <div className="ml-auto flex flex-wrap items-center gap-2">
            <Button type="button" variant="outline" size="xs" onClick={onStepAdd}>
              단계 추가
            </Button>
            <Button
              type="button"
              variant="outline"
              size="xs"
              disabled={!currentStep}
              onClick={() => currentStep && onStepDuplicate(currentStep.id)}
            >
              복제
            </Button>
            <Button
              type="button"
              variant="outline"
              size="xs"
              disabled={!currentStep}
              onClick={() => currentStep && onStepDelete(currentStep.id)}
            >
              삭제
            </Button>
          </div>
        )}
      </div>

      {!hasSteps && canEdit && (
        <div className="mt-3 rounded-lg border border-dashed bg-background px-3 py-2 text-xs leading-5 text-muted-foreground">
          단계가 아직 없습니다. <strong className="text-foreground">단계 추가</strong>를 누른 뒤,
          현재 단계에서 강조할 노드, 셀, 값을 선택하세요. 선택 내용은 현재 단계의
          강조 정보로 저장됩니다.
        </div>
      )}

      {hasSteps && (
        <>
          <div className="mt-3 flex gap-1">
            {steps.map((step, index) => (
              <button
                key={step.id}
                type="button"
                className={[
                  'h-2 min-w-8 flex-1 rounded-full border-0 transition-colors',
                  index < currentIndex
                    ? 'bg-primary/40'
                    : index === currentIndex
                      ? 'bg-primary'
                      : 'bg-muted-foreground/20',
                ].join(' ')}
                aria-label={`${index + 1}단계로 이동`}
                onClick={() => onStepSelect(step.id)}
              />
            ))}
          </div>
          <div className="mt-3 border-l-2 border-primary bg-background px-3 py-2 text-xs leading-5 text-muted-foreground">
            <strong className="mr-2 text-foreground">
              {currentStep?.title || `${currentIndex + 1}단계`}
            </strong>
            {currentStep?.description || '아직 단계 설명이 없습니다.'}
          </div>
        </>
      )}
    </div>
  );
}

function VisualizationPreviewShell({
  editorMode,
  element,
  onVisualTypeChange,
  onCellColumnCreate,
  onCellColumnDelete,
  onCellRowCreate,
  onCellRowDelete,
  onEdgeAnchorChange,
  onCellValueChange,
  onEdgeCreate,
  onEdgeDelete,
  onEdgeDirectionChange,
  onEdgeEndpointsChange,
  onEdgeLabelChange,
  onEdgeLineStyleChange,
  onEdgeRouteChange,
  onEdgeStateChange,
  onCodeChange,
  onNodeCreate,
  onNodeDelete,
  onNodeLabelChange,
  onNodeMove,
  onNodeShapeChange,
  onNodeStateChange,
  onItemCreate,
  onItemDelete,
  onItemActiveChange,
  onItemValueChange,
  onCellActiveChange,
  onCommandChange,
  onViewModeChange,
  readOnly,
  viewMode,
}: {
  editorMode: boolean;
  element: TVisualizationElement;
  onVisualTypeChange: (
    visualType: VisualizationType,
    startMode?: VisualizationStartMode
  ) => void;
  onCellColumnCreate: () => void;
  onCellColumnDelete: (column: number) => void;
  onCellRowCreate: () => void;
  onCellRowDelete: (row: number) => void;
  onEdgeAnchorChange: (
    edge: VisualizationEdge,
    fromAnchor: VisualizationAnchorSide,
    toAnchor: VisualizationAnchorSide
  ) => void;
  onCellValueChange: (row: number, column: number, value: string) => void;
  onEdgeCreate: (fromNodeId: string, toNodeId: string) => void;
  onEdgeDelete: (edge: VisualizationEdge) => void;
  onEdgeDirectionChange: (
    edge: VisualizationEdge,
    direction: VisualizationDirection
  ) => void;
  onEdgeEndpointsChange: (
    edge: VisualizationEdge,
    nextFromNodeId: string,
    nextToNodeId: string
  ) => void;
  onEdgeLabelChange: (edge: VisualizationEdge, label: string) => void;
  onEdgeLineStyleChange: (
    edge: VisualizationEdge,
    lineStyle: 'solid' | 'dashed'
  ) => void;
  onEdgeRouteChange: (
    edge: VisualizationEdge,
    routeSide: VisualizationEdgeRouteSide,
    routeOffset: number
  ) => void;
  onEdgeStateChange: (
    edge: VisualizationEdge,
    state: VisualizationElementState
  ) => void;
  onCodeChange: (code: string) => void;
  onNodeCreate: (node: {
    label: string;
    shape: VisualizationShapeKind;
    x: number;
    y: number;
  }) => void;
  onNodeDelete: (nodeId: string) => void;
  onNodeLabelChange: (nodeId: string, label: string) => void;
  onNodeMove: (nodeId: string, x: number, y: number) => void;
  onNodeShapeChange: (
    nodeId: string,
    shape: VisualizationShapeKind
  ) => void;
  onNodeStateChange: (
    nodeId: string,
    state: VisualizationElementState
  ) => void;
  onCommandChange: (command: string, value: string) => void;
  onItemCreate: (itemIndex?: number, value?: string) => void;
  onItemDelete: (itemIndex: number) => void;
  onItemActiveChange: (itemIndexes: number[]) => void;
  onItemValueChange: (itemIndex: number, value: string) => void;
  onCellActiveChange: (row: number, column: number) => void;
  onViewModeChange: (viewMode: VisualizationViewMode) => void;
  readOnly: boolean;
  viewMode: VisualizationViewMode;
}) {
  const document = element.data;
  const visualType = document?.visualType ?? 'flowchart';
  const definition = getVisualizationTypeDefinition(visualType);
  const parsedDocument = React.useMemo(() => {
    if (!document) {
      return null;
    }

    return document.source.format === 'algoviz-dsl'
      ? parseVisualizationDsl(document.source.code, document.visualType, document.title)
      : document;
  }, [document]);
  const steps = React.useMemo(
    () => (parsedDocument ? getOrderedVisualizationSteps(parsedDocument) : []),
    [parsedDocument]
  );
  const [currentStepId, setCurrentStepId] = React.useState<string | null>(null);
  const [playingSteps, setPlayingSteps] = React.useState(false);

  const code = document?.source.code ?? `visual ${visualType}`;
  const canEdit = editorMode && !readOnly;
  const effectiveViewMode = canEdit ? viewMode : 'render';
  const showRender =
    effectiveViewMode === 'render' || effectiveViewMode === 'split';
  const showSource =
    effectiveViewMode === 'source' || effectiveViewMode === 'split';
  const isSplit = showRender && showSource;

  React.useEffect(() => {
    if (steps.length === 0) {
      setCurrentStepId(null);
      setPlayingSteps(false);

      return;
    }

    if (!currentStepId || !steps.some((step) => step.id === currentStepId)) {
      setCurrentStepId(steps[0].id);
    }
  }, [currentStepId, steps]);

  React.useEffect(() => {
    if (!playingSteps || steps.length <= 1) {
      return;
    }

    const timer = window.setInterval(() => {
      setCurrentStepId((previousStepId) => {
        const currentIndex = Math.max(
          0,
          steps.findIndex((step) => step.id === previousStepId)
        );
        const nextIndex = (currentIndex + 1) % steps.length;

        return steps[nextIndex].id;
      });
    }, 1200);

    return () => window.clearInterval(timer);
  }, [playingSteps, steps]);

  const handleStepAdd = React.useCallback(() => {
    if (!document || document.source.format !== 'algoviz-dsl') {
      return;
    }

    setPlayingSteps(false);
    onCodeChange(appendVisualizationStepInDsl(document.source.code));
  }, [document, onCodeChange]);

  const handleStepDuplicate = React.useCallback(
    (stepId: string) => {
      if (!document || document.source.format !== 'algoviz-dsl') {
        return;
      }

      setPlayingSteps(false);
      onCodeChange(duplicateVisualizationStepInDsl(document.source.code, stepId));
    },
    [document, onCodeChange]
  );

  const handleStepDelete = React.useCallback(
    (stepId: string) => {
      if (!document || document.source.format !== 'algoviz-dsl') {
        return;
      }

      setPlayingSteps(false);
      onCodeChange(removeVisualizationStepInDsl(document.source.code, stepId));
    },
    [document, onCodeChange]
  );

  const handleStepAwareItemActiveChange = React.useCallback(
    (itemIndexes: number[]) => {
      if (
        !readOnly &&
        currentStepId &&
        document?.source.format === 'algoviz-dsl' &&
        steps.length > 0
      ) {
        onCodeChange(
          updateVisualizationStepFocusInDsl(
            document.source.code,
            currentStepId,
            itemIndexes.map((itemIndex) => `item:${itemIndex}`)
          )
        );

        return;
      }

      onItemActiveChange(itemIndexes);
    },
    [
      currentStepId,
      document,
      onCodeChange,
      onItemActiveChange,
      readOnly,
      steps.length,
    ]
  );

  const handleStepAwareCellActiveChange = React.useCallback(
    (row: number, column: number) => {
      if (
        !readOnly &&
        currentStepId &&
        document?.source.format === 'algoviz-dsl' &&
        steps.length > 0
      ) {
        onCodeChange(
          updateVisualizationStepFocusInDsl(document.source.code, currentStepId, [
            `cell:${row}:${column}`,
          ])
        );

        return;
      }

      onCellActiveChange(row, column);
    },
    [
      currentStepId,
      document,
      onCellActiveChange,
      onCodeChange,
      readOnly,
      steps.length,
    ]
  );

  const handleStepAwareNodeStateChange = React.useCallback(
    (nodeId: string, state: VisualizationElementState) => {
      if (
        !readOnly &&
        currentStepId &&
        document?.source.format === 'algoviz-dsl' &&
        steps.length > 0
      ) {
        onCodeChange(
          appendVisualizationStepStateInDsl(
            document.source.code,
            currentStepId,
            `node:${nodeId}`,
            state
          )
        );

        return;
      }

      onNodeStateChange(nodeId, state);
    },
    [
      currentStepId,
      document,
      onCodeChange,
      onNodeStateChange,
      readOnly,
      steps.length,
    ]
  );

  if (!readOnly && isVisualizationTypePickerOpen(element)) {
    return (
      <VisualizationTypePicker
        element={element}
        onVisualTypeChange={onVisualTypeChange}
      />
    );
  }

  return (
    <div
      className="contents-editor-visualization-result-shell group my-4 min-w-0 rounded-lg border bg-background"
      data-visualization-display-mode={editorMode ? 'editor' : 'result'}
      data-visualization-preview-shell
    >
      <div className="flex min-h-12 flex-wrap items-center gap-2 border-b px-4 py-3">
        <strong className="text-sm">{document?.title || definition.label}</strong>
        {editorMode && (
          <span className="text-xs text-muted-foreground">{definition.label}</span>
        )}

        {canEdit && (
          <div className="ml-auto">
            <Select
              value={visualType}
              onValueChange={(value) =>
                onVisualTypeChange(value as VisualizationType)
              }
            >
              <SelectTrigger
                className="h-8 w-[180px] bg-background text-xs shadow-none"
                title="시각화 유형">
                <SelectValue />
              </SelectTrigger>
              <SelectContent className="z-[2147483000] max-h-[420px]">
                {VISUALIZATION_TYPE_GROUPS.map((group, groupIndex) => (
                  <React.Fragment key={group.category}>
                    {groupIndex > 0 && <SelectSeparator />}
                    <SelectGroup>
                      <SelectLabel>
                        {VISUALIZATION_CATEGORY_LABELS[group.category]}
                      </SelectLabel>
                      {group.types.map((type) => {
                        const typeDefinition =
                          visualizationDefinitionsByType.get(type);

                        if (!typeDefinition) {
                          return null;
                        }

                        return (
                          <SelectItem key={type} value={type}>
                            {typeDefinition.label}
                          </SelectItem>
                        );
                      })}
                    </SelectGroup>
                  </React.Fragment>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {canEdit && (
          <div>
            <Select value={viewMode} onValueChange={onViewModeChange}>
              <SelectTrigger
                className="h-8 w-[132px] bg-background text-xs shadow-none"
                title="보기 방식">
                <SelectValue />
              </SelectTrigger>
              <SelectContent className="z-[2147483000]">
                {VIEW_MODE_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {VIEW_MODE_LABELS[option.value]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      <VisualizationStepPlayer
        canEdit={canEdit && document?.source.format === 'algoviz-dsl'}
        currentStepId={currentStepId}
        onStepAdd={handleStepAdd}
        onStepDelete={handleStepDelete}
        onStepDuplicate={handleStepDuplicate}
        onStepSelect={(stepId) => {
          setPlayingSteps(false);
          setCurrentStepId(stepId);
        }}
        playing={playingSteps}
        setPlaying={setPlayingSteps}
        steps={steps}
      />

      <div
        className={
          isSplit
            ? 'grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_360px]'
            : 'grid gap-4 p-4'
        }
      >
        {showRender && (
          <VisualizationRenderPanel
            currentStepId={currentStepId}
            element={element}
            readOnly={!canEdit}
            onCellColumnCreate={onCellColumnCreate}
            onCellColumnDelete={onCellColumnDelete}
            onCellRowCreate={onCellRowCreate}
            onCellRowDelete={onCellRowDelete}
            onCommandChange={onCommandChange}
            onCellValueChange={onCellValueChange}
            onEdgeAnchorChange={onEdgeAnchorChange}
            onEdgeCreate={onEdgeCreate}
            onEdgeDelete={onEdgeDelete}
            onEdgeDirectionChange={onEdgeDirectionChange}
            onEdgeEndpointsChange={onEdgeEndpointsChange}
            onEdgeLabelChange={onEdgeLabelChange}
            onEdgeLineStyleChange={onEdgeLineStyleChange}
            onEdgeRouteChange={onEdgeRouteChange}
            onEdgeStateChange={onEdgeStateChange}
            onNodeCreate={onNodeCreate}
            onNodeDelete={onNodeDelete}
          onNodeLabelChange={onNodeLabelChange}
          onNodeMove={onNodeMove}
          onNodeShapeChange={onNodeShapeChange}
          onNodeStateChange={handleStepAwareNodeStateChange}
            onItemCreate={onItemCreate}
            onItemDelete={onItemDelete}
            onItemActiveChange={handleStepAwareItemActiveChange}
            onItemValueChange={onItemValueChange}
            onCellActiveChange={handleStepAwareCellActiveChange}
          />
        )}
        {showSource && (
          <VisualizationSourcePanel
            code={code}
            readOnly={readOnly}
            onCodeChange={onCodeChange}
          />
        )}
      </div>
    </div>
  );
}

export function VisualizationElement(
  props: PlateElementProps<TVisualizationElement>
) {
  const { children } = props;
  const editor = useEditorRef();
  const readOnly = useReadOnly();
  const selected = useSelected();
  const element = useElement<TVisualizationElement>();
  const [editorOpen, setEditorOpen] = React.useState(false);
  const viewMode = getVisualizationViewMode(element);

  const updateElementData = React.useCallback(
    (data: TVisualizationElement['data']) => {
      if (readOnly) return;

      const path = editor.api.findPath(element);

      if (path) {
        editor.tf.setNodes({ data }, { at: path });
      }
    },
    [editor, element, readOnly]
  );

  const handleCodeChange = React.useCallback(
    (code: string) => {
      if (!element.data) return;

      updateElementData({
        ...element.data,
        source: {
          ...element.data.source,
          code,
        },
      });
    },
    [element.data, updateElementData]
  );

  const handleViewModeChange = React.useCallback(
    (nextViewMode: VisualizationViewMode) => {
      if (!element.data) return;

      updateElementData({
        ...element.data,
        metadata: {
          ...element.data.metadata,
          viewMode: nextViewMode,
        },
      });
    },
    [element.data, updateElementData]
  );

  const handleVisualTypeChange = React.useCallback(
    (
      nextVisualType: VisualizationType,
      startMode: VisualizationStartMode = 'sample'
    ) => {
      if (!element.data) {
        return;
      }

      const needsTypeSelection =
        element.data.metadata?.needsTypeSelection === true;

      if (!needsTypeSelection && element.data.visualType === nextVisualType) {
        return;
      }

      updateElementData(
        createVisualizationDocumentForType(
          element.data,
          nextVisualType,
          startMode
        )
      );
      setEditorOpen(true);
    },
    [element.data, updateElementData]
  );

  const handleNodeCreate = React.useCallback(
    (node: {
      label: string;
      shape: VisualizationShapeKind;
      x: number;
      y: number;
    }) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        appendVisualizationNodeInDsl(element.data.source.code, node)
      );
    },
    [element.data, handleCodeChange]
  );

  const handleNodeDelete = React.useCallback(
    (nodeId: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        removeVisualizationNodeInDsl(element.data.source.code, nodeId)
      );
    },
    [element.data, handleCodeChange]
  );

  const handleNodeMove = React.useCallback(
    (nodeId: string, x: number, y: number) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationNodePositionInDsl(
          element.data.source.code,
          nodeId,
          x,
          y
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleNodeLabelChange = React.useCallback(
    (nodeId: string, label: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationNodeLabelInDsl(
          element.data.source.code,
          nodeId,
          label
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleNodeShapeChange = React.useCallback(
    (nodeId: string, shape: VisualizationShapeKind) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationNodeShapeInDsl(
          element.data.source.code,
          nodeId,
          shape
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleNodeStateChange = React.useCallback(
    (nodeId: string, state: VisualizationElementState) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationNodeStateInDsl(
          element.data.source.code,
          nodeId,
          state
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeCreate = React.useCallback(
    (fromNodeId: string, toNodeId: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }
      const defaultDirection =
        element.data.visualType === 'tree'
          ? 'none'
          : isVisualizationEdgeDirected(element.data.visualType)
            ? 'forward'
            : 'none';

      handleCodeChange(
        appendVisualizationEdgeInDsl(
          element.data.source.code,
          fromNodeId,
          toNodeId,
          {
            allowDuplicate: element.data.visualType === 'sequence-diagram',
            direction: defaultDirection,
            label:
              element.data.visualType === 'sequence-diagram' ? 'message' : undefined,
          }
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeDirectionChange = React.useCallback(
    (edge: VisualizationEdge, direction: VisualizationDirection) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeDirectionInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          direction,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeAnchorChange = React.useCallback(
    (
      edge: VisualizationEdge,
      fromAnchor: VisualizationAnchorSide,
      toAnchor: VisualizationAnchorSide
    ) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeAnchorsInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          fromAnchor,
          toAnchor
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeRouteChange = React.useCallback(
    (
      edge: VisualizationEdge,
      routeSide: VisualizationEdgeRouteSide,
      routeOffset: number
    ) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeRouteInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          routeSide,
          routeOffset,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeEndpointsChange = React.useCallback(
    (edge: VisualizationEdge, nextFromNodeId: string, nextToNodeId: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeEndpointsInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          nextFromNodeId,
          nextToNodeId,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeLabelChange = React.useCallback(
    (edge: VisualizationEdge, label: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeLabelInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          label,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeLineStyleChange = React.useCallback(
    (edge: VisualizationEdge, lineStyle: 'solid' | 'dashed') => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeLineStyleInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          lineStyle,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeStateChange = React.useCallback(
    (edge: VisualizationEdge, state: VisualizationElementState) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationEdgeStateInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          state,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleEdgeDelete = React.useCallback(
    (edge: VisualizationEdge) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        removeVisualizationEdgeInDsl(
          element.data.source.code,
          edge.from,
          edge.to,
          edge.id
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleItemValueChange = React.useCallback(
    (itemIndex: number, value: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationDataItemInDsl(
          element.data.source.code,
          itemIndex,
          value
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleItemActiveChange = React.useCallback(
    (itemIndexes: number[]) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationActiveItemsInDsl(
          element.data.source.code,
          itemIndexes
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleItemCreate = React.useCallback((itemIndex?: number, value = '0') => {
    if (!element.data || element.data.source.format !== 'algoviz-dsl') {
      return;
    }

    handleCodeChange(
      typeof itemIndex === 'number'
        ? insertVisualizationDataItemInDsl(element.data.source.code, itemIndex, value)
        : appendVisualizationDataItemInDsl(element.data.source.code, value)
    );
  }, [element.data, handleCodeChange]);

  const handleCommandChange = React.useCallback(
    (command: string, value: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationCommandInDsl(
          element.data.source.code,
          command,
          value
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleItemDelete = React.useCallback(
    (itemIndex: number) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        removeVisualizationDataItemInDsl(element.data.source.code, itemIndex)
      );
    },
    [element.data, handleCodeChange]
  );

  const handleCellValueChange = React.useCallback(
    (row: number, column: number, value: string) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationCellValueInDsl(
          element.data.source.code,
          row,
          column,
          value
        )
      );
    },
    [element.data, handleCodeChange]
  );

  const handleCellActiveChange = React.useCallback(
    (row: number, column: number) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        updateVisualizationActiveCellInDsl(element.data.source.code, row, column)
      );
    },
    [element.data, handleCodeChange]
  );

  const handleCellRowCreate = React.useCallback(() => {
    if (!element.data || element.data.source.format !== 'algoviz-dsl') {
      return;
    }

    handleCodeChange(appendVisualizationTableRowInDsl(element.data.source.code));
  }, [element.data, handleCodeChange]);

  const handleCellColumnCreate = React.useCallback(() => {
    if (!element.data || element.data.source.format !== 'algoviz-dsl') {
      return;
    }

    handleCodeChange(
      appendVisualizationTableColumnInDsl(element.data.source.code)
    );
  }, [element.data, handleCodeChange]);

  const handleCellRowDelete = React.useCallback(
    (row: number) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        removeVisualizationTableRowInDsl(element.data.source.code, row)
      );
    },
    [element.data, handleCodeChange]
  );

  const handleCellColumnDelete = React.useCallback(
    (column: number) => {
      if (!element.data || element.data.source.format !== 'algoviz-dsl') {
        return;
      }

      handleCodeChange(
        removeVisualizationTableColumnInDsl(element.data.source.code, column)
      );
    },
    [element.data, handleCodeChange]
  );

  const removeNode = React.useCallback(() => {
    if (readOnly) return;

    const path = editor.api.findPath(element);

    if (path) {
      editor.tf.removeNodes({ at: path });
    }
  }, [editor, element, readOnly]);

  const renderPreviewShell = (editorMode: boolean) => (
    <VisualizationPreviewShell
      editorMode={editorMode}
      element={element}
      readOnly={readOnly}
      viewMode={viewMode}
      onVisualTypeChange={handleVisualTypeChange}
      onCellColumnCreate={handleCellColumnCreate}
      onCellColumnDelete={handleCellColumnDelete}
      onCellRowCreate={handleCellRowCreate}
      onCellRowDelete={handleCellRowDelete}
      onCommandChange={handleCommandChange}
      onCellActiveChange={handleCellActiveChange}
      onCellValueChange={handleCellValueChange}
      onEdgeAnchorChange={handleEdgeAnchorChange}
      onEdgeCreate={handleEdgeCreate}
      onEdgeDelete={handleEdgeDelete}
      onEdgeDirectionChange={handleEdgeDirectionChange}
      onEdgeEndpointsChange={handleEdgeEndpointsChange}
      onEdgeLabelChange={handleEdgeLabelChange}
      onEdgeLineStyleChange={handleEdgeLineStyleChange}
      onEdgeRouteChange={handleEdgeRouteChange}
      onEdgeStateChange={handleEdgeStateChange}
      onCodeChange={handleCodeChange}
      onNodeCreate={handleNodeCreate}
      onNodeDelete={handleNodeDelete}
      onNodeLabelChange={handleNodeLabelChange}
      onNodeMove={handleNodeMove}
      onNodeShapeChange={handleNodeShapeChange}
      onNodeStateChange={handleNodeStateChange}
      onItemCreate={handleItemCreate}
      onItemDelete={handleItemDelete}
      onItemActiveChange={handleItemActiveChange}
      onItemValueChange={handleItemValueChange}
      onViewModeChange={handleViewModeChange}
    />
  );
  const typePickerOpen = isVisualizationTypePickerOpen(element);

  return (
    <PlateElement {...props}>
      <div
        className="group/visualization-block relative"
        contentEditable={false}
        data-visualization-result-block
        onDoubleClickCapture={(event) => {
          if (readOnly || typePickerOpen) return;

          event.preventDefault();
          event.stopPropagation();
          setEditorOpen(true);
        }}
      >
        {renderPreviewShell(false)}
        {!readOnly && !typePickerOpen && selected && (
          <div
            className="absolute top-3 right-3 z-20 flex items-center gap-1 rounded-md border bg-background p-1 shadow-sm"
            data-visualization-block-menu
          >
            <Button
              aria-label="시각화 편집"
              className="size-8"
              size="icon"
              title="시각화 편집"
              type="button"
              variant="ghost"
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setEditorOpen(true);
              }}
            >
              <Pencil className="size-4" />
            </Button>
            <Button
              aria-label="시각화 삭제"
              className="size-8 text-red-600 hover:bg-red-50 hover:text-red-700"
              size="icon"
              title="시각화 삭제"
              type="button"
              variant="ghost"
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                removeNode();
              }}
            >
              <Trash2 className="size-4" />
            </Button>
          </div>
        )}
      </div>
      {children}

      {!readOnly && (
        <Dialog open={editorOpen} onOpenChange={setEditorOpen}>
          <DialogContent
            className="flex h-[min(94vh,980px)] w-[min(96vw,1480px)] max-w-none flex-col gap-0 overflow-hidden p-0 sm:max-w-none"
            contentEditable={false}
            data-visualization-editor-dialog
          >
            <DialogHeader className="shrink-0 border-b px-6 py-4 pr-14">
              <DialogTitle>시각화 편집</DialogTitle>
              <DialogDescription>
                보이는 도형과 값을 직접 선택해 수정하세요. 변경 내용은 문서에 바로 반영됩니다.
              </DialogDescription>
            </DialogHeader>
            <div className="min-h-0 flex-1 overflow-auto bg-muted/20 px-4 py-2 sm:px-6">
              {renderPreviewShell(true)}
            </div>
          </DialogContent>
        </Dialog>
      )}
    </PlateElement>
  );
}

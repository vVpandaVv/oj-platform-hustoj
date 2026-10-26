import * as React from 'react';

import {
  getVisualizationFloatingActionKinds as getContractFloatingActionKinds,
  getVisualizationFloatingMenuLabels,
} from './visualization-editing-contract';
import type { VisualizationDocument } from './visualization-model';
import type { VisualizationType } from './visualization-types';

type VisualizationFloatingMenuProps = {
  document: VisualizationDocument;
  onCellColumnCreate: () => void;
  onCellColumnDelete: (column: number) => void;
  onCellRowCreate: () => void;
  onCellRowDelete: (row: number) => void;
  onCommandChange?: (command: string, value: string) => void;
  onItemCreate: (itemIndex?: number, value?: string) => void;
  onItemDelete: (itemIndex: number) => void;
  readOnly: boolean;
};

function FloatingButton({
  children,
  disabled,
  onClick,
  action,
}: {
  action: string;
  children: React.ReactNode;
  disabled?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      className="pointer-events-auto h-7 rounded-md border bg-background px-2 text-xs font-medium shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45"
      data-visual-floating-action={action}
      disabled={disabled}
      type="button"
      onClick={onClick}
    >
      {children}
    </button>
  );
}

export function getVisualizationFloatingActionKinds(visualType: VisualizationType) {
  return getContractFloatingActionKinds(visualType);
}

export function VisualizationFloatingMenu({
  document,
  onCellColumnCreate,
  onCellColumnDelete,
  onCellRowCreate,
  onCellRowDelete,
  onCommandChange,
  onItemCreate,
  onItemDelete,
  readOnly,
}: VisualizationFloatingMenuProps) {
  const actions = getVisualizationFloatingActionKinds(document.visualType);
  const hasItemEdit = actions.includes('item-add');
  const hasCellEdit = actions.includes('row-add');
  const hasNodeEdit = actions.includes('node-hint');
  const itemCount = document.model.items?.length ?? 0;
  const cells = document.model.cells ?? [];
  const lastRow =
    cells.length > 0 ? Math.max(...cells.map((cell) => cell.row)) : -1;
  const lastColumn =
    cells.length > 0 ? Math.max(...cells.map((cell) => cell.column)) : -1;
  const labels = getVisualizationFloatingMenuLabels(document.visualType);
  const sortMode = String(document.model.typeData?.sortMode ?? 'compare');
  const graphDirected = document.model.typeData?.directed !== false;
  const graphWeighted = document.model.typeData?.weighted !== false;
  const scatterTrendLine = document.model.typeData?.trendLine !== false;
  const geometryHull = document.model.typeData?.hull !== false;
  const vectorProjection = document.model.typeData?.projection !== false;
  const treeType = String(document.model.typeData?.treeType ?? 'binary');
  const hashBucketCount = Math.max(
    1,
    Number(document.model.typeData?.bucketCount ?? 4) || 4
  );

  if (readOnly || actions.length === 0) {
    return null;
  }

  return (
    <details
      className="pointer-events-auto absolute right-3 bottom-3 z-20"
      data-visual-floating-menu={document.visualType}
      data-visual-floating-placement="bottom-right"
    >
      <summary
        className="cursor-pointer list-none rounded-md border bg-background px-3 py-2 text-xs font-semibold shadow-sm hover:bg-muted"
        data-visual-floating-menu-trigger
      >
        요소 편집
      </summary>
      <div className="absolute right-0 bottom-11 flex max-h-56 w-max max-w-[min(560px,calc(100vw-3rem))] flex-wrap items-center justify-end gap-1 overflow-auto rounded-lg border bg-background p-2 text-xs shadow-lg">
      {document.visualType === 'vector-space' && onCommandChange ? (
        <>
          <FloatingButton action="vector-add" onClick={() => onCommandChange('vector-add', '')}>
            벡터 추가
          </FloatingButton>
          <FloatingButton action="vector-point-add" onClick={() => onCommandChange('vector-point-add', '')}>
            점 추가
          </FloatingButton>
          <FloatingButton action="vector-boundary-add" onClick={() => onCommandChange('vector-boundary-add', '')}>
            경계 추가
          </FloatingButton>
          <FloatingButton
            action="vector-projection-toggle"
            onClick={() => onCommandChange('projection', vectorProjection ? 'hide' : 'show')}
          >
            {vectorProjection ? '투영 숨기기' : '투영 보이기'}
          </FloatingButton>
        </>
      ) : null}
      {document.visualType === 'hash-table' && onCommandChange ? (
        <>
          <FloatingButton
            action="hash-bucket-add"
            onClick={() => onCommandChange('bucket-count', String(hashBucketCount + 1))}
          >
            버킷 추가
          </FloatingButton>
          <FloatingButton
            action="hash-bucket-delete"
            disabled={hashBucketCount <= 1}
            onClick={() => onCommandChange('hash-remove-bucket', String(hashBucketCount - 1))}
          >
            마지막 버킷 삭제
          </FloatingButton>
          <FloatingButton
            action="hash-key-add"
            onClick={() => onItemCreate(undefined, '0@0')}
          >
            키 추가
          </FloatingButton>
          <FloatingButton
            action="hash-key-delete"
            disabled={itemCount === 0}
            onClick={() => onItemDelete(itemCount - 1)}
          >
            마지막 키 삭제
          </FloatingButton>
        </>
      ) : hasItemEdit ? (
        <>
          <FloatingButton
            action="item-add"
            onClick={() =>
              document.visualType === 'hash-table'
                ? onItemCreate(undefined, '0@0')
                : onItemCreate()
            }
          >
            {labels.addItem}
          </FloatingButton>
          <FloatingButton
            action="item-delete"
            disabled={itemCount === 0}
            onClick={() => onItemDelete(itemCount - 1)}
          >
            {labels.removeItem}
          </FloatingButton>
        </>
      ) : null}
      {document.visualType === 'sorting' && onCommandChange && (
        <>
          {[
            ['compare', '비교'],
            ['swap', '교환'],
            ['both', '비교 + 교환'],
          ].map(([mode, label]) => (
            <FloatingButton
              action={`sort-mode-${mode}`}
              disabled={sortMode === mode}
              key={mode}
              onClick={() => onCommandChange('sort-mode', mode)}
            >
              {label}
            </FloatingButton>
          ))}
        </>
      )}
      {document.visualType === 'network' && onCommandChange && (
        <>
          <FloatingButton
            action="graph-directed-toggle"
            onClick={() =>
              onCommandChange(
                'graph',
                `${graphDirected ? 'undirected' : 'directed'} ${
                  graphWeighted ? 'weighted' : 'unweighted'
                }`
              )
            }
          >
            {graphDirected ? '무방향으로 변경' : '방향 그래프로 변경'}
          </FloatingButton>
          <FloatingButton
            action="graph-weighted-toggle"
            onClick={() =>
              onCommandChange(
                'graph',
                `${graphDirected ? 'directed' : 'undirected'} ${
                  graphWeighted ? 'unweighted' : 'weighted'
                }`
              )
            }
          >
            {graphWeighted ? '가중치 숨기기' : '가중치 보이기'}
          </FloatingButton>
        </>
      )}
      {document.visualType === 'scatter' && onCommandChange && (
        <FloatingButton
          action="scatter-trend-line-toggle"
          onClick={() => onCommandChange('trend-line', scatterTrendLine ? 'hide' : 'show')}
        >
          {scatterTrendLine ? '추세선 숨기기' : '추세선 보이기'}
        </FloatingButton>
      )}
      {document.visualType === 'geometry-plane' && onCommandChange && (
        <FloatingButton
          action="geometry-hull-toggle"
          onClick={() => onCommandChange('hull', geometryHull ? 'hide' : 'show')}
        >
          {geometryHull ? 'Convex hull 숨기기' : 'Convex hull 보이기'}
        </FloatingButton>
      )}
      {document.visualType === 'tree' && onCommandChange && (
        <>
          {[
            ['binary', 'Binary'],
            ['b-tree', 'B-Tree'],
            ['b-plus-tree', 'B+Tree'],
            ['avl', 'AVL'],
            ['red-black', 'Red-Black'],
          ].map(([type, label]) => (
            <FloatingButton
              action={`tree-type-${type}`}
              disabled={treeType === type}
              key={type}
              onClick={() => onCommandChange('tree-type', type)}
            >
              {label}
            </FloatingButton>
          ))}
        </>
      )}
      {hasCellEdit && (
        <>
          <FloatingButton action="row-add" onClick={onCellRowCreate}>
            {labels.addRow}
          </FloatingButton>
          <FloatingButton action="column-add" onClick={onCellColumnCreate}>
            {labels.addColumn}
          </FloatingButton>
          <FloatingButton
            action="row-delete"
            disabled={lastRow < 0}
            onClick={() => onCellRowDelete(lastRow)}
          >
            {labels.removeRow}
          </FloatingButton>
          <FloatingButton
            action="column-delete"
            disabled={lastColumn < 0}
            onClick={() => onCellColumnDelete(lastColumn)}
          >
            {labels.removeColumn}
          </FloatingButton>
        </>
      )}
      {hasNodeEdit && (
        <span
          className="px-2 text-muted-foreground"
          data-visual-floating-action="node-hint"
        >
          {labels.nodeHint}
        </span>
      )}
      </div>
    </details>
  );
}

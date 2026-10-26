import * as React from 'react';
import { createRoot } from 'react-dom/client';

import { createVisualizationDocumentForType } from './visualization-block';
import {
  ACTIVE_VISUALIZATION_TYPES,
  getVisualizationEditingContract,
  getVisualizationFloatingActionKinds,
} from './visualization-editing-contract';
import { VisualizationFloatingMenu } from './visualization-floating-menu';
import { createEmptyVisualizationDocument } from './visualization-model';
import { VisualizationRenderer } from './visualization-renderer';
import type {
  VisualizationAnchorSide,
  VisualizationDirection,
  VisualizationEdge,
  VisualizationEdgeRouteSide,
  VisualizationElementState,
  VisualizationShapeKind,
} from './visualization-model';
import {
  VISUALIZATION_TYPE_DEFINITIONS,
  type VisualizationType,
} from './visualization-types';

import '../styles.css';

const EDITING_QA_TYPES: VisualizationType[] = VISUALIZATION_TYPE_DEFINITIONS
  .filter((definition) => definition.type !== 'stack-queue')
  .map((definition) => definition.type);

declare global {
  interface Window {
    __visualizationEditingContracts?: unknown;
  }
}

window.__visualizationEditingContracts = {
  contracts: Object.fromEntries(
    ACTIVE_VISUALIZATION_TYPES.map((type) => [
      type,
      getVisualizationEditingContract(type),
    ])
  ),
  floatingActions: Object.fromEntries(
    ACTIVE_VISUALIZATION_TYPES.map((type) => [
      type,
      getVisualizationFloatingActionKinds(type),
    ])
  ),
  types: ACTIVE_VISUALIZATION_TYPES,
};

function createQaDocument(type: VisualizationType) {
  return createVisualizationDocumentForType(
    createEmptyVisualizationDocument(type),
    type,
    'sample'
  );
}

function EditableVisualizationCard({ type }: { type: VisualizationType }) {
  const [document] = React.useState(() => createQaDocument(type));
  const [lastItemChange, setLastItemChange] = React.useState('');
  const [lastItemCreate, setLastItemCreate] = React.useState('');
  const [lastItemDelete, setLastItemDelete] = React.useState('');
  const [lastCellChange, setLastCellChange] = React.useState('');
  const [lastActiveItems, setLastActiveItems] = React.useState('');
  const [lastActiveCell, setLastActiveCell] = React.useState('');
  const [lastEdgeLabelChange, setLastEdgeLabelChange] = React.useState('');
  const [lastEdgeCreate, setLastEdgeCreate] = React.useState('');
  const [lastEdgeDelete, setLastEdgeDelete] = React.useState('');
  const [lastEdgeDirectionChange, setLastEdgeDirectionChange] = React.useState('');
  const [lastEdgeEndpointsChange, setLastEdgeEndpointsChange] = React.useState('');
  const [lastEdgeRouteChange, setLastEdgeRouteChange] = React.useState('');
  const [lastEdgeStateChange, setLastEdgeStateChange] = React.useState('');
  const [lastEdgeStyleChange, setLastEdgeStyleChange] = React.useState('');
  const [lastNodeLabelChange, setLastNodeLabelChange] = React.useState('');
  const [lastNodeMove, setLastNodeMove] = React.useState('');
  const [lastNodeCreate, setLastNodeCreate] = React.useState('');
  const [lastNodeDelete, setLastNodeDelete] = React.useState('');
  const [lastNodeStateChange, setLastNodeStateChange] = React.useState('');
  const noopNodeCreate = React.useCallback(
    (_node: { label: string; shape: VisualizationShapeKind; x: number; y: number }) => {
      setLastNodeCreate(`${_node.label}:${_node.shape}:${_node.x}:${_node.y}`);
    },
    []
  );
  const noopNodeDelete = React.useCallback((_nodeId: string) => {
    setLastNodeDelete(_nodeId);
  }, []);
  const noopNodeChange = React.useCallback((_nodeId: string, _value: string) => {
    setLastNodeLabelChange(`${_nodeId}:${_value}`);
  }, []);
  const noopNodeMove = React.useCallback(
    (_nodeId: string, _x: number, _y: number) => {
      setLastNodeMove(`${_nodeId}:${_x}:${_y}`);
    },
    []
  );
  const noopNodeShape = React.useCallback(
    (_nodeId: string, _shape: VisualizationShapeKind) => {},
    []
  );
  const noopNodeState = React.useCallback(
    (_nodeId: string, _state: VisualizationElementState) => {
      setLastNodeStateChange(`${_nodeId}:${_state}`);
    },
    []
  );
  const noopEdgeChange = React.useCallback((_edge: VisualizationEdge, _label: string) => {
    setLastEdgeLabelChange(`${_edge.from}->${_edge.to}:${_label}`);
  }, []);
  const noopEdgeCreate = React.useCallback((_fromNodeId: string, _toNodeId: string) => {
    setLastEdgeCreate(`${_fromNodeId}->${_toNodeId}`);
  }, []);
  const noopEdgeDelete = React.useCallback((_edge: VisualizationEdge) => {
    setLastEdgeDelete(`${_edge.from}->${_edge.to}`);
  }, []);
  const noopEdgeDirection = React.useCallback(
    (_edge: VisualizationEdge, _direction: VisualizationDirection) => {
      setLastEdgeDirectionChange(`${_edge.from}->${_edge.to}:${_direction}`);
    },
    []
  );
  const noopEdgeState = React.useCallback(
    (_edge: VisualizationEdge, _state: VisualizationElementState) => {
      setLastEdgeStateChange(`${_edge.from}->${_edge.to}:${_state}`);
    },
    []
  );
  const noopEdgeEndpoints = React.useCallback(
    (
      _edge: VisualizationEdge,
      _nextFromNodeId: string,
      _nextToNodeId: string
    ) => {
      setLastEdgeEndpointsChange(
        `${_edge.from}->${_edge.to}:${_nextFromNodeId}->${_nextToNodeId}`
      );
    },
    []
  );
  const noopEdgeAnchor = React.useCallback(
    (
      _edge: VisualizationEdge,
      _fromAnchor: VisualizationAnchorSide,
      _toAnchor: VisualizationAnchorSide
    ) => {},
    []
  );
  const noopEdgeRoute = React.useCallback(
    (
      _edge: VisualizationEdge,
      _routeSide: VisualizationEdgeRouteSide,
      _routeOffset: number
    ) => {
      setLastEdgeRouteChange(
        `${_edge.from}->${_edge.to}:${_routeSide}:${_routeOffset}`
      );
    },
    []
  );
  const noopEdgeStyle = React.useCallback(
    (_edge: VisualizationEdge, _lineStyle: 'solid' | 'dashed') => {
      setLastEdgeStyleChange(`${_edge.from}->${_edge.to}:${_lineStyle}`);
    },
    []
  );
  const noopCellValue = React.useCallback(
    (row: number, column: number, value: string) => {
      setLastCellChange(`${row}:${column}:${value}`);
    },
    []
  );
  const noopItemValue = React.useCallback((index: number, value: string) => {
    setLastItemChange(`${index}:${value}`);
  }, []);
  const noopItemActive = React.useCallback((indexes: number[]) => {
    setLastActiveItems(indexes.join(','));
  }, []);
  const noopItemCreate = React.useCallback((index?: number, value?: string) => {
    setLastItemCreate(
      index === undefined
        ? `created:${value ?? ''}`
        : `created:${index}:${value ?? ''}`
    );
  }, []);
  const noopItemDelete = React.useCallback((index: number) => {
    setLastItemDelete(String(index));
  }, []);
  const noopCellActive = React.useCallback((row: number, column: number) => {
    setLastActiveCell(`${row}:${column}`);
  }, []);
  const noopCellRowCreate = React.useCallback(() => {
    setLastCellChange('row:created');
  }, []);
  const noopCellColumnCreate = React.useCallback(() => {
    setLastCellChange('column:created');
  }, []);
  const noopCellRowDelete = React.useCallback((row: number) => {
    setLastCellChange(`row:deleted:${row}`);
  }, []);
  const noopCellColumnDelete = React.useCallback((column: number) => {
    setLastCellChange(`column:deleted:${column}`);
  }, []);
  const noopCommandChange = React.useCallback((command: string, value: string) => {
    setLastCellChange(`command:${command}:${value}`);
  }, []);
  return (
    <article
      className="rounded-xl border bg-white p-4"
      data-editing-qa-card
      data-editing-qa-type={type}
      data-last-active-cell={lastActiveCell}
      data-last-active-items={lastActiveItems}
      data-last-cell-change={lastCellChange}
      data-last-edge-create={lastEdgeCreate}
      data-last-edge-delete={lastEdgeDelete}
      data-last-edge-direction-change={lastEdgeDirectionChange}
      data-last-edge-endpoints-change={lastEdgeEndpointsChange}
      data-last-edge-label-change={lastEdgeLabelChange}
      data-last-edge-route-change={lastEdgeRouteChange}
      data-last-edge-state-change={lastEdgeStateChange}
      data-last-edge-style-change={lastEdgeStyleChange}
      data-last-item-change={lastItemChange}
      data-last-item-create={lastItemCreate}
      data-last-item-delete={lastItemDelete}
      data-last-node-create={lastNodeCreate}
      data-last-node-delete={lastNodeDelete}
      data-last-node-label-change={lastNodeLabelChange}
      data-last-node-move={lastNodeMove}
      data-last-node-state-change={lastNodeStateChange}
    >
      <h2 className="mb-3 text-base font-bold">{type}</h2>
      <div
        className="group/visual-panel relative min-w-0 max-w-[460px] rounded-xl border p-3"
        data-editing-qa-render-wrapper
        data-visualization-render-panel
      >
        <VisualizationFloatingMenu
          document={document}
          readOnly={false}
          onCellColumnCreate={noopCellColumnCreate}
          onCellColumnDelete={noopCellColumnDelete}
          onCellRowCreate={noopCellRowCreate}
          onCellRowDelete={noopCellRowDelete}
          onCommandChange={noopCommandChange}
          onItemCreate={noopItemCreate}
          onItemDelete={noopItemDelete}
        />
        <VisualizationRenderer
          document={document}
          readOnly={false}
          onEdgeAnchorChange={noopEdgeAnchor}
          onEdgeCreate={noopEdgeCreate}
          onEdgeDelete={noopEdgeDelete}
          onEdgeDirectionChange={noopEdgeDirection}
          onEdgeEndpointsChange={noopEdgeEndpoints}
          onEdgeLabelChange={noopEdgeChange}
          onEdgeLineStyleChange={noopEdgeStyle}
          onEdgeRouteChange={noopEdgeRoute}
          onEdgeStateChange={noopEdgeState}
          onCellActiveChange={noopCellActive}
          onCellValueChange={noopCellValue}
          onCommandChange={noopCommandChange}
          onNodeCreate={noopNodeCreate}
          onNodeDelete={noopNodeDelete}
          onNodeLabelChange={noopNodeChange}
          onNodeMove={noopNodeMove}
          onNodeShapeChange={noopNodeShape}
          onNodeStateChange={noopNodeState}
          onItemActiveChange={noopItemActive}
          onItemCreate={noopItemCreate}
          onItemDelete={noopItemDelete}
          onItemValueChange={noopItemValue}
        />
      </div>
    </article>
  );
}

function VisualizationEditingQaApp() {
  return (
    <main className="min-h-screen bg-slate-50 p-6 text-slate-950">
      <h1 className="mb-2 text-2xl font-bold">Visualization Editing QA</h1>
      <p className="mb-6 text-sm text-slate-600">
        시각화 유형별 컨텍스트 메뉴, 플로팅 메뉴, 직접 편집 동작을 브라우저 테스트로 확인합니다.
      </p>
      <section className="grid gap-5 lg:grid-cols-2">
        {EDITING_QA_TYPES.map((type) => (
          <EditableVisualizationCard key={type} type={type} />
        ))}
      </section>
    </main>
  );
}

const root = document.getElementById('visualization-editing-qa-root');

if (root) {
  const host = root as HTMLElement & {
    __visualizationEditingQaRoot?: ReturnType<typeof createRoot>;
  };
  const reactRoot =
    host.__visualizationEditingQaRoot ?? createRoot(host);

  host.__visualizationEditingQaRoot = reactRoot;
  reactRoot.render(
    <React.StrictMode>
      <VisualizationEditingQaApp />
    </React.StrictMode>
  );
}

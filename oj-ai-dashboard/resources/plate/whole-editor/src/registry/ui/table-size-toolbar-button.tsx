'use client';

import * as React from 'react';
import { getTableColumnCount } from '@platejs/table';
import { Columns3Icon } from 'lucide-react';
import { KEYS, type TTableElement } from 'platejs';
import { useEditorRef, useEditorSelector } from 'platejs/react';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import { ToolbarButton } from './toolbar';

const MIN_WIDTH = 40;
const MAX_WIDTH = 1600;

function resizeColumns(widths: number[], total: number, min = MIN_WIDTH, max = MAX_WIDTH) {
  if (!widths.length) return [];
  const sum = widths.reduce((a, b) => a + b, 0);
  const next = widths.map(width => Math.max(min, Math.min(max, Math.round(width / sum * total))));
  let remainder = total - next.reduce((a, b) => a + b, 0);
  // Distribute rounding and clamping remainder without changing the requested total.
  while (remainder !== 0) {
    for (let index = 0; index < next.length && remainder !== 0; index++) {
      const step = Math.sign(remainder);
      if (next[index] + step >= min && next[index] + step <= max) {
        next[index] += step;
        remainder -= step;
      }
    }
  }
  return next;
}

export function TableSizeToolbarButton() {
  const editor = useEditorRef();
  const selected = useEditorSelector(editor => editor.api.some({ match: { type: KEYS.table } }), []);
  const [open, setOpen] = React.useState(false);
  const [unit, setUnit] = React.useState<'px' | '%'>('px');
  const [widths, setWidths] = React.useState<number[]>([]);
  const [column, setColumn] = React.useState(0);
  const [tableDraft, setTableDraft] = React.useState('');
  const [columnDraft, setColumnDraft] = React.useState('');
  const pathRef = React.useRef<ReturnType<typeof editor.api.pathRef> | null>(null);
  const pixelTotalRef = React.useRef(600);
  const percentRef = React.useRef(100);
  React.useEffect(() => () => { pathRef.current?.unref(); }, []);

  const changeOpen = (nextOpen: boolean) => {
    if (nextOpen) {
      const entry = editor.api.above<TTableElement>({ match: { type: KEYS.table } });
      if (!entry) return;
      pathRef.current?.unref();
      pathRef.current = editor.api.pathRef(entry[1]);
      const relative = typeof entry[0].widthPercent === 'number';
      const initial = Array.from({ length: getTableColumnCount(entry[0]) }, (_, index) =>
        Math.max(relative ? 1 : MIN_WIDTH, Math.min(relative ? 10000 : MAX_WIDTH, Math.round(entry[0].colSizes?.[index] || 120))));
      if (!initial.length) return;
      const next = relative ? resizeColumns(initial, 10000, 1, 10000) : initial;
      const table = editor.api.toDOMNode(entry[0])?.querySelector('table');
      pixelTotalRef.current = relative
        ? Math.max(MIN_WIDTH * next.length, Math.min(MAX_WIDTH * next.length, Math.round((table?.getBoundingClientRect().width || 608) - 8)))
        : next.reduce((a, b) => a + b, 0);
      percentRef.current = relative ? Number(entry[0].widthPercent) : 100;
      const cell = editor.api.above({ match: node => node.type === KEYS.td || node.type === KEYS.th });
      const selectedColumn = Math.min(next.length - 1, cell?.[1].at(-1) ?? 0);
      setUnit(relative ? '%' : 'px'); setWidths(next); setColumn(selectedColumn);
      setTableDraft(String(relative ? percentRef.current : pixelTotalRef.current));
      setColumnDraft(String(relative ? next[selectedColumn] / 100 : next[selectedColumn]));
    } else {
      pathRef.current?.unref(); pathRef.current = null;
    }
    setOpen(nextOpen);
  };

  return (
    <Popover open={open} onOpenChange={changeOpen}>
      <PopoverTrigger asChild>
        <ToolbarButton type="button" disabled={!selected} tooltip="표 너비 설정" aria-label="표 너비 설정">
          <Columns3Icon />
        </ToolbarButton>
      </PopoverTrigger>
      <PopoverContent align="start" className="hws-table-size-popover" onCloseAutoFocus={event => event.preventDefault()}>
        <form onSubmit={event => {
          event.preventDefault(); event.stopPropagation();
          const at = pathRef.current?.current;
          if (!at || !widths.length) return;
          editor.tf.setNodes({ colSizes: widths }, { at });
          if (unit === '%') editor.tf.setNodes({ widthPercent: Number(tableDraft) }, { at });
          else editor.tf.unsetNodes('widthPercent', { at });
          changeOpen(false); editor.tf.focus();
        }}>
          <label>너비 단위
            <select aria-label="너비 단위" value={unit} onChange={event => {
              const nextUnit = event.target.value as 'px' | '%';
              const next = nextUnit === '%' ? resizeColumns(widths, 10000, 1, 10000) : resizeColumns(widths, pixelTotalRef.current);
              setUnit(nextUnit); setWidths(next);
              setTableDraft(String(nextUnit === '%' ? percentRef.current : pixelTotalRef.current));
              setColumnDraft(String(nextUnit === '%' ? next[column] / 100 : next[column]));
            }}><option value="px">px</option><option value="%">%</option></select>
          </label>
          <label>표 너비 ({unit})
            <input type="number" required min={unit === '%' ? 1 : MIN_WIDTH * widths.length} max={unit === '%' ? 100 : MAX_WIDTH * widths.length} step="1" value={tableDraft}
              onChange={event => {
                const value = event.target.value; setTableDraft(value);
                const total = Number(value);
                if (unit === '%') {
                  if (value && Number.isInteger(total) && total >= 1 && total <= 100) percentRef.current = total;
                  return;
                }
                if (value && Number.isInteger(total) && total >= MIN_WIDTH * widths.length && total <= MAX_WIDTH * widths.length) {
                  pixelTotalRef.current = total;
                  const next = resizeColumns(widths, total); setWidths(next); setColumnDraft(String(next[column]));
                }
              }} />
          </label>
          <label>열
            <select aria-label="열" value={column} onChange={event => { const index = Number(event.target.value); setColumn(index); setColumnDraft(String(unit === '%' ? widths[index] / 100 : widths[index])); }}>
              {widths.map((_, index) => <option key={index} value={index}>{index + 1}열</option>)}
            </select>
          </label>
          <label>열 너비 ({unit})
            <input type="number" required disabled={unit === '%' && widths.length === 1} min={unit === '%' ? 0.01 : MIN_WIDTH} max={unit === '%' ? 100 - (widths.length - 1) / 100 : MAX_WIDTH} step={unit === '%' ? '0.01' : '1'} value={columnDraft}
              onChange={event => {
                const value = event.target.value; setColumnDraft(value);
                const width = Number(value);
                if (unit === '%') {
                  const ratio = Math.round(width * 100);
                  if (value && ratio >= 1 && ratio <= 10000 - widths.length + 1) {
                    const other = resizeColumns(widths.filter((_, index) => index !== column), 10000 - ratio, 1, 10000);
                    let offset = 0;
                    setWidths(widths.map((_, index) => index === column ? ratio : other[offset++]));
                  }
                  return;
                }
                if (value && Number.isInteger(width) && width >= MIN_WIDTH && width <= MAX_WIDTH) {
                  const next = widths.map((old, index) => index === column ? width : old);
                  pixelTotalRef.current = next.reduce((a, b) => a + b, 0);
                  setWidths(next); setTableDraft(String(pixelTotalRef.current));
                }
              }} />
          </label>
          <div className="hws-table-size-actions">
            <button type="button" onClick={() => {
              const next = unit === '%' ? resizeColumns(widths.map(() => 1), 10000, 1, 10000) : resizeColumns(widths.map(() => 1), widths.reduce((a, b) => a + b, 0));
              setWidths(next); setColumnDraft(String(unit === '%' ? next[column] / 100 : next[column]));
            }}>열 너비 균등</button>
            <button type="submit">적용</button>
          </div>
        </form>
      </PopoverContent>
    </Popover>
  );
}

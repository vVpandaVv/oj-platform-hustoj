import * as React from 'react';
import { createRoot } from 'react-dom/client';
import cloneDeep from 'lodash/cloneDeep.js';
import debounce from 'lodash/debounce.js';
import { Calendar } from '@/components/ui/calendar';
import { Toolbar, ToolbarButton } from '@/components/ui/toolbar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useDebounce } from '@/hooks/use-debounce';
import { filter } from '@/shims/fuzzy';
import { createStarterVisualizationDsl } from '@/visualization/visualization-block';
import { parseVisualizationDsl } from '@/visualization/visualization-dsl';
import { VisualizationRenderer } from '@/visualization/visualization-renderer';
import type { VisualizationType } from '@/visualization/visualization-types';
import '../assets/src/admin/styles.css';

// This entry is built only by the regression runner, never by the plugin build.
function check(condition: unknown, message: string): asserts condition {
  if (!condition) throw new Error(message);
}

async function checkUtilities() {
  const original = { children: [{ text: '본문' }] };
  const cloned = cloneDeep(original);
  cloned.children[0].text = '편집';
  check(original.children[0].text === '본문', 'cloneDeep must isolate nested text');
  const values = [{ label: 'Array' }, { label: 'alpha' }, { label: 'Beta' }];
  const matches = filter('ar', values, { extract: value => value.label, pre: '<b>', post: '</b>' });
  check(matches.length === 1 && 'original' in matches[0] && matches[0].original === values[0], 'fuzzy object extraction');
  check('string' in matches[0] && matches[0].string.startsWith('<b>A</b><b>r</b>'), 'fuzzy highlighting');
  const calls: string[] = [];
  const delayed = debounce((value: string) => { calls.push(value); return value.length; }, 30);
  check(delayed('discarded') === undefined, 'debounce first result');
  delayed('kept');
  check(delayed.flush() === 4 && calls.join() === 'kept', 'debounce flush/latest arguments');
  delayed('cancelled'); delayed.cancel();
  await new Promise(resolve => setTimeout(resolve, 60));
  check(calls.join() === 'kept', 'debounce cancel');
  delayed('trailing');
  await new Promise(resolve => setTimeout(resolve, 60));
  check(calls.join() === 'kept,trailing', 'debounce trailing callback');
  const contextual = debounce(function (this: { prefix: string }, value: string) { return this.prefix + value; }, 30, { leading: true, trailing: false });
  check(contextual.call({ prefix: 'a' }, 'b') === 'ab', 'debounce leading/this');
  contextual.cancel();
  return 'passed';
}

function Controls() {
  const [selected, setSelected] = React.useState<Date | undefined>(new Date(2026, 9, 18));
  const [value, setValue] = React.useState('');
  const [clicked, setClicked] = React.useState('');
  const [utilities, setUtilities] = React.useState('pending');
  const ref = React.useRef<HTMLButtonElement>(null);
  const debounced = useDebounce(value, 80);
  React.useEffect(() => { void checkUtilities().then(setUtilities).catch(error => setUtilities(String(error))); }, []);
  return <>
    <Calendar mode="single" autoFocus selected={selected} defaultMonth={new Date(2026, 9, 1)} onSelect={setSelected} />
    <output data-testid="selected">{selected?.getDate()}</output>
    <TooltipProvider><Toolbar><ToolbarButton ref={ref} tooltip="검증 버튼" onClick={() => setClicked(ref.current?.tagName ?? 'missing')}>Test</ToolbarButton></Toolbar></TooltipProvider>
    <output data-testid="clicked">{clicked}</output>
    <input aria-label="Debounce input" value={value} onChange={event => setValue(event.target.value)} />
    <output data-testid="debounced">{debounced}</output>
    <output data-testid="utilities">{utilities}</output>
  </>;
}

function Visual({ name }: { name: string }) {
  const type = name.split(':')[0] as VisualizationType;
  const [document, setDocument] = React.useState(() => {
    const next = parseVisualizationDsl(createStarterVisualizationDsl(type), type, type);
    next.source = { format: 'json', code: '' };
    if (name === 'dp-table:invalid') next.model.cells?.forEach(cell => { if (cell.state === 'active') cell.value = 'unknown'; });
    if (name === 'scatter:empty') next.model.items = [];
    if (name === 'gantt:invalid') next.model.typeData = { ...next.model.typeData, columns: { unexpected: 'value' } };
    if (name === 'divide-conquer:invalid') next.model.edges?.push({ id: 'missing', from: 'missing', to: 'A', label: 'merge' });
    return next;
  });
  const [events, setEvents] = React.useState<unknown[]>([]);
  return <>
    <VisualizationRenderer document={document} onNodeMove={(id, x, y) => {
      setEvents(events => [...events, { id, x, y }]);
      setDocument(previous => ({ ...previous, model: { ...previous.model, nodes: previous.model.nodes?.map(node => node.id === id ? { ...node, position: { x, y } } : node) } }));
    }} onCommandChange={(command, value) => setEvents(events => [...events, { command, value }])} onItemValueChange={(index, value) => setEvents(events => [...events, { index, value }])} />
    <output data-testid="events">{JSON.stringify(events)}</output>
  </>;
}

const name = new URLSearchParams(location.search).get('case') ?? 'controls';
const root = window.document.getElementById('test-root');
if (root) createRoot(root).render(name === 'controls' ? <Controls /> : <Visual name={name} />);

import * as React from 'react';
import type { Value } from 'platejs';
import { createPlatePlugin, Plate, usePlateEditor } from 'platejs/react';

import type { ResponseFieldKind } from '../types';
import { createDefaultSchema, ensureEditablePlateValue, ensureQuestionGroups, parseSchemaFromField, questionNode, responseNode, serializeSchema } from '../utils/schema';
import { EditorKit } from '@/registry/components/editor/editor-kit';
import { createFixedToolbarPlugin } from '@/registry/components/editor/plugins/fixed-toolbar-kit';
import { Editor, EditorContainer } from '@/registry/ui/editor';
import { ResponseFieldElement } from './ResponseFieldElement';
import { QuestionBlockElement } from './QuestionBlockElement';

const ResponseFieldPlugin = createPlatePlugin({
  key: 'hws_response',
  node: {
    component: ResponseFieldElement,
    isElement: true,
    isVoid: true,
    type: 'hws_response'
  }
});
const QuestionBlockPlugin = createPlatePlugin({
  key: 'hws_question',
  node: {
    component: QuestionBlockElement,
    isElement: true,
    type: 'hws_question'
  }
});


const plugins = [
  ...EditorKit,
  ResponseFieldPlugin,
  QuestionBlockPlugin,
];
const studentPlugins = [
  ...plugins.filter((plugin) => plugin.key !== 'fixed-toolbar'),
  createFixedToolbarPlugin({ showResponseMenu: false }),
];

function cloneValue(value: Value): Value {
  return JSON.parse(JSON.stringify(value)) as Value;
}


function editableQuestionValue(value: unknown): Value {
  return ensureEditablePlateValue(ensureQuestionGroups(ensureEditablePlateValue(value)));
}

function findQuestionPath(value: Value, questionId: string): number[] | null {
  let found: number[] | null = null;
  const visit = (nodes: any[], parentPath: number[]) => {
    nodes.some((node, index) => {
      if (!node || typeof node !== 'object') return false;
      const path = [...parentPath, index];
      const key = String(node.question_id || node.node_id || node.id || '');
      if (node.type === 'hws_question' && key === questionId) {
        found = path;
        return true;
      }
      return Array.isArray(node.children) && visit(node.children, path);
    });
    return found !== null;
  };
  visit(value as any[], []);
  return found;
}

function nodeAtPath(value: Value, path: number[]): any {
  let current: any = { children: value };
  path.forEach((index) => {
    current = current?.children?.[index];
  });
  return current;
}
function schemaJsonFromValue(value: Value, title: string): string {
  return JSON.stringify(serializeSchema(value, title), null, 2);
}

function valueText(value: Value): string {
  const readNode = (node: any): string => {
    if (!node || typeof node !== 'object') {
      return '';
    }
    if (typeof node.text === 'string') {
      return node.text;
    }
    if (!Array.isArray(node.children)) {
      return '';
    }
    return node.children.map(readNode).join('');
  };

  return value.map(readNode).filter((line) => line.trim() !== '').join('\n');
}

function studentInitialValue(contentField: HTMLTextAreaElement): Value {
  const raw = String(contentField.value || '').trim();
  if (raw) {
    try {
      const parsed = JSON.parse(raw);
      if (parsed?.engine === 'plate_student' && Array.isArray(parsed.plate_value)) {
        return ensureEditablePlateValue(parsed.plate_value);
      }
      if (Array.isArray(parsed)) {
        return ensureEditablePlateValue(parsed);
      }
    } catch {
      // Existing plain text responses are converted into Plate paragraphs below.
    }
  }

  return raw
    ? raw.split(/\r\n|\r|\n/).map((line) => ({ type: 'p', children: [{ text: line }] }))
    : [{ type: 'p', children: [{ text: '' }] }];
}

interface PlateTemplateEditorProps {
  builderField: HTMLTextAreaElement;
  renderField: HTMLTextAreaElement;
  titleField: HTMLInputElement | null;
}

type TemplateEditorTab = 'editor' | 'preview' | 'source';

export function PlateTemplateEditor({ builderField, renderField, titleField }: PlateTemplateEditorProps) {
  const initialSchema = React.useMemo(() => {
    const configSchema = createDefaultSchema(window.ojAiDashboardWorksheetPlateBuilder?.defaultSchema || {});
    return parseSchemaFromField(builderField, configSchema);
  }, [builderField]);
  const initialValue = React.useMemo(() => cloneValue(editableQuestionValue(initialSchema.plate_value)), [initialSchema]);
  const initialJson = React.useMemo(
    () => schemaJsonFromValue(initialValue, titleField?.value || initialSchema.title || ''),
    [initialSchema.title, initialValue, titleField]
  );
  const lastValueRef = React.useRef<Value>(initialValue);
  const [activeTab, setActiveTab] = React.useState<TemplateEditorTab>('editor');
  const [previewValue, setPreviewValue] = React.useState<Value>(initialValue);
  const [sourceJson, setSourceJson] = React.useState(initialJson);
  const [sourceError, setSourceError] = React.useState('');

  const editor = usePlateEditor({
    id: 'hws-plate-template-editor',
    plugins,
    shouldNormalizeEditor: true,
    value: initialValue
  });

  const sync = React.useCallback((nextValue: Value) => {
    if (!Array.isArray(nextValue)) {
      return;
    }

    const currentValue = cloneValue(editableQuestionValue(nextValue));
    lastValueRef.current = currentValue;
    const json = schemaJsonFromValue(currentValue, titleField?.value || '');
    setPreviewValue(cloneValue(currentValue));
    setSourceJson(json);
    setSourceError('');
    builderField.value = json;
    renderField.value = json;
    builderField.dispatchEvent(new Event('input', { bubbles: true }));
    renderField.dispatchEvent(new Event('input', { bubbles: true }));
  }, [builderField, renderField, titleField]);

  const syncFromEditor = React.useCallback(() => {
    if (Array.isArray(editor?.children)) {
      sync(cloneValue(editor.children as Value));
    }
  }, [editor, sync]);

  React.useEffect(() => {
    syncFromEditor();
  }, [syncFromEditor]);

  React.useEffect(() => {
    const form = builderField.form;
    const syncFormData = (event: Event) => {
      syncFromEditor();
      const formData = (event as any).formData as FormData | undefined;
      formData?.set('schema_json', builderField.value);
      formData?.set('builder_schema_json', builderField.value);
      formData?.set('render_schema_json', renderField.value);
    };
    const syncResponseFieldChange = () => syncFromEditor();

    form?.addEventListener('submit', syncFromEditor, { capture: true });
    form?.addEventListener('formdata', syncFormData);
    window.addEventListener('hws-plate-response-field-change', syncResponseFieldChange);

    return () => {
      form?.removeEventListener('submit', syncFromEditor, { capture: true });
      form?.removeEventListener('formdata', syncFormData);
      window.removeEventListener('hws-plate-response-field-change', syncResponseFieldChange);
    };
  }, [builderField, renderField, syncFromEditor]);

  React.useEffect(() => {
    const updateTitle = () => sync(lastValueRef.current);
    titleField?.addEventListener('input', updateTitle);
    return () => titleField?.removeEventListener('input', updateTitle);
  }, [sync, titleField]);

  React.useEffect(() => {
    const loadTemplate = (event: Event) => {
      const detail = (event as CustomEvent).detail || {};
      const nextSchema = createDefaultSchema(detail.schema || {});
      const nextValue = cloneValue(editableQuestionValue(nextSchema.plate_value));

      setActiveTab('editor');
      const inspectorRoot = builderField.ownerDocument.getElementById('ws-response-inspector-root');
      if (inspectorRoot) {
        delete inspectorRoot.dataset.activeResponse;
      }
      window.dispatchEvent(new CustomEvent('ojai-plate-response-select', { detail: { id: '' } }));
      editor.tf.withoutSaving(() => editor.tf.setValue(nextValue));
      // The workbench captures its saved baseline before this event returns.
      // Serialize Plate's normalized tree, not the pre-normalization input.
      syncFromEditor();
    };

    window.addEventListener('ojai-plate-template-load', loadTemplate);
    return () => window.removeEventListener('ojai-plate-template-load', loadTemplate);
  }, [builderField, editor, syncFromEditor]);

  React.useEffect(() => {
    const insertAsset = (event: Event) => {
      const detail = (event as CustomEvent).detail || {};
      const asset = detail.asset || {};
      const assetId = String(asset.id || '');
      const name = String(asset.original_name || '').trim();
      const mimeType = String(asset.mime_type || '').trim().toLowerCase();
      const url = String(asset.url || '');
      if (!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(assetId) || !name || !mimeType) {
        return;
      }

      const common = {
        asset_id: assetId,
        children: [{ text: '' }],
        ...(url ? { url } : {}),
      };
      let node: Record<string, unknown>;
      if (mimeType.startsWith('image/')) {
        node = { ...common, type: 'img', alt: name };
      } else if (mimeType.startsWith('audio/')) {
        node = { ...common, type: 'audio', transcript: name };
      } else if (mimeType.startsWith('video/')) {
        node = { ...common, type: 'video', transcript: name };
      } else {
        node = { ...common, type: 'file', label: name, name };
      }

      editor.tf.focus();
      editor.tf.insertNodes([
        node as any,
        { type: 'p', children: [{ text: '' }] },
      ], { select: true });
    };

    window.addEventListener('ojai-plate-asset-insert', insertAsset);
    return () => window.removeEventListener('ojai-plate-asset-insert', insertAsset);
  }, [editor]);


  React.useEffect(() => {
    const allowedKinds: ResponseFieldKind[] = [
      'short_text',
      'long_text',
      'single_choice',
      'multiple_choice',
      'checkbox',
      'number',
      'table',
      'image_upload',
      'file_upload',
      'drawing',
    ];
    const handleResponseInsert = (event: Event) => {
      const detail = (event as CustomEvent).detail || {};
      const kind = String(detail.kind || '') as ResponseFieldKind;
      if (!allowedKinds.includes(kind)) {
        return;
      }
      const node = responseNode(kind);
      const inspectorRoot = builderField.ownerDocument.getElementById('ws-response-inspector-root');
      const questionId = String(detail.questionId || inspectorRoot?.dataset.activeQuestion || '');
      const currentValue = editor.children as Value;
      const questionPath = findQuestionPath(currentValue, questionId);
      editor.tf.focus();
      if (questionPath) {
        const question = nodeAtPath(currentValue, questionPath);
        const childIndex = Array.isArray(question?.children) ? question.children.length : 0;
        editor.tf.insertNodes(node, { at: [...questionPath, childIndex], select: true });
      } else {
        const question = questionNode(kind);
        question.children[1] = node;
        const insertIndex = currentValue.length > 0 && (currentValue.at(-1) as any)?.type === 'p'
          ? currentValue.length - 1
          : currentValue.length;
        editor.tf.insertNodes(question, { at: [insertIndex], select: true });
      }
      window.setTimeout(() => {
        const response = builderField.ownerDocument.querySelector<HTMLElement>(`[data-hws-response-id="${node.name}"]`);
        response?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        response?.click();
      }, 0);
    };

    window.addEventListener('ojai-plate-response-insert', handleResponseInsert);
    return () => window.removeEventListener('ojai-plate-response-insert', handleResponseInsert);
  }, [builderField, editor]);

  React.useEffect(() => {
    const handleQuestionInsert = () => {
      const question = questionNode('long_text');
      const currentValue = editor.children as Value;
      const inspectorRoot = builderField.ownerDocument.getElementById('ws-response-inspector-root');
      const activeQuestionId = String(inspectorRoot?.dataset.activeQuestion || '');
      const activePath = findQuestionPath(currentValue, activeQuestionId);
      const insertIndex = activePath
        ? activePath[0] + 1
        : currentValue.length > 0 && (currentValue.at(-1) as any)?.type === 'p'
          ? currentValue.length - 1
          : currentValue.length;
      editor.tf.focus();
      editor.tf.insertNodes(question, { at: [insertIndex], select: true });
      if (inspectorRoot) inspectorRoot.dataset.activeQuestion = question.question_id;
      window.dispatchEvent(new CustomEvent('ojai-plate-question-select', {
        detail: { id: question.question_id },
      }));
      window.setTimeout(() => {
        const questionElement = builderField.ownerDocument.querySelector<HTMLElement>(`.hws-question-block[data-hws-question-id="${question.question_id}"]`);
        const response = questionElement?.querySelector<HTMLElement>('.hws-response-node__preview');
        questionElement?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        response?.click();
      }, 50);
    };
    window.addEventListener('ojai-plate-question-insert', handleQuestionInsert);
    return () => window.removeEventListener('ojai-plate-question-insert', handleQuestionInsert);
  }, [builderField, editor]);

  const applySourceJson = React.useCallback((rawJson: string) => {
    setSourceJson(rawJson);
    builderField.value = rawJson;
    renderField.value = rawJson;
    builderField.dispatchEvent(new Event('input', { bubbles: true }));
    renderField.dispatchEvent(new Event('input', { bubbles: true }));

    if (rawJson.trim() === '') {
      const nextValue = editableQuestionValue([]);
      lastValueRef.current = nextValue;
      setPreviewValue(cloneValue(nextValue));
      setSourceError('');
      editor.tf.setValue(nextValue);
      sync(nextValue);
      return;
    }

    try {
      const parsed = JSON.parse(rawJson);
      const parsedRootWasEmpty =
        (Array.isArray(parsed) && parsed.length === 0) ||
        (parsed && typeof parsed === 'object' && Array.isArray(parsed.plate_value) && parsed.plate_value.length === 0);
      const nextValue = cloneValue(
        Array.isArray(parsed)
          ? editableQuestionValue(parsed)
          : editableQuestionValue(createDefaultSchema(parsed).plate_value)
      );
      lastValueRef.current = nextValue;
      setPreviewValue(cloneValue(nextValue));
      setSourceError('');
      editor.tf.setValue(nextValue);
      if (parsedRootWasEmpty) {
        sync(nextValue);
      }
    } catch (error) {
      setSourceError(error instanceof Error ? error.message : 'JSON을 해석할 수 없습니다.');
    }
  }, [builderField, editor, renderField, sync]);

  return (
    <div className="hws-template-workspace">
      <Plate
        editor={editor}
        onValueChange={({ value }) => {
          const nextValue = cloneValue(editableQuestionValue(value));
          sync(nextValue);
        }}
      >
        <EditorContainer className="hws-template-editor-container" variant="demo">
          <Editor
            className="hws-template-editor-page"
            variant="fullWidth"
            placeholder="활동지 내용을 입력하세요."
          />
        </EditorContainer>
      </Plate>
    </div>
  );
}

interface StudentPlateEditorProps {
  contentField: HTMLTextAreaElement;
  editable: boolean;
}

export function StudentPlateEditor({ contentField, editable }: StudentPlateEditorProps) {
  const initialValue = React.useMemo(() => cloneValue(ensureEditablePlateValue(studentInitialValue(contentField))), [contentField]);

  const editor = usePlateEditor({
    id: 'hws-plate-student-editor',
    plugins: studentPlugins,
    shouldNormalizeEditor: true,
    value: initialValue
  });

  const sync = React.useCallback(() => {
    if (!Array.isArray(editor?.children)) {
      return;
    }

    const currentValue = cloneValue(ensureEditablePlateValue(editor.children as Value));
    contentField.value = JSON.stringify({
      engine: 'plate_student',
      plain_text: valueText(currentValue),
      plate_value: currentValue
    });
    contentField.dispatchEvent(new Event('input', { bubbles: true }));
  }, [contentField, editor]);

  React.useEffect(() => {
    sync();
  }, [sync]);

  React.useEffect(() => {
    const form = contentField.form;
    if (!form) {
      return undefined;
    }

    form.addEventListener('submit', sync);
    return () => form.removeEventListener('submit', sync);
  }, [contentField, sync]);

  return (
    <div className="w-full overflow-visible bg-background">
      <Plate
        editor={editor}
        onValueChange={({ value }) => {
          const currentValue = cloneValue(ensureEditablePlateValue(value));
          contentField.value = JSON.stringify({
            engine: 'plate_student',
            plain_text: valueText(currentValue),
            plate_value: currentValue
          });
          contentField.dispatchEvent(new Event('input', { bubbles: true }));
        }}
      >
        <EditorContainer variant="demo">
          <Editor
            readOnly={!editable}
            variant="fullWidth"
            placeholder="여기에 활동 내용을 처음부터 작성하세요."
          />
        </EditorContainer>
      </Plate>
    </div>
  );
}

interface PlateReadonlyViewerProps {
  value: Value;
}

export function PlateReadonlyViewer({ value }: PlateReadonlyViewerProps) {
  const initialValue = React.useMemo(() => cloneValue(ensureEditablePlateValue(value)), [value]);
  const editor = usePlateEditor({
    id: 'hws-plate-readonly-viewer',
    plugins: studentPlugins,
    shouldNormalizeEditor: true,
    value: initialValue
  });

  return (
    <div className="w-full overflow-visible bg-background">
      <Plate editor={editor}>
        <EditorContainer className="hws-plate-readonly-viewer" variant="demo">
          <Editor
            readOnly
            variant="fullWidth"
            placeholder="표시할 제출 내용이 없습니다."
          />
        </EditorContainer>
      </Plate>
    </div>
  );
}

'use client';

import * as React from 'react';

import { type Value, normalizeStaticValue } from 'platejs';
import {
  Plate,
  useEditorReadOnly,
  useEditorRef,
  usePlateEditor,
} from 'platejs/react';

import { EditorKit } from '@/components/editor-kit';
import { FixedToolbar } from '@/components/ui/fixed-toolbar';
import { FixedToolbarButtons } from '@/components/ui/fixed-toolbar-buttons';
import { createLessonValue, type PlateValue } from '@/components/plate-value';
import { SettingsDialog } from '@/components/settings-dialog';
import { Editor, EditorContainer } from '@/components/ui/editor';

function EnsureEditableMode() {
  const editor = useEditorRef();
  const readOnly = useEditorReadOnly();

  React.useEffect(() => {
    if (readOnly) {
      editor.store.setReadOnly(false);
    }
  }, [editor, readOnly]);

  return null;
}

export function PlateEditor({
  initialValue = createLessonValue('새 레슨'),
  onValueChange,
}: {
  initialValue?: PlateValue;
  onValueChange?: (value: PlateValue) => void;
}) {
  const initialEditorValue = React.useMemo(
    () => normalizeStaticValue(initialValue as Value),
    [],
  );
  const editor = usePlateEditor({
    plugins: EditorKit,
    value: initialEditorValue,
  });

  return (
    <Plate
      editor={editor}
      readOnly={false}
      onValueChange={({ value: nextValue }) => {
        if (import.meta.env.DEV) {
          console.debug('[slash-debug] onValueChange len', Array.isArray(nextValue) ? nextValue.length : -1);
        }
        onValueChange?.(nextValue as PlateValue);
      }}
    >
      <EnsureEditableMode />

      <FixedToolbar
        aria-label="콘텐츠 편집 메뉴"
        className="top-0 min-h-12 rounded-none border-x border-t border-slate-200 bg-white"
        data-contents-editor-fixed-toolbar="true"
      >
        <FixedToolbarButtons />
      </FixedToolbar>

      <EditorContainer className="min-h-[720px] overflow-visible" variant="default">
        <Editor
          aria-label="레슨 본문 편집"
          className="contents-editor-content-surface contents-editor-content-surface--authoring"
          data-contents-editor-content-surface="authoring"
          variant="demo"
        />
      </EditorContainer>

      <SettingsDialog />
    </Plate>
  );
}

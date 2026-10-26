import * as React from 'react';

import { PlusIcon, Trash2Icon } from 'lucide-react';
import { PlateElement, useEditorRef, useElement, usePath, useReadOnly } from 'platejs/react';

function questionKey(element: any): string {
  return String(element.question_id || element.node_id || element.id || 'question');
}

function responseCount(element: any): number {
  let count = 0;
  const visit = (node: any) => {
    if (!node || typeof node !== 'object') return;
    if (node.type === 'hws_response') count += 1;
    if (Array.isArray(node.children)) node.children.forEach(visit);
  };
  visit(element);
  return count;
}

export function QuestionBlockElement(props: any) {
  const element = useElement<any>() || props.element || {};
  const editor = useEditorRef();
  const path = usePath();
  const readOnly = useReadOnly();
  const key = questionKey(element);
  const [active, setActive] = React.useState(() => (
    typeof document !== 'undefined'
    && document.getElementById('ws-response-inspector-root')?.dataset.activeQuestion === key
  ));

  React.useEffect(() => {
    const selectQuestion = (event: Event) => {
      setActive(String((event as CustomEvent).detail?.id || '') === key);
    };
    window.addEventListener('ojai-plate-question-select', selectQuestion);
    return () => window.removeEventListener('ojai-plate-question-select', selectQuestion);
  }, [key]);

  const activate = React.useCallback(() => {
    window.dispatchEvent(new CustomEvent('ojai-plate-question-select', {
      detail: { id: key },
    }));
    const inspectorRoot = document.getElementById('ws-response-inspector-root');
    if (inspectorRoot) inspectorRoot.dataset.activeQuestion = key;
  }, [key]);

  const addResponse = (event: React.MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    activate();
    window.dispatchEvent(new CustomEvent('ojai-plate-response-insert', {
      detail: { kind: 'long_text', questionId: key },
    }));
  };

  const removeQuestion = (event: React.MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    if (!window.confirm('문항과 문항에 포함된 모든 답안을 삭제할까요?')) return;
    editor.tf.removeNodes({ at: path, voids: true });
    window.dispatchEvent(new CustomEvent('ojai-plate-question-select', { detail: { id: '' } }));
    window.dispatchEvent(new CustomEvent('ojai-plate-response-select', { detail: { id: '' } }));
  };

  if (readOnly) {
    return (
      <PlateElement {...props} as="section" className="hws-question-block hws-question-block--readonly" data-hws-question-id={key}>
        {props.children}
      </PlateElement>
    );
  }

  return (
    <PlateElement
      {...props}
      as="section"
      className={`hws-question-block${active ? ' is-selected' : ''}`}
      data-hws-question-id={key}
      onClick={activate}
    >
      <div contentEditable={false} className="hws-question-block__header">
        <span className="hws-question-block__badge">문항</span>
        <span className="hws-question-block__count">답안 {responseCount(element)}개</span>
        <button type="button" className="hws-question-block__delete" onClick={removeQuestion} title="문항 삭제" aria-label="문항 삭제">
          <Trash2Icon aria-hidden="true" />
        </button>
      </div>
      <div className="hws-question-block__content">{props.children}</div>
      <div contentEditable={false} className="hws-question-block__footer">
        <button type="button" className="hws-question-block__add-response" onClick={addResponse}>
          <PlusIcon aria-hidden="true" />
          <span>답안 추가</span>
        </button>
      </div>
    </PlateElement>
  );
}

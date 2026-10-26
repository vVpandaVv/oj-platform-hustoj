'use client';

import * as React from 'react';

import {
  CheckCircle2Icon,
  CircleIcon,
  PlusIcon,
  Trash2Icon,
} from 'lucide-react';
import type { PlateElementProps } from 'platejs/react';
import {
  PlateElement,
  useEditorRef,
  useElement,
  useFocusedLast,
  useReadOnly,
  useSelected,
} from 'platejs/react';

import { Button } from '@/components/ui/button';
import {
  Popover,
  PopoverAnchor,
  PopoverContent,
} from '@/components/ui/popover';
import {
  createQuizData,
  type QuizChoice,
  type QuizData,
  type TQuizElement,
} from '@/quiz/quiz-block';

function createChoiceId() {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }

  return `choice-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function normalizeQuizData(data: TQuizElement['data'] | undefined): QuizData {
  return createQuizData(data ?? {});
}

export function QuizElement(props: PlateElementProps<TQuizElement>) {
  const { children } = props;
  const editor = useEditorRef();
  const readOnly = useReadOnly();
  const selected = useSelected();
  const focusedLast = useFocusedLast();
  const element = useElement<TQuizElement>();
  const data = normalizeQuizData(element.data);
  const [selectedChoiceId, setSelectedChoiceId] = React.useState<string | null>(
    null
  );
  const [checked, setChecked] = React.useState(false);

  const updateData = React.useCallback(
    (nextData: QuizData) => {
      if (readOnly) return;

      const path = editor.api.findPath(element);

      if (path) {
        editor.tf.setNodes({ data: nextData }, { at: path });
      }
    },
    [editor, element, readOnly]
  );

  const removeNode = React.useCallback(() => {
    if (readOnly) return;

    const path = editor.api.findPath(element);

    if (path) {
      editor.tf.removeNodes({ at: path });
    }
  }, [editor, element, readOnly]);

  const updateChoice = React.useCallback(
    (choiceId: string, text: string) => {
      updateData({
        ...data,
        choices: data.choices.map((choice) =>
          choice.id === choiceId ? { ...choice, text } : choice
        ),
      });
    },
    [data, updateData]
  );

  const addChoice = React.useCallback(() => {
    const nextChoice: QuizChoice = {
      id: createChoiceId(),
      text: `새 선택지 ${data.choices.length + 1}`,
    };

    updateData({
      ...data,
      choices: [...data.choices, nextChoice],
    });
  }, [data, updateData]);

  const removeChoice = React.useCallback(
    (choiceId: string) => {
      if (data.choices.length <= 2) return;

      const choices = data.choices.filter((choice) => choice.id !== choiceId);
      const correctChoiceId =
        data.correctChoiceId === choiceId
          ? choices[0]?.id ?? data.correctChoiceId
          : data.correctChoiceId;

      updateData({
        ...data,
        choices,
        correctChoiceId,
      });
    },
    [data, updateData]
  );

  const isCorrect = selectedChoiceId === data.correctChoiceId;
  const resultText = isCorrect
    ? data.feedbackCorrect || '정답입니다.'
    : data.feedbackIncorrect || '다시 생각해 보세요.';

  const content = (
    <PlateElement {...props}>
      <section
        className={[
          'my-5 rounded-xl border bg-white p-5 shadow-sm',
          selected && !readOnly ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200',
        ].join(' ')}
        contentEditable={false}
        data-contents-editor-quiz-block
      >
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <span className="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
            퀴즈
          </span>
          <span className="text-xs text-slate-500">
            정답 확인과 해설을 제공하는 문제입니다.
          </span>
        </div>

        <label className="grid gap-2">
          <span className="text-sm font-semibold text-slate-700">질문</span>
          <textarea
            className="min-h-20 resize-y rounded-lg border border-slate-200 bg-white px-3 py-2 text-base font-semibold leading-7 text-slate-950 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
            disabled={readOnly}
            value={data.question}
            onChange={(event) =>
              updateData({ ...data, question: event.target.value })
            }
          />
        </label>

        <div className="mt-4 grid gap-2">
          <div className="flex items-center justify-between gap-2">
            <span className="text-sm font-semibold text-slate-700">선택지</span>
            {!readOnly ? (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8"
                onClick={addChoice}
              >
                <PlusIcon className="size-4" />
                선택지 추가
              </Button>
            ) : null}
          </div>

          {data.choices.map((choice, index) => {
            const correct = choice.id === data.correctChoiceId;
            const selectedForCheck = choice.id === selectedChoiceId;

            return (
              <div
                key={choice.id}
                className={[
                  'grid gap-2 rounded-lg border p-3',
                  correct && !readOnly
                    ? 'border-emerald-300 bg-emerald-50/60'
                    : selectedForCheck
                      ? 'border-blue-300 bg-blue-50'
                      : 'border-slate-200 bg-slate-50/60',
                ].join(' ')}
              >
                <div className="flex items-start gap-2">
                  <button
                    type="button"
                    className="mt-1 text-slate-500"
                    onClick={() => {
                      setSelectedChoiceId(choice.id);
                      setChecked(false);
                    }}
                    title="답안 선택"
                  >
                    {selectedForCheck ? (
                      <CheckCircle2Icon className="size-5 text-blue-600" />
                    ) : (
                      <CircleIcon className="size-5" />
                    )}
                  </button>

                  <input
                    className="min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
                    disabled={readOnly}
                    value={choice.text}
                    onChange={(event) =>
                      updateChoice(choice.id, event.target.value)
                    }
                  />

                  {!readOnly ? (
                    <button
                      type="button"
                      className={[
                        'rounded-md border px-2.5 py-2 text-xs font-semibold',
                        correct
                          ? 'border-emerald-300 bg-emerald-100 text-emerald-800'
                          : 'border-slate-200 bg-white text-slate-600 hover:border-blue-300 hover:text-blue-700',
                      ].join(' ')}
                      onClick={() =>
                        updateData({ ...data, correctChoiceId: choice.id })
                      }
                    >
                      {correct ? '정답' : `${index + 1}번 정답`}
                    </button>
                  ) : null}

                  {!readOnly ? (
                    <button
                      type="button"
                      className="rounded-md border border-slate-200 bg-white p-2 text-slate-500 hover:border-red-200 hover:text-red-600 disabled:opacity-40"
                      disabled={data.choices.length <= 2}
                      onClick={() => removeChoice(choice.id)}
                      title="선택지 삭제"
                    >
                      <Trash2Icon className="size-4" />
                    </button>
                  ) : null}
                </div>
              </div>
            );
          })}
        </div>

        <label className="mt-4 grid gap-2">
          <span className="text-sm font-semibold text-slate-700">해설</span>
          <textarea
            className="min-h-24 resize-y rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm leading-6 text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
            disabled={readOnly}
            value={data.explanation}
            onChange={(event) =>
              updateData({ ...data, explanation: event.target.value })
            }
          />
        </label>

        <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              size="sm"
              disabled={!selectedChoiceId}
              onClick={() => setChecked(true)}
            >
              정답 확인
            </Button>
            {checked ? (
              <strong
                className={isCorrect ? 'text-emerald-700' : 'text-red-700'}
              >
                {resultText}
              </strong>
            ) : (
              <span className="text-sm text-slate-500">
                선택지를 고른 뒤 정답을 확인하세요.
              </span>
            )}
          </div>

          {checked ? (
            <p className="mt-3 whitespace-pre-wrap rounded-md bg-white px-3 py-2 text-sm leading-6 text-slate-700">
              {data.explanation}
            </p>
          ) : null}
        </div>
      </section>
      {children}
    </PlateElement>
  );

  if (readOnly) {
    return content;
  }

  return (
    <Popover open={focusedLast && selected} modal={false}>
      <PopoverAnchor asChild>{content}</PopoverAnchor>
      <PopoverContent
        className="w-auto p-1"
        contentEditable={false}
        onOpenAutoFocus={(event) => event.preventDefault()}
      >
        <Button
          size="icon"
          variant="ghost"
          className="size-8"
          onClick={removeNode}
          title="퀴즈 삭제"
        >
          <Trash2Icon className="size-4" />
        </Button>
      </PopoverContent>
    </Popover>
  );
}

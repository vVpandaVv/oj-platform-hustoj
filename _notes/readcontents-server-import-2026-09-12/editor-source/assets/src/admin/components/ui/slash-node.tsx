'use client';

import * as React from 'react';

import type { PlateEditor, PlateElementProps } from 'platejs/react';

import { AIChatPlugin } from '@platejs/ai/react';
import {
  CalendarIcon,
  ChevronRightIcon,
  Code2,
  Columns3Icon,
  HelpCircleIcon,
  Heading1Icon,
  Heading2Icon,
  Heading3Icon,
  LightbulbIcon,
  ListIcon,
  ListOrdered,
  PenToolIcon,
  PilcrowIcon,
  Quote,
  RadicalIcon,
  SparklesIcon,
  Square,
  SuperscriptIcon,
  Table,
  TableOfContentsIcon,
} from 'lucide-react';
import { type TComboboxInputElement, KEYS } from 'platejs';
import { PlateElement } from 'platejs/react';

import { insertBlock, insertInlineElement } from '@/components/transforms';
import { QUIZ_BLOCK_TYPE } from '@/quiz/quiz-block';
import { VISUALIZATION_BLOCK_TYPE } from '@/visualization/visualization-block';

import {
  InlineCombobox,
  InlineComboboxContent,
  InlineComboboxEmpty,
  InlineComboboxGroup,
  InlineComboboxGroupLabel,
  InlineComboboxInput,
  InlineComboboxItem,
} from './inline-combobox';

type Group = {
  group: string;
  items: {
    icon: React.ReactNode;
    value: string;
    onSelect: (editor: PlateEditor, value: string) => void;
    className?: string;
    focusEditor?: boolean;
    keywords?: string[];
    label?: string;
  }[];
};

const groups: Group[] = [
  {
    group: '글쓰기 도구',
    items: [
      {
        focusEditor: false,
        icon: <SparklesIcon />,
        keywords: ['ai', '작성', '요약', '다듬기'],
        label: 'AI로 작성',
        value: 'AI',
        onSelect: (editor) => {
          editor.getApi(AIChatPlugin).aiChat.show();
        },
      },
    ],
  },
  {
    group: '기본 내용',
    items: [
      {
        icon: <PilcrowIcon />,
        keywords: ['paragraph', 'text', '문단'],
        label: '본문',
        value: KEYS.p,
      },
      {
        icon: <Heading1Icon />,
        keywords: ['title', 'h1', '제목'],
        label: '큰 제목',
        value: KEYS.h1,
      },
      {
        icon: <Heading2Icon />,
        keywords: ['subtitle', 'h2', '제목'],
        label: '중간 제목',
        value: KEYS.h2,
      },
      {
        icon: <Heading3Icon />,
        keywords: ['subtitle', 'h3', '제목'],
        label: '작은 제목',
        value: KEYS.h3,
      },
      {
        icon: <ListIcon />,
        keywords: ['unordered', 'ul', '-', '목록'],
        label: '글머리 기호 목록',
        value: KEYS.ul,
      },
      {
        icon: <ListOrdered />,
        keywords: ['ordered', 'ol', '1', '목록'],
        label: '번호 목록',
        value: KEYS.ol,
      },
      {
        icon: <Square />,
        keywords: ['checklist', 'task', 'checkbox', '[]', '할 일'],
        label: '할 일 목록',
        value: KEYS.listTodo,
      },
      {
        icon: <ChevronRightIcon />,
        keywords: ['collapsible', 'expandable', '접기'],
        label: '접기/펼치기 목록',
        value: KEYS.toggle,
      },
      {
        icon: <Code2 />,
        keywords: ['```'],
        label: '코드 블록',
        value: KEYS.codeBlock,
      },
      {
        icon: <Table />,
        keywords: ['table', '표'],
        label: '표',
        value: KEYS.table,
      },
      {
        icon: <Quote />,
        keywords: ['citation', 'blockquote', 'quote', '>', '인용'],
        label: '인용문',
        value: KEYS.blockquote,
      },
      {
        icon: <LightbulbIcon />,
        keywords: ['note', '안내', '강조'],
        label: '안내 상자',
        value: KEYS.callout,
      },
      {
        icon: <HelpCircleIcon />,
        keywords: ['quiz', 'question', 'answer', 'explanation', '문제'],
        label: '퀴즈',
        value: QUIZ_BLOCK_TYPE,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertBlock(editor, value, { upsert: true });
      },
    })),
  },
  {
    group: '학습 자료',
    items: [
      {
        icon: <TableOfContentsIcon />,
        keywords: ['toc', '목차'],
        label: '문서 목차',
        value: KEYS.toc,
      },
      {
        icon: <Columns3Icon />,
        label: '3단 구성',
        value: 'action_three_columns',
      },
      {
        focusEditor: false,
        icon: <RadicalIcon />,
        keywords: ['equation', '수식'],
        label: '수식',
        value: KEYS.equation,
      },
      {
        icon: <PenToolIcon />,
        keywords: ['visualization', 'visual', 'algoviz', 'flowchart', '시각화'],
        label: '시각화',
        value: VISUALIZATION_BLOCK_TYPE,
      },
      {
        icon: <PenToolIcon />,
        keywords: ['excalidraw', '그리기'],
        label: '자유 드로잉',
        value: KEYS.excalidraw,
      },
      {
        icon: <Code2 />,
        keywords: [
          'code-drawing',
          'diagram',
          'plantuml',
          'graphviz',
          'flowchart',
          'mermaid',
        ],
        label: '코드 다이어그램',
        value: KEYS.codeDrawing,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertBlock(editor, value, { upsert: true });
      },
    })),
  },
  {
    group: '문장 안에',
    items: [
      {
        focusEditor: true,
        icon: <CalendarIcon />,
        keywords: ['time', '날짜'],
        label: '날짜',
        value: KEYS.date,
      },
      {
        focusEditor: true,
        icon: <SuperscriptIcon />,
        keywords: ['citation', 'fn', 'footnote', '[^]', '각주'],
        label: '각주',
        value: 'action_footnote',
      },
      {
        focusEditor: false,
        icon: <RadicalIcon />,
        keywords: ['equation', '수식'],
        label: '인라인 수식',
        value: KEYS.inlineEquation,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertInlineElement(editor, value);
      },
    })),
  },
];

export function SlashInputElement(
  props: PlateElementProps<TComboboxInputElement>
) {
  const { editor, element } = props;

  return (
    <PlateElement {...props} as="span">
      <InlineCombobox element={element} trigger="/">
        <InlineComboboxInput />

        <InlineComboboxContent>
          <InlineComboboxEmpty>검색 결과가 없습니다.</InlineComboboxEmpty>

          {groups.map(({ group, items }) => (
            <InlineComboboxGroup key={group}>
              <InlineComboboxGroupLabel>{group}</InlineComboboxGroupLabel>

              {items.map(
                ({ focusEditor, icon, keywords, label, value, onSelect }) => (
                  <InlineComboboxItem
                    key={value}
                    value={value}
                    onClick={() => {
                      onSelect(editor, value);

                      if (focusEditor !== false) {
                        editor.tf.focus();
                      }
                    }}
                    label={label}
                    focusEditor={focusEditor}
                    group={group}
                    keywords={keywords}
                  >
                    <div className="mr-2 text-muted-foreground">{icon}</div>
                    {label ?? value}
                  </InlineComboboxItem>
                )
              )}
            </InlineComboboxGroup>
          ))}
        </InlineComboboxContent>
      </InlineCombobox>

      {props.children}
    </PlateElement>
  );
}

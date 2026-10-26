'use client';

import * as React from 'react';

import type { DropdownMenuProps } from '@radix-ui/react-dropdown-menu';

import {
  CalendarIcon,
  ChevronRightIcon,
  Code2,
  Columns3Icon,
  FileCodeIcon,
  FilmIcon,
  HelpCircleIcon,
  Heading1Icon,
  Heading2Icon,
  Heading3Icon,
  ImageIcon,
  LightbulbIcon,
  Link2Icon,
  ListIcon,
  ListOrderedIcon,
  MinusIcon,
  PenToolIcon,
  PilcrowIcon,
  PlusIcon,
  QuoteIcon,
  RadicalIcon,
  SquareIcon,
  SuperscriptIcon,
  TableIcon,
  TableOfContentsIcon,
} from 'lucide-react';
import { KEYS } from 'platejs';
import { type PlateEditor, useEditorRef } from 'platejs/react';

import { insertBlock, insertInlineElement } from '@/components/transforms';
import { QUIZ_BLOCK_TYPE } from '@/quiz/quiz-block';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { VISUALIZATION_BLOCK_TYPE } from '@/visualization/visualization-block';

import { ToolbarButton, ToolbarMenuGroup } from './toolbar';

type Group = {
  group: string;
  items: Item[];
};

type Item = {
  icon: React.ReactNode;
  value: string;
  onSelect: (editor: PlateEditor, value: string) => void;
  focusEditor?: boolean;
  label?: string;
};

const groups: Group[] = [
  {
    group: '기본 내용',
    items: [
      {
        icon: <PilcrowIcon />,
        label: '본문',
        value: KEYS.p,
      },
      {
        icon: <Heading1Icon />,
        label: '큰 제목',
        value: 'h1',
      },
      {
        icon: <Heading2Icon />,
        label: '중간 제목',
        value: 'h2',
      },
      {
        icon: <Heading3Icon />,
        label: '작은 제목',
        value: 'h3',
      },
      {
        icon: <TableIcon />,
        label: '표',
        value: KEYS.table,
      },
      {
        icon: <FileCodeIcon />,
        label: '코드 블록',
        value: KEYS.codeBlock,
      },
      {
        icon: <QuoteIcon />,
        label: '인용문',
        value: KEYS.blockquote,
      },
      {
        icon: <LightbulbIcon />,
        label: '안내 상자',
        value: KEYS.callout,
      },
      {
        icon: <HelpCircleIcon />,
        label: '퀴즈',
        value: QUIZ_BLOCK_TYPE,
      },
      {
        icon: <MinusIcon />,
        label: '구분선',
        value: KEYS.hr,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertBlock(editor, value);
      },
    })),
  },
  {
    group: '목록',
    items: [
      {
        icon: <ListIcon />,
        label: '글머리 기호 목록',
        value: KEYS.ul,
      },
      {
        icon: <ListOrderedIcon />,
        label: '번호 목록',
        value: KEYS.ol,
      },
      {
        icon: <SquareIcon />,
        label: '할 일 목록',
        value: KEYS.listTodo,
      },
      {
        icon: <ChevronRightIcon />,
        label: '접기/펼치기 목록',
        value: KEYS.toggle,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertBlock(editor, value);
      },
    })),
  },
  {
    group: '미디어',
    items: [
      {
        icon: <ImageIcon />,
        label: '이미지',
        value: KEYS.img,
      },
      {
        icon: <FilmIcon />,
        label: '영상/웹 콘텐츠',
        value: KEYS.mediaEmbed,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertBlock(editor, value);
      },
    })),
  },
  {
    group: '학습 자료',
    items: [
      {
        icon: <TableOfContentsIcon />,
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
        label: '수식',
        value: KEYS.equation,
      },
      {
        icon: <PenToolIcon />,
        label: '시각화',
        value: VISUALIZATION_BLOCK_TYPE,
      },
      {
        icon: <PenToolIcon />,
        label: '자유 드로잉',
        value: KEYS.excalidraw,
      },
      {
        icon: <Code2 />,
        label: '코드 다이어그램',
        value: KEYS.codeDrawing,
      },
    ].map((item) => ({
      ...item,
      onSelect: (editor, value) => {
        insertBlock(editor, value);
      },
    })),
  },
  {
    group: '문장 안에',
    items: [
      {
        icon: <Link2Icon />,
        label: '링크',
        value: KEYS.link,
      },
      {
        focusEditor: true,
        icon: <CalendarIcon />,
        label: '날짜',
        value: KEYS.date,
      },
      {
        focusEditor: true,
        icon: <SuperscriptIcon />,
        label: '각주',
        value: 'action_footnote',
      },
      {
        focusEditor: false,
        icon: <RadicalIcon />,
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

export function InsertToolbarButton(props: DropdownMenuProps) {
  const editor = useEditorRef();
  const [open, setOpen] = React.useState(false);

  return (
    <DropdownMenu open={open} onOpenChange={setOpen} modal={false} {...props}>
      <DropdownMenuTrigger asChild>
        <ToolbarButton pressed={open} tooltip="콘텐츠 삽입" isDropdown>
          <PlusIcon />
        </ToolbarButton>
      </DropdownMenuTrigger>

      <DropdownMenuContent
        className="grid max-h-[min(70vh,560px)] w-[min(360px,calc(100vw-24px))] grid-cols-1 overflow-y-auto sm:w-[440px] sm:grid-cols-2"
        align="start"
      >
        {groups.map(({ group, items: nestedItems }) => (
          <ToolbarMenuGroup key={group} label={group}>
            {nestedItems.map(({ focusEditor = true, icon, label, value, onSelect }) => (
              <DropdownMenuItem
                key={value}
                className="min-w-0"
                onSelect={() => {
                  onSelect(editor, value);
                  if (focusEditor) editor.tf.focus();
                  setOpen(false);
                }}
              >
                {icon}
                {label}
              </DropdownMenuItem>
            ))}
          </ToolbarMenuGroup>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

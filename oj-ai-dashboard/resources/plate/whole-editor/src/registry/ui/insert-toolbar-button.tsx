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

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/registry/ui/dropdown-menu';
import {
  insertBlock,
  insertInlineElement,
} from '@/registry/components/editor/transforms';

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
    group: '기본 요소',
    items: [
      {
        icon: <PilcrowIcon />,
        label: '본문',
        value: KEYS.p,
      },
      {
        icon: <Heading1Icon />,
        label: '제목 1',
        value: 'h1',
      },
      {
        icon: <Heading2Icon />,
        label: '제목 2',
        value: 'h2',
      },
      {
        icon: <Heading3Icon />,
        label: '제목 3',
        value: 'h3',
      },
      {
        icon: <TableIcon />,
        label: '표',
        value: KEYS.table,
      },
      {
        icon: <FileCodeIcon />,
        label: '코드',
        value: KEYS.codeBlock,
      },
      {
        icon: <QuoteIcon />,
        label: '인용',
        value: KEYS.blockquote,
      },
      {
        icon: <LightbulbIcon />,
        label: '강조 상자',
        value: KEYS.callout,
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
        label: '글머리 목록',
        value: KEYS.ulClassic,
      },
      {
        icon: <ListOrderedIcon />,
        label: '번호 목록',
        value: KEYS.olClassic,
      },
      {
        icon: <SquareIcon />,
        label: '할 일 목록',
        value: KEYS.taskList,
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
        label: '외부 콘텐츠',
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
    group: '고급 요소',
    items: [
      {
        icon: <TableOfContentsIcon />,
        label: '목차',
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
        label: '그리기',
        value: KEYS.excalidraw,
      },
      {
        icon: <Code2 />,
        label: '코드 도식',
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
    group: '문장 요소',
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
        label: '문장 수식',
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
        <ToolbarButton className="min-w-[76px]" pressed={open} tooltip="삽입" isDropdown>
          <PlusIcon />
          <span>삽입</span>
        </ToolbarButton>
      </DropdownMenuTrigger>

      <DropdownMenuContent
        className="flex max-h-[500px] min-w-0 flex-col overflow-y-auto"
        align="start"
      >
        {groups.map(({ group, items: nestedItems }) => (
          <ToolbarMenuGroup key={group} label={group}>
            {nestedItems.map(({ icon, label, value, onSelect }) => (
              <DropdownMenuItem
                key={value}
                className="min-w-[180px]"
                onSelect={() => {
                  onSelect(editor, value);
                  editor.tf.focus();
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

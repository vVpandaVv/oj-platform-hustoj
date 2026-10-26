'use client';

import * as React from 'react';

import {
  CircleIcon,
  FileUpIcon,
  HashIcon,
  ImageIcon,
  ListIcon,
  PenToolIcon,
  PilcrowIcon,
  PlusIcon,
  SquareIcon,
  TableIcon,
} from 'lucide-react';

import type { ResponseFieldKind } from '@/types';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/registry/ui/dropdown-menu';

import { ToolbarButton } from './toolbar';

const responseGroups: Array<{
  label: string;
  items: Array<{ icon: React.ReactNode; kind: ResponseFieldKind; label: string }>;
}> = [
  {
    label: '글로 답하기',
    items: [
      { icon: <PilcrowIcon />, kind: 'short_text', label: '단답형' },
      { icon: <PilcrowIcon />, kind: 'long_text', label: '서술형' },
      { icon: <HashIcon />, kind: 'number', label: '숫자' },
    ],
  },
  {
    label: '선택해서 답하기',
    items: [
      { icon: <CircleIcon />, kind: 'single_choice', label: '단일 선택' },
      { icon: <ListIcon />, kind: 'multiple_choice', label: '복수 선택' },
      { icon: <SquareIcon />, kind: 'checkbox', label: '확인 체크' },
    ],
  },
  {
    label: '구조화된 답변',
    items: [
      { icon: <TableIcon />, kind: 'table', label: '표 입력' },
      { icon: <PenToolIcon />, kind: 'drawing', label: '그림 응답' },
    ],
  },
  {
    label: '파일로 답하기',
    items: [
      { icon: <ImageIcon />, kind: 'image_upload', label: '이미지 첨부' },
      { icon: <FileUpIcon />, kind: 'file_upload', label: '파일 첨부' },
    ],
  },
];

export function ResponseToolbarButton() {
  const [open, setOpen] = React.useState(false);

  const insertResponse = (kind: ResponseFieldKind) => {
    window.dispatchEvent(new CustomEvent('ojai-plate-response-insert', {
      detail: { kind },
    }));
  };

  return (
    <DropdownMenu open={open} onOpenChange={setOpen} modal={false}>
      <DropdownMenuTrigger asChild>
        <ToolbarButton
          className="hws-response-toolbar-button"
          pressed={open}
          tooltip="응답 문항 추가"
          isDropdown
        >
          <PlusIcon />
          <span>응답</span>
        </ToolbarButton>
      </DropdownMenuTrigger>

      <DropdownMenuContent
        className="hws-response-menu min-w-[190px]"
        align="start"
      >
        {responseGroups.map((group, groupIndex) => (
          <React.Fragment key={group.label}>
            {groupIndex > 0 && <DropdownMenuSeparator />}
            <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
            {group.items.map((item) => (
              <DropdownMenuItem
                key={item.kind}
                data-response-kind={item.kind}
                onSelect={() => insertResponse(item.kind)}
              >
                {item.icon}
                {item.label}
              </DropdownMenuItem>
            ))}
          </React.Fragment>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

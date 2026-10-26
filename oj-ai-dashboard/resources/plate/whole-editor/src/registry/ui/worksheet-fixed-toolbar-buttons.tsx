'use client';

import * as React from 'react';

import {
  BoldIcon,
  ItalicIcon,
  StrikethroughIcon,
  UnderlineIcon,
} from 'lucide-react';
import { KEYS } from 'platejs';
import { useEditorReadOnly } from 'platejs/react';

import { AlignToolbarButton } from './align-toolbar-button';
import { RedoToolbarButton, UndoToolbarButton } from './history-toolbar-button';
import { InsertToolbarButton } from './insert-toolbar-button';
import { LinkToolbarButton } from './link-toolbar-button';
import {
  BulletedListToolbarButton,
  NumberedListToolbarButton,
} from './list-toolbar-button';
import { MarkToolbarButton } from './mark-toolbar-button';
import { ResponseToolbarButton } from './response-toolbar-button';
import { ToolbarGroup } from './toolbar';
import { TurnIntoToolbarButton } from './turn-into-toolbar-button';
import { TableSizeToolbarButton } from './table-size-toolbar-button';

export function WorksheetFixedToolbarButtons({
  showResponseMenu = true,
}: {
  showResponseMenu?: boolean;
}) {
  const readOnly = useEditorReadOnly();

  if (readOnly) {
    return null;
  }

  return (
    <div className="hws-worksheet-toolbar flex w-full">
      <ToolbarGroup>
        <UndoToolbarButton tooltip="실행 취소" />
        <RedoToolbarButton tooltip="다시 실행" />
      </ToolbarGroup>

      <ToolbarGroup>
        <TurnIntoToolbarButton />
      </ToolbarGroup>

      <ToolbarGroup>
        <MarkToolbarButton nodeType={KEYS.bold} tooltip="굵게">
          <BoldIcon />
        </MarkToolbarButton>
        <MarkToolbarButton nodeType={KEYS.italic} tooltip="기울임">
          <ItalicIcon />
        </MarkToolbarButton>
        <MarkToolbarButton nodeType={KEYS.underline} tooltip="밑줄">
          <UnderlineIcon />
        </MarkToolbarButton>
        <MarkToolbarButton nodeType={KEYS.strikethrough} tooltip="취소선">
          <StrikethroughIcon />
        </MarkToolbarButton>
      </ToolbarGroup>

      <ToolbarGroup>
        <AlignToolbarButton />
        <BulletedListToolbarButton />
        <NumberedListToolbarButton />
        <LinkToolbarButton />
      </ToolbarGroup>

      <ToolbarGroup>
        <InsertToolbarButton />
        <TableSizeToolbarButton />
        {showResponseMenu && <ResponseToolbarButton />}
      </ToolbarGroup>
    </div>
  );
}

'use client';

import * as React from 'react';

import {
  ArrowUpToLineIcon,
  BaselineIcon,
  BoldIcon,
  Code2Icon,
  HighlighterIcon,
  ItalicIcon,
  PaintBucketIcon,
  Settings2Icon,
  StrikethroughIcon,
  UnderlineIcon,
  WandSparklesIcon,
} from 'lucide-react';
import { KEYS } from 'platejs';

import { AIToolbarButton } from './ai-toolbar-button';
import { AlignToolbarButton } from './align-toolbar-button';
import { CommentToolbarButton } from './comment-toolbar-button';
import { EmojiToolbarButton } from './emoji-toolbar-button';
import { ExportToolbarButton } from './export-toolbar-button';
import { FontColorToolbarButton } from './font-color-toolbar-button';
import { FontSizeToolbarButton } from './font-size-toolbar-button';
import { RedoToolbarButton, UndoToolbarButton } from './history-toolbar-button';
import { ImportToolbarButton } from './import-toolbar-button';
import {
  IndentToolbarButton,
  OutdentToolbarButton,
} from './indent-toolbar-button';
import { InsertToolbarButton } from './insert-toolbar-button';
import { LineHeightToolbarButton } from './line-height-toolbar-button';
import { LinkToolbarButton } from './link-toolbar-button';
import {
  BulletedListToolbarButton,
  NumberedListToolbarButton,
  TodoListToolbarButton,
} from './list-toolbar-button';
import { MarkToolbarButton } from './mark-toolbar-button';
import { MediaToolbarButton } from './media-toolbar-button';
import { ModeToolbarButton } from './mode-toolbar-button';
import { MoreToolbarButton } from './more-toolbar-button';
import { TableToolbarButton } from './table-toolbar-button';
import { ToggleToolbarButton } from './toggle-toolbar-button';
import { ToolbarButton, ToolbarGroup } from './toolbar';
import { TurnIntoToolbarButton } from './turn-into-toolbar-button';

export function FixedToolbarButtons() {
  const [showMoreTools, setShowMoreTools] = React.useState(false);

  return (
    <div className="w-full min-w-0" data-contents-editor-priority-toolbar="true">
      <div className="flex min-w-0 items-center gap-0.5">
        <ToolbarGroup>
          <UndoToolbarButton />
          <span className="hidden sm:contents">
            <RedoToolbarButton />
          </span>
        </ToolbarGroup>

        <ToolbarGroup>
          <InsertToolbarButton />
          <TurnIntoToolbarButton />
        </ToolbarGroup>

        <ToolbarGroup>
          <MarkToolbarButton nodeType={KEYS.bold} tooltip="굵게 (Ctrl+B)">
            <BoldIcon />
          </MarkToolbarButton>
          <span className="hidden sm:contents">
            <MarkToolbarButton nodeType={KEYS.italic} tooltip="기울임 (Ctrl+I)">
              <ItalicIcon />
            </MarkToolbarButton>
          </span>
        </ToolbarGroup>

        <div className="min-w-0 grow" />

        <ToolbarGroup>
          <ToolbarButton
            aria-expanded={showMoreTools}
            aria-controls="contents-editor-secondary-toolbar"
            pressed={showMoreTools}
            tooltip={showMoreTools ? '도구 접기' : '도구 더보기'}
            onClick={() => setShowMoreTools((current) => !current)}
          >
            <Settings2Icon />
          </ToolbarButton>
        </ToolbarGroup>
      </div>

      {showMoreTools ? (
        <div
          id="contents-editor-secondary-toolbar"
          className="mt-1 flex w-full flex-wrap items-center gap-y-1 border-t border-slate-200 pt-1"
          data-contents-editor-secondary-toolbar="true"
        >
          <ToolbarGroup className="sm:hidden">
            <RedoToolbarButton />
            <MarkToolbarButton nodeType={KEYS.italic} tooltip="기울임 (Ctrl+I)">
              <ItalicIcon />
            </MarkToolbarButton>
          </ToolbarGroup>

          <ToolbarGroup>
            <AIToolbarButton tooltip="AI 글쓰기 도구">
              <WandSparklesIcon />
            </AIToolbarButton>
          </ToolbarGroup>

          <ToolbarGroup>
            <ExportToolbarButton>
              <ArrowUpToLineIcon />
            </ExportToolbarButton>
            <ImportToolbarButton />
          </ToolbarGroup>

          <ToolbarGroup>
            <FontSizeToolbarButton />
          </ToolbarGroup>

          <ToolbarGroup>
            <MarkToolbarButton nodeType={KEYS.underline} tooltip="밑줄 (Ctrl+U)">
              <UnderlineIcon />
            </MarkToolbarButton>
            <MarkToolbarButton nodeType={KEYS.strikethrough} tooltip="취소선">
              <StrikethroughIcon />
            </MarkToolbarButton>
            <MarkToolbarButton nodeType={KEYS.code} tooltip="인라인 코드">
              <Code2Icon />
            </MarkToolbarButton>
            <FontColorToolbarButton nodeType={KEYS.color} tooltip="글자색">
              <BaselineIcon />
            </FontColorToolbarButton>
            <FontColorToolbarButton nodeType={KEYS.backgroundColor} tooltip="배경색">
              <PaintBucketIcon />
            </FontColorToolbarButton>
          </ToolbarGroup>

          <ToolbarGroup>
            <AlignToolbarButton />
            <NumberedListToolbarButton />
            <BulletedListToolbarButton />
            <TodoListToolbarButton />
            <ToggleToolbarButton />
          </ToolbarGroup>

          <ToolbarGroup>
            <LinkToolbarButton />
            <TableToolbarButton />
            <EmojiToolbarButton />
          </ToolbarGroup>

          <ToolbarGroup>
            <MediaToolbarButton nodeType={KEYS.img} />
            <MediaToolbarButton nodeType={KEYS.video} />
            <MediaToolbarButton nodeType={KEYS.audio} />
            <MediaToolbarButton nodeType={KEYS.file} />
          </ToolbarGroup>

          <ToolbarGroup>
            <LineHeightToolbarButton />
            <OutdentToolbarButton />
            <IndentToolbarButton />
          </ToolbarGroup>

          <ToolbarGroup>
            <MoreToolbarButton />
            <MarkToolbarButton nodeType={KEYS.highlight} tooltip="형광펜">
              <HighlighterIcon />
            </MarkToolbarButton>
            <CommentToolbarButton />
          </ToolbarGroup>

          <ToolbarGroup>
            <ModeToolbarButton />
          </ToolbarGroup>
        </div>
      ) : null}
    </div>
  );
}

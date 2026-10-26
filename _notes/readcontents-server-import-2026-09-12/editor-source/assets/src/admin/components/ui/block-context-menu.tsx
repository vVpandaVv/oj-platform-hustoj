'use client';

import * as React from 'react';

import { AIChatPlugin } from '@platejs/ai/react';
import {
  BLOCK_CONTEXT_MENU_ID,
  BlockMenuPlugin,
  BlockSelectionPlugin,
} from '@platejs/selection/react';
import { KEYS } from 'platejs';
import {
  useEditorPlugin,
  useEditorReadOnly,
  usePluginOption,
} from 'platejs/react';

import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuGroup,
  ContextMenuItem,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { setBlockType } from '@/components/transforms';
import { useIsTouchDevice } from '@/hooks/use-is-touch-device';

type Value = 'askAI' | null;

export function BlockContextMenu({ children }: { children: React.ReactNode }) {
  const { api, editor } = useEditorPlugin(BlockMenuPlugin);
  const [value, setValue] = React.useState<Value>(null);
  const isTouch = useIsTouchDevice();
  const readOnly = useEditorReadOnly();
  const openId = usePluginOption(BlockMenuPlugin, 'openId');
  const isOpen = openId === BLOCK_CONTEXT_MENU_ID;

  const handleTurnInto = React.useCallback(
    (type: string) => {
      editor
        .getApi(BlockSelectionPlugin)
        .blockSelection.getNodes()
        .forEach(([, path]) => {
          setBlockType(editor, type, { at: path });
        });
    },
    [editor]
  );

  const handleAlign = React.useCallback(
    (align: 'center' | 'left' | 'right') => {
      editor
        .getTransforms(BlockSelectionPlugin)
        .blockSelection.setNodes({ align });
    },
    [editor]
  );

  if (isTouch) {
    return children;
  }

  return (
    <ContextMenu
      onOpenChange={(open) => {
        if (!open) {
          api.blockMenu.hide();
        }
      }}
      modal={false}
    >
      <ContextMenuTrigger
        asChild
        onContextMenu={(event) => {
          const dataset = (event.target as HTMLElement).dataset;
          const disabled =
            dataset?.slateEditor === 'true' ||
            readOnly ||
            dataset?.plateOpenContextMenu === 'false';

          if (disabled) return event.preventDefault();

          setTimeout(() => {
            api.blockMenu.show(BLOCK_CONTEXT_MENU_ID, {
              x: event.clientX,
              y: event.clientY,
            });
          }, 0);
        }}
      >
        <div className="w-full">{children}</div>
      </ContextMenuTrigger>
      {isOpen && (
        <ContextMenuContent
          className="w-64"
          onCloseAutoFocus={(e) => {
            e.preventDefault();
            editor.getApi(BlockSelectionPlugin).blockSelection.focus();

            if (value === 'askAI') {
              editor.getApi(AIChatPlugin).aiChat.show();
            }

            setValue(null);
          }}
        >
          <ContextMenuGroup>
            <ContextMenuItem
              onClick={() => {
                setValue('askAI');
              }}
            >
              AI에게 요청
            </ContextMenuItem>
            <ContextMenuItem
              onClick={() => {
                editor
                  .getTransforms(BlockSelectionPlugin)
                  .blockSelection.removeNodes();
                editor.tf.focus();
              }}
            >
              삭제
            </ContextMenuItem>
            <ContextMenuItem
              onClick={() => {
                editor
                  .getTransforms(BlockSelectionPlugin)
                  .blockSelection.duplicate();
              }}
            >
              복제
              {/* <ContextMenuShortcut>⌘ + D</ContextMenuShortcut> */}
            </ContextMenuItem>
            <ContextMenuSub>
              <ContextMenuSubTrigger>블록 변환</ContextMenuSubTrigger>
              <ContextMenuSubContent className="w-48">
                <ContextMenuItem onClick={() => handleTurnInto(KEYS.p)}>
                  본문
                </ContextMenuItem>

                <ContextMenuItem onClick={() => handleTurnInto(KEYS.h1)}>
                  큰 제목
                </ContextMenuItem>
                <ContextMenuItem onClick={() => handleTurnInto(KEYS.h2)}>
                  중간 제목
                </ContextMenuItem>
                <ContextMenuItem onClick={() => handleTurnInto(KEYS.h3)}>
                  작은 제목
                </ContextMenuItem>
                <ContextMenuItem
                  onClick={() => handleTurnInto(KEYS.blockquote)}
                >
                  인용문
                </ContextMenuItem>
                <ContextMenuItem
                  onClick={() => handleTurnInto(KEYS.codeDrawing)}
                >
                  코드 다이어그램
                </ContextMenuItem>
              </ContextMenuSubContent>
            </ContextMenuSub>
          </ContextMenuGroup>

          <ContextMenuGroup>
            <ContextMenuItem
              onClick={() =>
                editor
                  .getTransforms(BlockSelectionPlugin)
                  .blockSelection.setIndent(1)
              }
            >
              들여쓰기
            </ContextMenuItem>
            <ContextMenuItem
              onClick={() =>
                editor
                  .getTransforms(BlockSelectionPlugin)
                  .blockSelection.setIndent(-1)
              }
            >
              내어쓰기
            </ContextMenuItem>
            <ContextMenuSub>
              <ContextMenuSubTrigger>정렬</ContextMenuSubTrigger>
              <ContextMenuSubContent className="w-48">
                <ContextMenuItem onClick={() => handleAlign('left')}>
                  왼쪽
                </ContextMenuItem>
                <ContextMenuItem onClick={() => handleAlign('center')}>
                  가운데
                </ContextMenuItem>
                <ContextMenuItem onClick={() => handleAlign('right')}>
                  오른쪽
                </ContextMenuItem>
              </ContextMenuSubContent>
            </ContextMenuSub>
          </ContextMenuGroup>
        </ContextMenuContent>
      )}
    </ContextMenu>
  );
}

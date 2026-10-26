'use client';

import * as React from 'react';

import type { WithRequiredKey } from 'platejs';

import {
  FloatingMedia as FloatingMediaPrimitive,
  FloatingMediaStore,
  useFloatingMediaValue,
  useImagePreviewValue,
} from '@platejs/media/react';
import { cva } from 'class-variance-authority';
import { Link, TextQuote, Trash2Icon } from 'lucide-react';
import {
  useEditorRef,
  useEditorSelector,
  useElement,
  useFocusedLast,
  useReadOnly,
  useRemoveNodeButton,
  useSelected,
} from 'platejs/react';

import { Button, buttonVariants } from '@/components/ui/button';
import {
  Popover,
  PopoverAnchor,
  PopoverContent,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';

import { CaptionButton } from './caption';

const inputVariants = cva(
  'flex h-[28px] w-full rounded-md border-none bg-transparent px-1.5 py-1 text-base placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-transparent md:text-sm'
);

type MediaKind = 'audio' | 'embed' | 'file' | 'image' | 'video';

const MEDIA_LABELS: Record<MediaKind, string> = {
  audio: '오디오',
  embed: '외부 콘텐츠',
  file: '파일',
  image: '이미지',
  video: '영상',
};

export function MediaToolbar({
  children,
  kind,
  plugin,
}: {
  children: React.ReactNode;
  kind: MediaKind;
  plugin: WithRequiredKey;
}) {
  const editor = useEditorRef();
  const mediaLabel = MEDIA_LABELS[kind];
  const readOnly = useReadOnly();
  const selected = useSelected();
  const isFocusedLast = useFocusedLast();
  const selectionCollapsed = useEditorSelector(
    (editor) => !editor.api.isExpanded(),
    []
  );
  const isImagePreviewOpen = useImagePreviewValue('isOpen', editor.id);
  const open =
    isFocusedLast &&
    !readOnly &&
    selected &&
    selectionCollapsed &&
    !isImagePreviewOpen;
  const isEditing = useFloatingMediaValue('isEditing');

  React.useEffect(() => {
    if (!open && isEditing) {
      FloatingMediaStore.set('isEditing', false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const element = useElement();
  const { props: buttonProps } = useRemoveNodeButton({ element });

  return (
    <Popover open={open} modal={false}>
      <PopoverAnchor>{children}</PopoverAnchor>

      <PopoverContent
        align="center"
        className="z-[2147483000] w-auto p-1"
        data-media-context-menu={kind}
        side="top"
        sideOffset={8}
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        {isEditing ? (
          <div className="flex w-[330px] flex-col">
            <div className="flex items-center">
              <div className="flex items-center pr-1 pl-2 text-muted-foreground">
                <Link className="size-4" />
              </div>

              <FloatingMediaPrimitive.UrlInput
                className={inputVariants()}
                aria-label={`${mediaLabel} 주소`}
                placeholder={`${mediaLabel} 주소를 입력하세요.`}
                options={{ plugin }}
              />
            </div>
          </div>
        ) : (
          <div className="box-content flex items-center">
            <FloatingMediaPrimitive.EditButton
              className={buttonVariants({ size: 'sm', variant: 'ghost' })}
            >
              <Link className="size-4" />
              주소 변경
            </FloatingMediaPrimitive.EditButton>

            <CaptionButton size="sm" variant="ghost">
              <TextQuote className="size-4" />
              설명
            </CaptionButton>

            <Separator orientation="vertical" className="mx-1 h-6" />

            <Button
              aria-label={`${mediaLabel} 삭제`}
              size="icon"
              title={`${mediaLabel} 삭제`}
              variant="ghost"
              {...buttonProps}
            >
              <Trash2Icon className="size-4" />
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}

'use client';

import * as React from 'react';

import type { TAudioElement } from 'platejs';
import type { PlateElementProps } from 'platejs/react';

import { AudioPlugin, useMediaState } from '@platejs/media/react';
import { ResizableProvider } from '@platejs/resizable';
import { PlateElement, withHOC } from 'platejs/react';

import { cn } from '@/lib/utils';

import { Caption, CaptionTextarea } from './caption';
import { MediaToolbar } from './media-toolbar';

export const AudioElement = withHOC(
  ResizableProvider,
  function AudioElement(props: PlateElementProps<TAudioElement>) {
    const { align = 'center', focused, readOnly, selected, unsafeUrl } =
      useMediaState();

    return (
      <MediaToolbar kind="audio" plugin={AudioPlugin}>
        <PlateElement {...props} className="mb-1">
        <figure
          className="group relative cursor-default"
          contentEditable={false}
        >
          <div
            className={cn(
              'h-16 rounded-sm',
              focused && selected && 'ring-2 ring-ring ring-offset-2'
            )}
          >
            <audio className="size-full" src={unsafeUrl} controls />
          </div>

          <Caption style={{ width: '100%' }} align={align}>
            <CaptionTextarea
              className="h-20"
              readOnly={readOnly}
              placeholder="오디오 설명을 입력하세요."
            />
          </Caption>
        </figure>
          {props.children}
        </PlateElement>
      </MediaToolbar>
    );
  }
);

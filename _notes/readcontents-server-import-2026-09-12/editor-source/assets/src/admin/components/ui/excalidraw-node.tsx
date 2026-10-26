'use client';

import * as React from 'react';

import type { TExcalidrawElement } from '@platejs/excalidraw';
import type { PlateElementProps } from 'platejs/react';

import { useExcalidrawElement } from '@platejs/excalidraw/react';
import {
  PlateElement,
  useElement,
  useReadOnly,
  useSelected,
} from 'platejs/react';

import { cn } from '@/lib/utils';

export function ExcalidrawElement(
  props: PlateElementProps<TExcalidrawElement>
) {
  const { children } = props;
  const element = useElement<TExcalidrawElement>();
  const readOnly = useReadOnly();
  const selected = useSelected();
  const { Excalidraw, excalidrawProps } = useExcalidrawElement({ element });

  return (
    <PlateElement {...props}>
      <div
        contentEditable={false}
        className={cn(
          'my-4 overflow-hidden rounded-lg border bg-white shadow-sm',
          selected && !readOnly ? 'ring-2 ring-ring ring-offset-2' : null
        )}
      >
        <div className="border-b bg-muted/60 px-3 py-2 text-muted-foreground text-xs">
          Excalidraw
        </div>
        <div className="h-[520px] min-h-[360px] w-full">
          {Excalidraw ? (
            <Excalidraw {...excalidrawProps} />
          ) : (
            <div className="flex h-full items-center justify-center text-muted-foreground text-sm">
              Drawing editor is loading...
            </div>
          )}
        </div>
      </div>
      {children}
    </PlateElement>
  );
}

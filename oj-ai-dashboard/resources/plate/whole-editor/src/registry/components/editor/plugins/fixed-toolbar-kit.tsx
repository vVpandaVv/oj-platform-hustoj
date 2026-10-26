'use client';

import { createPlatePlugin } from 'platejs/react';

import { FixedToolbar } from '@/registry/ui/fixed-toolbar';
import { WorksheetFixedToolbarButtons } from '@/registry/ui/worksheet-fixed-toolbar-buttons';

export function createFixedToolbarPlugin({
  showResponseMenu = true,
}: {
  showResponseMenu?: boolean;
} = {}) {
  return createPlatePlugin({
    key: 'fixed-toolbar',
    render: {
      beforeEditable: () => (
        <FixedToolbar>
          <WorksheetFixedToolbarButtons showResponseMenu={showResponseMenu} />
        </FixedToolbar>
      ),
    },
  });
}

export const FixedToolbarKit = [
  createFixedToolbarPlugin(),
];


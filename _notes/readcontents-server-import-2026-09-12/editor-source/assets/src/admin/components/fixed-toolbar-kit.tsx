'use client';

import { createPlatePlugin } from 'platejs/react';

import { FixedToolbar } from '@/components/ui/fixed-toolbar';
import { FixedToolbarButtons } from '@/components/ui/fixed-toolbar-buttons';

export const FixedToolbarKit = [
  createPlatePlugin({
    key: 'fixed-toolbar',
    render: {
      beforeEditable: () => (
        <FixedToolbar
          aria-label="콘텐츠 편집 메뉴"
          className="min-h-12 rounded-none border-x border-t border-slate-200 bg-white"
          data-contents-editor-fixed-toolbar="true"
        >
          <FixedToolbarButtons />
        </FixedToolbar>
      ),
    },
  }),
];

'use client';

import * as React from 'react';

import {
  useListToolbarButton,
  useListToolbarButtonState,
} from '@platejs/list-classic/react';
import { List, ListOrdered, ListTodoIcon } from 'lucide-react';
import { KEYS } from 'platejs';

import { ToolbarButton } from './toolbar';

// NOTE: @platejs/list-classic only supports a single bullet/number style per
// list type (no circle/square/roman/alpha variants like the flat
// @platejs/list model did), so these are plain toggle buttons rather than
// split buttons with a style dropdown.
export function BulletedListToolbarButton() {
  const state = useListToolbarButtonState({ nodeType: KEYS.ulClassic });
  const { props } = useListToolbarButton(state);

  return (
    <ToolbarButton {...props} tooltip="글머리 목록">
      <List className="size-4" />
    </ToolbarButton>
  );
}

export function NumberedListToolbarButton() {
  const state = useListToolbarButtonState({ nodeType: KEYS.olClassic });
  const { props } = useListToolbarButton(state);

  return (
    <ToolbarButton {...props} tooltip="번호 목록">
      <ListOrdered className="size-4" />
    </ToolbarButton>
  );
}

export function TodoListToolbarButton(
  props: React.ComponentProps<typeof ToolbarButton>
) {
  const state = useListToolbarButtonState({ nodeType: KEYS.taskList });
  const { props: buttonProps } = useListToolbarButton(state);

  return (
    <ToolbarButton {...props} {...buttonProps} tooltip="할 일 목록">
      <ListTodoIcon />
    </ToolbarButton>
  );
}

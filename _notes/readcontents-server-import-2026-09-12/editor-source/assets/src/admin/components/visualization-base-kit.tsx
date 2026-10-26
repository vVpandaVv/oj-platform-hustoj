'use client';

import { createPlatePlugin } from 'platejs/react';

import { VisualizationElement } from '@/components/ui/visualization-node';
import { VISUALIZATION_BLOCK_TYPE } from '@/visualization/visualization-block';

export const VisualizationPlugin = createPlatePlugin({
  key: VISUALIZATION_BLOCK_TYPE,
  node: {
    component: VisualizationElement,
    isElement: true,
    isVoid: true,
  },
});

export const VisualizationKit = [VisualizationPlugin];

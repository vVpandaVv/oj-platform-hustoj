'use client';

import { createPlatePlugin } from 'platejs/react';

import { QuizElement } from '@/components/ui/quiz-node';
import { QUIZ_BLOCK_TYPE } from '@/quiz/quiz-block';

export const QuizPlugin = createPlatePlugin({
  key: QUIZ_BLOCK_TYPE,
  node: {
    component: QuizElement,
    isElement: true,
    isVoid: true,
  },
});

export const QuizKit = [QuizPlugin];

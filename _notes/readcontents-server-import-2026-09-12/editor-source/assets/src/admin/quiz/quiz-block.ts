'use client';

export const QUIZ_BLOCK_TYPE = 'quiz';

export type QuizChoice = {
  id: string;
  text: string;
};

export type QuizData = {
  choices: QuizChoice[];
  correctChoiceId: string;
  explanation: string;
  feedbackCorrect?: string;
  feedbackIncorrect?: string;
  question: string;
};

export type TQuizElement = {
  children: [{ text: '' }];
  data: QuizData;
  type: typeof QUIZ_BLOCK_TYPE;
};

export function createQuizData(input: Partial<QuizData> = {}): QuizData {
  const choices =
    input.choices && input.choices.length >= 2
      ? input.choices
      : [
          { id: 'choice-1', text: '첫 번째 선택지' },
          { id: 'choice-2', text: '두 번째 선택지' },
          { id: 'choice-3', text: '세 번째 선택지' },
        ];

  return {
    choices,
    correctChoiceId: input.correctChoiceId ?? choices[0]?.id ?? 'choice-1',
    explanation:
      input.explanation ??
      '정답이 되는 이유를 학습자가 이해할 수 있게 설명하세요.',
    feedbackCorrect: input.feedbackCorrect ?? '정답입니다.',
    feedbackIncorrect: input.feedbackIncorrect ?? '다시 생각해 보세요.',
    question: input.question ?? '질문을 입력하세요.',
  };
}

export function createQuizBlock(input: Partial<QuizData> = {}): TQuizElement {
  return {
    children: [{ text: '' }],
    data: createQuizData(input),
    type: QUIZ_BLOCK_TYPE,
  };
}

export type PlateValue = Array<Record<string, unknown>>;

export function createLessonValue(title: string): PlateValue {
  return [
    {
      children: [{ text: title }],
      type: "h1",
    },
    {
      children: [
        {
          text: "이곳에 레슨 본문을 작성하세요. Enter로 새 문단을 만들고, / 명령으로 Plate 블록을 추가할 수 있습니다.",
        },
      ],
      type: "p",
    },
  ];
}

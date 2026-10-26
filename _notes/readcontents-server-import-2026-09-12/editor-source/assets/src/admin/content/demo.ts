import type { ContentNode, ContentNodeType, ContentTree } from "./types";

let nextDemoId = 100;

export function createNode(
  type: ContentNodeType,
  title: string,
  parentId: string | null,
  order = 0,
): ContentNode {
  const id = `demo-${nextDemoId++}`;

  return {
    id,
    slug: title
      .toLowerCase()
      .replace(/[^a-z0-9가-힣]+/gi, "-")
      .replace(/(^-|-$)/g, ""),
    title,
    type,
    status: "draft",
    parentId,
    order,
    contentType: "default",
    schemaVersion: "1.0.0",
    metadata: {},
  };
}

export function createDemoTree(): ContentTree[] {
  const topic: ContentNode = {
    id: "demo-topic-1",
    slug: "algorithm",
    title: "알고리즘",
    type: "topic",
    status: "draft",
    parentId: null,
    order: 0,
    contentType: "algorithm",
    schemaVersion: "1.0.0",
    metadata: {},
  };
  const chapter: ContentNode = {
    id: "demo-chapter-1",
    slug: "basic",
    title: "기초 개념",
    type: "chapter",
    status: "draft",
    parentId: topic.id,
    order: 0,
    contentType: "algorithm",
    schemaVersion: "1.0.0",
    metadata: {},
  };
  const section: ContentNode = {
    id: "demo-section-1",
    slug: "definition",
    title: "정의와 표현",
    type: "section",
    status: "draft",
    parentId: chapter.id,
    order: 0,
    contentType: "algorithm",
    schemaVersion: "1.0.0",
    metadata: {},
  };
  const lesson: ContentNode = {
    id: "demo-lesson-1",
    slug: "algorithm-definition",
    title: "알고리즘의 정의",
    type: "lesson",
    status: "draft",
    parentId: section.id,
    order: 0,
    contentType: "algorithm",
    schemaVersion: "1.0.0",
    metadata: {},
  };

  return [
    {
      topic,
      chapters: [
        {
          chapter,
          sections: [
            {
              section,
              lessons: [lesson],
            },
          ],
        },
      ],
    },
  ];
}

export function findFirstLesson(trees: ContentTree[]): ContentNode | null {
  for (const tree of trees) {
    for (const chapter of tree.chapters) {
      for (const section of chapter.sections) {
        if (section.lessons[0]) {
          return section.lessons[0];
        }
      }
    }
  }

  return null;
}

export function findNode(trees: ContentTree[], id: string | null): ContentNode | null {
  if (!id) {
    return null;
  }

  for (const tree of trees) {
    if (tree.topic.id === id) {
      return tree.topic;
    }

    for (const chapter of tree.chapters) {
      if (chapter.chapter.id === id) {
        return chapter.chapter;
      }

      for (const section of chapter.sections) {
        if (section.section.id === id) {
          return section.section;
        }

        for (const lesson of section.lessons) {
          if (lesson.id === id) {
            return lesson;
          }
        }
      }
    }
  }

  return null;
}

export function insertDemoNode(
  trees: ContentTree[],
  type: ContentNodeType,
  parentId: string | null,
  title: string,
): { node: ContentNode; trees: ContentTree[] } {
  const node = createNode(type, title, parentId);

  if (type === "topic") {
    return {
      node,
      trees: [...trees, { topic: node, chapters: [] }],
    };
  }

  return {
    node,
    trees: trees.map((tree) => {
      if (type === "chapter" && tree.topic.id === parentId) {
        return {
          ...tree,
          chapters: [...tree.chapters, { chapter: node, sections: [] }],
        };
      }

      return {
        ...tree,
        chapters: tree.chapters.map((chapter) => {
          if (type === "section" && chapter.chapter.id === parentId) {
            return {
              ...chapter,
              sections: [...chapter.sections, { section: node, lessons: [] }],
            };
          }

          return {
            ...chapter,
            sections: chapter.sections.map((section) => {
              if (type === "lesson" && section.section.id === parentId) {
                return {
                  ...section,
                  lessons: [...section.lessons, node],
                };
              }

              return section;
            }),
          };
        }),
      };
    }),
  };
}

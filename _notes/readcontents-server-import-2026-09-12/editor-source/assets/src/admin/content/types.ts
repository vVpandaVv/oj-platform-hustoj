export type ContentNodeType = "topic" | "chapter" | "section" | "lesson";

export type ContentStatus = "draft" | "review" | "approved" | "published" | "archived";

export type ContentNode = {
  id: string;
  slug: string;
  title: string;
  type: ContentNodeType;
  status: ContentStatus;
  parentId: string | null;
  order: number;
  contentType: string;
  schemaVersion: string;
  metadata: Record<string, unknown>;
};

export type LessonAssetKind = "image" | "video" | "audio" | "file" | "embed";

export type LessonAsset = {
  alt?: string;
  caption?: string;
  id: string;
  kind: LessonAssetKind;
  license?: string;
  source?: string;
  title: string;
  url: string;
};

export type LessonDocument = {
  lessonId: string;
  schemaVersion: string;
  revision: number;
  plateValue: Array<Record<string, unknown>>;
  assets: LessonAsset[];
  visuals: Array<Record<string, unknown>>;
};

export type ContentTree = {
  topic: ContentNode;
  chapters: Array<ContentTreeChapter>;
};

export type ContentTreeChapter = {
  chapter: ContentNode;
  sections: Array<ContentTreeSection>;
};

export type ContentTreeSection = {
  section: ContentNode;
  lessons: Array<ContentNode>;
};

export type ContentPackageScope = "topic" | "chapter" | "section" | "lesson";

export type ContentPackage = {
  exportedAt: string;
  kind: "contents-editor-package" | "contents-editor-topic";
  lessonDocuments: Record<string, LessonDocument>;
  packageVersion: string;
  rootNode?: ContentNode;
  scope?: ContentPackageScope;
  tree: ContentTree;
};

export type ContentImportLessonConflictPolicy = "create_new" | "replace" | "skip";

export type ContentImportLessonConflictPolicyMap = Record<string, ContentImportLessonConflictPolicy>;

export type PackageImportConflict = {
  existing: ContentNode;
  existingSummary?: string;
  incoming: ContentNode;
  incomingSummary?: string;
  matchBy: "slug" | "title";
  path: string[];
  type: "lesson";
};

export type PackageImportPreviewResult = {
  assetsDetected?: number;
  conflicts: PackageImportConflict[];
  created: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  reused: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  topic: ContentNode;
};

export type PackageImportResult = {
  assetMap?: Record<string, string>;
  assetsImported?: number;
  conflicts?: PackageImportConflict[];
  created: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  idMap: Record<string, string>;
  replaced?: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  reused?: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  skipped?: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  topic: ContentNode;
};

export type ContentDeleteResult = {
  deleted: {
    chapters: number;
    lessons: number;
    sections: number;
    topics: number;
  };
  node: ContentNode;
};

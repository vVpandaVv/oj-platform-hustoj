import * as React from "react";
import {
  Archive,
  ArrowDown,
  ArrowUp,
  BookOpen,
  CheckSquare2,
  ChevronRight,
  Copy,
  Download,
  Eye,
  FileCode2,
  FileJson2,
  GripVertical,
  Library,
  ListFilter,
  Menu,
  MoreHorizontal,
  PackageOpen,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  Search,
  ShieldCheck,
  Trash2,
  X,
  RotateCcw,
} from "lucide-react";

import { PlateEditor } from "@/components/plate-editor";
import { createLessonValue, type PlateValue } from "@/components/plate-value";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { createQuizBlock } from "@/quiz/quiz-block";

import { ContentApiError, contentApi, hasWordPressRest, isOverUploadLimit, uploadLimitLabel } from "./api";
import { ServerImportPanel } from "./ServerImportPanel";
import { OutlineResizeHandle } from "./OutlineResizeHandle";
import {
  createDemoTree,
  findFirstLesson,
  insertDemoNode,
} from "./demo";
import type {
  ContentNode,
  ContentStatus,
  ContentImportLessonConflictPolicy,
  ContentImportLessonConflictPolicyMap,
  ContentNodeType,
  ContentPackage,
  ContentPackageScope,
  ContentTree,
  LessonAsset,
  LessonDocument,
  PackageImportPreviewResult,
  PackageImportResult,
} from "./types";

const nodeLabels: Record<ContentNodeType, string> = {
  topic: "주제",
  chapter: "챕터",
  section: "장",
  lesson: "레슨",
};

const contentStatusLabels: Record<ContentStatus, string> = {
  draft: "초안",
  review: "검토",
  approved: "승인",
  published: "공개",
  archived: "보관",
};

const editableContentStatuses: ContentStatus[] = ["draft", "review", "approved", "published"];
const bulkContentStatuses: ContentStatus[] = ["draft", "review", "approved", "published"];

type AddTarget = {
  parentId: string | null;
  type: ContentNodeType;
};

type OutlineStatusFilter = "all" | ContentStatus;

type OutlineDropPosition = "after" | "before" | "inside";

type OutlineDropTarget = {
  nodeId: string;
  position: OutlineDropPosition;
};

type OutlineInlineEditor =
  | {
      anchorNodeId: string;
      depth: number;
      insertIndex: number;
      mode: "create";
      parentId: string;
      type: Exclude<ContentNodeType, "topic">;
      value: string;
    }
  | {
      anchorNodeId: string;
      depth: number;
      mode: "rename";
      node: ContentNode;
      value: string;
    };

type OutlineRow = {
  ancestorIds: string[];
  childCount: number;
  depth: number;
  hasChildren: boolean;
  node: ContentNode;
  parentId: string | null;
  siblingIndex: number;
  siblingCount: number;
};

type LocalLessonDocument = Pick<
  LessonDocument,
  "assets" | "lessonId" | "plateValue" | "revision" | "schemaVersion" | "visuals"
>;

type LessonSaveStatus = "idle" | "saving" | "saved" | "blocked" | "error" | "conflict";

type LessonSaveReason = "automatic" | "manual" | "navigation";

type LessonRecoveryDraft = LocalLessonDocument & {
  savedAt: number;
};

type LessonSnapshotInput = {
  assets: LessonAsset[];
  plateValue: PlateValue;
  visuals: Array<Record<string, unknown>>;
};

type QualityCheck = {
  help: string;
  key: string;
  label: string;
  passed: boolean;
  required: boolean;
};

const assetKindLabels: Record<LessonAsset["kind"], string> = {
  audio: "오디오",
  embed: "임베드",
  file: "파일",
  image: "이미지",
  video: "영상",
};

type WorkflowTemplate = "concept" | "example" | "quiz";

type EditorErrorBoundaryProps = {
  children: React.ReactNode;
};

type EditorErrorBoundaryState = {
  error: Error | null;
};

type ContentSelectionPath = {
  chapter?: ContentTree["chapters"][number];
  lesson?: ContentNode;
  section?: ContentTree["chapters"][number]["sections"][number];
  tree: ContentTree;
};

type ContentSelection = {
  lessonId: string | null;
  topicId: string | null;
};

const packageScopeLabels: Record<ContentPackageScope, string> = {
  topic: "전체 주제",
  chapter: "현재 챕터",
  section: "현재 장",
  lesson: "현재 레슨",
};

type WorkspaceView = "library" | "outline" | "lesson";
type WorkspaceDrawerView = "archive" | "package" | "publish" | "quality" | "source";

const workspaceViewLabels: Record<WorkspaceView, string> = {
  lesson: "레슨 작성",
  library: "콘텐츠 라이브러리",
  outline: "목차 편집",
};

const workspaceDrawerLabels: Record<WorkspaceDrawerView, string> = {
  archive: "보관함",
  package: "패키지 가져오기/내보내기",
  publish: "공개 설정",
  quality: "작성 및 품질 확인",
  source: "레슨 소스",
};

type ImportResultSummary = {
  assetsImported: number | null;
  created: PackageImportResult["created"];
  fileName: string;
  id: string;
  importedAt: string;
  label: string;
  mappedItems: number;
  replaced: PackageImportResult["created"];
  reused: PackageImportResult["created"];
  selectedLessonId: string | null;
  skipped: PackageImportResult["created"];
  topic: Pick<ContentNode, "id" | "slug" | "title">;
};

type PendingPackageImport = {
  file?: File;
  fileName: string;
  isZip: boolean;
  packageData?: ContentPackage;
  preview: PackageImportPreviewResult;
};

type ImportPreviewProblem = {
  fileName: string;
  message: string;
};

function requirePendingZipFile(pendingImport: PendingPackageImport): File {
  if (!pendingImport.file) {
    throw new Error("가져올 ZIP 패키지 파일을 찾지 못했습니다.");
  }

  return pendingImport.file;
}

function requirePendingJsonPackage(pendingImport: PendingPackageImport): ContentPackage {
  if (!pendingImport.packageData) {
    throw new Error("가져올 JSON 패키지 정보를 찾지 못했습니다.");
  }

  return pendingImport.packageData;
}

function packageImportNotice(
  label: string,
  result: Pick<PackageImportResult, "assetsImported" | "created" | "replaced" | "reused" | "skipped">,
): string {
  const createdCounts = normalizePackageCounters(result.created);
  const reused = normalizePackageCounters(result.reused);
  const replaced = normalizePackageCounters(result.replaced);
  const skipped = normalizePackageCounters(result.skipped);
  const created = `새로 만든 항목: 주제 ${createdCounts.topics}개, 챕터 ${createdCounts.chapters}개, 장 ${createdCounts.sections}개, 레슨 ${createdCounts.lessons}개`;
  const reusedText = `기존 위치 재사용: 주제 ${reused.topics}개, 챕터 ${reused.chapters}개, 장 ${reused.sections}개`;
  const conflictText = replaced.lessons || skipped.lessons
    ? `, 대체한 레슨 ${replaced.lessons}개, 건너뛴 레슨 ${skipped.lessons}개`
    : "";
  const assetText =
    typeof result.assetsImported === "number"
      ? `, 미디어 ${result.assetsImported}개 등록`
      : "";

  return `${label} 가져왔습니다. ${created}. ${reusedText}${conflictText}${assetText}.`;
}

function emptyPackageCounters(): PackageImportResult["created"] {
  return {
    chapters: 0,
    lessons: 0,
    sections: 0,
    topics: 0,
  };
}

function normalizePackageCounters(
  counters: Partial<PackageImportResult["created"]> | null | undefined,
): PackageImportResult["created"] {
  const empty = emptyPackageCounters();

  if (!counters || typeof counters !== "object") {
    return empty;
  }

  return {
    chapters: toSafeCount(counters.chapters),
    lessons: toSafeCount(counters.lessons),
    sections: toSafeCount(counters.sections),
    topics: toSafeCount(counters.topics),
  };
}

function importPreviewNotice(preview: PackageImportPreviewResult, label: string): string {
  const conflicts = Array.isArray(preview.conflicts) ? preview.conflicts.length : 0;
  const assetsText =
    typeof preview.assetsDetected === "number"
      ? ` 미디어 파일 ${preview.assetsDetected}개가 포함되어 있습니다.`
      : "";

  if (conflicts > 0) {
    return `${label}에서 기존 레슨과 중복되는 항목 ${conflicts}개를 찾았습니다. 아래 비교 내용을 보고 가져오기 방식을 선택하세요.${assetsText}`;
  }

  return `${label}을 확인했습니다. 중복 레슨은 없습니다. 아래 확인 버튼을 누르면 가져옵니다.${assetsText}`;
}

function createClientPackageImportPreview(
  packageData: ContentPackage,
  currentTrees: ContentTree[],
): PackageImportPreviewResult {
  if (!packageData.tree?.topic) {
    throw new Error("패키지에 목차 정보가 없습니다.");
  }

  const created = emptyPackageCounters();
  const reused = emptyPackageCounters();
  const conflicts: PackageImportPreviewResult["conflicts"] = [];
  const sourceTopic = packageData.tree.topic;
  const topicMatch = findMatchingNodeWithReason(
    sourceTopic,
    currentTrees.map((tree) => tree.topic),
  );
  const topicTree = topicMatch
    ? currentTrees.find((tree) => tree.topic.id === topicMatch.node.id) ?? null
    : null;

  if (topicMatch) {
    reused.topics += 1;
  } else {
    created.topics += 1;
  }

  for (const chapterGroup of packageData.tree.chapters ?? []) {
    const sourceChapter = chapterGroup.chapter;
    const chapterMatch = topicTree
      ? findMatchingNodeWithReason(
          sourceChapter,
          topicTree.chapters.map((chapter) => chapter.chapter),
        )
      : null;
    const chapterTree = chapterMatch
      ? topicTree?.chapters.find((chapter) => chapter.chapter.id === chapterMatch.node.id) ?? null
      : null;

    if (chapterMatch) {
      reused.chapters += 1;
    } else {
      created.chapters += 1;
    }

    for (const sectionGroup of chapterGroup.sections ?? []) {
      const sourceSection = sectionGroup.section;
      const sectionMatch = chapterTree
        ? findMatchingNodeWithReason(
            sourceSection,
            chapterTree.sections.map((section) => section.section),
          )
        : null;
      const sectionTree = sectionMatch
        ? chapterTree?.sections.find((section) => section.section.id === sectionMatch.node.id) ?? null
        : null;

      if (sectionMatch) {
        reused.sections += 1;
      } else {
        created.sections += 1;
      }

      for (const sourceLesson of sectionGroup.lessons ?? []) {
        created.lessons += 1;

        if (!sectionTree) {
          continue;
        }

        const lessonMatch = findMatchingNodeWithReason(sourceLesson, sectionTree.lessons);

        if (!lessonMatch) {
          continue;
        }

        conflicts.push({
          existing: lessonMatch.node,
          existingSummary: "현재 저장된 본문은 서버 확인 후 최종 비교됩니다.",
          incoming: {
            ...sourceLesson,
            parentId: sectionTree.section.id,
            status: "draft",
          },
          incomingSummary: summarizePackageLesson(packageData, sourceLesson.id),
          matchBy: lessonMatch.matchBy,
          path: [
            sourceTopic.title,
            sourceChapter.title,
            sourceSection.title,
            sourceLesson.title,
          ],
          type: "lesson",
        });
      }
    }
  }

  return {
    conflicts,
    created,
    reused,
    topic: topicMatch?.node ?? sourceTopic,
  };
}

function buildImportConflictPolicies(
  preview: PackageImportPreviewResult,
  fallbackPolicy: ContentImportLessonConflictPolicy,
  currentPolicies: ContentImportLessonConflictPolicyMap = {},
): ContentImportLessonConflictPolicyMap {
  const policies: ContentImportLessonConflictPolicyMap = {};
  const conflicts = Array.isArray(preview.conflicts) ? preview.conflicts : [];

  for (const conflict of conflicts) {
    const sourceLessonId = String(conflict.incoming.id);

    policies[sourceLessonId] = currentPolicies[sourceLessonId] ?? fallbackPolicy;
  }

  return policies;
}

function countImportConflictPolicies(
  conflicts: PackageImportPreviewResult["conflicts"],
  policies: ContentImportLessonConflictPolicyMap,
  fallbackPolicy: ContentImportLessonConflictPolicy,
): Record<ContentImportLessonConflictPolicy, number> {
  const counts: Record<ContentImportLessonConflictPolicy, number> = {
    create_new: 0,
    replace: 0,
    skip: 0,
  };

  for (const conflict of conflicts) {
    const policy = policies[String(conflict.incoming.id)] ?? fallbackPolicy;

    counts[policy] += 1;
  }

  return counts;
}

function findMatchingNodeWithReason(
  source: Pick<ContentNode, "slug" | "title">,
  candidates: ContentNode[],
): { matchBy: "slug" | "title"; node: ContentNode } | null {
  const slug = source.slug.trim();
  const title = source.title.trim();

  if (slug) {
    const node = candidates.find((candidate) => candidate.slug === slug);

    if (node) {
      return { matchBy: "slug", node };
    }
  }

  if (title) {
    const node = candidates.find((candidate) => candidate.title === title);

    if (node) {
      return { matchBy: "title", node };
    }
  }

  return null;
}

function summarizePackageLesson(packageData: ContentPackage, lessonId: string): string {
  const document = packageData.lessonDocuments?.[lessonId];

  if (!document) {
    return "본문 요약이 없습니다.";
  }

  const text = plainTextFromPlateValue(document.plateValue);

  if (text) {
    return text.slice(0, 140);
  }

  return `본문 없음, 시각화 ${document.visuals.length}개, 미디어 ${document.assets.length}개`;
}

function plainTextFromPlateValue(value: unknown): string {
  if (typeof value === "string") {
    return value.replace(/\s+/g, " ").trim();
  }

  if (!Array.isArray(value)) {
    return "";
  }

  return value
    .map((item) => {
      if (!item || typeof item !== "object") {
        return "";
      }

      const record = item as Record<string, unknown>;

      if (typeof record.text === "string") {
        return record.text;
      }

      return plainTextFromPlateValue(record.children);
    })
    .filter(Boolean)
    .join(" ")
    .replace(/\s+/g, " ")
    .trim();
}

function toSafeCount(value: unknown): number {
  return typeof value === "number" && Number.isFinite(value) ? value : 0;
}

function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) {
    return "0B";
  }

  const units = ["B", "KB", "MB", "GB"];
  let value = bytes;
  let unitIndex = 0;

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex += 1;
  }

  return `${value.toFixed(unitIndex === 0 ? 0 : 1)}${units[unitIndex]}`;
}

function createImportResultSummary({
  fileName,
  label,
  result,
  selectedLessonId,
}: {
  fileName: string;
  label: string;
  result: PackageImportResult;
  selectedLessonId: string | null;
}): ImportResultSummary {
  return {
    assetsImported:
      typeof result.assetsImported === "number" && Number.isFinite(result.assetsImported)
        ? result.assetsImported
        : null,
    created: normalizePackageCounters(result.created),
    fileName,
    id: `${Date.now()}-${fileName}`,
    importedAt: new Date().toLocaleString(),
    label,
    mappedItems: result.idMap && typeof result.idMap === "object"
      ? Object.keys(result.idMap).length
      : 0,
    replaced: normalizePackageCounters(result.replaced),
    reused: normalizePackageCounters(result.reused),
    selectedLessonId,
    skipped: normalizePackageCounters(result.skipped),
    topic: {
      id: result.topic.id,
      slug: result.topic.slug,
      title: result.topic.title,
    },
  };
}

function requirePackageImportResult(
  result: PackageImportResult | null | undefined,
): PackageImportResult {
  if (!result || typeof result !== "object") {
    throw new Error("가져오기 결과가 비어 있습니다. 패키지는 처리되었지만 서버 응답을 확인할 수 없습니다.");
  }

  if (!result.topic || typeof result.topic !== "object") {
    throw new Error("가져오기 결과에 주제 정보가 없습니다.");
  }

  return {
    ...result,
    created: normalizePackageCounters(result.created),
    idMap: result.idMap && typeof result.idMap === "object" ? result.idMap : {},
    replaced: normalizePackageCounters(result.replaced),
    reused: normalizePackageCounters(result.reused),
    skipped: normalizePackageCounters(result.skipped),
  };
}

export function ContentWorkspace() {
  const canPublish = !hasWordPressRest() || window.contentsEditorAdmin?.canPublish === true;
  const canReimportJson = canPublish && window.contentsEditorAdmin?.canReimportJson === true;
  const [initialContentTrees] = React.useState<ContentTree[]>(() =>
    hasWordPressRest() ? [] : createDemoTree(),
  );
  const [trees, setTrees] = React.useState<ContentTree[]>(() => initialContentTrees);
  const [selectedTopicId, setSelectedTopicId] = React.useState<string | null>(
    initialContentTrees[0]?.topic.id ?? null,
  );
  const [selectedLessonId, setSelectedLessonId] = React.useState<string | null>(() => {
    return findFirstLesson(initialContentTrees)?.id ?? null;
  });
  const [selectedChapterId, setSelectedChapterId] = React.useState<string | null>(() => {
    const firstLesson = findFirstLesson(initialContentTrees);

    return findSelectionPath(initialContentTrees, initialContentTrees[0]?.topic.id ?? null, firstLesson?.id ?? null)?.chapter?.chapter.id ?? null;
  });
  const [selectedSectionId, setSelectedSectionId] = React.useState<string | null>(() => {
    const firstLesson = findFirstLesson(initialContentTrees);

    return findSelectionPath(initialContentTrees, initialContentTrees[0]?.topic.id ?? null, firstLesson?.id ?? null)?.section?.section.id ?? null;
  });
  const [selectedOutlineNodeId, setSelectedOutlineNodeId] = React.useState<string | null>(
    () => findFirstLesson(initialContentTrees)?.id ?? initialContentTrees[0]?.topic.id ?? null,
  );
  const [workspaceView, setWorkspaceView] = React.useState<WorkspaceView>("library");
  const [workspaceDrawer, setWorkspaceDrawer] = React.useState<WorkspaceDrawerView | null>(null);
  const [addTarget, setAddTarget] = React.useState<AddTarget | null>(null);
  const [dirty, setDirty] = React.useState(false);
  const dirtyRef = React.useRef(false);
  const [saveStatus, setSaveStatus] = React.useState<LessonSaveStatus>("idle");
  const [documentRevision, setDocumentRevision] = React.useState<number | null>(null);
  const documentRevisionRef = React.useRef<number | null>(null);
  const [editVersion, setEditVersion] = React.useState(0);
  const [reloadRequestId, setReloadRequestId] = React.useState(0);
  const [viewMode, setViewMode] = React.useState<"editor" | "source">("editor");
  const [packageExportScope, setPackageExportScope] =
    React.useState<ContentPackageScope>("topic");
  const [editorValue, setEditorValue] = React.useState<PlateValue>(() =>
    createLessonValue("알고리즘의 정의"),
  );
  const editorValueRef = React.useRef<PlateValue>(editorValue);
  const [assets, setAssets] = React.useState<LessonAsset[]>([]);
  const [visuals, setVisuals] = React.useState<Array<Record<string, unknown>>>([]);
  const assetsRef = React.useRef(assets);
  const visualsRef = React.useRef(visuals);
  const lastSavedSnapshotRef = React.useRef("");
  const saveInFlightRef = React.useRef<Promise<boolean> | null>(null);
  const [editorResetKey, setEditorResetKey] = React.useState(0);
  const [localDocuments, setLocalDocuments] = React.useState<Record<string, LocalLessonDocument>>({});
  const treesRef = React.useRef(trees);
  const selectedTopicIdRef = React.useRef(selectedTopicId);
  const selectedLessonIdRef = React.useRef(selectedLessonId);
  const selectedOutlineNodeIdRef = React.useRef(selectedOutlineNodeId);
  const [pendingRecovery, setPendingRecovery] = React.useState<LessonRecoveryDraft | null>(null);
  const [archivedNodes, setArchivedNodes] = React.useState<ContentNode[]>([]);
  const [archivedTrees, setArchivedTrees] = React.useState<ContentTree[]>([]);
  const nodeActionRef = React.useRef(false);
  const [isNodeActionPending, setIsNodeActionPending] = React.useState(false);
  const [collapsedNodeIds, setCollapsedNodeIds] = React.useState<Set<string>>(
    () => new Set(),
  );
  const [bulkSelectedNodeIds, setBulkSelectedNodeIds] = React.useState<Set<string>>(
    () => new Set(),
  );
  const [bulkStatus, setBulkStatus] = React.useState<ContentStatus>(() =>
    canPublish ? "published" : "review",
  );
  const [isBulkUpdatingStatus, setIsBulkUpdatingStatus] = React.useState(false);
  const [newTitle, setNewTitle] = React.useState("");
  const [editingNode, setEditingNode] = React.useState<ContentNode | null>(null);
  const [editTitle, setEditTitle] = React.useState("");
  const [editStatus, setEditStatus] = React.useState<ContentStatus>("draft");
  const [sourceError, setSourceError] = React.useState<string | null>(null);
  const [sourceText, setSourceText] = React.useState("");
  const [lastImportResult, setLastImportResult] = React.useState<ImportResultSummary | null>(null);
  const [pendingImport, setPendingImport] = React.useState<PendingPackageImport | null>(null);
  const [isPreviewingPackage, setIsPreviewingPackage] = React.useState(false);
  const [importPreviewProblem, setImportPreviewProblem] =
    React.useState<ImportPreviewProblem | null>(null);
  const [importConflictPolicy, setImportConflictPolicy] =
    React.useState<ContentImportLessonConflictPolicy>("create_new");
  const [importConflictPolicies, setImportConflictPolicies] =
    React.useState<ContentImportLessonConflictPolicyMap>({});
  const [isImportingPackage, setIsImportingPackage] = React.useState(false);
  const [isServerImportActive, setIsServerImportActive] = React.useState(false);
  const packageInputRef = React.useRef<HTMLInputElement>(null);
  const [notice, setNotice] = React.useState(
    hasWordPressRest()
      ? "WordPress 저장소에 연결합니다."
      : "프리뷰 모드입니다. WordPress 밖에서는 데모 목차로 동작합니다.",
  );

  const selectionPath = findSelectionPath(trees, selectedTopicId, selectedLessonId);
  const selectedTopicTree = selectionPath?.tree ?? null;
  const selectedLesson = selectionPath?.lesson ?? null;
  const visibleArchivedNodes = hasWordPressRest()
    ? archivedNodes
    : archivedTrees.flatMap(flattenContentTreeNodes).filter((node) => node.status === "archived");
  const selectedTopic = selectedTopicTree?.topic ?? null;
  const publicBaseUrl = (window.contentsEditorAdmin?.publicBaseUrl ?? "").replace(/\/+$/, "");
  const selectedTopicPublicUrl = selectedTopic && publicBaseUrl ? publicBaseUrl : "";
  const selectedLessonPublicUrl =
    selectedLesson && selectedLesson.type === "lesson" && publicBaseUrl
      ? `${publicBaseUrl}/${encodeURIComponent(selectedLesson.slug)}`
      : "";
  const availablePackageScopes = getAvailablePackageScopes(selectionPath);
  const documentAssets = React.useMemo(
    () => collectDocumentAssets(editorValue, assets),
    [assets, editorValue],
  );
  const qualityChecks = React.useMemo(
    () =>
      buildQualityChecks({
        assets: documentAssets,
        plateValue: editorValue,
        title: selectedLesson?.title,
      }),
    [documentAssets, editorValue, selectedLesson?.title],
  );

  React.useEffect(() => {
    editorValueRef.current = editorValue;
  }, [editorValue]);

  React.useEffect(() => {
    treesRef.current = trees;
    selectedTopicIdRef.current = selectedTopicId;
    selectedLessonIdRef.current = selectedLessonId;
    selectedOutlineNodeIdRef.current = selectedOutlineNodeId;
    assetsRef.current = assets;
    visualsRef.current = visuals;
  }, [assets, selectedLessonId, selectedOutlineNodeId, selectedTopicId, trees, visuals]);

  React.useEffect(() => {
    if (!hasWordPressRest()) {
      return;
    }

    void refreshTree();
  }, []);

  React.useEffect(() => {
    if (!selectionPath) {
      return;
    }

    const visibleAncestorIds = [
      selectionPath.tree.topic.id,
      selectionPath.chapter?.chapter.id,
      selectionPath.section?.section.id,
    ].filter((id): id is string => Boolean(id));

    setCollapsedNodeIds((current) => {
      let changed = false;
      const next = new Set(current);

      for (const id of visibleAncestorIds) {
        if (next.delete(id)) {
          changed = true;
        }
      }

      return changed ? next : current;
    });
  }, [
    selectionPath?.tree.topic.id,
    selectionPath?.chapter?.chapter.id,
    selectionPath?.section?.section.id,
  ]);

  React.useEffect(() => {
    if (!selectedTopicTree) {
      setBulkSelectedNodeIds(new Set());
      return;
    }

    const availableIds = new Set(flattenContentTreeNodes(selectedTopicTree).map((node) => node.id));

    setBulkSelectedNodeIds((current) => {
      const next = new Set(Array.from(current).filter((nodeId) => availableIds.has(nodeId)));

      return next.size === current.size ? current : next;
    });
  }, [selectedTopicTree]);

  function setDocumentDirty(nextDirty: boolean) {
    dirtyRef.current = nextDirty;
    setDirty(nextDirty);
  }

  function setCurrentDocumentRevision(revision: number | null) {
    documentRevisionRef.current = revision;
    setDocumentRevision(revision);
  }

  function applyDocumentToEditor(
    document: LocalLessonDocument,
    options: { allowRecovery: boolean } = { allowRecovery: true },
  ): "document" | "recovered" | "recovery-pending" {
    const baseValue = document.plateValue as PlateValue;
    const baseAssets = collectDocumentAssets(
      baseValue,
      normalizeAssets(document.assets),
    );
    const baseVisuals = readRecordArray(document.visuals);
    const baseSnapshot = lessonSnapshot({
      assets: baseAssets,
      plateValue: baseValue,
      visuals: baseVisuals,
    });
    const recovery = options.allowRecovery
      ? readLessonRecovery(document.lessonId)
      : null;
    const shouldRecover = Boolean(recovery && recovery.revision === document.revision);
    const nextValue = shouldRecover && recovery ? recovery.plateValue : baseValue;
    const nextAssets = collectDocumentAssets(
      nextValue,
      shouldRecover && recovery ? recovery.assets : baseAssets,
    );
    const nextVisuals = shouldRecover && recovery ? recovery.visuals : baseVisuals;
    const nextSnapshot = lessonSnapshot({
      assets: nextAssets,
      plateValue: nextValue,
      visuals: nextVisuals,
    });

    editorValueRef.current = nextValue;
    assetsRef.current = nextAssets;
    visualsRef.current = nextVisuals;
    lastSavedSnapshotRef.current = baseSnapshot;
    setCurrentDocumentRevision(document.revision);
    setEditorValue(nextValue);
    setAssets(nextAssets);
    setVisuals(nextVisuals);
    setPendingRecovery(recovery && !shouldRecover ? recovery : null);
    setDocumentDirty(nextSnapshot !== baseSnapshot);
    setSaveStatus(nextSnapshot !== baseSnapshot ? "idle" : "saved");
    setViewMode("editor");
    setEditorResetKey((current) => current + 1);
    setEditVersion((current) => current + 1);

    if (shouldRecover) {
      return "recovered";
    }

    return recovery ? "recovery-pending" : "document";
  }

  React.useEffect(() => {
    if (!selectedLesson || selectedLesson.type !== "lesson") {
      setPendingRecovery(null);
      setCurrentDocumentRevision(null);
      setDocumentDirty(false);
      setSaveStatus("idle");
      return;
    }

    const lesson = selectedLesson;
    let cancelled = false;

    async function loadLessonDocument() {
      setPendingRecovery(null);
      setCurrentDocumentRevision(null);
      setDocumentDirty(false);
      setSaveStatus("idle");

      if (!hasWordPressRest()) {
        const localDocument = localDocuments[lesson.id] ?? {
          assets: [],
          lessonId: lesson.id,
          plateValue: createLessonValue(lesson.title),
          revision: 0,
          schemaVersion: "1.0.0",
          visuals: [],
        };
        const result = applyDocumentToEditor(localDocument);

        if (result === "recovered") {
          setNotice("저장되지 않은 로컬 복구본을 불러왔습니다. 자동 저장을 다시 시도합니다.");
        } else if (result === "recovery-pending") {
          setNotice("현재 문서와 다른 로컬 복구본이 있습니다. 내용을 확인해 복원하거나 버리세요.");
        }
        return;
      }

      try {
        const document = await contentApi.getLessonDocument(lesson.id);

        if (!cancelled) {
          const result = applyDocumentToEditor(document);

          if (result === "recovered") {
            setNotice("저장되지 않은 로컬 복구본을 불러왔습니다. 자동 저장을 다시 시도합니다.");
          } else if (result === "recovery-pending") {
            setNotice("서버 최신본과 다른 로컬 복구본이 있습니다. 복구본을 열지 선택하세요.");
          } else {
            setNotice("레슨 문서를 불러왔습니다.");
          }
        }
      } catch (error) {
        if (!cancelled) {
          const recovery = readLessonRecovery(lesson.id);
          const fallbackValue = recovery?.plateValue ?? createLessonValue(lesson.title);
          const fallbackAssets = recovery?.assets ?? [];
          const fallbackVisuals = recovery?.visuals ?? [];

          editorValueRef.current = fallbackValue;
          assetsRef.current = fallbackAssets;
          visualsRef.current = fallbackVisuals;
          setEditorValue(fallbackValue);
          setAssets(fallbackAssets);
          setVisuals(fallbackVisuals);
          setDocumentDirty(Boolean(recovery));
          setSaveStatus("error");
          setEditorResetKey((current) => current + 1);
          setNotice(
            recovery
              ? "서버 문서를 불러오지 못해 로컬 복구본을 열었습니다. 연결이 복구된 뒤 다시 시도하세요."
              : error instanceof Error
                ? error.message
                : "레슨 문서를 불러오지 못했습니다.",
          );
        }
      }
    }

    void loadLessonDocument();

    return () => {
      cancelled = true;
    };
  }, [localDocuments, reloadRequestId, selectedLesson?.id]);

  function applyContentSelection(selection: ContentSelection) {
    selectedTopicIdRef.current = selection.topicId;
    selectedLessonIdRef.current = selection.lessonId;
    setSelectedTopicId(selection.topicId);
    setSelectedLessonId(selection.lessonId);
  }

  function selectOutlineNode(node: ContentNode, sourceTrees = treesRef.current) {
    const path = findNodeSelectionPath(sourceTrees, node.id);

    if (!path) {
      setNotice("선택한 목차 항목을 찾지 못했습니다.");
      return;
    }

    selectedTopicIdRef.current = path.tree.topic.id;
    selectedOutlineNodeIdRef.current = node.id;
    setSelectedTopicId(path.tree.topic.id);
    setSelectedOutlineNodeId(node.id);
    setSelectedChapterId(path.chapter?.chapter.id ?? null);
    setSelectedSectionId(path.section?.section.id ?? null);

    if (path.lesson) {
      selectedLessonIdRef.current = path.lesson.id;
      setSelectedLessonId(path.lesson.id);
    }
  }

  function selectLessonPath(lessonId: string, sourceTrees = treesRef.current): boolean {
    const path = findNodeSelectionPath(sourceTrees, lessonId);

    if (!path?.lesson) {
      return false;
    }

    selectedTopicIdRef.current = path.tree.topic.id;
    selectedLessonIdRef.current = path.lesson.id;
    selectedOutlineNodeIdRef.current = path.lesson.id;
    setSelectedTopicId(path.tree.topic.id);
    setSelectedChapterId(path.chapter?.chapter.id ?? null);
    setSelectedSectionId(path.section?.section.id ?? null);
    setSelectedLessonId(path.lesson.id);
    setSelectedOutlineNodeId(path.lesson.id);

    return true;
  }

  async function refreshTree(
    preferred: Partial<ContentSelection> = {},
    throwOnError = false,
  ): Promise<ContentTree[]> {
    try {
      const [nextTrees, nextArchivedNodes] = await Promise.all([
        contentApi.listTopics(true),
        contentApi.listArchivedNodes(),
      ]);

      const preferredTopicId = Object.prototype.hasOwnProperty.call(preferred, "topicId")
        ? preferred.topicId ?? null
        : selectedTopicIdRef.current;
      const preferredLessonId = Object.prototype.hasOwnProperty.call(preferred, "lessonId")
        ? preferred.lessonId ?? null
        : selectedLessonIdRef.current;
      const preferredOutlineNodeId = selectedOutlineNodeIdRef.current;
      const nextSelection = normalizeContentSelection(
        nextTrees,
        preferredTopicId,
        preferredLessonId,
      );

      treesRef.current = nextTrees;
      setTrees(nextTrees);
      setArchivedNodes(nextArchivedNodes);
      applyContentSelection(nextSelection);

      const nextOutlineNode = findContentNodeById(nextTrees, preferredOutlineNodeId);

      if (nextOutlineNode) {
        selectOutlineNode(nextOutlineNode, nextTrees);
      } else if (nextSelection.lessonId) {
        selectLessonPath(nextSelection.lessonId, nextTrees);
      } else {
        const nextTopic = findContentNodeById(nextTrees, nextSelection.topicId);

        if (nextTopic) {
          selectOutlineNode(nextTopic, nextTrees);
        }
      }
      setNotice("WordPress 저장소와 연결되었습니다.");
      return nextTrees;
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "목차를 불러오지 못했습니다.");
      if (throwOnError) throw error;
      return treesRef.current;
    }
  }

  async function createOutlineNode(input: {
    insertIndex: number;
    parentId: string;
    title: string;
    type: Exclude<ContentNodeType, "topic">;
  }): Promise<ContentNode | null> {
    const title = input.title.trim();

    if (!title) {
      return null;
    }

    const siblings = outlineSiblingNodes(treesRef.current, input.type, input.parentId);
    const insertIndex = Math.max(0, Math.min(input.insertIndex, siblings.length));

    try {
      let createdNode: ContentNode;

      if (hasWordPressRest()) {
        const appendOrder = siblings.reduce((highest, sibling) => Math.max(highest, sibling.order), 0) + 10;

        createdNode = await contentApi.createNode({
          order: insertIndex === siblings.length ? appendOrder : (insertIndex + 1) * 10,
          parentId: input.parentId,
          title,
          type: input.type,
        });

        if (insertIndex < siblings.length) {
          const orderedSiblings = [...siblings];
          orderedSiblings.splice(insertIndex, 0, createdNode);

          for (const [index, sibling] of orderedSiblings.entries()) {
            await contentApi.updateNode(sibling.id, {
              order: (index + 1) * 10,
              parentId: input.parentId,
            });
          }
        }

        selectedOutlineNodeIdRef.current = createdNode.id;
        await refreshTree({
          lessonId: createdNode.type === "lesson" ? createdNode.id : selectedLessonIdRef.current,
          topicId: selectedTopicIdRef.current,
        });
      } else {
        const inserted = insertDemoNode(
          treesRef.current,
          input.type,
          input.parentId,
          title,
        );
        const nextTrees = moveNodeInTrees(
          inserted.trees,
          inserted.node.id,
          input.parentId,
          insertIndex,
        );

        createdNode = findContentNodeById(nextTrees, inserted.node.id) ?? inserted.node;
        treesRef.current = nextTrees;
        setTrees(nextTrees);
        selectOutlineNode(createdNode, nextTrees);
      }

      setNotice(`${nodeLabels[input.type]} "${title}" 항목을 추가했습니다.`);
      return createdNode;
    } catch (error) {
      if (hasWordPressRest()) {
        await refreshTree();
      }
      setNotice(error instanceof Error ? error.message : "목차를 추가하지 못했습니다.");
      return null;
    }
  }

  async function renameOutlineNode(node: ContentNode, nextTitle: string): Promise<boolean> {
    const title = nextTitle.trim();

    if (!title) {
      setNotice("목차 이름을 입력하세요.");
      return false;
    }

    if (title === node.title) {
      return true;
    }

    try {
      if (hasWordPressRest()) {
        selectedOutlineNodeIdRef.current = node.id;
        await contentApi.updateNode(node.id, { title });
        await refreshTree();
      } else {
        const nextTrees = updateNodeInTrees(treesRef.current, node.id, { title });
        const nextNode = findContentNodeById(nextTrees, node.id);

        treesRef.current = nextTrees;
        setTrees(nextTrees);
        if (nextNode) {
          selectOutlineNode(nextNode, nextTrees);
        }
      }

      setNotice(`${nodeLabels[node.type]} 이름을 "${title}"(으)로 바꿨습니다.`);
      return true;
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "목차 이름을 바꾸지 못했습니다.");
      return false;
    }
  }

  async function moveOutlineNode(
    node: ContentNode,
    targetParentId: string,
    targetIndex: number,
  ): Promise<boolean> {
    const nextTrees = moveNodeInTrees(
      treesRef.current,
      node.id,
      targetParentId,
      targetIndex,
    );
    const movedNode = findContentNodeById(nextTrees, node.id);

    if (!movedNode) {
      setNotice("이동할 목차 항목을 찾지 못했습니다.");
      return false;
    }

    const destinationSiblings = outlineSiblingNodes(nextTrees, node.type, targetParentId);

    try {
      if (hasWordPressRest()) {
        for (const sibling of destinationSiblings) {
          await contentApi.updateNode(sibling.id, {
            order: sibling.order,
            parentId: targetParentId,
          });
        }

        selectedOutlineNodeIdRef.current = node.id;
        await refreshTree({
          lessonId: node.type === "lesson" ? node.id : selectedLessonIdRef.current,
          topicId: selectedTopicIdRef.current,
        });
      } else {
        treesRef.current = nextTrees;
        setTrees(nextTrees);
        selectOutlineNode(movedNode, nextTrees);
      }

      setNotice(`${nodeLabels[node.type]} "${node.title}" 항목을 이동했습니다.`);
      return true;
    } catch (error) {
      if (hasWordPressRest()) {
        await refreshTree();
      }
      setNotice(error instanceof Error ? error.message : "목차를 이동하지 못했습니다.");
      return false;
    }
  }

  async function changeOutlineNodeStatus(
    node: ContentNode,
    status: ContentStatus,
  ): Promise<boolean> {
    if (
      !canPublish
      && node.status !== status
      && (node.status === "published" || status === "published")
    ) {
      setNotice("공개 상태를 변경하려면 읽기자료 공개 권한이 필요합니다.");
      return false;
    }

    try {
      if (hasWordPressRest()) {
        selectedOutlineNodeIdRef.current = node.id;
        await contentApi.updateNode(node.id, { status });
        await refreshTree();
      } else {
        const nextTrees = updateNodeInTrees(treesRef.current, node.id, { status });
        const nextNode = findContentNodeById(nextTrees, node.id);

        treesRef.current = nextTrees;
        setTrees(nextTrees);
        if (nextNode) {
          selectOutlineNode(nextNode, nextTrees);
        }
      }

      setNotice(`"${node.title}" 항목을 ${contentStatusLabels[status]} 상태로 변경했습니다.`);
      return true;
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "공개 상태를 변경하지 못했습니다.");
      return false;
    }
  }

  async function exportOutlineNode(node: ContentNode): Promise<void> {
    const path = findNodeSelectionPath(treesRef.current, node.id);

    if (!path) {
      setNotice("내보낼 목차 항목을 찾지 못했습니다.");
      return;
    }

    try {
      const scope = node.type as ContentPackageScope;
      const packageData = hasWordPressRest()
        ? await contentApi.exportNodePackage(node.id)
        : createPreviewPackage(createScopedTree(path, scope), localDocuments, scope, node);

      downloadJson(
        packageData,
        `${safeFileName(node.slug || node.title)}.${scope}.contents-editor.json`,
      );
      setNotice(`${nodeLabels[node.type]} "${node.title}" 패키지를 내보냈습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "콘텐츠 패키지를 내보내지 못했습니다.");
    }
  }

  async function duplicateOutlineNode(node: ContentNode): Promise<ContentNode | null> {
    const path = findNodeSelectionPath(treesRef.current, node.id);

    if (!path) {
      setNotice("복제할 목차 항목을 찾지 못했습니다.");
      return null;
    }

    const copiedDocuments: Record<string, LocalLessonDocument> = {};
    let previewTrees = treesRef.current;

    const cloneNode = async (
      source: ContentNode,
      parentId: string | null,
      isRoot: boolean,
    ): Promise<ContentNode> => {
      const title = isRoot ? `${source.title} 사본` : source.title;
      let clone: ContentNode;

      if (hasWordPressRest()) {
        clone = source.type === "topic"
          ? await contentApi.createTopic(title)
          : await contentApi.createNode({
              order: source.order + 10,
              parentId: parentId as string,
              title,
              type: source.type,
            });

        if (source.type === "lesson") {
          const [sourceDocument, targetDocument] = await Promise.all([
            contentApi.getLessonDocument(source.id),
            contentApi.getLessonDocument(clone.id),
          ]);

          await contentApi.saveLessonDocument(clone.id, {
            assets: sourceDocument.assets,
            plateValue: sourceDocument.plateValue,
            revision: targetDocument.revision,
            schemaVersion: sourceDocument.schemaVersion,
            visuals: sourceDocument.visuals,
          });
        }
      } else {
        const inserted = insertDemoNode(previewTrees, source.type, parentId, title);
        clone = inserted.node;
        previewTrees = inserted.trees;

        if (source.type === "lesson") {
          const sourceDocument = localDocuments[source.id];

          copiedDocuments[clone.id] = sourceDocument
            ? { ...sourceDocument, lessonId: clone.id, revision: 1 }
            : {
                assets: [],
                lessonId: clone.id,
                plateValue: createLessonValue(title),
                revision: 1,
                schemaVersion: "1.0.0",
                visuals: [],
              };
        }
      }

      return clone;
    };

    try {
      const duplicatedRoot = await duplicateContentBranch(path, node, cloneNode);

      selectedOutlineNodeIdRef.current = duplicatedRoot.id;

      if (hasWordPressRest()) {
        await refreshTree({
          lessonId: duplicatedRoot.type === "lesson" ? duplicatedRoot.id : selectedLessonIdRef.current,
          topicId: duplicatedRoot.type === "topic" ? duplicatedRoot.id : selectedTopicIdRef.current,
        });
      } else {
        treesRef.current = previewTrees;
        setTrees(previewTrees);
        setLocalDocuments((current) => ({ ...current, ...copiedDocuments }));
        selectOutlineNode(duplicatedRoot, previewTrees);
      }

      setNotice(`${nodeLabels[node.type]} "${node.title}" 항목을 복제했습니다.`);
      return duplicatedRoot;
    } catch (error) {
      if (hasWordPressRest()) {
        await refreshTree();
      }
      setNotice(error instanceof Error ? error.message : "목차 항목을 복제하지 못했습니다.");
      return null;
    }
  }

  async function submitAddNode(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!addTarget || !newTitle.trim()) {
      return;
    }

    const title = newTitle.trim();

    try {
      if (hasWordPressRest()) {
        let createdNode: ContentNode | null = null;

        if (addTarget.type === "topic") {
          createdNode = await contentApi.createTopic(title);
        } else if (addTarget.parentId) {
          createdNode = await contentApi.createNode({
            parentId: addTarget.parentId,
            title,
            type: addTarget.type,
          });
        }

        if (createdNode?.type === "topic") {
          await refreshTree({ lessonId: null, topicId: createdNode.id });
        } else if (createdNode?.type === "lesson") {
          await refreshTree({ lessonId: createdNode.id, topicId: null });
        } else {
          await refreshTree();
        }
      } else {
        const inserted = insertDemoNode(trees, addTarget.type, addTarget.parentId, title);
        const nextSelection = normalizeContentSelection(
          inserted.trees,
          inserted.node.type === "topic" ? inserted.node.id : selectedTopicIdRef.current,
          inserted.node.type === "lesson" ? inserted.node.id : selectedLessonIdRef.current,
        );

        treesRef.current = inserted.trees;
        setTrees(inserted.trees);
        applyContentSelection(nextSelection);
      }

      setNewTitle("");
      setAddTarget(null);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "목차를 추가하지 못했습니다.");
    }
  }

  function startEditNode(node: ContentNode) {
    setEditingNode(node);
    setEditTitle(node.title);
    setEditStatus(node.status === "archived" ? "draft" : node.status);
    setAddTarget(null);
  }

  async function submitEditNode(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!editingNode || !editTitle.trim()) {
      return;
    }

    const title = editTitle.trim();

    if (
      !canPublish &&
      editingNode.status !== editStatus &&
      (editingNode.status === "published" || editStatus === "published")
    ) {
      setNotice("공개 상태를 변경하려면 읽기자료 공개 권한이 필요합니다.");
      return;
    }

    try {
      if (hasWordPressRest()) {
        await contentApi.updateNode(editingNode.id, {
          status: editStatus,
          title,
        });
        await refreshTree();
      } else {
        setTrees((current) =>
          updateNodeInTrees(current, editingNode.id, {
            status: editStatus,
            title,
          }),
        );
      }

      setEditingNode(null);
      setEditTitle("");
      setNotice(`${nodeLabels[editingNode.type]} 목차를 수정했습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "목차를 수정하지 못했습니다.");
    }
  }

  function toggleBulkNodeSelection(nodeId: string, checked: boolean) {
    setBulkSelectedNodeIds((current) => {
      const next = new Set(current);

      if (checked) {
        next.add(nodeId);
      } else {
        next.delete(nodeId);
      }

      return next;
    });
  }

  function selectBulkNodeBranch(nodeId: string, checked: boolean) {
    if (!selectedTopicTree) {
      return;
    }

    const nodeIds = descendantNodeIds(selectedTopicTree, nodeId);

    setBulkSelectedNodeIds((current) => {
      const next = new Set(current);

      for (const id of nodeIds) {
        if (checked) {
          next.add(id);
        } else {
          next.delete(id);
        }
      }

      return next;
    });
  }

  function selectAllBulkNodes() {
    if (!selectedTopicTree) {
      return;
    }

    setBulkSelectedNodeIds(new Set(flattenContentTreeNodes(selectedTopicTree).map((node) => node.id)));
  }

  async function applyBulkStatus() {
    const nodeIds = Array.from(bulkSelectedNodeIds);

    if (!nodeIds.length) {
      setNotice("상태를 변경할 주제, 챕터, 장, 레슨을 선택하세요.");
      return;
    }

    if (!canPublish && selectedTopicTree) {
      const selectedNodes = flattenContentTreeNodes(selectedTopicTree).filter((node) =>
        bulkSelectedNodeIds.has(node.id),
      );
      const changesPublishedNode = selectedNodes.some(
        (node) => node.status === "published" && bulkStatus !== "published",
      );

      if (bulkStatus === "published" || changesPublishedNode) {
        setNotice("공개 상태를 일괄 변경하려면 읽기자료 공개 권한이 필요합니다.");
        return;
      }
    }

    if (!window.confirm(`선택한 ${nodeIds.length}개 항목을 "${contentStatusLabels[bulkStatus]}" 상태로 변경할까요?`)) {
      return;
    }

    setIsBulkUpdatingStatus(true);

    try {
      if (hasWordPressRest()) {
        await contentApi.bulkUpdateNodeStatus(nodeIds, bulkStatus);
        await refreshTree();
      } else {
        setTrees((current) =>
          nodeIds.reduce(
            (nextTrees, nodeId) => updateNodeInTrees(nextTrees, nodeId, { status: bulkStatus }),
            current,
          ),
        );
      }

      setBulkSelectedNodeIds(new Set());
      setNotice(`선택한 ${nodeIds.length}개 항목을 "${contentStatusLabels[bulkStatus]}" 상태로 변경했습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "선택한 항목의 상태를 변경하지 못했습니다.");
    } finally {
      setIsBulkUpdatingStatus(false);
    }
  }

  async function publishSelectedTopicTree() {
    if (!selectedTopicTree) {
      setNotice("공개할 주제를 먼저 선택하세요.");
      return;
    }

    if (!canPublish) {
      setNotice("주제를 공개하려면 읽기자료 공개 권한이 필요합니다.");
      return;
    }

    const nodeIds = flattenContentTreeNodes(selectedTopicTree).map((node) => node.id);

    if (!window.confirm(`"${selectedTopicTree.topic.title}" 주제와 하위 ${nodeIds.length - 1}개 항목을 모두 공개 상태로 변경할까요?`)) {
      return;
    }

    setIsBulkUpdatingStatus(true);

    try {
      if (hasWordPressRest()) {
        await contentApi.bulkUpdateNodeStatus(nodeIds, "published");
        await refreshTree();
      } else {
        setTrees((current) =>
          nodeIds.reduce(
            (nextTrees, nodeId) => updateNodeInTrees(nextTrees, nodeId, { status: "published" }),
            current,
          ),
        );
      }

      setBulkSelectedNodeIds(new Set());
      setNotice(`"${selectedTopicTree.topic.title}" 주제와 하위 항목을 공개 상태로 변경했습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "주제 전체를 공개 상태로 변경하지 못했습니다.");
    } finally {
      setIsBulkUpdatingStatus(false);
    }
  }

  async function archiveContentNode(node: ContentNode) {
    if (nodeActionRef.current) return;
    if (!canPublish && node.status === "published") {
      setNotice("공개 콘텐츠를 보관하려면 읽기자료 공개 권한이 필요합니다.");
      return;
    }

    nodeActionRef.current = true;
    setIsNodeActionPending(true);
    try {
      if (!await confirmContentAction("보관함으로 이동", `${nodeLabels[node.type]} "${node.title}"을 보관할까요? 본문은 유지되며 하위 항목과 함께 학생 화면에서 숨겨집니다.`)) return;
      if (!await persistActiveLesson("navigation")) return;
      if (hasWordPressRest()) {
        await contentApi.archiveNode(node.id);
        await refreshTree({}, true);
      } else {
        const path = findNodeSelectionPath(treesRef.current, node.id);
        const archivedTree = path ? createScopedTree(path, node.type) : null;
        const nextTrees = removeNodeFromTrees(trees, node.id);

        if (archivedTree) {
          setArchivedTrees((current) => [
            ...current,
            updateNodeInTrees([archivedTree], node.id, { status: "archived" })[0],
          ]);
        }

        treesRef.current = nextTrees;
        setTrees(nextTrees);
        applyContentSelection(
          normalizeContentSelection(
            nextTrees,
            selectedTopicIdRef.current,
            selectedLessonIdRef.current,
          ),
        );
      }

      setNotice(`${nodeLabels[node.type]} 항목을 보관했습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "목차를 보관하지 못했습니다.");
    } finally {
      nodeActionRef.current = false;
      setIsNodeActionPending(false);
    }
  }

  async function deleteContentNode(node: ContentNode) {
    if (nodeActionRef.current) return;
    if (!canPublish && node.status === "published") {
      setNotice("공개 콘텐츠를 삭제하려면 읽기자료 공개 권한이 필요합니다.");
      return;
    }

    const warning =
      `${nodeLabels[node.type]} "${node.title}" 항목을 영구 삭제할까요?\n\n` +
      "보관된 항목을 포함한 모든 하위 항목과 레슨 본문도 함께 삭제됩니다. 이 작업은 되돌릴 수 없습니다.";
    nodeActionRef.current = true;
    setIsNodeActionPending(true);
    try {
      if (!await confirmContentAction("영구 삭제", warning, true)) return;
      if (!await persistActiveLesson("navigation")) return;
      let deletedSummary = "";

      if (hasWordPressRest()) {
        const result = await contentApi.deleteNode(node.id);
        const { deleted } = result;

        deletedSummary = ` 주제 ${deleted.topics}개, 챕터 ${deleted.chapters}개, 장 ${deleted.sections}개, 레슨 ${deleted.lessons}개를 삭제했습니다.`;
        await refreshTree({}, true);
      } else {
        const nextTrees = removeNodeFromTrees(trees, node.id);
        treesRef.current = nextTrees;
        setTrees(nextTrees);
        setArchivedTrees((current) => removeNodeFromTrees(current, node.id));
        applyContentSelection(
          normalizeContentSelection(
            nextTrees,
            selectedTopicIdRef.current,
            selectedLessonIdRef.current,
          ),
        );
      }

      setEditingNode((current) => (current?.id === node.id ? null : current));
      setNotice(`${nodeLabels[node.type]} 항목을 삭제했습니다.${deletedSummary}`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "목차를 삭제하지 못했습니다.");
    } finally {
      nodeActionRef.current = false;
      setIsNodeActionPending(false);
    }
  }

  async function restoreArchivedNode(node: ContentNode) {
    if (nodeActionRef.current) return;
    nodeActionRef.current = true;
    setIsNodeActionPending(true);
    try {
      if (!await confirmContentAction("초안으로 복원", `보관한 ${nodeLabels[node.type]} "${node.title}"을 초안으로 복원할까요? 상위 항목도 보관 중이면 상위 항목을 먼저 복원해야 목록에 나타납니다.`)) return;
      if (!await persistActiveLesson("navigation")) return;
      if (hasWordPressRest()) {
        await contentApi.restoreNode(node.id);
        await refreshTree({}, true);
      } else {
        const archivedTree = archivedTrees.find((tree) => flattenContentTreeNodes(tree).some((item) => item.id === node.id && item.status === "archived"));

        if (!archivedTree) {
          setNotice("복원할 보관 항목을 찾지 못했습니다.");
          return;
        }

        const nextTrees = restoreDemoBranch(treesRef.current, archivedTree, node);
        setArchivedTrees((current) => current.filter((tree) => tree !== archivedTree));
        treesRef.current = nextTrees;
        setTrees(nextTrees);
        applyContentSelection(normalizeContentSelection(nextTrees, selectedTopicIdRef.current, selectedLessonIdRef.current));
      }

      setNotice(`${nodeLabels[node.type]} "${node.title}" 항목을 초안으로 복원했습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "보관 항목을 복원하지 못했습니다.");
    } finally {
      nodeActionRef.current = false;
      setIsNodeActionPending(false);
    }
  }

  const persistActiveLesson = React.useCallback(
    async (reason: LessonSaveReason): Promise<boolean> => {
      while (dirtyRef.current) {
        if (saveInFlightRef.current) {
          const saved = await saveInFlightRef.current;

          if (!saved) {
            return false;
          }

          if (reason !== "navigation") {
            return true;
          }

          continue;
        }

        const lessonId = selectedLessonIdRef.current;
        const activeSelection = findSelectionPath(
          treesRef.current,
          selectedTopicIdRef.current,
          lessonId,
        );
        const lesson = activeSelection?.lesson;

        if (!lesson || !lessonId) {
          return true;
        }

        const currentPlateValue = editorValueRef.current;
        const currentDocumentAssets = collectDocumentAssets(
          currentPlateValue,
          assetsRef.current,
        );
        const currentVisuals = visualsRef.current;
        const currentRevision = documentRevisionRef.current;
        const currentQualityChecks = buildQualityChecks({
          assets: currentDocumentAssets,
          plateValue: currentPlateValue,
          title: lesson.title,
        });
        const failedRequiredCheck = currentQualityChecks.find(
          (check) => check.required && !check.passed,
        );
        const recoveryDraft: LessonRecoveryDraft = {
          assets: currentDocumentAssets,
          lessonId,
          plateValue: currentPlateValue,
          revision: currentRevision ?? 0,
          savedAt: Date.now(),
          schemaVersion: "1.0.0",
          visuals: currentVisuals,
        };

        if (failedRequiredCheck) {
          writeLessonRecovery(recoveryDraft);
          setSaveStatus("blocked");
          setNotice("저장 전 확인이 필요합니다: " + failedRequiredCheck.label);
          return false;
        }

        if (hasWordPressRest() && currentRevision === null) {
          writeLessonRecovery(recoveryDraft);
          setSaveStatus("error");
          setNotice("서버 문서 revision을 확인하지 못해 저장을 중단했습니다. 레슨을 다시 불러오세요.");
          return false;
        }

        const savedSnapshot = lessonSnapshot({
          assets: currentDocumentAssets,
          plateValue: currentPlateValue,
          visuals: currentVisuals,
        });
        setSaveStatus("saving");

        const request = (async (): Promise<boolean> => {
          try {
            let savedDocument: LocalLessonDocument;

            if (hasWordPressRest()) {
              savedDocument = await contentApi.saveLessonDocument(lessonId, {
                assets: currentDocumentAssets,
                plateValue: currentPlateValue,
                revision: currentRevision as number,
                schemaVersion: "1.0.0",
                visuals: currentVisuals,
              });
            } else {
              savedDocument = {
                assets: currentDocumentAssets,
                lessonId,
                plateValue: currentPlateValue,
                revision: (currentRevision ?? 0) + 1,
                schemaVersion: "1.0.0",
                visuals: currentVisuals,
              };
              setLocalDocuments((current) => ({
                ...current,
                [lessonId]: savedDocument,
              }));
            }

            if (selectedLessonIdRef.current !== lessonId) {
              removeLessonRecovery(lessonId);
              return true;
            }

            lastSavedSnapshotRef.current = savedSnapshot;
            assetsRef.current = currentDocumentAssets;
            setCurrentDocumentRevision(savedDocument.revision);
            setEditorValue(currentPlateValue);
            setAssets(currentDocumentAssets);

            const latestPlateValue = editorValueRef.current;
            const latestAssets = collectDocumentAssets(latestPlateValue, assetsRef.current);
            const latestSnapshot = lessonSnapshot({
              assets: latestAssets,
              plateValue: latestPlateValue,
              visuals: visualsRef.current,
            });
            const remainsDirty = latestSnapshot !== savedSnapshot;

            setDocumentDirty(remainsDirty);
            setSaveStatus(remainsDirty ? "idle" : "saved");

            if (remainsDirty) {
              writeLessonRecovery({
                assets: latestAssets,
                lessonId,
                plateValue: latestPlateValue,
                revision: savedDocument.revision,
                savedAt: Date.now(),
                schemaVersion: savedDocument.schemaVersion,
                visuals: visualsRef.current,
              });
            } else {
              removeLessonRecovery(lessonId);
              setPendingRecovery(null);
            }

            if (reason !== "automatic") {
              setNotice("레슨 문서를 저장했습니다.");
            }

            return true;
          } catch (error) {
            writeLessonRecovery(recoveryDraft);
            const isConflict =
              error instanceof ContentApiError
              && (error.status === 409 || error.code === "contents_editor_revision_conflict");

            setSaveStatus(isConflict ? "conflict" : "error");
            setNotice(
              isConflict
                ? "다른 창에서 이 레슨이 먼저 저장되었습니다. 현재 작업은 로컬 복구본으로 보관했습니다."
                : error instanceof Error
                  ? error.message
                  : "레슨 문서를 저장하지 못했습니다. 현재 작업은 로컬에 보관했습니다.",
            );
            return false;
          }
        })();

        saveInFlightRef.current = request;
        const saved = await request;

        if (saveInFlightRef.current === request) {
          saveInFlightRef.current = null;
        }

        if (!saved) {
          return false;
        }

        if (reason !== "navigation") {
          return true;
        }
      }

      return true;
    },
    [],
  );

  async function saveLesson() {
    await persistActiveLesson("manual");
  }

  React.useEffect(() => {
    if (!dirty || !selectedLessonId) {
      return;
    }

    const timeout = window.setTimeout(() => {
      void persistActiveLesson("automatic");
    }, 1200);

    return () => window.clearTimeout(timeout);
  }, [dirty, editVersion, persistActiveLesson, selectedLessonId]);

  React.useEffect(() => {
    if (!dirty || !selectedLessonId) {
      return;
    }

    const timeout = window.setTimeout(() => {
      writeLessonRecovery({
        assets: collectDocumentAssets(editorValueRef.current, assetsRef.current),
        lessonId: selectedLessonId,
        plateValue: editorValueRef.current,
        revision: documentRevisionRef.current ?? 0,
        savedAt: Date.now(),
        schemaVersion: "1.0.0",
        visuals: visualsRef.current,
      });
    }, 350);

    return () => window.clearTimeout(timeout);
  }, [dirty, editVersion, selectedLessonId]);

  React.useEffect(() => {
    if (!dirty) {
      return;
    }

    const warnBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };

    window.addEventListener("beforeunload", warnBeforeUnload);
    return () => window.removeEventListener("beforeunload", warnBeforeUnload);
  }, [dirty]);

  async function changeWorkspaceView(nextView: WorkspaceView): Promise<boolean> {
    if (nextView === workspaceView) {
      return true;
    }

    if (!await persistActiveLesson("navigation")) {
      setNotice("현재 레슨을 저장하지 못해 화면 이동을 중단했습니다.");
      return false;
    }

    setWorkspaceDrawer(null);
    setWorkspaceView(nextView);
    return true;
  }

  async function exportSelectedPackage() {
    const selection = selectionPath ?? (trees[0] ? { tree: trees[0] } : null);

    if (!selection) {
      setNotice("내보낼 목차를 먼저 만드세요.");
      return;
    }

    const scope = availablePackageScopes.includes(packageExportScope)
      ? packageExportScope
      : "topic";
    const targetNode = nodeForPackageScope(selection, scope);

    if (!targetNode) {
      setNotice(`${packageScopeLabels[scope]} 범위로 내보낼 항목을 찾지 못했습니다.`);
      return;
    }

    try {
      const packageData = hasWordPressRest()
        ? await contentApi.exportNodePackage(targetNode.id)
        : createPreviewPackage(
            createScopedTree(selection, scope),
            localDocuments,
            scope,
            targetNode,
          );

      downloadJson(
        packageData,
        `${safeFileName(targetNode.slug || targetNode.title)}.${scope}.contents-editor.json`,
      );
      setNotice(`${packageScopeLabels[scope]} 패키지를 내보냈습니다.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "콘텐츠 패키지를 내보내지 못했습니다.");
    }
  }

  async function importPackageFile(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) {
      return;
    }

    if (isServerImportActive) {
      setNotice("진행 중인 서버 ZIP 가져오기를 완료하거나 취소한 뒤 다른 패키지를 가져와 주세요.");
      return;
    }

    setLastImportResult(null);
    setPendingImport(null);
    setImportPreviewProblem(null);
    const initialConflictPolicy: ContentImportLessonConflictPolicy = "create_new";
    setImportConflictPolicy(initialConflictPolicy);
    setImportConflictPolicies({});
    if (!await persistActiveLesson("navigation")) {
      setNotice("현재 레슨을 저장하지 못해 패키지 가져오기를 중단했습니다.");
      return;
    }

    setWorkspaceDrawer("package");

    let showedPreviewPanel = false;

    try {
      if (isZipPackageFile(file)) {
        if (!hasWordPressRest()) {
          setNotice("ZIP 패키지는 WordPress 미디어 라이브러리에 이미지를 등록해야 하므로 WordPress 화면에서만 가져올 수 있습니다.");
          return;
        }

        if (isOverUploadLimit(file)) {
          const limit = uploadLimitLabel() ?? "현재 서버 업로드 제한";

          setNotice(
            `ZIP 파일이 서버 업로드 제한보다 큽니다. 선택한 파일은 ${formatBytes(file.size)}이고, 허용 크기는 ${limit}입니다. 서버 ZIP 가져오기를 사용하거나 장별 ZIP을 선택해 주세요.`,
          );
          return;
        }

        setIsPreviewingPackage(true);
        setNotice("ZIP 패키지의 목차와 중복 레슨을 확인하는 중입니다.");
        const preview = await contentApi.previewZipPackage(file);
        setPendingImport({
          file,
          fileName: file.name,
          isZip: true,
          preview,
        });
        setImportConflictPolicies(buildImportConflictPolicies(preview, initialConflictPolicy));
        showedPreviewPanel = true;
        setNotice(importPreviewNotice(preview, "ZIP 패키지"));
        return;
      }

      const packageData = JSON.parse(await file.text()) as ContentPackage;

      if (!hasWordPressRest()) {
        const result = importPreviewPackage(packageData);
        const selectedImportLessonId = findFirstLesson([result.tree])?.id ?? null;
        const summaryResult: PackageImportResult = {
          created: result.created,
          idMap: {},
          reused: emptyPackageCounters(),
          topic: result.tree.topic,
        };
        const nextTrees = [...treesRef.current, result.tree];

        treesRef.current = nextTrees;
        setTrees(nextTrees);
        setLocalDocuments((current) => ({ ...current, ...result.documents }));
        applyContentSelection({
          lessonId: selectedImportLessonId,
          topicId: result.tree.topic.id,
        });
        setLastImportResult(
          createImportResultSummary({
            fileName: file.name,
            label: "미리보기 JSON 패키지",
            result: summaryResult,
            selectedLessonId: selectedImportLessonId,
          }),
        );
        setNotice(packageImportNotice("미리보기 목차에 패키지를", summaryResult));
        return;
      }

      const localPreview = createClientPackageImportPreview(packageData, trees);
      setPendingImport({
        fileName: file.name,
        isZip: false,
        packageData,
        preview: localPreview,
      });
      setImportConflictPolicies(buildImportConflictPolicies(localPreview, initialConflictPolicy));
      showedPreviewPanel = true;
      setNotice(importPreviewNotice(localPreview, "JSON 패키지"));

      const preview = await contentApi.previewPackage(packageData);
      setPendingImport({
        fileName: file.name,
        isZip: false,
        packageData,
        preview,
      });
      setImportConflictPolicies((current) =>
        buildImportConflictPolicies(preview, initialConflictPolicy, current),
      );
      setNotice(importPreviewNotice(preview, "JSON 패키지"));
    } catch (error) {
      const message = error instanceof Error ? error.message : "콘텐츠 패키지를 확인하지 못했습니다.";

      if (!showedPreviewPanel) {
        setImportPreviewProblem({
          fileName: file.name,
          message,
        });
      }

      setNotice(message);
    } finally {
      setIsPreviewingPackage(false);
    }
  }

  async function confirmPendingImport() {
    if (isServerImportActive) {
      setNotice("진행 중인 서버 ZIP 가져오기를 먼저 완료하거나 취소해 주세요.");
      return;
    }
    if (!pendingImport) {
      return;
    }

    setIsImportingPackage(true);
    setLastImportResult(null);

    try {
      const lessonConflictPolicyBySourceId =
        Object.keys(importConflictPolicies).length > 0 ? importConflictPolicies : undefined;
      const rawResult = pendingImport.isZip
        ? await contentApi.importZipPackage(requirePendingZipFile(pendingImport), {
            lessonConflictPolicy: importConflictPolicy,
            lessonConflictPolicyBySourceId,
          })
        : await contentApi.importPackage(requirePendingJsonPackage(pendingImport), {
            lessonConflictPolicy: importConflictPolicy,
            lessonConflictPolicyBySourceId,
          });
      const result = requirePackageImportResult(rawResult);
      const nextTrees = await refreshTree({
        lessonId: null,
        topicId: result.topic.id,
      });
      const selectedImportLessonId = firstLessonIdForTopic(nextTrees, result.topic.id);
      applyContentSelection({
        lessonId: selectedImportLessonId,
        topicId: result.topic.id,
      });
      setLastImportResult(
        createImportResultSummary({
          fileName: pendingImport.fileName,
          label: pendingImport.isZip ? "ZIP 패키지" : "JSON 패키지",
          result,
          selectedLessonId: selectedImportLessonId,
        }),
      );
      setPendingImport(null);
      setImportConflictPolicy("create_new");
      setImportConflictPolicies({});
      setNotice(packageImportNotice(pendingImport.isZip ? "ZIP 패키지를" : "패키지를", result));
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "콘텐츠 패키지를 가져오지 못했습니다.");
    } finally {
      setIsImportingPackage(false);
    }
  }

  function cancelPendingImport() {
    setPendingImport(null);
    setImportConflictPolicy("create_new");
    setImportConflictPolicies({});
    setNotice("패키지 가져오기를 취소했습니다.");
  }

  function applyImportConflictPolicyToAll(policy: ContentImportLessonConflictPolicy) {
    setImportConflictPolicy(policy);
    setImportConflictPolicies(
      pendingImport ? buildImportConflictPolicies(pendingImport.preview, policy) : {},
    );
  }

  function changeImportConflictPolicyForLesson(
    sourceLessonId: string,
    policy: ContentImportLessonConflictPolicy,
  ) {
    setImportConflictPolicies((current) => ({
      ...current,
      [sourceLessonId]: policy,
    }));
  }

  function updateDirtyFromCurrentDocument() {
    const nextSnapshot = lessonSnapshot({
      assets: collectDocumentAssets(editorValueRef.current, assetsRef.current),
      plateValue: editorValueRef.current,
      visuals: visualsRef.current,
    });
    const nextDirty = nextSnapshot !== lastSavedSnapshotRef.current;

    setDocumentDirty(nextDirty);
    setSaveStatus(nextDirty ? "idle" : "saved");
    setEditVersion((current) => current + 1);
  }

  function handleEditorValueChange(nextValue: PlateValue) {
    editorValueRef.current = nextValue;
    updateDirtyFromCurrentDocument();
  }

  function restorePendingRecovery() {
    if (!pendingRecovery || !selectedLessonId) {
      return;
    }

    const nextAssets = collectDocumentAssets(
      pendingRecovery.plateValue,
      pendingRecovery.assets,
    );

    editorValueRef.current = pendingRecovery.plateValue;
    assetsRef.current = nextAssets;
    visualsRef.current = pendingRecovery.visuals;
    setEditorValue(pendingRecovery.plateValue);
    setAssets(nextAssets);
    setVisuals(pendingRecovery.visuals);
    setPendingRecovery(null);
    setDocumentDirty(true);
    setSaveStatus("idle");
    setEditorResetKey((current) => current + 1);
    setEditVersion((current) => current + 1);
    writeLessonRecovery({
      ...pendingRecovery,
      assets: nextAssets,
      revision: documentRevisionRef.current ?? pendingRecovery.revision,
      savedAt: Date.now(),
    });
    setNotice("로컬 복구본을 편집기에 열었습니다. 자동 저장을 시도합니다.");
  }

  function discardPendingRecovery() {
    if (!pendingRecovery) {
      return;
    }

    removeLessonRecovery(pendingRecovery.lessonId);
    setPendingRecovery(null);
    setNotice("로컬 복구본을 삭제하고 서버 문서를 유지했습니다.");
  }

  function reloadCurrentLesson() {
    setReloadRequestId((current) => current + 1);
    setNotice("서버 최신 문서를 다시 불러오는 중입니다.");
  }

  async function resetCurrentLessonToBaseline() {
    if (!selectedLesson || selectedLesson.type !== "lesson") {
      setNotice("레슨을 먼저 선택하세요.");
      return;
    }

    if (
      !window.confirm(
        `"${selectedLesson.title}" 레슨 내용을 기준본(제목+빈 문단)으로 되돌리면\n현재 미저장 내용이 모두 사라집니다. 계속할까요?`,
      )
    ) {
      return;
    }

    if (!hasWordPressRest()) {
      setNotice("WordPress 연결이 없어 기준본으로 초기화할 수 없습니다.");
      return;
    }

    setSaveStatus("saving");

    try {
      if (saveInFlightRef.current) {
        try {
          await saveInFlightRef.current;
        } catch {
          // 진행 중 저장이 실패하더라도 기준본 초기화 자체는 수행 가능해야 합니다.
        } finally {
          saveInFlightRef.current = null;
        }
      }

      removeLessonRecovery(selectedLesson.id);
      setPendingRecovery(null);

      const document = await contentApi.resetLessonDocument(selectedLesson.id);

      if (selectedLesson.id === document.lessonId) {
        applyDocumentToEditor(document, { allowRecovery: false });
      }

      setNotice("레슨 내용을 기준본으로 되돌렸습니다.");
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "레슨 내용을 기준본으로 되돌리지 못했습니다.");
      setSaveStatus("error");
    }
  }

  async function reimportCurrentLessonFromJson() {
    if (!canReimportJson) {
      setWorkspaceDrawer("package");
      return;
    }
    if (!selectedLesson || selectedLesson.type !== "lesson") {
      setNotice("레슨을 먼저 선택하세요.");
      return;
    }

    if (
      !window.confirm(
        `"${selectedLesson.title}" 레슨 내용을 data/lessons JSON 파일 기준으로 다시 가져오면\n` +
          "현재 편집기·서버에 저장된 내용이 모두 사라지고 파일 내용으로 덮어써집니다. 계속할까요?",
      )
    ) {
      return;
    }

    if (!hasWordPressRest()) {
      setNotice("WordPress 연결이 없어 JSON에서 다시 가져올 수 없습니다.");
      return;
    }

    setSaveStatus("saving");

    try {
      if (saveInFlightRef.current) {
        try {
          await saveInFlightRef.current;
        } catch {
          // 진행 중 저장이 실패하더라도 다시 가져오기 자체는 수행 가능해야 합니다.
        } finally {
          saveInFlightRef.current = null;
        }
      }

      removeLessonRecovery(selectedLesson.id);
      setPendingRecovery(null);

      const document = await contentApi.reimportLessonFromJson(selectedLesson.id);

      if (selectedLesson.id === document.lessonId) {
        applyDocumentToEditor(document, { allowRecovery: false });
      }

      setNotice("data/lessons JSON 파일에서 레슨 내용을 다시 가져왔습니다.");
    } catch (error) {
      setNotice(
        error instanceof Error ? error.message : "JSON 파일에서 레슨 내용을 다시 가져오지 못했습니다.",
      );
      setSaveStatus("error");
    }
  }

  async function reimportAllLessonsFromJson() {
    if (!canReimportJson) {
      setWorkspaceDrawer("package");
      return;
    }
    if (
      !window.confirm(
        "data/lessons 폴더의 모든 JSON 파일 기준으로 전체 레슨을 다시 가져옵니다.\n" +
          "legacyTopicId가 있는 모든 레슨의 현재 내용이 파일 내용으로 덮어써집니다. 계속할까요?",
      )
    ) {
      return;
    }

    if (!hasWordPressRest()) {
      setNotice("WordPress 연결이 없어 JSON에서 다시 가져올 수 없습니다.");
      return;
    }

    setNotice("전체 레슨을 data/lessons JSON 파일 기준으로 다시 가져오는 중입니다...");

    try {
      const result = await contentApi.reimportAllLessonsFromJson();
      const failedCount = Object.keys(result.failed).length;
      setNotice(
        `전체 다시 가져오기 완료: 성공 ${result.succeeded.length}건, 건너뜀 ${result.skipped.length}건, 실패 ${failedCount}건.` +
          (failedCount > 0 ? ` 실패 사유는 브라우저 콘솔에서 확인하세요.` : ""),
      );

      if (failedCount > 0) {
        // eslint-disable-next-line no-console
        console.warn("레슨 일괄 다시 가져오기 실패 목록", result.failed);
      }
    } catch (error) {
      setNotice(
        error instanceof Error ? error.message : "전체 레슨을 JSON 파일에서 다시 가져오지 못했습니다.",
      );
    }
  }

  function applyWorkflowTemplate(template: WorkflowTemplate) {
    if (!selectedLesson || selectedLesson.type !== "lesson") {
      setNotice("템플릿을 적용할 레슨을 먼저 선택하세요.");
      return;
    }

    const nextValue = createWorkflowTemplate(template, selectedLesson.title);

    editorValueRef.current = nextValue;
    setEditorValue(nextValue);
    setEditorResetKey((current) => current + 1);
    updateDirtyFromCurrentDocument();
    setViewMode("editor");
    setNotice("작성 템플릿을 적용했습니다. 내용을 바로 수정하세요.");
  }

  function startLessonFromWorkflow() {
    const section = selectedTopicTree
      ? findFirstSectionNode([selectedTopicTree])
      : null;

    if (!section) {
      setAddTarget({ parentId: null, type: "topic" });
      setNotice("먼저 주제를 만든 뒤 챕터와 장을 추가하세요.");
      return;
    }

    setAddTarget({ parentId: section.id, type: "lesson" });
    setNotice("새 레슨 제목을 입력하세요.");
  }

  function openSourceView() {
    const currentPlateValue = editorValueRef.current;
    const currentDocumentAssets = collectDocumentAssets(currentPlateValue, assets);

    setEditorValue(currentPlateValue);
    setSourceText(
      JSON.stringify(createLessonSource(currentPlateValue, currentDocumentAssets, visuals), null, 2),
    );
    setSourceError(null);
    setViewMode("editor");
    setWorkspaceDrawer("source");
  }

  function applySourceText() {
    try {
      const parsed = JSON.parse(sourceText) as unknown;
      const nextValue = Array.isArray(parsed)
        ? parsed
        : isPlateDocumentSource(parsed)
          ? parsed.plateValue
          : null;

      if (!nextValue) {
        setSourceError("소스는 Plate 배열이거나 plateValue 배열을 가진 객체여야 합니다.");
        return;
      }

      editorValueRef.current = nextValue as PlateValue;
      setEditorValue(nextValue as PlateValue);
      if (isPlateDocumentSource(parsed)) {
        const nextAssets = normalizeAssets(parsed.assets);
        const nextVisuals = readRecordArray(parsed.visuals);

        assetsRef.current = nextAssets;
        visualsRef.current = nextVisuals;
        setAssets(nextAssets);
        setVisuals(nextVisuals);
      }
      setEditorResetKey((current) => current + 1);
      updateDirtyFromCurrentDocument();
      setSourceError(null);
      setViewMode("editor");
      setWorkspaceDrawer(null);
      setNotice("소스 내용을 편집기에 적용했습니다.");
    } catch (error) {
      setSourceError(error instanceof Error ? error.message : "소스 JSON을 읽지 못했습니다.");
    }
  }

  async function openTopicOutline(tree: ContentTree) {
    if (!await persistActiveLesson("navigation")) {
      setNotice("현재 레슨을 저장하지 못해 주제 이동을 중단했습니다.");
      return;
    }

    const firstLesson = findFirstLesson([tree]);

    applyContentSelection({
      lessonId: firstLesson?.id ?? null,
      topicId: tree.topic.id,
    });
    selectedOutlineNodeIdRef.current = tree.topic.id;
    setSelectedChapterId(null);
    setSelectedSectionId(null);
    setSelectedOutlineNodeId(tree.topic.id);
    setWorkspaceDrawer(null);
    setWorkspaceView("outline");
  }

  async function openLessonEditor(lessonId: string) {
    const nextSelection = normalizeContentSelection(treesRef.current, null, lessonId);

    if (!nextSelection.lessonId) {
      setNotice("선택한 레슨을 현재 목차에서 찾지 못했습니다.");
      return;
    }

    if (
      nextSelection.lessonId !== selectedLessonIdRef.current
      && !await persistActiveLesson("navigation")
    ) {
      setNotice("현재 레슨을 저장하지 못해 레슨 이동을 중단했습니다.");
      return;
    }

    selectLessonPath(nextSelection.lessonId);
    setWorkspaceDrawer(null);
    setWorkspaceView("lesson");
  }

  function toggleCollapsedNode(nodeId: string) {
    setCollapsedNodeIds((current) => {
      const next = new Set(current);

      if (next.has(nodeId)) {
        next.delete(nodeId);
      } else {
        next.add(nodeId);
      }

      return next;
    });
  }

  const selectedOutlinePath = selectedOutlineNodeId
    ? findNodeSelectionPath(trees, selectedOutlineNodeId)
    : null;
  const selectedContextLabel = workspaceView === "outline" && selectedOutlinePath
    ? [
        selectedOutlinePath.tree.topic.title,
        selectedChapterId ? selectedOutlinePath.chapter?.chapter.title : null,
        selectedSectionId ? selectedOutlinePath.section?.section.title : null,
        selectedOutlinePath.lesson?.title,
      ]
        .filter(Boolean)
        .join(" / ")
    : selectionPath
      ? [
          selectionPath.tree.topic.title,
          selectionPath.chapter?.chapter.title,
          selectionPath.section?.section.title,
          selectionPath.lesson?.title,
        ]
          .filter(Boolean)
          .join(" / ")
      : "선택한 콘텐츠가 없습니다.";

  const packageManagementPanels = (
    <>
      {isPreviewingPackage ? <ImportPreviewStatusPanel /> : null}

      {importPreviewProblem ? (
        <ImportPreviewProblemPanel
          problem={importPreviewProblem}
          onClose={() => setImportPreviewProblem(null)}
        />
      ) : null}

      {pendingImport ? (
        <ImportPreviewPanel
          importConflictPolicies={importConflictPolicies}
          importConflictPolicy={importConflictPolicy}
          isImporting={isImportingPackage}
          pendingImport={pendingImport}
          onCancel={cancelPendingImport}
          onConfirm={confirmPendingImport}
          onApplyPolicyToAll={applyImportConflictPolicyToAll}
          onConflictPolicyChange={changeImportConflictPolicyForLesson}
        />
      ) : null}

      {lastImportResult ? (
        <ImportResultPanel
          result={lastImportResult}
          onClose={() => setLastImportResult(null)}
          onOpenLesson={openLessonEditor}
        />
      ) : null}
    </>
  );

  const managementBanners = (
    <>
      {addTarget ? (
        <div className="rounded-xl border border-blue-100 bg-blue-50 p-4">
          <form className="flex flex-wrap items-center gap-2" onSubmit={submitAddNode}>
            <label className="shrink-0 text-sm font-semibold text-blue-950">
              {nodeLabels[addTarget.type]} 이름
            </label>
            <input
              autoFocus
              className="min-w-72 flex-1 rounded-md border border-blue-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
              placeholder={`${nodeLabels[addTarget.type]} 제목을 입력하세요`}
              value={newTitle}
              onChange={(event) => setNewTitle(event.target.value)}
            />
            <button
              className="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
              type="submit"
            >
              추가
            </button>
            <button
              className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
              type="button"
              onClick={() => {
                setAddTarget(null);
                setNewTitle("");
              }}
            >
              취소
            </button>
          </form>
        </div>
      ) : null}

      {editingNode ? (
        <div className="rounded-xl border border-amber-100 bg-amber-50 p-4">
          <form className="flex flex-wrap items-center gap-2" onSubmit={submitEditNode}>
            <label className="shrink-0 text-sm font-semibold text-amber-950">
              {nodeLabels[editingNode.type]} 수정
            </label>
            <span className="rounded-full border border-amber-200 bg-white px-2.5 py-1 text-xs font-semibold text-amber-900">
              ID {editingNode.id}
            </span>
            <input
              autoFocus
              className="min-w-72 flex-1 rounded-md border border-amber-200 bg-white px-3 py-2 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100"
              value={editTitle}
              onChange={(event) => setEditTitle(event.target.value)}
            />
            <label className="flex items-center gap-2 text-sm font-semibold text-amber-950">
              상태
              <select
                className="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm"
                disabled={!canPublish && editingNode.status === "published"}
                value={editStatus}
                onChange={(event) => setEditStatus(event.target.value as ContentStatus)}
              >
                {editableContentStatuses
                  .filter(
                    (status) => canPublish || status !== "published" || editingNode.status === "published",
                  )
                  .map((status) => (
                    <option key={status} value={status}>
                      {contentStatusLabels[status]}
                    </option>
                  ))}
              </select>
            </label>
            <button
              className="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700"
              type="submit"
            >
              수정 저장
            </button>
            <button
              className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
              type="button"
              onClick={() => {
                setEditingNode(null);
                setEditTitle("");
              }}
            >
              취소
            </button>
          </form>
        </div>
      ) : null}
    </>
  );

  const qualityIssueCount = qualityChecks.filter((check) => !check.passed).length;
  const drawerContent = workspaceDrawer === "package" ? (
    <div className="space-y-4">
      {window.contentsEditorAdmin?.canImportServer ? <ServerImportPanel
        disabled={isImportingPackage}
        onActiveChange={setIsServerImportActive}
        onBeforeStart={() => persistActiveLesson("navigation")}
        onComplete={async result => {
          await refreshTree({ lessonId: null, topicId: result.topic.id });
          setNotice(packageImportNotice("서버 ZIP을", result));
        }}
      /> : null}
      {packageManagementPanels}
      <PackageWorkspacePage
        availablePackageScopes={availablePackageScopes}
        importConflictPolicy={importConflictPolicy}
        isImportingPackage={isImportingPackage}
        packageExportScope={packageExportScope}
        packageInputRef={packageInputRef}
        pendingImport={pendingImport}
        selectedContextLabel={selectedContextLabel}
        onCancelImport={cancelPendingImport}
        onConfirmImport={confirmPendingImport}
        onExport={exportSelectedPackage}
        onImport={importPackageFile}
        onPolicyChange={setImportConflictPolicy}
        onScopeChange={setPackageExportScope}
        onReimportAllFromJson={canReimportJson ? reimportAllLessonsFromJson : undefined}
      />
    </div>
  ) : workspaceDrawer === "publish" ? (
    <PublishWorkspacePage
      canPublish={canPublish}
      selectedLesson={selectedLesson}
      selectedLessonPublicUrl={selectedLessonPublicUrl}
      selectedTopic={selectedTopic}
      selectedTopicPublicUrl={selectedTopicPublicUrl}
      isPublishing={isBulkUpdatingStatus}
      onPublishTopic={publishSelectedTopicTree}
    />
  ) : workspaceDrawer === "archive" ? (
    <ArchiveWorkspacePage
      nodes={visibleArchivedNodes}
      busy={isNodeActionPending}
      notice={notice}
      onDelete={deleteContentNode}
      onRefresh={hasWordPressRest() ? refreshTree : undefined}
      onRestore={restoreArchivedNode}
    />
  ) : workspaceDrawer === "quality" ? (
    <div className="space-y-4">
      <AuthoringWorkflow
        dirty={dirty}
        hasBody={hasMeaningfulContent(editorValue, selectedLesson?.title)}
        hasLesson={Boolean(selectedLesson && selectedLesson.type === "lesson")}
        onApplyTemplate={applyWorkflowTemplate}
        onSave={saveLesson}
        onStartLesson={startLessonFromWorkflow}
        qualityReady={!qualityChecks.some((check) => check.required && !check.passed)}
        viewMode="editor"
      />
      <QualityChecklist checks={qualityChecks} />
    </div>
  ) : workspaceDrawer === "source" ? (
    <SourcePanel
      error={sourceError}
      sourceText={sourceText}
      onApply={applySourceText}
      onChange={setSourceText}
      onRefresh={() => {
        const currentPlateValue = editorValueRef.current;
        const currentDocumentAssets = collectDocumentAssets(currentPlateValue, assets);

        setSourceText(
          JSON.stringify(
            createLessonSource(currentPlateValue, currentDocumentAssets, visuals),
            null,
            2,
          ),
        );
        setSourceError(null);
      }}
    />
  ) : null;

  const shellProps = {
    canReload: hasWordPressRest() && (saveStatus === "conflict" || saveStatus === "error"),
    collapsedNodeIds,
    dirty,
    drawerContent,
    drawerTitle: workspaceDrawer ? workspaceDrawerLabels[workspaceDrawer] : null,
    notice,
    previewUrl: selectedLessonPublicUrl || selectedTopicPublicUrl,
    qualityIssueCount,
    saveStatus,
    selectedContextLabel,
    selectedLessonId,
    selectedLessonTitle: selectedLesson?.title ?? null,
    selectedTopicId,
    trees,
    view: workspaceView,
    onCloseDrawer: () => setWorkspaceDrawer(null),
    onOpenDrawer: (drawer: WorkspaceDrawerView) => {
      if (drawer === "publish" && dirty) {
        void persistActiveLesson("navigation").then((saved) => {
          if (saved) {
            setWorkspaceDrawer(drawer);
          }
        });
        return;
      }

      setWorkspaceDrawer(drawer);
    },
    onOpenSource: openSourceView,
    onReload: reloadCurrentLesson,
    onSave: saveLesson,
    onSelectLesson: openLessonEditor,
    onSelectTopic: openTopicOutline,
    onReimportFromJson: canReimportJson ? reimportCurrentLessonFromJson : undefined,
    onResetDocument: resetCurrentLessonToBaseline,
    onToggle: toggleCollapsedNode,
    onViewChange: changeWorkspaceView,
  };

  if (workspaceView === "library") {
    return (
      <ContentManagementShell {...shellProps}>
        {managementBanners}
        <TopicLibraryPage
          trees={trees}
          canPublish={canPublish}
          busy={isNodeActionPending}
          onArchive={archiveContentNode}
          onDelete={deleteContentNode}
          onOpenArchive={() => setWorkspaceDrawer("archive")}
          onCreateTopic={() => setAddTarget({ type: "topic", parentId: null })}
          onOpenTopic={openTopicOutline}
        />
      </ContentManagementShell>
    );
  }

  if (workspaceView === "outline") {
    return (
      <ContentManagementShell {...shellProps}>
        {managementBanners}
        {selectedTopicTree ? (
          <OutlineWorkspacePage
            bulkSelectedNodeIds={bulkSelectedNodeIds}
            isNodeActionPending={isNodeActionPending}
            bulkStatus={bulkStatus}
            canPublish={canPublish}
            collapsedNodeIds={collapsedNodeIds}
            isBulkUpdatingStatus={isBulkUpdatingStatus}
            tree={selectedTopicTree}
            selectedNodeId={selectedOutlineNodeId}
            onArchive={archiveContentNode}
            onBulkStatusApply={applyBulkStatus}
            onBulkStatusChange={setBulkStatus}
            onClearBulkSelection={() => setBulkSelectedNodeIds(new Set())}
            onCreateNode={createOutlineNode}
            onDelete={deleteContentNode}
            onDuplicate={duplicateOutlineNode}
            onExport={exportOutlineNode}
            onMoveNode={moveOutlineNode}
            onOpenLesson={openLessonEditor}
            onRenameNode={renameOutlineNode}
            onSelectAllBulkNodes={selectAllBulkNodes}
            onSelectBulkNode={toggleBulkNodeSelection}
            onSelectNode={selectOutlineNode}
            onStatusChange={changeOutlineNodeStatus}
            onToggle={toggleCollapsedNode}
          />
        ) : (
          <EmptyWorkspaceNotice
            title="목차를 편집할 주제가 없습니다."
            description="콘텐츠 라이브러리에서 주제를 먼저 만드세요."
            actionLabel="주제 만들기"
            onAction={() => setAddTarget({ type: "topic", parentId: null })}
          />
        )}
      </ContentManagementShell>
    );
  }

  if (workspaceView === "lesson") {
    return (
      <ContentManagementShell {...shellProps}>
        {managementBanners}
        {selectedLesson ? (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
              <h2 className="min-w-0 break-words text-base font-semibold">{selectedLesson.title}</h2>
              <ContentRemovalButtons node={selectedLesson} disabled={isNodeActionPending || (!canPublish && selectedLesson.status === "published")} onArchive={archiveContentNode} onDelete={deleteContentNode} />
            </div>
            {pendingRecovery ? (
              <section
                className="flex flex-wrap items-center justify-between gap-3 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                role="status"
              >
                <div>
                  <p className="font-semibold">저장되지 않은 로컬 복구본이 있습니다.</p>
                  <p className="mt-1 leading-6 text-amber-800">
                    현재 서버 문서와 내용이 다릅니다. 복구본을 열기 전에는 서버 문서를 유지합니다.
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    className="rounded-md bg-amber-700 px-3 py-2 font-semibold text-white hover:bg-amber-800"
                    type="button"
                    onClick={restorePendingRecovery}
                  >
                    복구본 열기
                  </button>
                  <button
                    className="rounded-md border border-amber-300 bg-white px-3 py-2 font-semibold text-amber-900 hover:bg-amber-100"
                    type="button"
                    onClick={discardPendingRecovery}
                  >
                    복구본 버리기
                  </button>
                </div>
              </section>
            ) : null}

            <section
              aria-label={`레슨 편집: ${selectedLesson.title}`}
              className="overflow-visible border border-slate-200 bg-white shadow-sm"
            >
              <EditorErrorBoundary key={`${selectedLesson.id}:${editorResetKey}:boundary`}>
                <PlateEditor
                  key={`${selectedLesson.id}:${editorResetKey}`}
                  initialValue={editorValue}
                  onValueChange={handleEditorValueChange}
                />
              </EditorErrorBoundary>
            </section>
          </div>
        ) : (
          <EmptyWorkspaceNotice
            title="작성할 레슨을 선택하세요."
            description="목차 편집 화면에서 레슨을 선택하거나 새 레슨을 추가하면 Plate 편집기가 열립니다."
            actionLabel="목차 편집으로 이동"
            onAction={() => void changeWorkspaceView("outline")}
          />
        )}
      </ContentManagementShell>
    );
  }

}

class EditorErrorBoundary extends React.Component<
  EditorErrorBoundaryProps,
  EditorErrorBoundaryState
> {
  state: EditorErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): EditorErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error) {
    console.error("Plate editor render failed", error);
  }

  render() {
    if (!this.state.error) {
      return this.props.children;
    }

    return (
      <div className="min-h-[520px] bg-white p-8">
        <div className="rounded-xl border border-red-200 bg-red-50 p-5 text-sm text-red-900">
          <p className="font-semibold">편집기 화면을 유지했습니다.</p>
          <p className="mt-2 leading-6">
            Plate 편집기 로딩 중 오류가 발생했지만 전체 페이지가 사라지지 않도록
            차단했습니다. 아래 오류 내용을 기준으로 수정할 수 있습니다.
          </p>
          <pre className="mt-4 max-h-40 overflow-auto rounded-lg bg-white p-3 text-xs text-red-800">
            {this.state.error.message}
          </pre>
        </div>
      </div>
    );
  }
}

function normalizeContentSelection(
  trees: ContentTree[],
  topicId: string | null,
  lessonId: string | null,
): ContentSelection {
  let tree = topicId
    ? trees.find((candidate) => candidate.topic.id === topicId) ?? null
    : null;

  if (!tree && lessonId) {
    tree = findTopicTreeForLesson(trees, lessonId);
  }

  tree ??= trees[0] ?? null;

  if (!tree) {
    return { lessonId: null, topicId: null };
  }

  const lesson = lessonId ? findLessonInTree(tree, lessonId) : null;

  return {
    lessonId: lesson?.id ?? findFirstLesson([tree])?.id ?? null,
    topicId: tree.topic.id,
  };
}

function findTopicTreeForLesson(trees: ContentTree[], lessonId: string): ContentTree | null {
  return trees.find((tree) => Boolean(findLessonInTree(tree, lessonId))) ?? null;
}

function findLessonInTree(tree: ContentTree, lessonId: string): ContentNode | null {
  for (const chapter of tree.chapters) {
    for (const section of chapter.sections) {
      const lesson = section.lessons.find((candidate) => candidate.id === lessonId);

      if (lesson) {
        return lesson;
      }
    }
  }

  return null;
}

function findSelectionPath(
  trees: ContentTree[],
  topicId: string | null,
  lessonId: string | null,
): ContentSelectionPath | null {
  const tree = topicId
    ? trees.find((candidate) => candidate.topic.id === topicId) ?? null
    : lessonId
      ? findTopicTreeForLesson(trees, lessonId)
      : null;

  if (!tree) {
    return null;
  }

  if (lessonId) {
    for (const chapter of tree.chapters) {
      for (const section of chapter.sections) {
        const lesson = section.lessons.find((candidate) => candidate.id === lessonId);

        if (lesson) {
          return {
            chapter,
            lesson,
            section,
            tree,
          };
        }
      }
    }
  }

  return { tree };
}

function findNodeSelectionPath(
  trees: ContentTree[],
  nodeId: string,
): ContentSelectionPath | null {
  for (const tree of trees) {
    if (tree.topic.id === nodeId) {
      return { tree };
    }

    for (const chapter of tree.chapters) {
      if (chapter.chapter.id === nodeId) {
        return { chapter, tree };
      }

      for (const section of chapter.sections) {
        if (section.section.id === nodeId) {
          return { chapter, section, tree };
        }

        const lesson = section.lessons.find((candidate) => candidate.id === nodeId);

        if (lesson) {
          return { chapter, lesson, section, tree };
        }
      }
    }
  }

  return null;
}

function findContentNodeById(
  trees: ContentTree[],
  nodeId: string | null,
): ContentNode | null {
  if (!nodeId) {
    return null;
  }

  const path = findNodeSelectionPath(trees, nodeId);

  if (!path) {
    return null;
  }

  if (path.lesson?.id === nodeId) {
    return path.lesson;
  }

  if (path.section?.section.id === nodeId) {
    return path.section.section;
  }

  if (path.chapter?.chapter.id === nodeId) {
    return path.chapter.chapter;
  }

  return path.tree.topic.id === nodeId ? path.tree.topic : null;
}

function outlineChildType(
  type: ContentNodeType,
): Exclude<ContentNodeType, "topic"> | null {
  if (type === "topic") {
    return "chapter";
  }

  if (type === "chapter") {
    return "section";
  }

  if (type === "section") {
    return "lesson";
  }

  return null;
}

function outlineParentType(type: ContentNodeType): ContentNodeType | null {
  if (type === "chapter") {
    return "topic";
  }

  if (type === "section") {
    return "chapter";
  }

  if (type === "lesson") {
    return "section";
  }

  return null;
}

function buildOutlineRows(tree: ContentTree): OutlineRow[] {
  const rows: OutlineRow[] = [
    {
      ancestorIds: [],
      childCount: tree.chapters.length,
      depth: 0,
      hasChildren: tree.chapters.length > 0,
      node: tree.topic,
      parentId: null,
      siblingCount: 1,
      siblingIndex: 0,
    },
  ];

  tree.chapters.forEach((chapter, chapterIndex) => {
    rows.push({
      ancestorIds: [tree.topic.id],
      childCount: chapter.sections.length,
      depth: 1,
      hasChildren: chapter.sections.length > 0,
      node: chapter.chapter,
      parentId: tree.topic.id,
      siblingCount: tree.chapters.length,
      siblingIndex: chapterIndex,
    });

    chapter.sections.forEach((section, sectionIndex) => {
      rows.push({
        ancestorIds: [tree.topic.id, chapter.chapter.id],
        childCount: section.lessons.length,
        depth: 2,
        hasChildren: section.lessons.length > 0,
        node: section.section,
        parentId: chapter.chapter.id,
        siblingCount: chapter.sections.length,
        siblingIndex: sectionIndex,
      });

      section.lessons.forEach((lesson, lessonIndex) => {
        rows.push({
          ancestorIds: [tree.topic.id, chapter.chapter.id, section.section.id],
          childCount: 0,
          depth: 3,
          hasChildren: false,
          node: lesson,
          parentId: section.section.id,
          siblingCount: section.lessons.length,
          siblingIndex: lessonIndex,
        });
      });
    });
  });

  return rows;
}

function outlineSiblingNodes(
  trees: ContentTree[],
  type: ContentNodeType,
  parentId: string | null,
): ContentNode[] {
  if (type === "topic") {
    return trees.map((tree) => tree.topic);
  }

  for (const tree of trees) {
    if (type === "chapter" && tree.topic.id === parentId) {
      return tree.chapters.map((chapter) => chapter.chapter);
    }

    for (const chapter of tree.chapters) {
      if (type === "section" && chapter.chapter.id === parentId) {
        return chapter.sections.map((section) => section.section);
      }

      for (const section of chapter.sections) {
        if (type === "lesson" && section.section.id === parentId) {
          return section.lessons;
        }
      }
    }
  }

  return [];
}

function normalizeOutlineTree(tree: ContentTree): ContentTree {
  return {
    topic: { ...tree.topic, order: tree.topic.order, parentId: null },
    chapters: tree.chapters.map((chapter, chapterIndex) => ({
      chapter: {
        ...chapter.chapter,
        order: (chapterIndex + 1) * 10,
        parentId: tree.topic.id,
      },
      sections: chapter.sections.map((section, sectionIndex) => ({
        section: {
          ...section.section,
          order: (sectionIndex + 1) * 10,
          parentId: chapter.chapter.id,
        },
        lessons: section.lessons.map((lesson, lessonIndex) => ({
          ...lesson,
          order: (lessonIndex + 1) * 10,
          parentId: section.section.id,
        })),
      })),
    })),
  };
}

function moveNodeInTrees(
  trees: ContentTree[],
  nodeId: string,
  targetParentId: string,
  targetIndex: number,
): ContentTree[] {
  const nextTrees = trees.map(cloneTree);
  const node = findContentNodeById(nextTrees, nodeId);

  if (!node || node.type === "topic") {
    return nextTrees;
  }
  const targetParent = findContentNodeById(nextTrees, targetParentId);
  if (!targetParent || targetParent.type !== outlineParentType(node.type)) {
    return nextTrees;
  }

  if (node.type === "chapter") {
    let moving: ContentTree["chapters"][number] | null = null;

    for (const tree of nextTrees) {
      const index = tree.chapters.findIndex((chapter) => chapter.chapter.id === nodeId);

      if (index >= 0) {
        moving = tree.chapters.splice(index, 1)[0];
        break;
      }
    }

    const targetTree = nextTrees.find((tree) => tree.topic.id === targetParentId);

    if (moving && targetTree) {
      targetTree.chapters.splice(
        Math.max(0, Math.min(targetIndex, targetTree.chapters.length)),
        0,
        moving,
      );
    }
  } else if (node.type === "section") {
    let moving: ContentTree["chapters"][number]["sections"][number] | null = null;

    for (const tree of nextTrees) {
      for (const chapter of tree.chapters) {
        const index = chapter.sections.findIndex((section) => section.section.id === nodeId);

        if (index >= 0) {
          moving = chapter.sections.splice(index, 1)[0];
          break;
        }
      }
      if (moving) break;
    }

    const targetChapter = nextTrees
      .flatMap((tree) => tree.chapters)
      .find((chapter) => chapter.chapter.id === targetParentId);

    if (moving && targetChapter) {
      targetChapter.sections.splice(
        Math.max(0, Math.min(targetIndex, targetChapter.sections.length)),
        0,
        moving,
      );
    }
  } else {
    let moving: ContentNode | null = null;

    for (const tree of nextTrees) {
      for (const chapter of tree.chapters) {
        for (const section of chapter.sections) {
          const index = section.lessons.findIndex((lesson) => lesson.id === nodeId);

          if (index >= 0) {
            moving = section.lessons.splice(index, 1)[0];
            break;
          }
        }
        if (moving) break;
      }
      if (moving) break;
    }

    const targetSection = nextTrees
      .flatMap((tree) => tree.chapters)
      .flatMap((chapter) => chapter.sections)
      .find((section) => section.section.id === targetParentId);

    if (moving && targetSection) {
      targetSection.lessons.splice(
        Math.max(0, Math.min(targetIndex, targetSection.lessons.length)),
        0,
        moving,
      );
    }
  }

  return nextTrees.map(normalizeOutlineTree);
}

async function duplicateContentBranch(
  path: ContentSelectionPath,
  node: ContentNode,
  cloneNode: (
    source: ContentNode,
    parentId: string | null,
    isRoot: boolean,
  ) => Promise<ContentNode>,
): Promise<ContentNode> {
  const cloneSection = async (
    section: ContentTree["chapters"][number]["sections"][number],
    parentId: string,
    isRoot: boolean,
  ) => {
    const sectionClone = await cloneNode(section.section, parentId, isRoot);

    for (const lesson of section.lessons) {
      await cloneNode(lesson, sectionClone.id, false);
    }

    return sectionClone;
  };
  const cloneChapter = async (
    chapter: ContentTree["chapters"][number],
    parentId: string,
    isRoot: boolean,
  ) => {
    const chapterClone = await cloneNode(chapter.chapter, parentId, isRoot);

    for (const section of chapter.sections) {
      await cloneSection(section, chapterClone.id, false);
    }

    return chapterClone;
  };

  if (node.type === "topic") {
    const topicClone = await cloneNode(path.tree.topic, null, true);

    for (const chapter of path.tree.chapters) {
      await cloneChapter(chapter, topicClone.id, false);
    }

    return topicClone;
  }

  if (node.type === "chapter" && path.chapter) {
    return cloneChapter(path.chapter, path.tree.topic.id, true);
  }

  if (node.type === "section" && path.section && path.chapter) {
    return cloneSection(path.section, path.chapter.chapter.id, true);
  }

  if (node.type === "lesson" && path.lesson && path.section) {
    return cloneNode(path.lesson, path.section.section.id, true);
  }

  throw new Error("복제할 목차 구조를 찾지 못했습니다.");
}

function nodeContainsSelectedLesson(
  selection: ContentSelectionPath | null,
  nodeId: string,
  lessonId: string | null,
): boolean {
  if (!selection || !lessonId) {
    return false;
  }

  return [
    selection.tree.topic.id,
    selection.chapter?.chapter.id,
    selection.section?.section.id,
    selection.lesson?.id,
  ].includes(nodeId);
}

function getAvailablePackageScopes(selection: ContentSelectionPath | null): ContentPackageScope[] {
  if (!selection) {
    return [];
  }

  return [
    "topic",
    ...(selection.chapter ? (["chapter"] as const) : []),
    ...(selection.section ? (["section"] as const) : []),
    ...(selection.lesson ? (["lesson"] as const) : []),
  ];
}

function nodeForPackageScope(
  selection: ContentSelectionPath,
  scope: ContentPackageScope,
): ContentNode | null {
  if (scope === "topic") {
    return selection.tree.topic;
  }

  if (scope === "chapter") {
    return selection.chapter?.chapter ?? null;
  }

  if (scope === "section") {
    return selection.section?.section ?? null;
  }

  return selection.lesson ?? null;
}

function createScopedTree(
  selection: ContentSelectionPath,
  scope: ContentPackageScope,
): ContentTree {
  if (scope === "topic") {
    return cloneTree(selection.tree);
  }

  if (scope === "chapter" && selection.chapter) {
    return {
      topic: { ...selection.tree.topic },
      chapters: [cloneChapter(selection.chapter)],
    };
  }

  if (scope === "section" && selection.chapter && selection.section) {
    return {
      topic: { ...selection.tree.topic },
      chapters: [
        {
          chapter: { ...selection.chapter.chapter },
          sections: [cloneSection(selection.section)],
        },
      ],
    };
  }

  if (scope === "lesson" && selection.chapter && selection.section && selection.lesson) {
    return {
      topic: { ...selection.tree.topic },
      chapters: [
        {
          chapter: { ...selection.chapter.chapter },
          sections: [
            {
              section: { ...selection.section.section },
              lessons: [{ ...selection.lesson }],
            },
          ],
        },
      ],
    };
  }

  return cloneTree(selection.tree);
}

function cloneTree(tree: ContentTree): ContentTree {
  return {
    topic: { ...tree.topic },
    chapters: tree.chapters.map(cloneChapter),
  };
}

function cloneChapter(chapter: ContentTree["chapters"][number]): ContentTree["chapters"][number] {
  return {
    chapter: { ...chapter.chapter },
    sections: chapter.sections.map(cloneSection),
  };
}

function cloneSection(
  section: ContentTree["chapters"][number]["sections"][number],
): ContentTree["chapters"][number]["sections"][number] {
  return {
    section: { ...section.section },
    lessons: section.lessons.map((lesson) => ({ ...lesson })),
  };
}

function createPreviewPackage(
  tree: ContentTree,
  localDocuments: Record<string, LocalLessonDocument>,
  scope: ContentPackageScope,
  rootNode: ContentNode,
): ContentPackage {
  const lessonDocuments: ContentPackage["lessonDocuments"] = {};

  for (const lesson of lessonNodesFromTree(tree)) {
    const localDocument = localDocuments[lesson.id];

    lessonDocuments[lesson.id] = {
      assets: localDocument?.assets ?? [],
      lessonId: lesson.id,
      plateValue: localDocument?.plateValue ?? createLessonValue(lesson.title),
      revision: localDocument?.revision ?? 1,
      schemaVersion: localDocument?.schemaVersion ?? "1.0.0",
      visuals: localDocument?.visuals ?? [],
    };
  }

  return {
    exportedAt: new Date().toISOString(),
    kind: "contents-editor-package",
    lessonDocuments,
    packageVersion: "1.0.0",
    rootNode,
    scope,
    tree,
  };
}

function importPreviewPackage(packageData: ContentPackage): {
  created: PackageImportResult["created"];
  documents: Record<string, LocalLessonDocument>;
  tree: ContentTree;
} {
  if (!packageData.tree?.topic) {
    throw new Error("패키지에 목차 정보가 없습니다.");
  }

  const idMap = new Map<string, string>();
  let sequence = 0;
  const created: PackageImportResult["created"] = {
    chapters: 0,
    lessons: 0,
    sections: 0,
    topics: 0,
  };
  const documents: Record<string, LocalLessonDocument> = {};
  const createId = () => `preview-import-${Date.now()}-${sequence++}`;
  const cloneImportedNode = (
    source: ContentNode,
    type: ContentNodeType,
    parentId: string | null,
  ): ContentNode => {
    const nextId = createId();
    idMap.set(source.id, nextId);

    return {
      ...source,
      id: nextId,
      parentId,
      status: "draft",
      type,
    };
  };

  const topic = cloneImportedNode(packageData.tree.topic, "topic", null);
  created.topics += 1;

  const chapters = packageData.tree.chapters.map((chapterGroup) => {
    const chapter = cloneImportedNode(chapterGroup.chapter, "chapter", topic.id);
    created.chapters += 1;

    const sections = chapterGroup.sections.map((sectionGroup) => {
      const section = cloneImportedNode(sectionGroup.section, "section", chapter.id);
      created.sections += 1;

      const lessons = sectionGroup.lessons.map((sourceLesson) => {
        const lesson = cloneImportedNode(sourceLesson, "lesson", section.id);
        const sourceDocument = packageData.lessonDocuments[sourceLesson.id];
        created.lessons += 1;

        documents[lesson.id] = {
          assets: sourceDocument?.assets ?? [],
          lessonId: lesson.id,
          plateValue: sourceDocument?.plateValue ?? createLessonValue(lesson.title),
          revision: 1,
          schemaVersion: sourceDocument?.schemaVersion ?? "1.0.0",
          visuals: sourceDocument?.visuals ?? [],
        };

        return lesson;
      });

      return {
        section,
        lessons,
      };
    });

    return {
      chapter,
      sections,
    };
  });

  return {
    created,
    documents,
    tree: {
      topic,
      chapters,
    },
  };
}

function lessonNodesFromTree(tree: ContentTree): ContentNode[] {
  return tree.chapters.flatMap((chapter) =>
    chapter.sections.flatMap((section) => section.lessons),
  );
}

function firstLessonIdForTopic(trees: ContentTree[], topicId: string): string | null {
  const tree = trees.find((item) => item.topic.id === topicId);

  return tree ? findFirstLesson([tree])?.id ?? null : null;
}

function downloadJson(data: unknown, fileName: string) {
  const blob = new Blob([JSON.stringify(data, null, 2)], {
    type: "application/json;charset=utf-8",
  });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");

  anchor.href = url;
  anchor.download = fileName;
  anchor.click();

  URL.revokeObjectURL(url);
}

function isZipPackageFile(file: File): boolean {
  return (
    file.name.toLowerCase().endsWith(".zip") ||
    file.type === "application/zip" ||
    file.type === "application/x-zip-compressed"
  );
}

function safeFileName(value: string): string {
  const normalized = value.trim().replace(/[\\/:*?"<>|]+/g, "-");

  return normalized || "contents-editor-topic";
}

const lessonRecoveryStoragePrefix = "readcontents-library:lesson-recovery:";

function lessonSnapshot({ assets, plateValue, visuals }: LessonSnapshotInput): string {
  return JSON.stringify({
    assets,
    plateValue,
    visuals,
  });
}

function lessonRecoveryKey(lessonId: string): string {
  return `${lessonRecoveryStoragePrefix}${encodeURIComponent(lessonId)}`;
}

function readLessonRecovery(lessonId: string): LessonRecoveryDraft | null {
  try {
    const raw = window.localStorage.getItem(lessonRecoveryKey(lessonId));

    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as Partial<LessonRecoveryDraft>;

    if (
      parsed.lessonId !== lessonId
      || !Array.isArray(parsed.plateValue)
      || !Array.isArray(parsed.assets)
      || !Array.isArray(parsed.visuals)
      || typeof parsed.revision !== "number"
      || !Number.isInteger(parsed.revision)
      || parsed.revision < 0
      || typeof parsed.savedAt !== "number"
    ) {
      window.localStorage.removeItem(lessonRecoveryKey(lessonId));
      return null;
    }

    return {
      assets: normalizeAssets(parsed.assets),
      lessonId,
      plateValue: parsed.plateValue as PlateValue,
      revision: parsed.revision,
      savedAt: parsed.savedAt,
      schemaVersion:
        typeof parsed.schemaVersion === "string" && parsed.schemaVersion
          ? parsed.schemaVersion
          : "1.0.0",
      visuals: readRecordArray(parsed.visuals),
    };
  } catch {
    return null;
  }
}

function writeLessonRecovery(draft: LessonRecoveryDraft): void {
  try {
    window.localStorage.setItem(lessonRecoveryKey(draft.lessonId), JSON.stringify(draft));
  } catch {
    // The editor still keeps the current in-memory value when storage is unavailable.
  }
}

function removeLessonRecovery(lessonId: string): void {
  try {
    window.localStorage.removeItem(lessonRecoveryKey(lessonId));
  } catch {
    // Ignore unavailable browser storage.
  }
}

function lessonSaveStatusLabel(status: LessonSaveStatus, dirty: boolean): string {
  if (status === "saving") {
    return "저장 중";
  }

  if (status === "blocked") {
    return "확인 필요";
  }

  if (status === "conflict") {
    return "저장 충돌";
  }

  if (status === "error") {
    return "저장 실패";
  }

  return dirty ? "저장 대기" : "저장됨";
}

function createLessonSource(
  plateValue: PlateValue,
  assets: LessonAsset[],
  visuals: Array<Record<string, unknown>>,
) {
  return {
    assets,
    plateValue,
    schemaVersion: "1.0.0",
    visuals,
  };
}

function isPlateDocumentSource(
  value: unknown,
): value is { assets?: unknown; plateValue: Array<unknown>; visuals?: unknown } {
  return Boolean(
    value &&
      typeof value === "object" &&
      "plateValue" in value &&
      Array.isArray((value as { plateValue?: unknown }).plateValue),
  );
}

function normalizeAssets(value: unknown): LessonAsset[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.flatMap((item) => {
    if (!item || typeof item !== "object") {
      return [];
    }

    const candidate = item as Partial<LessonAsset>;
    const url = typeof candidate.url === "string" ? candidate.url.trim() : "";

    if (!url) {
      return [];
    }

    const kind = isLessonAssetKind(candidate.kind) ? candidate.kind : "file";

    return [
      {
        alt: typeof candidate.alt === "string" ? candidate.alt : undefined,
        caption: typeof candidate.caption === "string" ? candidate.caption : undefined,
        id: typeof candidate.id === "string" && candidate.id ? candidate.id : createAssetId(),
        kind,
        license: typeof candidate.license === "string" ? candidate.license : undefined,
        source: typeof candidate.source === "string" ? candidate.source : undefined,
        title:
          typeof candidate.title === "string" && candidate.title.trim()
            ? candidate.title.trim()
            : assetKindLabels[kind],
        url,
      },
    ];
  });
}

function readRecordArray(value: unknown): Array<Record<string, unknown>> {
  return Array.isArray(value)
    ? value.filter(
        (item): item is Record<string, unknown> =>
          Boolean(item) && typeof item === "object" && !Array.isArray(item),
      )
    : [];
}

function isLessonAssetKind(value: unknown): value is LessonAsset["kind"] {
  return (
    value === "audio" ||
    value === "embed" ||
    value === "file" ||
    value === "image" ||
    value === "video"
  );
}

function createAssetId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }

  return `asset-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function createWorkflowTemplate(template: WorkflowTemplate, title: string): PlateValue {
  if (template === "example") {
    return [
      { children: [{ text: title }], type: "h1" },
      { children: [{ text: "문제를 읽고 입력, 출력, 조건을 확인합니다." }], type: "p" },
      { children: [{ text: "문제" }], type: "h2" },
      { children: [{ text: "학습자가 풀어 볼 예제 상황을 작성하세요." }], type: "p" },
      { children: [{ text: "접근" }], type: "h2" },
      { children: [{ text: "어떤 아이디어로 해결할지 단계별로 설명하세요." }], type: "p" },
      { children: [{ text: "풀이" }], type: "h2" },
      { children: [{ text: "핵심 코드나 계산 과정을 Plate 블록으로 추가하세요." }], type: "p" },
      { children: [{ text: "정리" }], type: "h2" },
      { children: [{ text: "이 예제에서 꼭 기억할 점을 한두 문장으로 정리하세요." }], type: "p" },
    ];
  }

  if (template === "quiz") {
    return [
      { children: [{ text: title }], type: "h1" },
      { children: [{ text: "학습자가 방금 배운 개념을 확인할 수 있는 문제를 만듭니다." }], type: "p" },
      createQuizBlock({
        question: "다음 중 핵심 개념을 가장 잘 설명한 것은 무엇인가요?",
        choices: [
          { id: "choice-1", text: "겉으로 보이는 결과만 외우는 것" },
          { id: "choice-2", text: "문제 해결 과정을 단계로 나누어 이해하는 것" },
          { id: "choice-3", text: "정답을 바로 확인하는 것" },
        ],
        correctChoiceId: "choice-2",
        explanation:
          "퀴즈는 학습자가 개념을 실제로 이해했는지 확인하는 장치입니다. 정답뿐 아니라 왜 그 선택지가 맞는지 해설까지 함께 제시해야 합니다.",
      }),
      { children: [{ text: "정리" }], type: "h2" },
      { children: [{ text: "퀴즈에서 확인한 내용을 한 문장으로 정리하세요." }], type: "p" },
    ];
  }

  return [
    { children: [{ text: title }], type: "h1" },
    { children: [{ text: "이 레슨에서 배울 핵심 개념을 한 문장으로 소개하세요." }], type: "p" },
    { children: [{ text: "핵심 개념" }], type: "h2" },
    { children: [{ text: "개념을 처음 보는 학습자도 이해할 수 있게 설명하세요." }], type: "p" },
    { children: [{ text: "예시" }], type: "h2" },
    { children: [{ text: "짧은 예시나 비유를 넣어 개념을 연결하세요." }], type: "p" },
    { children: [{ text: "확인 질문" }], type: "h2" },
    { children: [{ text: "학습자가 스스로 확인할 질문을 작성하세요." }], type: "p" },
  ];
}

function findFirstSectionNode(trees: ContentTree[]): ContentNode | null {
  for (const tree of trees) {
    for (const chapter of tree.chapters) {
      if (chapter.sections[0]?.section) {
        return chapter.sections[0].section;
      }
    }
  }

  return null;
}

function flattenContentTreeNodes(tree: ContentTree): ContentNode[] {
  const nodes: ContentNode[] = [tree.topic];

  for (const chapter of tree.chapters) {
    nodes.push(chapter.chapter);

    for (const section of chapter.sections) {
      nodes.push(section.section, ...section.lessons);
    }
  }

  return nodes;
}

function descendantNodeIds(tree: ContentTree, nodeId: string): string[] {
  if (tree.topic.id === nodeId) {
    return flattenContentTreeNodes(tree).map((node) => node.id);
  }

  for (const chapter of tree.chapters) {
    if (chapter.chapter.id === nodeId) {
      return [
        chapter.chapter.id,
        ...chapter.sections.flatMap((section) => [
          section.section.id,
          ...section.lessons.map((lesson) => lesson.id),
        ]),
      ];
    }

    for (const section of chapter.sections) {
      if (section.section.id === nodeId) {
        return [
          section.section.id,
          ...section.lessons.map((lesson) => lesson.id),
        ];
      }

      const lesson = section.lessons.find((item) => item.id === nodeId);

      if (lesson) {
        return [lesson.id];
      }
    }
  }

  return [];
}

function updateNodeInTrees(
  trees: ContentTree[],
  nodeId: string,
  patch: Partial<Pick<ContentNode, "status" | "title">>,
): ContentTree[] {
  const updateNode = (node: ContentNode): ContentNode =>
    node.id === nodeId ? { ...node, ...patch } : node;

  return trees.map((tree) => ({
    ...tree,
    topic: updateNode(tree.topic),
    chapters: tree.chapters.map((chapter) => ({
      ...chapter,
      chapter: updateNode(chapter.chapter),
      sections: chapter.sections.map((section) => ({
        ...section,
        section: updateNode(section.section),
        lessons: section.lessons.map(updateNode),
      })),
    })),
  }));
}

function removeNodeFromTrees(trees: ContentTree[], nodeId: string): ContentTree[] {
  return trees
    .filter((tree) => tree.topic.id !== nodeId)
    .map((tree) => ({
      ...tree,
      chapters: tree.chapters
        .filter((chapter) => chapter.chapter.id !== nodeId)
        .map((chapter) => ({
          ...chapter,
          sections: chapter.sections
            .filter((section) => section.section.id !== nodeId)
            .map((section) => ({
              ...section,
              lessons: section.lessons.filter((lesson) => lesson.id !== nodeId),
            })),
        })),
    }));
}

function hasMeaningfulContent(plateValue: PlateValue, title?: string): boolean {
  const ignored = new Set([
    title?.trim(),
    "이곳에 레슨 본문을 작성하세요. Enter로 새 문단을 만들고, / 명령으로 Plate 블록을 추가할 수 있습니다.",
  ]);

  return plateValue.some((node) => {
    const text = extractNodeText(node).trim();

    return Boolean(text && !ignored.has(text));
  });
}

function extractNodeText(node: unknown): string {
  if (!node || typeof node !== "object") {
    return "";
  }

  if ("text" in node) {
    return String((node as { text?: unknown }).text ?? "");
  }

  const children = (node as { children?: unknown }).children;

  return Array.isArray(children) ? children.map(extractNodeText).join(" ") : "";
}

function buildQualityChecks({
  assets,
  plateValue,
  title,
}: {
  assets: LessonAsset[];
  plateValue: PlateValue;
  title?: string;
}): QualityCheck[] {
  const imageAssets = assets.filter((asset) => asset.kind === "image");
  const allAssetsHaveSource = assets.every(
    (asset) => Boolean(asset.source?.trim()) && Boolean(asset.license?.trim()),
  );
  const imageBlocks = collectPlateNodes(plateValue).filter((node) => {
    const type = String(node.type ?? "");

    return type === "img" || type === "image";
  });
  const allImageBlocksHaveAlt = imageBlocks.every((node) =>
    Boolean(String(node.alt ?? "").trim()),
  );
  const allImageAssetsHaveAlt = imageAssets.every((asset) => Boolean(asset.alt?.trim()));

  return [
    {
      help: title?.trim() ? "레슨 제목이 설정되어 있습니다." : "왼쪽 목차에서 레슨 제목을 정하세요.",
      key: "title",
      label: "레슨 제목",
      passed: Boolean(title?.trim()),
      required: true,
    },
    {
      help: "기본 안내 문장만 남아 있으면 학습 내용으로 보지 않습니다.",
      key: "body",
      label: "본문 내용",
      passed: hasMeaningfulContent(plateValue, title),
      required: true,
    },
    {
      help:
        imageAssets.length > 0 || imageBlocks.length > 0
          ? "Plate 미디어 블록에서 이미지 설명을 함께 입력하면 접근성이 좋아집니다."
          : "이미지가 없어서 추가 확인이 필요하지 않습니다.",
      key: "image-alt",
      label: "이미지 설명",
      passed: allImageAssetsHaveAlt && allImageBlocksHaveAlt,
      required: false,
    },
    {
      help:
        assets.length > 0
          ? "Plate로 삽입한 미디어의 출처와 라이선스는 이후 미디어 속성 패널에서 보강합니다."
          : "본문에 삽입한 외부 미디어가 없습니다.",
      key: "asset-source",
      label: "자료 출처와 라이선스",
      passed: assets.length === 0 || allAssetsHaveSource,
      required: false,
    },
  ];
}

function collectPlateNodes(nodes: unknown[]): Array<Record<string, unknown>> {
  const collected: Array<Record<string, unknown>> = [];

  for (const node of nodes) {
    if (!node || typeof node !== "object" || Array.isArray(node)) {
      continue;
    }

    const record = node as Record<string, unknown>;
    collected.push(record);

    if (Array.isArray(record.children)) {
      collected.push(...collectPlateNodes(record.children));
    }
  }

  return collected;
}

function collectDocumentAssets(
  plateValue: PlateValue,
  storedAssets: LessonAsset[],
): LessonAsset[] {
  const collected = new Map<string, LessonAsset>();

  for (const asset of storedAssets) {
    collected.set(asset.url, asset);
  }

  for (const node of collectPlateNodes(plateValue)) {
    const asset = createAssetFromPlateNode(node, collected.size);

    if (asset && !collected.has(asset.url)) {
      collected.set(asset.url, asset);
    }
  }

  return Array.from(collected.values());
}

function createAssetFromPlateNode(
  node: Record<string, unknown>,
  index: number,
): LessonAsset | null {
  const type = String(node.type ?? "");
  const url = typeof node.url === "string" ? node.url.trim() : "";

  if (!url) {
    return null;
  }

  const kind = assetKindFromPlateType(type);

  if (!kind) {
    return null;
  }

  return {
    alt: typeof node.alt === "string" ? node.alt : undefined,
    caption: readCaptionText(node.caption),
    id: typeof node.id === "string" && node.id ? node.id : `plate-asset-${index + 1}`,
    kind,
    license: typeof node.license === "string" ? node.license : undefined,
    source: typeof node.source === "string" ? node.source : undefined,
    title:
      typeof node.name === "string" && node.name.trim()
        ? node.name.trim()
        : typeof node.title === "string" && node.title.trim()
          ? node.title.trim()
          : assetKindLabels[kind],
    url,
  };
}

function assetKindFromPlateType(type: string): LessonAsset["kind"] | null {
  if (type === "img" || type === "image") {
    return "image";
  }

  if (type === "video") {
    return "video";
  }

  if (type === "audio") {
    return "audio";
  }

  if (type === "file") {
    return "file";
  }

  if (type === "media_embed") {
    return "embed";
  }

  return null;
}

function readCaptionText(value: unknown): string | undefined {
  if (!Array.isArray(value)) {
    return undefined;
  }

  const caption = value.map(extractNodeText).join(" ").trim();

  return caption || undefined;
}

function QualityChecklist({ checks }: { checks: QualityCheck[] }) {
  const failedRequiredCount = checks.filter((check) => check.required && !check.passed).length;

  return (
    <section className="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-base font-semibold text-slate-950">저장 전 품질 확인</h3>
          <p className="mt-1 text-sm text-slate-500">
            필수 항목을 통과해야 레슨을 저장할 수 있습니다.
          </p>
        </div>
        <span
          className={[
            "rounded-full border px-3 py-1 text-sm font-semibold",
            failedRequiredCount === 0
              ? "border-emerald-200 bg-emerald-50 text-emerald-700"
              : "border-amber-200 bg-amber-50 text-amber-700",
          ].join(" ")}
        >
          {failedRequiredCount === 0 ? "저장 가능" : `확인 필요 ${failedRequiredCount}개`}
        </span>
      </div>
      <div className="mt-3 grid gap-2 md:grid-cols-2">
        {checks.map((check) => (
          <div
            key={check.key}
            className={[
              "rounded-lg border p-3",
              check.passed
                ? "border-emerald-100 bg-emerald-50/60"
                : check.required
                  ? "border-amber-200 bg-amber-50"
                  : "border-slate-200 bg-slate-50",
            ].join(" ")}
          >
            <div className="flex items-center justify-between gap-2">
              <p className="font-semibold text-slate-950">{check.label}</p>
              <span
                className={[
                  "rounded-full px-2 py-0.5 text-xs font-semibold",
                  check.passed
                    ? "bg-emerald-100 text-emerald-700"
                    : check.required
                      ? "bg-amber-100 text-amber-700"
                      : "bg-slate-100 text-slate-500",
                ].join(" ")}
              >
                {check.passed ? "정상" : check.required ? "필수" : "선택"}
              </span>
            </div>
            <p className="mt-1 text-sm text-slate-600">{check.help}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

function AuthoringWorkflow({
  dirty,
  hasBody,
  hasLesson,
  onApplyTemplate,
  onSave,
  onStartLesson,
  qualityReady,
  viewMode,
}: {
  dirty: boolean;
  hasBody: boolean;
  hasLesson: boolean;
  onApplyTemplate: (template: WorkflowTemplate) => void;
  onSave: () => void;
  onStartLesson: () => void;
  qualityReady: boolean;
  viewMode: "editor" | "source";
}) {
  const steps = [
    { done: hasLesson, label: "레슨 선택" },
    { done: hasBody, label: "본문 작성" },
    { done: qualityReady, label: "품질 확인" },
    { done: hasLesson && hasBody && qualityReady && !dirty, label: "저장 완료" },
  ];
  const nextStep =
    !hasLesson
      ? "왼쪽 목차에서 레슨을 선택하거나 새 레슨을 만드세요."
      : !hasBody
        ? "템플릿을 적용한 뒤 본문을 수정하세요."
        : dirty
          ? "작성한 내용을 저장하세요."
          : "기본 작성 흐름이 완료되었습니다.";

  return (
    <section className="mb-4 rounded-xl border border-blue-100 bg-blue-50/70 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-slate-950">작성 흐름</h3>
          <p className="mt-1 text-sm text-slate-600">{nextStep}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {steps.map((step, index) => (
            <span
              key={step.label}
              className={[
                "rounded-full border px-3 py-1 text-sm font-semibold",
                step.done
                  ? "border-emerald-200 bg-emerald-50 text-emerald-700"
                  : "border-slate-200 bg-white text-slate-500",
              ].join(" ")}
            >
              {index + 1}. {step.label}
            </span>
          ))}
        </div>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <button
          className="rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"
          type="button"
          onClick={onStartLesson}
        >
          새 레슨 만들기
        </button>
        <button
          className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          disabled={!hasLesson}
          type="button"
          onClick={() => onApplyTemplate("concept")}
        >
          개념 설명 템플릿
        </button>
        <button
          className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          disabled={!hasLesson}
          type="button"
          onClick={() => onApplyTemplate("example")}
        >
          예제 풀이 템플릿
        </button>
        <button
          className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          disabled={!hasLesson}
          type="button"
          onClick={() => onApplyTemplate("quiz")}
        >
          확인 문제 템플릿
        </button>
        <button
          className="rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
          disabled={!hasLesson || !dirty || viewMode !== "editor"}
          type="button"
          onClick={onSave}
        >
          지금 저장
        </button>
      </div>
    </section>
  );
}

function SourcePanel({
  error,
  sourceText,
  onApply,
  onChange,
  onRefresh,
}: {
  error: string | null;
  sourceText: string;
  onApply: () => void;
  onChange: (value: string) => void;
  onRefresh: () => void;
}) {
  return (
    <div className="space-y-3 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-base font-semibold text-slate-950">레슨 소스</h3>
          <p className="mt-1 text-sm text-slate-500">
            Plate 문서 구조를 JSON으로 확인하고, 필요한 경우 수정해 다시 적용합니다.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            type="button"
            onClick={onRefresh}
          >
            현재 내용 불러오기
          </button>
          <button
            className="rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
            type="button"
            onClick={onApply}
          >
            소스 적용
          </button>
        </div>
      </div>
      {error ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
          {error}
        </div>
      ) : null}
      <textarea
        className="min-h-[520px] w-full resize-y rounded-lg border border-slate-200 bg-slate-950 p-4 font-mono text-sm leading-6 text-slate-100 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
        spellCheck={false}
        value={sourceText}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  );
}

function ImportResultPanel({
  result,
  onClose,
  onOpenLesson,
}: {
  result: ImportResultSummary;
  onClose: () => void;
  onOpenLesson: (lessonId: string) => void;
}) {
  const rows = [
    ["주제", result.created.topics, result.reused.topics],
    ["챕터", result.created.chapters, result.reused.chapters],
    ["장", result.created.sections, result.reused.sections],
    ["레슨", result.created.lessons, result.reused.lessons],
  ] as const;
  const changedLessons = result.replaced.lessons + result.skipped.lessons;

  return (
    <section className="border-b border-emerald-100 bg-emerald-50 px-8 py-4">
      <div className="rounded-xl border border-emerald-200 bg-white p-4 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-emerald-800">패키지 가져오기 완료</p>
            <h3 className="mt-1 text-lg font-semibold text-slate-950">{result.topic.title}</h3>
            <p className="mt-1 text-sm text-slate-600">
              {result.label} · {result.fileName} · {result.importedAt}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {result.selectedLessonId ? (
              <button
                className="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800"
                type="button"
                onClick={() => onOpenLesson(result.selectedLessonId as string)}
              >
                가져온 첫 레슨 열기
              </button>
            ) : null}
            <button
              className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
              type="button"
              onClick={onClose}
            >
              닫기
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 md:grid-cols-4">
          {rows.map(([label, created, reused]) => (
            <div key={label} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
              <p className="text-xs font-semibold text-slate-500">{label}</p>
              <p className="mt-1 text-sm font-semibold text-slate-900">
                새로 생성 {created}개
              </p>
              <p className="text-xs text-slate-500">기존 위치 재사용 {reused}개</p>
              {label === "레슨" && changedLessons ? (
                <p className="text-xs text-slate-500">
                  대체 {result.replaced.lessons}개 · 건너뜀 {result.skipped.lessons}개
                </p>
              ) : null}
            </div>
          ))}
        </div>

        <div className="mt-4 grid gap-3 text-sm md:grid-cols-3">
          <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
            <p className="text-xs font-semibold text-slate-500">가져온 주제 ID</p>
            <p className="mt-1 break-all font-mono text-xs text-slate-700">{result.topic.id}</p>
          </div>
          <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
            <p className="text-xs font-semibold text-slate-500">주제 슬러그</p>
            <p className="mt-1 break-all font-mono text-xs text-slate-700">{result.topic.slug}</p>
          </div>
          <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
            <p className="text-xs font-semibold text-slate-500">미디어/ID 매핑</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">
              미디어 {result.assetsImported ?? 0}개 · 항목 매핑 {result.mappedItems}개
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

function ContentManagementShell({
  children,
  canReload,
  collapsedNodeIds,
  dirty,
  drawerContent,
  drawerTitle,
  notice,
  previewUrl,
  qualityIssueCount,
  saveStatus,
  selectedContextLabel,
  selectedLessonId,
  selectedLessonTitle,
  selectedTopicId,
  trees,
  view,
  onCloseDrawer,
  onOpenDrawer,
  onOpenSource,
  onReimportFromJson,
  onReload,
  onResetDocument,
  onSave,
  onSelectLesson,
  onSelectTopic,
  onToggle,
  onViewChange,
}: {
  children: React.ReactNode;
  canReload: boolean;
  collapsedNodeIds: Set<string>;
  dirty: boolean;
  drawerContent: React.ReactNode | null;
  drawerTitle: string | null;
  notice: string;
  previewUrl: string;
  qualityIssueCount: number;
  saveStatus: LessonSaveStatus;
  selectedContextLabel: string;
  selectedLessonId: string | null;
  selectedLessonTitle: string | null;
  selectedTopicId: string | null;
  trees: ContentTree[];
  view: WorkspaceView;
  onCloseDrawer: () => void;
  onOpenDrawer: (drawer: WorkspaceDrawerView) => void;
  onOpenSource: () => void;
  onReimportFromJson?: () => void;
  onReload: () => void;
  onResetDocument: () => void;
  onSave: () => void;
  onSelectLesson: (lessonId: string) => void;
  onSelectTopic: (tree: ContentTree) => void;
  onToggle: (nodeId: string) => void;
  onViewChange: (view: WorkspaceView) => void;
}) {
  const [isMobileOutlineOpen, setIsMobileOutlineOpen] = React.useState(false);
  const selectedTree = trees.find((tree) => tree.topic.id === selectedTopicId) ?? null;
  const showOutline = view === "lesson";
  const isOverlayOpen = isMobileOutlineOpen || Boolean(drawerContent && drawerTitle);
  const heading = view === "lesson" && selectedLessonTitle
    ? selectedLessonTitle
    : workspaceViewLabels[view];

  const workspaceRef = React.useRef<HTMLDivElement>(null);
  const contentPaneRef = React.useRef<HTMLElement>(null);
  const [workspaceHeight, setWorkspaceHeight] = React.useState<number>();

  // Account for the console header and notices above the editor, including
  // changes to the server-import disclosure. Do not derive height from content.
  React.useLayoutEffect(() => {
    const workspace = workspaceRef.current;
    if (!showOutline || !workspace) return;

    const ancestors: HTMLElement[] = [];
    for (let parent = workspace.parentElement; parent; parent = parent.parentElement) {
      ancestors.push(parent);
    }
    let frame = 0;
    const measure = () => {
      const viewportHeight = window.visualViewport?.height ?? window.innerHeight;
      const top = Math.max(0, workspace.getBoundingClientRect().top);
      const bottomInset = Math.max(16, ancestors.reduce((sum, ancestor) => {
        const style = getComputedStyle(ancestor);
        return sum + parseFloat(style.paddingBottom) + parseFloat(style.borderBottomWidth);
      }, 0));
      setWorkspaceHeight(Math.max(160, Math.floor(viewportHeight - top - bottomInset)));
    };
    const scheduleMeasure = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(measure);
    };
    const observer = new ResizeObserver(scheduleMeasure);
    ancestors.forEach(ancestor => observer.observe(ancestor));
    window.addEventListener("resize", scheduleMeasure);
    window.addEventListener("scroll", scheduleMeasure, { passive: true });
    window.visualViewport?.addEventListener("resize", scheduleMeasure);
    measure();

    return () => {
      cancelAnimationFrame(frame);
      observer.disconnect();
      window.removeEventListener("resize", scheduleMeasure);
      window.removeEventListener("scroll", scheduleMeasure);
      window.visualViewport?.removeEventListener("resize", scheduleMeasure);
    };
  }, [showOutline]);

  React.useLayoutEffect(() => {
    if (contentPaneRef.current) {
      contentPaneRef.current.scrollTop = 0;
    }
  }, [selectedLessonId, view]);

  React.useEffect(() => {
    setIsMobileOutlineOpen(false);
  }, [selectedLessonId, view]);

  React.useEffect(() => {
    if (!isOverlayOpen) {
      return;
    }

    const previousOverflow = document.body.style.overflow;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== "Escape") {
        return;
      }

      if (drawerContent && drawerTitle) {
        onCloseDrawer();
      } else {
        setIsMobileOutlineOpen(false);
      }
    };

    document.body.style.overflow = "hidden";
    window.addEventListener("keydown", closeOnEscape);

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", closeOnEscape);
    };
  }, [drawerContent, drawerTitle, isOverlayOpen, onCloseDrawer]);

  const outlineTree = selectedTree ? (
    <TopicTree
      collapsedNodeIds={collapsedNodeIds}
      selectedLessonId={selectedLessonId}
      showActions={false}
      tree={selectedTree}
      onAdd={() => undefined}
      onArchive={() => undefined}
      onDelete={() => undefined}
      onEdit={() => undefined}
      onSelectLesson={(lessonId) => {
        setIsMobileOutlineOpen(false);
        onSelectLesson(lessonId);
      }}
      onSelectTopic={() => undefined}
      onToggle={onToggle}
    />
  ) : (
    <div className="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-600">
      선택한 주제의 목차가 없습니다.
    </div>
  );

  return (
    <div
      ref={workspaceRef}
      data-contents-workspace={view}
      className={showOutline
        ? "flex min-h-0 flex-col overflow-hidden bg-slate-50 text-slate-950"
        : "relative bg-slate-50 text-slate-950"}
      style={showOutline ? { height: workspaceHeight ?? "calc(100dvh - 8rem)" } : undefined}
    >
      <header className="sticky top-0 z-40 h-16 shrink-0 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-full max-w-[1680px] items-center justify-between gap-2 px-3 sm:px-5">
          <div className="flex min-w-0 flex-1 items-center gap-2">
            {showOutline ? (
              <button
                aria-label="목차 열기"
                aria-expanded={isMobileOutlineOpen}
                className="flex size-11 shrink-0 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 lg:hidden"
                title="목차 열기"
                type="button"
                onClick={() => setIsMobileOutlineOpen(true)}
              >
                <Menu aria-hidden="true" className="size-5" />
              </button>
            ) : null}

            <button
              aria-label="콘텐츠 라이브러리로 이동"
              className="flex size-11 shrink-0 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100"
              title="콘텐츠 라이브러리"
              type="button"
              onClick={() => onViewChange("library")}
            >
              <Library aria-hidden="true" className="size-5" />
            </button>

            {view !== "library" ? (
              <select
                aria-label="작업할 주제 선택"
                className="hidden max-w-52 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 md:block"
                value={selectedTopicId ?? ""}
                onChange={(event) => {
                  const nextTree = trees.find((tree) => tree.topic.id === event.target.value);

                  if (nextTree) {
                    onSelectTopic(nextTree);
                  }
                }}
              >
                {trees.map((tree) => (
                  <option key={tree.topic.id} value={tree.topic.id}>
                    {tree.topic.title}
                  </option>
                ))}
              </select>
            ) : null}

            <div className="min-w-0">
              <h1 className="truncate text-sm font-semibold text-slate-950 sm:text-base">
                {heading}
              </h1>
              <p className="hidden truncate text-xs text-slate-500 xl:block">
                {selectedContextLabel}
              </p>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-1">
            {view === "outline" && selectedLessonId ? (
              <button
                className="hidden items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:flex"
                type="button"
                onClick={() => onViewChange("lesson")}
              >
                <BookOpen aria-hidden="true" className="size-4" />
                레슨 작성
              </button>
            ) : null}

            {view === "lesson" ? (
              <>
                <button
                  className="hidden rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 lg:block"
                  type="button"
                  onClick={() => onViewChange("outline")}
                >
                  목차 편집
                </button>
                <span
                  className={[
                    "inline-flex max-w-24 truncate rounded-full border px-2 py-1 text-[11px] font-semibold sm:max-w-none sm:px-2.5 sm:text-xs",
                    saveStatus === "error" || saveStatus === "conflict"
                      ? "border-red-200 bg-red-50 text-red-700"
                      : saveStatus === "saving" || dirty
                        ? "border-amber-200 bg-amber-50 text-amber-700"
                        : "border-emerald-200 bg-emerald-50 text-emerald-700",
                  ].join(" ")}
                  role="status"
                >
                  {lessonSaveStatusLabel(saveStatus, dirty)}
                </span>
                {qualityIssueCount > 0 ? (
                  <button
                    aria-label={`확인 필요 ${qualityIssueCount}개`}
                    className="hidden h-11 items-center gap-2 rounded-md px-2.5 text-sm font-semibold text-amber-800 hover:bg-amber-50 sm:flex"
                    title="작성 및 품질 확인"
                    type="button"
                    onClick={() => onOpenDrawer("quality")}
                  >
                    <ShieldCheck aria-hidden="true" className="size-5" />
                    <span className="hidden xl:inline">확인 필요 {qualityIssueCount}</span>
                  </button>
                ) : null}
                {canReload ? (
                  <button
                    aria-label="서버 최신본 확인"
                    className="hidden size-11 items-center justify-center rounded-md text-amber-800 hover:bg-amber-50 sm:flex"
                    title="서버 최신본 확인"
                    type="button"
                    onClick={onReload}
                  >
                    <RefreshCw aria-hidden="true" className="size-5" />
                  </button>
                ) : null}
                <button
                  aria-label="기준본으로 되돌리기"
                  className="hidden size-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 sm:flex"
                  title="기준본으로 되돌리기"
                  type="button"
                  onClick={onResetDocument}
                >
                  <RotateCcw aria-hidden="true" className="size-5" />
                </button>
                <button
                  aria-label="현재 레슨 저장"
                  className="hidden size-11 items-center justify-center rounded-md bg-slate-950 text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300 sm:flex"
                  disabled={!dirty || saveStatus === "saving"}
                  title="저장"
                  type="button"
                  onClick={onSave}
                >
                  <Save aria-hidden="true" className="size-5" />
                </button>
              </>
            ) : null}

            {view !== "library" && previewUrl ? (
              <a
                aria-label="학생 화면 미리보기"
                className="hidden size-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 sm:flex"
                href={previewUrl}
                rel="noreferrer"
                target="_blank"
                title="학생 화면 미리보기"
              >
                <Eye aria-hidden="true" className="size-5" />
              </a>
            ) : null}

            {view !== "library" ? (
              <button
                className="hidden rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 lg:block"
                type="button"
                onClick={() => onOpenDrawer("publish")}
              >
                공개
              </button>
            ) : null}

            <DropdownMenu modal={false}>
              <DropdownMenuTrigger asChild>
                <button
                  aria-label="보조 기능 열기"
                  className="flex size-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 data-[state=open]:bg-slate-100"
                  title="더보기"
                  type="button"
                >
                  <MoreHorizontal aria-hidden="true" className="size-5" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="z-[2147483000] w-56" sideOffset={6}>
                {view === "lesson" ? (
                  <>
                    <DropdownMenuItem onSelect={() => onOpenDrawer("quality")}>
                      <ShieldCheck aria-hidden="true" className="mr-2 size-4" />
                      작성 및 품질 확인
                    </DropdownMenuItem>
                    {dirty ? (
                      <DropdownMenuItem onSelect={onSave}>
                        <Save aria-hidden="true" className="mr-2 size-4" />
                        지금 저장
                      </DropdownMenuItem>
                    ) : null}
                    <DropdownMenuItem onSelect={onResetDocument}>
                      <RotateCcw aria-hidden="true" className="mr-2 size-4" />
                      기준본으로 되돌리기
                    </DropdownMenuItem>
                    {canReload ? (
                      <DropdownMenuItem onSelect={onReload}>
                        <RefreshCw aria-hidden="true" className="mr-2 size-4" />
                        서버 최신본 확인
                      </DropdownMenuItem>
                    ) : null}
                    <DropdownMenuItem onSelect={onOpenSource}>
                      <FileCode2 aria-hidden="true" className="mr-2 size-4" />
                      소스 보기
                    </DropdownMenuItem>
                    {onReimportFromJson ? (
                      <DropdownMenuItem onSelect={onReimportFromJson}>
                        <FileJson2 aria-hidden="true" className="mr-2 size-4" />
                        data/lessons JSON에서 다시 가져오기
                      </DropdownMenuItem>
                    ) : null}
                  </>
                ) : null}
                {view !== "library" && previewUrl ? (
                  <DropdownMenuItem asChild>
                    <a href={previewUrl} rel="noreferrer" target="_blank">
                      <Eye aria-hidden="true" className="mr-2 size-4" />
                      학생 화면 미리보기
                    </a>
                  </DropdownMenuItem>
                ) : null}
                {view !== "library" ? (
                  <DropdownMenuItem onSelect={() => onOpenDrawer("publish")}>
                    <Eye aria-hidden="true" className="mr-2 size-4" />
                    공개 설정
                  </DropdownMenuItem>
                ) : null}
                <DropdownMenuItem onSelect={() => onOpenDrawer("package")}>
                  <PackageOpen aria-hidden="true" className="mr-2 size-4" />
                  패키지 가져오기/내보내기
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onOpenDrawer("archive")}>
                  <Archive aria-hidden="true" className="mr-2 size-4" />
                  보관함
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </header>

      {notice ? (
        <div className="shrink-0 border-b border-slate-200 bg-white px-4 py-2 text-xs text-slate-600" role="status">
          <p className="mx-auto max-w-[1680px] truncate">{notice}</p>
        </div>
      ) : null}

      <div
        data-contents-workspace-split={showOutline ? "" : undefined}
        className={showOutline
          ? "grid min-h-0 flex-1 grid-cols-1"
          : "block"}
      >
        {showOutline ? (
          <aside className="hidden min-h-0 min-w-0 flex-col border-r border-slate-200 bg-white lg:flex">
            <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-3">
              <div>
                <p className="text-sm font-semibold text-slate-950">목차</p>
                <p className="mt-0.5 text-xs text-slate-500">레슨을 선택해 바로 이동합니다.</p>
              </div>
              <button
                className="rounded-md px-2 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                type="button"
                onClick={() => onViewChange("outline")}
              >
                편집
              </button>
            </div>
            <div
              aria-label="레슨 목차"
              className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3"
              data-contents-outline-scroll
              role="region"
              tabIndex={0}
            >{outlineTree}</div>
          </aside>
        ) : null}

        {showOutline ? <OutlineResizeHandle /> : null}

        <main
          ref={contentPaneRef}
          aria-label={showOutline ? "레슨 본문" : undefined}
          className={`min-w-0 px-3 sm:px-5${showOutline ? " min-h-0 overflow-y-auto overscroll-contain" : ""}`}
          data-contents-content-scroll={showOutline ? "" : undefined}
          tabIndex={showOutline ? 0 : undefined}
        >
          <div className="mx-auto max-w-[1500px] space-y-4 py-4 sm:py-5">{children}</div>
        </main>
      </div>

      {showOutline && isMobileOutlineOpen ? (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            aria-label="목차 닫기"
            className="absolute inset-0 bg-slate-950/35"
            type="button"
            onClick={() => setIsMobileOutlineOpen(false)}
          />
          <aside
            aria-label="레슨 목차"
            aria-modal="true"
            className="absolute inset-y-0 left-0 flex w-[min(90vw,360px)] flex-col bg-white shadow-2xl"
            role="dialog"
          >
            <div className="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 px-4">
              <div>
                <p className="font-semibold text-slate-950">목차</p>
                <p className="text-xs text-slate-500">{selectedTree?.topic.title ?? "주제 선택 필요"}</p>
              </div>
              <button
                aria-label="목차 닫기"
                className="flex size-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100"
                title="닫기"
                type="button"
                onClick={() => setIsMobileOutlineOpen(false)}
              >
                <X aria-hidden="true" className="size-5" />
              </button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3" data-contents-mobile-outline-scroll>{outlineTree}</div>
            <div className="shrink-0 border-t border-slate-200 p-3">
              <button
                className="w-full rounded-md border border-slate-200 px-3 py-3 text-sm font-semibold text-slate-700"
                type="button"
                onClick={() => {
                  setIsMobileOutlineOpen(false);
                  onViewChange("outline");
                }}
              >
                목차 편집
              </button>
            </div>
          </aside>
        </div>
      ) : null}

      {drawerContent && drawerTitle ? (
        <div className="fixed inset-0 z-[60]">
          <button
            aria-label={`${drawerTitle} 닫기`}
            className="absolute inset-0 bg-slate-950/35"
            type="button"
            onClick={onCloseDrawer}
          />
          <aside
            aria-labelledby="content-workspace-drawer-title"
            aria-modal="true"
            className="absolute inset-y-0 right-0 flex w-full flex-col bg-slate-50 shadow-2xl sm:max-w-[600px]"
            role="dialog"
          >
            <div className="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-5">
              <h2 id="content-workspace-drawer-title" className="font-semibold text-slate-950">
                {drawerTitle}
              </h2>
              <button
                aria-label={`${drawerTitle} 닫기`}
                className="flex size-11 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100"
                title="닫기"
                type="button"
                onClick={onCloseDrawer}
              >
                <X aria-hidden="true" className="size-5" />
              </button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">{drawerContent}</div>
          </aside>
        </div>
      ) : null}
    </div>
  );
}

async function confirmContentAction(title: string, message: string, destructive = false): Promise<boolean> {
  const host = window as Window & { OJP?: { confirm?: (options: { title: string; message: string; confirmLabel: string; destructive: boolean }) => Promise<boolean> } };
  return host.OJP?.confirm
    ? host.OJP.confirm({ title, message, confirmLabel: title, destructive })
    : window.confirm(message);
}

function restoreDemoBranch(trees: ContentTree[], snapshot: ContentTree, node: ContentNode): ContentTree[] {
  const branch = updateNodeInTrees([snapshot], node.id, { status: "draft" })[0];
  if (node.type === "topic") return [...trees, branch];
  if (!findContentNodeById(trees, node.parentId)) throw new Error("상위 항목을 먼저 복원해 주세요.");
  return trees.map((tree) => tree.topic.id !== snapshot.topic.id ? tree : normalizeOutlineTree({
    ...tree,
    chapters: node.type === "chapter" ? [...tree.chapters, branch.chapters[0]] : tree.chapters.map((chapter) => ({
      ...chapter,
      sections: node.type === "section" && chapter.chapter.id === node.parentId
        ? [...chapter.sections, branch.chapters[0].sections[0]]
        : chapter.sections.map((section) => node.type === "lesson" && section.section.id === node.parentId
          ? { ...section, lessons: [...section.lessons, branch.chapters[0].sections[0].lessons[0]] }
          : section),
    })),
  }));
}

function ContentRemovalButtons({ node, disabled = false, onArchive, onDelete }: {
  node: ContentNode;
  disabled?: boolean;
  onArchive: (node: ContentNode) => void;
  onDelete: (node: ContentNode) => void;
}) {
  return <div className="flex shrink-0 items-center gap-1" role="group" aria-label={`${node.title} 관리`}>
    <button type="button" title="보관함으로 이동" aria-label={`${node.title} 보관함으로 이동`} disabled={disabled}
      className="inline-flex size-10 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 disabled:opacity-40"
      onClick={() => onArchive(node)}><Archive className="size-4" aria-hidden="true" /></button>
    <button type="button" title="영구 삭제" aria-label={`${node.title} 영구 삭제`} disabled={disabled}
      className="inline-flex size-10 items-center justify-center rounded-md border border-red-200 bg-white text-red-700 hover:bg-red-50 disabled:opacity-40"
      onClick={() => onDelete(node)}><Trash2 className="size-4" aria-hidden="true" /></button>
  </div>;
}

function TopicLibraryPage({
  trees,
  canPublish,
  busy,
  onArchive,
  onDelete,
  onOpenArchive,
  onCreateTopic,
  onOpenTopic,
}: {
  trees: ContentTree[];
  canPublish: boolean;
  busy: boolean;
  onArchive: (node: ContentNode) => void;
  onDelete: (node: ContentNode) => void;
  onOpenArchive: () => void;
  onCreateTopic: () => void;
  onOpenTopic: (tree: ContentTree) => void;
}) {
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3 py-2">
        <div>
          <h2 className="text-xl font-semibold text-slate-950">콘텐츠 라이브러리</h2>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            주제를 선택하면 목차와 레슨 제작 작업공간이 열립니다.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
        <button type="button" onClick={onOpenArchive} className="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
          <Archive className="size-4" aria-hidden="true" />보관함
        </button>
        <button
          className="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
          type="button"
          onClick={onCreateTopic}
        >
          새 주제
        </button>
        </div>
      </div>

      {trees.length === 0 ? (
        <EmptyWorkspaceNotice
          title="아직 주제가 없습니다."
          description="새 주제를 만든 뒤 챕터, 장, 레슨을 추가하세요."
          actionLabel="주제 만들기"
          onAction={onCreateTopic}
        />
      ) : (
        <div className="grid gap-4 xl:grid-cols-2">
          {trees.map((tree) => {
            const stats = topicTreeStats(tree);

            return (
              <article
                key={tree.topic.id}
                aria-label={tree.topic.title}
                className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">
                        주제
                      </span>
                      <span className={statusBadgeClass(tree.topic.status)}>
                        {contentStatusLabels[tree.topic.status]}
                      </span>
                    </div>
                    <h3 className="mt-3 break-words text-lg font-semibold text-slate-950">
                      {tree.topic.title}
                    </h3>
                  </div>
                  <button
                    className="rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    type="button"
                    onClick={() => onOpenTopic(tree)}
                  >
                    열기
                  </button>
                </div>

                <div className="mt-4 grid grid-cols-3 gap-2 text-sm">
                  <StatPill label="챕터" value={stats.chapters} />
                  <StatPill label="장" value={stats.sections} />
                  <StatPill label="레슨" value={stats.lessons} />
                </div>
                <div className="mt-3 flex justify-end border-t border-slate-100 pt-3">
                  <ContentRemovalButtons node={tree.topic} disabled={busy || (!canPublish && tree.topic.status === "published")} onArchive={onArchive} onDelete={onDelete} />
                </div>
              </article>
            );
          })}
        </div>
      )}
    </section>
  );
}

function OutlineWorkspacePage({
  bulkSelectedNodeIds,
  isNodeActionPending,
  bulkStatus,
  canPublish,
  collapsedNodeIds,
  isBulkUpdatingStatus,
  selectedNodeId,
  tree,
  onArchive,
  onBulkStatusApply,
  onBulkStatusChange,
  onClearBulkSelection,
  onCreateNode,
  onDelete,
  onDuplicate,
  onExport,
  onMoveNode,
  onOpenLesson,
  onRenameNode,
  onSelectAllBulkNodes,
  onSelectBulkNode,
  onSelectNode,
  onStatusChange,
  onToggle,
}: {
  bulkSelectedNodeIds: Set<string>;
  isNodeActionPending: boolean;
  bulkStatus: ContentStatus;
  canPublish: boolean;
  collapsedNodeIds: Set<string>;
  isBulkUpdatingStatus: boolean;
  selectedNodeId: string | null;
  tree: ContentTree;
  onArchive: (node: ContentNode) => void;
  onBulkStatusApply: () => void;
  onBulkStatusChange: (status: ContentStatus) => void;
  onClearBulkSelection: () => void;
  onCreateNode: (input: {
    insertIndex: number;
    parentId: string;
    title: string;
    type: Exclude<ContentNodeType, "topic">;
  }) => Promise<ContentNode | null>;
  onDelete: (node: ContentNode) => void;
  onDuplicate: (node: ContentNode) => Promise<ContentNode | null>;
  onExport: (node: ContentNode) => Promise<void>;
  onMoveNode: (node: ContentNode, parentId: string, index: number) => Promise<boolean>;
  onOpenLesson: (lessonId: string) => void;
  onRenameNode: (node: ContentNode, title: string) => Promise<boolean>;
  onSelectAllBulkNodes: () => void;
  onSelectBulkNode: (nodeId: string, checked: boolean) => void;
  onSelectNode: (node: ContentNode) => void;
  onStatusChange: (node: ContentNode, status: ContentStatus) => Promise<boolean>;
  onToggle: (nodeId: string) => void;
}) {
  const [query, setQuery] = React.useState("");
  const [statusFilter, setStatusFilter] = React.useState<OutlineStatusFilter>("all");
  const [selectionMode, setSelectionMode] = React.useState(false);
  const [inlineEditor, setInlineEditor] = React.useState<OutlineInlineEditor | null>(null);
  const [isSavingInline, setIsSavingInline] = React.useState(false);
  const [draggedNodeId, setDraggedNodeId] = React.useState<string | null>(null);
  const [dropTarget, setDropTarget] = React.useState<OutlineDropTarget | null>(null);
  const [movingNodeId, setMovingNodeId] = React.useState<string | null>(null);
  const [visibleLimit, setVisibleLimit] = React.useState(200);
  const allRows = React.useMemo(() => buildOutlineRows(tree), [tree]);
  const actionNode = allRows.find((row) => row.node.id === selectedNodeId)?.node ?? tree.topic;
  const stats = topicTreeStats(tree);
  const normalizedQuery = query.trim().toLocaleLowerCase();
  const filtering = Boolean(normalizedQuery) || statusFilter !== "all";

  const filteredRows = React.useMemo(() => {
    if (!filtering) {
      return allRows.filter(
        (row) => !row.ancestorIds.some((ancestorId) => collapsedNodeIds.has(ancestorId)),
      );
    }

    const includedIds = new Set<string>();

    for (const row of allRows) {
      const matchesQuery = !normalizedQuery
        || row.node.title.toLocaleLowerCase().includes(normalizedQuery)
        || row.node.id.toLocaleLowerCase().includes(normalizedQuery);
      const matchesStatus = statusFilter === "all" || row.node.status === statusFilter;

      if (matchesQuery && matchesStatus) {
        includedIds.add(row.node.id);
        row.ancestorIds.forEach((id) => includedIds.add(id));
      }
    }

    return allRows.filter((row) => includedIds.has(row.node.id));
  }, [allRows, collapsedNodeIds, filtering, normalizedQuery, statusFilter]);

  React.useEffect(() => {
    setVisibleLimit(200);
  }, [normalizedQuery, statusFilter, tree.topic.id]);

  React.useEffect(() => {
    const selectedIndex = filteredRows.findIndex((row) => row.node.id === selectedNodeId);

    if (selectedIndex >= visibleLimit) {
      setVisibleLimit(Math.ceil((selectedIndex + 1) / 200) * 200);
    }
  }, [filteredRows, selectedNodeId, visibleLimit]);

  const visibleRows = filteredRows.slice(0, visibleLimit);
  const selectedCount = bulkSelectedNodeIds.size;

  function childCreationAnchor(row: OutlineRow): string {
    if (collapsedNodeIds.has(row.node.id)) {
      onToggle(row.node.id);
      return row.node.id;
    }

    const visibleDescendants = visibleRows.filter((candidate) =>
      candidate.ancestorIds.includes(row.node.id),
    );

    return visibleDescendants.at(-1)?.node.id ?? row.node.id;
  }

  function beginChildCreate(row: OutlineRow) {
    const type = outlineChildType(row.node.type);

    if (!type) {
      return;
    }

    setInlineEditor({
      anchorNodeId: childCreationAnchor(row),
      depth: row.depth + 1,
      insertIndex: row.childCount,
      mode: "create",
      parentId: row.node.id,
      type,
      value: "",
    });
  }

  function beginCreateAfter(row: OutlineRow) {
    if (row.node.type === "topic" || !row.parentId) {
      beginChildCreate(row);
      return;
    }

    setInlineEditor({
      anchorNodeId: row.node.id,
      depth: row.depth,
      insertIndex: row.siblingIndex + 1,
      mode: "create",
      parentId: row.parentId,
      type: row.node.type,
      value: "",
    });
  }

  function beginRename(row: OutlineRow) {
    onSelectNode(row.node);
    setInlineEditor({
      anchorNodeId: row.node.id,
      depth: row.depth,
      mode: "rename",
      node: row.node,
      value: row.node.title,
    });
  }

  async function submitInlineEditor(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!inlineEditor || !inlineEditor.value.trim() || isSavingInline) {
      return;
    }

    setIsSavingInline(true);

    try {
      const succeeded = inlineEditor.mode === "create"
        ? Boolean(await onCreateNode({
            insertIndex: inlineEditor.insertIndex,
            parentId: inlineEditor.parentId,
            title: inlineEditor.value,
            type: inlineEditor.type,
          }))
        : await onRenameNode(inlineEditor.node, inlineEditor.value);

      if (succeeded) {
        setInlineEditor(null);
      }
    } finally {
      setIsSavingInline(false);
    }
  }

  function resolveDropTarget(
    draggedNode: ContentNode,
    targetRow: OutlineRow,
    event: React.DragEvent<HTMLDivElement>,
  ): OutlineDropTarget | null {
    if (draggedNode.id === targetRow.node.id) {
      return null;
    }

    if (draggedNode.type === targetRow.node.type) {
      const bounds = event.currentTarget.getBoundingClientRect();
      const position = event.clientY < bounds.top + bounds.height / 2 ? "before" : "after";

      return { nodeId: targetRow.node.id, position };
    }

    if (outlineParentType(draggedNode.type) === targetRow.node.type) {
      return { nodeId: targetRow.node.id, position: "inside" };
    }

    return null;
  }

  function handleDragOver(row: OutlineRow, event: React.DragEvent<HTMLDivElement>) {
    const draggedNode = findContentNodeById([tree], draggedNodeId);

    if (!draggedNode) {
      return;
    }

    const nextTarget = resolveDropTarget(draggedNode, row, event);

    if (!nextTarget) {
      return;
    }

    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    if (
      dropTarget?.nodeId !== nextTarget.nodeId
      || dropTarget.position !== nextTarget.position
    ) {
      setDropTarget(nextTarget);
    }
  }

  async function handleDrop(row: OutlineRow, event: React.DragEvent<HTMLDivElement>) {
    event.preventDefault();
    const draggedNode = findContentNodeById([tree], draggedNodeId);
    const activeTarget = draggedNode ? resolveDropTarget(draggedNode, row, event) : null;

    setDropTarget(null);
    setDraggedNodeId(null);

    if (!draggedNode || !activeTarget) {
      return;
    }

    let targetParentId: string;
    let targetIndex: number;

    if (activeTarget.position === "inside") {
      targetParentId = row.node.id;
      targetIndex = outlineSiblingNodes([tree], draggedNode.type, targetParentId)
        .filter((sibling) => sibling.id !== draggedNode.id)
        .length;
    } else {
      if (!row.parentId) {
        return;
      }

      targetParentId = row.parentId;
      const siblings = outlineSiblingNodes([tree], draggedNode.type, targetParentId)
        .filter((sibling) => sibling.id !== draggedNode.id);
      const anchorIndex = siblings.findIndex((sibling) => sibling.id === row.node.id);

      if (anchorIndex < 0) {
        return;
      }

      targetIndex = anchorIndex + (activeTarget.position === "after" ? 1 : 0);
    }

    setMovingNodeId(draggedNode.id);
    try {
      await onMoveNode(draggedNode, targetParentId, targetIndex);
    } finally {
      setMovingNodeId(null);
    }
  }

  async function moveByOffset(row: OutlineRow, offset: -1 | 1) {
    if (!row.parentId) {
      return;
    }

    const targetIndex = row.siblingIndex + offset;

    if (targetIndex < 0 || targetIndex >= row.siblingCount) {
      return;
    }

    setMovingNodeId(row.node.id);
    try {
      await onMoveNode(row.node, row.parentId, targetIndex);
    } finally {
      setMovingNodeId(null);
    }
  }

  async function moveToParent(row: OutlineRow, parentId: string) {
    const targetIndex = outlineSiblingNodes([tree], row.node.type, parentId)
      .filter((sibling) => sibling.id !== row.node.id)
      .length;

    setMovingNodeId(row.node.id);
    try {
      await onMoveNode(row.node, parentId, targetIndex);
    } finally {
      setMovingNodeId(null);
    }
  }

  function parentOptionsFor(row: OutlineRow): ContentNode[] {
    const parentType = outlineParentType(row.node.type);

    return parentType
      ? allRows.filter((candidate) => candidate.node.type === parentType).map((candidate) => candidate.node)
      : [];
  }

  return (
    <section className="overflow-hidden border border-slate-200 bg-white shadow-sm">
      <header className="border-b border-slate-200 px-4 py-4 sm:px-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs font-semibold text-slate-500">목차 편집</p>
            <h2 className="mt-1 truncate text-xl font-semibold text-slate-950">{tree.topic.title}</h2>
            <p className="mt-1 text-xs text-slate-500">
              챕터 {stats.chapters} · 장 {stats.sections} · 레슨 {stats.lessons}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              aria-pressed={selectionMode}
              className={[
                "inline-flex h-10 items-center gap-2 rounded-md border px-3 text-sm font-semibold",
                selectionMode
                  ? "border-blue-200 bg-blue-50 text-blue-700"
                  : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
              ].join(" ")}
              type="button"
              onClick={() => {
                const nextMode = !selectionMode;

                setSelectionMode(nextMode);
                if (!nextMode) {
                  onClearBulkSelection();
                }
              }}
            >
              <CheckSquare2 aria-hidden="true" className="size-4" />
              여러 항목 선택
            </button>
            <button
              className="inline-flex h-10 items-center gap-2 rounded-md bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700"
              type="button"
              onClick={() => beginChildCreate(allRows[0])}
            >
              <Plus aria-hidden="true" className="size-4" />
              챕터 추가
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_180px]">
          <label className="relative block">
            <span className="sr-only">목차 검색</span>
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400"
            />
            <input
              className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
              placeholder="제목 또는 ID 검색"
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
            />
          </label>
          <label className="relative block">
            <span className="sr-only">공개 상태 필터</span>
            <ListFilter
              aria-hidden="true"
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400"
            />
            <select
              className="h-10 w-full appearance-none rounded-md border border-slate-200 bg-white pl-9 pr-8 text-sm font-medium text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value as OutlineStatusFilter)}
            >
              <option value="all">모든 상태</option>
              {Object.entries(contentStatusLabels).map(([status, label]) => (
                <option key={status} value={status}>{label}</option>
              ))}
            </select>
          </label>
        </div>
      </header>

      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2" aria-label="선택 항목 관리">
        <span className="min-w-0 break-words text-sm font-semibold">{actionNode.title}</span>
        <ContentRemovalButtons node={actionNode}
          disabled={isNodeActionPending || (!canPublish && actionNode.status === "published")}
          onArchive={onArchive} onDelete={onDelete} />
      </div>

      {selectionMode ? (
        <div className="flex flex-wrap items-center gap-2 border-b border-blue-100 bg-blue-50 px-4 py-3 sm:px-5">
          <span className="mr-1 text-sm font-semibold text-blue-950">선택 {selectedCount}개</span>
          <button
            className="rounded-md border border-blue-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100"
            type="button"
            onClick={onSelectAllBulkNodes}
          >
            전체 선택
          </button>
          <button
            className="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
            type="button"
            onClick={onClearBulkSelection}
          >
            선택 해제
          </button>
          <select
            aria-label="선택 항목의 변경 상태"
            className="h-8 rounded-md border border-blue-200 bg-white px-2 text-xs font-semibold text-slate-700"
            value={bulkStatus}
            onChange={(event) => onBulkStatusChange(event.target.value as ContentStatus)}
          >
            {bulkContentStatuses
              .filter((status) => canPublish || status !== "published")
              .map((status) => (
                <option key={status} value={status}>{contentStatusLabels[status]}</option>
              ))}
          </select>
          <button
            className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            disabled={selectedCount === 0 || isBulkUpdatingStatus}
            type="button"
            onClick={onBulkStatusApply}
          >
            {isBulkUpdatingStatus ? "변경 중" : "상태 적용"}
          </button>
        </div>
      ) : null}

      <div className="px-2 py-3 sm:px-4" role="tree" aria-label={`${tree.topic.title} 목차`}>
        {visibleRows.length === 0 ? (
          <div className="px-4 py-12 text-center">
            <p className="text-sm font-semibold text-slate-700">조건에 맞는 목차가 없습니다.</p>
            <p className="mt-1 text-xs text-slate-500">
              검색어 또는 상태 필터를 바꾸세요. 보관 항목은 상단 더보기의 보관함에서도 확인할 수 있습니다.
            </p>
          </div>
        ) : (
          visibleRows.map((row) => {
            const renameEditor = inlineEditor?.mode === "rename" && inlineEditor.node.id === row.node.id
              ? inlineEditor
              : null;
            const createEditor = inlineEditor?.mode === "create" && inlineEditor.anchorNodeId === row.node.id
              ? inlineEditor
              : null;
            const isCollapsed = collapsedNodeIds.has(row.node.id);
            const parentOptions = parentOptionsFor(row);

            return (
              <React.Fragment key={row.node.id}>
                <OutlineDirectRow
                  bulkSelected={bulkSelectedNodeIds.has(row.node.id)}
                  canPublish={canPublish}
                  dropPosition={dropTarget?.nodeId === row.node.id ? dropTarget.position : null}
                  isCollapsed={isCollapsed}
                  isMoving={movingNodeId === row.node.id}
                  isSelected={row.node.id === selectedNodeId}
                  parentOptions={parentOptions}
                  renameEditor={renameEditor}
                  row={row}
                  selectionMode={selectionMode}
                  onAddChild={() => beginChildCreate(row)}
                  onArchive={() => onArchive(row.node)}
                  onDelete={() => onDelete(row.node)}
                  onDragEnd={() => {
                    setDraggedNodeId(null);
                    setDropTarget(null);
                  }}
                  onDragOver={(event) => handleDragOver(row, event)}
                  onDragStart={(event) => {
                    event.dataTransfer.effectAllowed = "move";
                    event.dataTransfer.setData("text/plain", row.node.id);
                    setDraggedNodeId(row.node.id);
                    onSelectNode(row.node);
                  }}
                  onDrop={(event) => void handleDrop(row, event)}
                  onDuplicate={() => void onDuplicate(row.node)}
                  onEnterCreate={() => beginCreateAfter(row)}
                  onExport={() => void onExport(row.node)}
                  onMoveDown={() => void moveByOffset(row, 1)}
                  onMoveParent={(parentId) => void moveToParent(row, parentId)}
                  onMoveUp={() => void moveByOffset(row, -1)}
                  onOpenLesson={() => onOpenLesson(row.node.id)}
                  onRename={() => beginRename(row)}
                  onRenameCancel={() => setInlineEditor(null)}
                  onRenameChange={(value) => {
                    if (inlineEditor?.mode === "rename") {
                      setInlineEditor({ ...inlineEditor, value });
                    }
                  }}
                  onRenameSubmit={submitInlineEditor}
                  onSelect={() => onSelectNode(row.node)}
                  onSelectBulk={(checked) => onSelectBulkNode(row.node.id, checked)}
                  onStatusChange={(status) => void onStatusChange(row.node, status)}
                  onToggle={() => onToggle(row.node.id)}
                />

                {createEditor ? (
                  <form
                    className="mb-1 flex min-w-0 items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-2 py-2"
                    style={{ marginLeft: `${createEditor.depth * 24}px` }}
                    onSubmit={submitInlineEditor}
                  >
                    <span className="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-blue-700 ring-1 ring-blue-200">
                      새 {nodeLabels[createEditor.type]}
                    </span>
                    <input
                      autoFocus
                      aria-label={`새 ${nodeLabels[createEditor.type]} 이름`}
                      className="h-9 min-w-0 flex-1 rounded-md border border-blue-300 bg-white px-3 text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-200"
                      placeholder={`${nodeLabels[createEditor.type]} 이름`}
                      value={createEditor.value}
                      onChange={(event) => setInlineEditor({ ...createEditor, value: event.target.value })}
                      onKeyDown={(event) => {
                        if (event.key === "Escape") {
                          event.preventDefault();
                          setInlineEditor(null);
                        }
                      }}
                    />
                    <button
                      className="h-9 rounded-md bg-blue-600 px-3 text-xs font-semibold text-white disabled:bg-slate-300"
                      disabled={isSavingInline || !createEditor.value.trim()}
                      type="submit"
                    >
                      추가
                    </button>
                    <button
                      aria-label="추가 취소"
                      className="flex size-9 items-center justify-center rounded-md text-slate-500 hover:bg-white"
                      type="button"
                      onClick={() => setInlineEditor(null)}
                    >
                      <X aria-hidden="true" className="size-4" />
                    </button>
                  </form>
                ) : null}

                {!createEditor ? (
                  <div
                    className="group/insert relative h-3"
                    style={{ marginLeft: `${Math.max(1, row.depth) * 24}px` }}
                  >
                    <button
                      aria-label={`${nodeLabels[row.node.type === "topic" ? "chapter" : row.node.type]} 추가`}
                      className="absolute left-2 top-1/2 z-10 flex size-6 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-400 opacity-0 shadow-sm transition hover:border-blue-300 hover:text-blue-700 focus:opacity-100 group-hover/insert:opacity-100"
                      title={row.node.type === "topic" ? "챕터 추가" : `같은 단계 ${nodeLabels[row.node.type]} 추가`}
                      type="button"
                      onClick={() => beginCreateAfter(row)}
                    >
                      <Plus aria-hidden="true" className="size-3.5" />
                    </button>
                    <span className="absolute inset-x-0 top-1/2 h-px bg-transparent group-hover/insert:bg-blue-100" />
                  </div>
                ) : null}
              </React.Fragment>
            );
          })
        )}

        {filteredRows.length > visibleRows.length ? (
          <button
            className="mt-3 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            type="button"
            onClick={() => setVisibleLimit((current) => current + 200)}
          >
            다음 {Math.min(200, filteredRows.length - visibleRows.length)}개 항목 보기
          </button>
        ) : null}
      </div>
    </section>
  );
}

function OutlineDirectRow({
  bulkSelected,
  canPublish,
  dropPosition,
  isCollapsed,
  isMoving,
  isSelected,
  parentOptions,
  renameEditor,
  row,
  selectionMode,
  onAddChild,
  onArchive,
  onDelete,
  onDragEnd,
  onDragOver,
  onDragStart,
  onDrop,
  onDuplicate,
  onEnterCreate,
  onExport,
  onMoveDown,
  onMoveParent,
  onMoveUp,
  onOpenLesson,
  onRename,
  onRenameCancel,
  onRenameChange,
  onRenameSubmit,
  onSelect,
  onSelectBulk,
  onStatusChange,
  onToggle,
}: {
  bulkSelected: boolean;
  canPublish: boolean;
  dropPosition: OutlineDropPosition | null;
  isCollapsed: boolean;
  isMoving: boolean;
  isSelected: boolean;
  parentOptions: ContentNode[];
  renameEditor: Extract<OutlineInlineEditor, { mode: "rename" }> | null;
  row: OutlineRow;
  selectionMode: boolean;
  onAddChild: () => void;
  onArchive: () => void;
  onDelete: () => void;
  onDragEnd: () => void;
  onDragOver: (event: React.DragEvent<HTMLDivElement>) => void;
  onDragStart: (event: React.DragEvent<HTMLButtonElement>) => void;
  onDrop: (event: React.DragEvent<HTMLDivElement>) => void;
  onDuplicate: () => void;
  onEnterCreate: () => void;
  onExport: () => void;
  onMoveDown: () => void;
  onMoveParent: (parentId: string) => void;
  onMoveUp: () => void;
  onOpenLesson: () => void;
  onRename: () => void;
  onRenameCancel: () => void;
  onRenameChange: (value: string) => void;
  onRenameSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
  onSelect: () => void;
  onSelectBulk: (checked: boolean) => void;
  onStatusChange: (status: ContentStatus) => void;
  onToggle: () => void;
}) {
  const clickTimerRef = React.useRef<number | null>(null);
  const childType = outlineChildType(row.node.type);

  React.useEffect(() => () => {
    if (clickTimerRef.current !== null) {
      window.clearTimeout(clickTimerRef.current);
    }
  }, []);

  function handleTitleClick() {
    onSelect();

    if (row.node.type !== "lesson") {
      return;
    }

    if (clickTimerRef.current !== null) {
      window.clearTimeout(clickTimerRef.current);
    }
    clickTimerRef.current = window.setTimeout(() => {
      clickTimerRef.current = null;
      onOpenLesson();
    }, 220);
  }

  function handleTitleDoubleClick(event: React.MouseEvent<HTMLButtonElement>) {
    event.preventDefault();
    if (clickTimerRef.current !== null) {
      window.clearTimeout(clickTimerRef.current);
      clickTimerRef.current = null;
    }
    onSelect();
    onRename();
  }

  return (
    <div
      aria-busy={isMoving}
      aria-level={row.depth + 1}
      aria-selected={isSelected}
      className={[
        "relative flex min-w-0 items-center gap-1 rounded-md border px-1.5 py-1 transition",
        isSelected ? "border-blue-200 bg-blue-50" : "border-transparent hover:bg-slate-50",
        dropPosition === "inside" ? "border-blue-400 bg-blue-100 ring-2 ring-blue-100" : "",
        dropPosition === "before" ? "before:absolute before:inset-x-0 before:-top-0.5 before:h-0.5 before:bg-blue-600" : "",
        dropPosition === "after" ? "after:absolute after:inset-x-0 after:-bottom-0.5 after:h-0.5 after:bg-blue-600" : "",
        isMoving ? "opacity-60" : "",
      ].join(" ")}
      role="treeitem"
      style={{ marginLeft: `${row.depth * 24}px` }}
      tabIndex={0}
      onDragOver={onDragOver}
      onDrop={onDrop}
      onKeyDown={(event) => {
        if (event.currentTarget !== event.target) {
          return;
        }
        if (event.key === "F2") {
          event.preventDefault();
          onRename();
        } else if (event.key === "Enter") {
          event.preventDefault();
          onEnterCreate();
        }
      }}
    >
      {selectionMode ? (
        <label className="flex size-8 shrink-0 items-center justify-center">
          <span className="sr-only">{row.node.title} 선택</span>
          <input
            checked={bulkSelected}
            type="checkbox"
            onChange={(event) => onSelectBulk(event.target.checked)}
          />
        </label>
      ) : null}

      {row.node.type !== "topic" ? (
        <button
          aria-label={`${row.node.title} 드래그하여 이동`}
          aria-grabbed={isMoving}
          className="flex size-8 shrink-0 cursor-grab items-center justify-center rounded-md text-slate-300 hover:bg-white hover:text-slate-600 active:cursor-grabbing"
          draggable={!renameEditor}
          title="드래그하여 순서 또는 부모 변경"
          type="button"
          onDragEnd={onDragEnd}
          onDragStart={onDragStart}
        >
          <GripVertical aria-hidden="true" className="size-4" />
        </button>
      ) : (
        <span className="block size-8 shrink-0" />
      )}

      {row.hasChildren ? (
        <button
          aria-label={`${row.node.title} 하위 목차 ${isCollapsed ? "펼치기" : "접기"}`}
          aria-expanded={!isCollapsed}
          className="flex size-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-white hover:text-slate-900"
          type="button"
          onClick={onToggle}
        >
          <ChevronRight
            aria-hidden="true"
            className={["size-4 transition-transform", isCollapsed ? "" : "rotate-90"].join(" ")}
          />
        </button>
      ) : (
        <span className="block size-8 shrink-0" />
      )}

      {renameEditor ? (
        <form className="flex min-w-0 flex-1 items-center gap-2" onSubmit={onRenameSubmit}>
          <input
            autoFocus
            aria-label={`${nodeLabels[row.node.type]} 이름 변경`}
            className="h-9 min-w-0 flex-1 rounded-md border border-blue-300 bg-white px-3 text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-200"
            value={renameEditor.value}
            onChange={(event) => onRenameChange(event.target.value)}
            onFocus={(event) => event.currentTarget.select()}
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                event.preventDefault();
                onRenameCancel();
              }
            }}
          />
          <button className="h-9 rounded-md bg-blue-600 px-3 text-xs font-semibold text-white" type="submit">
            저장
          </button>
        </form>
      ) : (
        <button
          className="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left"
          type="button"
          onClick={handleTitleClick}
          onDoubleClick={handleTitleDoubleClick}
        >
          <span className="hidden shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200 sm:inline-flex">
            {nodeLabels[row.node.type]}
          </span>
          <span className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900" title={row.node.title}>
            {row.node.title}
          </span>
          <span className={`${statusBadgeClass(row.node.status)} hidden sm:inline-flex`}>
            {contentStatusLabels[row.node.status]}
          </span>
          <span className="hidden shrink-0 font-mono text-[11px] text-slate-400 md:inline">#{row.node.id}</span>
          {row.childCount > 0 ? (
            <span className="hidden shrink-0 text-[11px] text-slate-400 lg:inline">하위 {row.childCount}</span>
          ) : null}
        </button>
      )}

      {!renameEditor ? (
        <DropdownMenu modal={false}>
          <DropdownMenuTrigger asChild>
            <button
              aria-label={`${row.node.title} 목차 메뉴`}
              className="flex size-9 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-white hover:text-slate-950 data-[state=open]:bg-white"
              type="button"
            >
              <MoreHorizontal aria-hidden="true" className="size-4" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="z-[2147483000] w-60" sideOffset={4}>
            {childType ? (
              <DropdownMenuItem onSelect={onAddChild}>
                <Plus aria-hidden="true" className="size-4" />
                하위 {nodeLabels[childType]} 추가
              </DropdownMenuItem>
            ) : null}
            <DropdownMenuItem onSelect={onRename}>
              <Pencil aria-hidden="true" className="size-4" />
              이름 바꾸기
              <span className="ml-auto text-xs text-slate-400">F2</span>
            </DropdownMenuItem>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger disabled={row.node.type === "topic"}>
                <GripVertical aria-hidden="true" className="size-4" />
                이동
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent className="w-64">
                <DropdownMenuItem disabled={row.siblingIndex === 0} onSelect={onMoveUp}>
                  <ArrowUp aria-hidden="true" className="size-4" />
                  위로 이동
                </DropdownMenuItem>
                <DropdownMenuItem
                  disabled={row.siblingIndex >= row.siblingCount - 1}
                  onSelect={onMoveDown}
                >
                  <ArrowDown aria-hidden="true" className="size-4" />
                  아래로 이동
                </DropdownMenuItem>
                {parentOptions.length > 1 ? <DropdownMenuSeparator /> : null}
                {parentOptions.length > 1
                  ? parentOptions.map((parent) => (
                      <DropdownMenuItem
                        key={parent.id}
                        disabled={parent.id === row.parentId}
                        onSelect={() => onMoveParent(parent.id)}
                      >
                        <ChevronRight aria-hidden="true" className="size-4" />
                        <span className="truncate">{parent.title}(으)로 이동</span>
                      </DropdownMenuItem>
                    ))
                  : null}
              </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuItem onSelect={onDuplicate}>
              <Copy aria-hidden="true" className="size-4" />
              복제
            </DropdownMenuItem>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                <Eye aria-hidden="true" className="size-4" />
                공개 상태 변경
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent className="w-44">
                {editableContentStatuses
                  .filter((status) => canPublish || status !== "published" || row.node.status === "published")
                  .map((status) => (
                    <DropdownMenuItem
                      key={status}
                      disabled={status === row.node.status}
                      onSelect={() => onStatusChange(status)}
                    >
                      {contentStatusLabels[status]}
                    </DropdownMenuItem>
                  ))}
              </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuItem onSelect={onExport}>
              <Download aria-hidden="true" className="size-4" />
              패키지 내보내기
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onArchive}>
              <Archive aria-hidden="true" className="size-4" />
              보관
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="text-red-600 focus:bg-red-50 focus:text-red-700"
              variant="destructive"
              onSelect={onDelete}
            >
              <Trash2 aria-hidden="true" className="size-4" />
              삭제
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}
    </div>
  );
}

function LegacyOutlineWorkspacePage({
  bulkSelectedNodeIds,
  bulkStatus,
  canPublish,
  collapsedNodeIds,
  isBulkUpdatingStatus,
  selectedLessonId,
  tree,
  onAdd,
  onArchive,
  onBulkStatusApply,
  onBulkStatusChange,
  onClearBulkSelection,
  onDelete,
  onEdit,
  onOpenLesson,
  onSelectAllBulkNodes,
  onSelectBulkNode,
  onSelectBulkNodeBranch,
  onToggle,
}: {
  bulkSelectedNodeIds: Set<string>;
  bulkStatus: ContentStatus;
  canPublish: boolean;
  collapsedNodeIds: Set<string>;
  isBulkUpdatingStatus: boolean;
  selectedLessonId: string | null;
  tree: ContentTree;
  onAdd: (target: AddTarget) => void;
  onArchive: (node: ContentNode) => void;
  onBulkStatusApply: () => void;
  onBulkStatusChange: (status: ContentStatus) => void;
  onClearBulkSelection: () => void;
  onDelete: (node: ContentNode) => void;
  onEdit: (node: ContentNode) => void;
  onOpenLesson: (lessonId: string) => void;
  onSelectAllBulkNodes: () => void;
  onSelectBulkNode: (nodeId: string, checked: boolean) => void;
  onSelectBulkNodeBranch: (nodeId: string, checked: boolean) => void;
  onToggle: (nodeId: string) => void;
}) {
  const stats = topicTreeStats(tree);
  const selectedCount = bulkSelectedNodeIds.size;

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
        <div>
          <p className="text-sm font-semibold text-slate-500">현재 주제</p>
          <h2 className="mt-1 text-2xl font-semibold text-slate-950">{tree.topic.title}</h2>
          <p className="mt-1 font-mono text-xs text-slate-500">
            ID {tree.topic.id} · {tree.topic.slug}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
            <input
              checked={bulkSelectedNodeIds.has(tree.topic.id)}
              type="checkbox"
              onChange={(event) => onSelectBulkNode(tree.topic.id, event.target.checked)}
            />
            주제 선택
          </label>
          <button
            className="rounded-md border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
            type="button"
            onClick={() => onSelectBulkNodeBranch(tree.topic.id, true)}
          >
            주제와 하위 선택
          </button>
          <button
            className="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
            type="button"
            onClick={() => onAdd({ parentId: tree.topic.id, type: "chapter" })}
          >
            챕터 추가
          </button>
          <OutlineActionButtons
            node={tree.topic}
            onArchive={onArchive}
            onDelete={onDelete}
            onEdit={onEdit}
          />
        </div>
      </div>

      <div className="mt-4 grid gap-2 sm:grid-cols-3">
        <StatPill label="챕터" value={stats.chapters} />
        <StatPill label="장" value={stats.sections} />
        <StatPill label="레슨" value={stats.lessons} />
      </div>

      <div className="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-emerald-950">일괄 상태 변경</p>
            <p className="mt-1 text-xs leading-5 text-emerald-800">
              공개하려면 주제, 챕터, 장, 레슨이 모두 공개 상태여야 합니다. 필요한 항목을 선택한 뒤 한 번에 상태를 바꾸세요.
            </p>
          </div>
          <span className="rounded-full border border-emerald-200 bg-white px-3 py-1 text-xs font-semibold text-emerald-800">
            선택 {selectedCount}개
          </span>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <button
            className="rounded-md border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100"
            type="button"
            onClick={onSelectAllBulkNodes}
          >
            현재 주제 전체 선택
          </button>
          <button
            className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            type="button"
            onClick={onClearBulkSelection}
          >
            선택 해제
          </button>
          <label className="flex items-center gap-2 text-sm font-semibold text-emerald-950">
            변경 상태
            <select
              className="rounded-md border border-emerald-200 bg-white px-2 py-2 text-sm"
              value={bulkStatus}
              onChange={(event) => onBulkStatusChange(event.target.value as ContentStatus)}
            >
              {bulkContentStatuses
                .filter((status) => canPublish || status !== "published")
                .map((status) => (
                  <option key={status} value={status}>
                    {contentStatusLabels[status]}
                  </option>
                ))}
            </select>
          </label>
          <button
            className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            disabled={selectedCount === 0 || isBulkUpdatingStatus}
            type="button"
            onClick={onBulkStatusApply}
          >
            {isBulkUpdatingStatus ? "변경 중" : "선택 항목 적용"}
          </button>
        </div>
      </div>

      <div className="mt-5 space-y-4">
        {tree.chapters.length === 0 ? (
          <EmptyWorkspaceNotice
            title="아직 챕터가 없습니다."
            description="챕터를 추가한 뒤 장과 레슨을 구성하세요."
            actionLabel="챕터 추가"
            onAction={() => onAdd({ parentId: tree.topic.id, type: "chapter" })}
          />
        ) : (
          tree.chapters.map((chapter) => {
            const chapterCollapsed = collapsedNodeIds.has(chapter.chapter.id);

            return (
              <div key={chapter.chapter.id} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <OutlineNodeHeader
                  actionLabel="장 추가"
                  childCount={chapter.sections.length}
                  isBulkSelected={bulkSelectedNodeIds.has(chapter.chapter.id)}
                  isCollapsed={chapterCollapsed}
                  node={chapter.chapter}
                  onAdd={() => onAdd({ parentId: chapter.chapter.id, type: "section" })}
                  onArchive={onArchive}
                  onDelete={onDelete}
                  onEdit={onEdit}
                  onSelectBulkBranch={(checked) => onSelectBulkNodeBranch(chapter.chapter.id, checked)}
                  onSelectBulkNode={(checked) => onSelectBulkNode(chapter.chapter.id, checked)}
                  onToggle={() => onToggle(chapter.chapter.id)}
                />
                {!chapterCollapsed ? (
                  <div className="mt-3 space-y-3 pl-4">
                    {chapter.sections.length === 0 ? (
                      <button
                        className="w-full rounded-lg border border-dashed border-slate-300 bg-white px-4 py-3 text-left text-sm font-semibold text-slate-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                        type="button"
                        onClick={() => onAdd({ parentId: chapter.chapter.id, type: "section" })}
                      >
                        장 추가
                      </button>
                    ) : (
                      chapter.sections.map((section) => {
                        const sectionCollapsed = collapsedNodeIds.has(section.section.id);

                        return (
                          <div key={section.section.id} className="rounded-lg border border-slate-200 bg-white p-3">
                            <OutlineNodeHeader
                              actionLabel="레슨 추가"
                              childCount={section.lessons.length}
                              isBulkSelected={bulkSelectedNodeIds.has(section.section.id)}
                              isCollapsed={sectionCollapsed}
                              node={section.section}
                              onAdd={() => onAdd({ parentId: section.section.id, type: "lesson" })}
                              onArchive={onArchive}
                              onDelete={onDelete}
                              onEdit={onEdit}
                              onSelectBulkBranch={(checked) => onSelectBulkNodeBranch(section.section.id, checked)}
                              onSelectBulkNode={(checked) => onSelectBulkNode(section.section.id, checked)}
                              onToggle={() => onToggle(section.section.id)}
                            />
                            {!sectionCollapsed ? (
                              <div className="mt-3 grid gap-2">
                                {section.lessons.map((lesson) => (
                                  <div
                                    key={lesson.id}
                                    className={[
                                      "flex min-w-0 items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm",
                                      lesson.id === selectedLessonId
                                        ? "border-blue-200 bg-blue-50 text-blue-900"
                                        : "border-slate-200 bg-slate-50 text-slate-700 hover:border-blue-200 hover:bg-blue-50",
                                    ].join(" ")}
                                  >
                                    <label className="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600">
                                      <input
                                        checked={bulkSelectedNodeIds.has(lesson.id)}
                                        type="checkbox"
                                        onChange={(event) => onSelectBulkNode(lesson.id, event.target.checked)}
                                      />
                                      선택
                                    </label>
                                    <button
                                      className="min-w-0 flex-1 text-left"
                                      type="button"
                                      onClick={() => onOpenLesson(lesson.id)}
                                    >
                                      <span className="block truncate font-semibold">{lesson.title}</span>
                                      <span className="mt-0.5 block font-mono text-[11px] text-slate-500">
                                        ID {lesson.id} · {contentStatusLabels[lesson.status]}
                                      </span>
                                    </button>
                                    <span className="flex shrink-0 items-center gap-1">
                                      <button
                                        className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                        type="button"
                                        onClick={() => onEdit(lesson)}
                                      >
                                        수정
                                      </button>
                                      <button
                                        className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                        type="button"
                                        onClick={() => onArchive(lesson)}
                                      >
                                        보관
                                      </button>
                                      <button
                                        className="rounded-md border border-red-200 bg-white px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50"
                                        type="button"
                                        onClick={() => onDelete(lesson)}
                                      >
                                        삭제
                                      </button>
                                    </span>
                                  </div>
                                ))}
                                <button
                                  className="rounded-lg border border-dashed border-slate-300 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                  type="button"
                                  onClick={() => onAdd({ parentId: section.section.id, type: "lesson" })}
                                >
                                  레슨 추가
                                </button>
                              </div>
                            ) : null}
                          </div>
                        );
                      })
                    )}
                  </div>
                ) : null}
              </div>
            );
          })
        )}
      </div>
    </section>
  );
}

const importConflictPolicyOrder: ContentImportLessonConflictPolicy[] = [
  "create_new",
  "replace",
  "skip",
];

const importConflictPolicyLabels: Record<ContentImportLessonConflictPolicy, string> = {
  create_new: "새 레슨으로 추가",
  replace: "기존 레슨 대체",
  skip: "중복 레슨 건너뛰기",
};

const importConflictPolicyDescriptions: Record<ContentImportLessonConflictPolicy, string> = {
  create_new: "기존 레슨은 그대로 두고 가져온 레슨을 새 항목으로 추가합니다.",
  replace: "중복된 기존 레슨의 제목과 본문을 가져온 파일 내용으로 바꿉니다. 상태는 초안으로 돌아갑니다.",
  skip: "중복된 레슨은 가져오지 않고, 중복되지 않은 항목과 미디어만 가져옵니다.",
};

function ImportPreviewStatusPanel() {
  return (
    <section className="rounded-xl border border-blue-100 bg-blue-50 p-4">
      <p className="text-sm font-semibold text-blue-900">패키지 확인 중</p>
      <p className="mt-1 text-sm leading-6 text-blue-800">
        목차와 레슨 중복 여부를 분석하고 있습니다. 이 단계에서는 기존 콘텐츠를 변경하지 않습니다.
      </p>
    </section>
  );
}

function ImportPreviewProblemPanel({
  problem,
  onClose,
}: {
  problem: ImportPreviewProblem;
  onClose: () => void;
}) {
  return (
    <section className="rounded-xl border border-red-200 bg-red-50 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-red-800">중복 비교 패널을 만들지 못했습니다.</p>
          <h3 className="mt-1 text-base font-semibold text-slate-950">{problem.fileName}</h3>
          <p className="mt-2 break-words rounded-md bg-white px-3 py-2 text-sm leading-6 text-red-700">
            {problem.message}
          </p>
          <p className="mt-2 text-sm leading-6 text-red-700">
            패키지 형식이나 서버 미리보기 API 응답을 확인해야 합니다. 이 상태에서는 안전을 위해 가져오기를 실행하지 않습니다.
          </p>
        </div>
        <button
          className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50"
          type="button"
          onClick={onClose}
        >
          닫기
        </button>
      </div>
    </section>
  );
}

function ImportPreviewPanel({
  importConflictPolicies,
  importConflictPolicy,
  isImporting,
  pendingImport,
  onCancel,
  onConfirm,
  onApplyPolicyToAll,
  onConflictPolicyChange,
}: {
  importConflictPolicies: ContentImportLessonConflictPolicyMap;
  importConflictPolicy: ContentImportLessonConflictPolicy;
  isImporting: boolean;
  pendingImport: PendingPackageImport;
  onCancel: () => void;
  onConfirm: () => void;
  onApplyPolicyToAll: (policy: ContentImportLessonConflictPolicy) => void;
  onConflictPolicyChange: (
    sourceLessonId: string,
    policy: ContentImportLessonConflictPolicy,
  ) => void;
}) {
  const conflicts = Array.isArray(pendingImport.preview.conflicts)
    ? pendingImport.preview.conflicts
    : [];
  const created = normalizePackageCounters(pendingImport.preview.created);
  const reused = normalizePackageCounters(pendingImport.preview.reused);
  const hasConflicts = conflicts.length > 0;
  const policyCounts = countImportConflictPolicies(
    conflicts,
    importConflictPolicies,
    importConflictPolicy,
  );

  return (
    <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-amber-900">가져오기 전 확인</p>
          <h3 className="mt-1 text-lg font-semibold text-slate-950">{pendingImport.fileName}</h3>
          <p className="mt-1 text-sm leading-6 text-slate-700">
            {hasConflicts
              ? `기존 레슨과 중복되는 항목 ${conflicts.length}개가 있습니다.`
              : "중복되는 레슨이 없습니다."}
            {typeof pendingImport.preview.assetsDetected === "number"
              ? ` 미디어 ${pendingImport.preview.assetsDetected}개를 함께 확인했습니다.`
              : ""}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            type="button"
            onClick={onCancel}
          >
            취소
          </button>
          <button
            className="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            disabled={isImporting}
            type="button"
            onClick={onConfirm}
          >
            {isImporting ? "가져오는 중" : "선택한 방식으로 가져오기"}
          </button>
        </div>
      </div>

      <div className="mt-4 grid gap-3 md:grid-cols-4">
        {[
          ["주제", created.topics, reused.topics],
          ["챕터", created.chapters, reused.chapters],
          ["장", created.sections, reused.sections],
          ["레슨", created.lessons, reused.lessons],
        ].map(([label, createdCount, reusedCount]) => (
          <div key={label} className="rounded-lg border border-amber-100 bg-white px-3 py-2">
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-950">새로 생성 예상 {createdCount}개</p>
            <p className="text-xs text-slate-500">기존 위치 재사용 {reusedCount}개</p>
          </div>
        ))}
      </div>

      {hasConflicts ? (
        <div className="mt-4 rounded-lg border border-amber-100 bg-white p-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-sm font-semibold text-slate-950">전체 처리 방식</p>
              <p className="mt-1 text-xs leading-5 text-slate-500">
                전체 적용 후 아래 중복 레슨별로 필요한 항목만 다시 바꿀 수 있습니다.
              </p>
            </div>
            <p className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
              새로 추가 {policyCounts.create_new}개 · 대체 {policyCounts.replace}개 · 건너뛰기 {policyCounts.skip}개
            </p>
          </div>
          <div className="mt-3 grid gap-2 lg:grid-cols-3">
            {importConflictPolicyOrder.map((policy) => (
              <button
                key={policy}
                className={[
                  "rounded-lg border p-3 text-left text-sm",
                  importConflictPolicy === policy
                    ? "border-blue-300 bg-blue-50 text-blue-950"
                    : "border-slate-200 bg-white text-slate-700 hover:border-blue-200",
                ].join(" ")}
                type="button"
                onClick={() => onApplyPolicyToAll(policy)}
              >
                <span className="block font-semibold">
                  {importConflictPolicyLabels[policy]}
                </span>
                <span className="mt-2 block text-xs leading-5 text-slate-500">
                  {importConflictPolicyDescriptions[policy]}
                </span>
              </button>
            ))}
          </div>
        </div>
      ) : null}

      {hasConflicts ? (
        <div className="mt-4 space-y-3">
          {conflicts.map((conflict, index) => {
            const sourceLessonId = String(conflict.incoming.id);
            const selectedPolicy = importConflictPolicies[sourceLessonId] ?? importConflictPolicy;

            return (
              <article key={`${conflict.existing.id}-${sourceLessonId}-${index}`} className="rounded-lg border border-amber-100 bg-white p-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold text-slate-950">
                      {conflict.path.join(" / ")}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                      {conflict.matchBy === "slug" ? "슬러그" : "제목"}이 같아서 중복으로 판단했습니다.
                    </p>
                  </div>
                  <label className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                    이 레슨
                    <select
                      className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-800"
                      value={selectedPolicy}
                      onChange={(event) =>
                        onConflictPolicyChange(
                          sourceLessonId,
                          event.target.value as ContentImportLessonConflictPolicy,
                        )
                      }
                    >
                      {importConflictPolicyOrder.map((policy) => (
                        <option key={policy} value={policy}>
                          {importConflictPolicyLabels[policy]}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                <div className="mt-3 grid gap-3 md:grid-cols-2">
                  <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-semibold text-slate-500">현재 저장된 레슨</p>
                    <p className="mt-1 text-sm font-semibold text-slate-950">{conflict.existing.title}</p>
                    <p className="mt-1 break-all font-mono text-xs text-slate-500">ID {conflict.existing.id} · {conflict.existing.slug}</p>
                    <p className="mt-2 text-xs leading-5 text-slate-600">{conflict.existingSummary || "본문 요약이 없습니다."}</p>
                  </div>
                  <div className="rounded-md border border-blue-100 bg-blue-50 p-3">
                    <p className="text-xs font-semibold text-blue-700">가져올 레슨</p>
                    <p className="mt-1 text-sm font-semibold text-slate-950">{conflict.incoming.title}</p>
                    <p className="mt-1 break-all font-mono text-xs text-slate-500">패키지 ID {conflict.incoming.id} · {conflict.incoming.slug}</p>
                    <p className="mt-2 text-xs leading-5 text-slate-600">{conflict.incomingSummary || "본문 요약이 없습니다."}</p>
                  </div>
                </div>
              </article>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

function PackageWorkspacePage({
  availablePackageScopes,
  importConflictPolicy,
  isImportingPackage,
  packageExportScope,
  packageInputRef,
  pendingImport,
  selectedContextLabel,
  onCancelImport,
  onConfirmImport,
  onExport,
  onImport,
  onPolicyChange,
  onScopeChange,
  onReimportAllFromJson,
}: {
  availablePackageScopes: ContentPackageScope[];
  importConflictPolicy: ContentImportLessonConflictPolicy;
  isImportingPackage: boolean;
  packageExportScope: ContentPackageScope;
  packageInputRef: React.RefObject<HTMLInputElement | null>;
  pendingImport: PendingPackageImport | null;
  selectedContextLabel: string;
  onCancelImport: () => void;
  onConfirmImport: () => void;
  onExport: () => void;
  onImport: (event: React.ChangeEvent<HTMLInputElement>) => void;
  onPolicyChange: (policy: ContentImportLessonConflictPolicy) => void;
  onScopeChange: (scope: ContentPackageScope) => void;
  onReimportAllFromJson?: () => void;
}) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <input
        ref={packageInputRef}
        accept="application/json,application/zip,application/x-zip-compressed,.json,.zip"
        className="hidden"
        type="file"
        onChange={onImport}
      />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-slate-950">패키지 가져오기/내보내기</h2>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            주제, 챕터, 장, 레슨 단위로 콘텐츠와 미디어를 공유합니다.
          </p>
          <p className="mt-2 text-xs font-semibold text-slate-500">현재 선택: {selectedContextLabel}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {onReimportAllFromJson ? (
            <button
              className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
              type="button"
              onClick={onReimportAllFromJson}
              title="data/lessons/*.json 원본을 기준으로 모든 레슨을 한 번에 다시 가져옵니다."
            >
              전체 레슨 JSON 다시 가져오기
            </button>
          ) : null}
          <button
            className="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
            type="button"
            onClick={() => packageInputRef.current?.click()}
          >
            패키지 가져오기
          </button>
        </div>
      </div>

      <div className="mt-5 grid gap-4 lg:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <h3 className="text-base font-semibold text-slate-950">가져오기</h3>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            JSON 또는 ZIP 패키지를 선택하면 먼저 중복 레슨을 비교합니다. 확인 전에는 기존 콘텐츠를 변경하지 않습니다.
          </p>
          <button
            className="mt-4 rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"
            type="button"
            onClick={() => packageInputRef.current?.click()}
          >
            파일 선택
          </button>
        </div>

        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <h3 className="text-base font-semibold text-slate-950">내보내기</h3>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            현재 선택한 위치를 기준으로 내보낼 범위를 고릅니다.
          </p>
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
              범위
              <select
                className="rounded-md border border-slate-200 bg-white px-2 py-2 text-sm"
                value={packageExportScope}
                onChange={(event) => onScopeChange(event.target.value as ContentPackageScope)}
              >
                {(Object.keys(packageScopeLabels) as ContentPackageScope[]).map((scope) => (
                  <option
                    key={scope}
                    disabled={!availablePackageScopes.includes(scope)}
                    value={scope}
                  >
                    {packageScopeLabels[scope]}
                  </option>
                ))}
              </select>
            </label>
            <button
              className="rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
              type="button"
              onClick={onExport}
            >
              패키지 내보내기
            </button>
          </div>
        </div>
      </div>

    </section>
  );
}

function PublishWorkspacePage({
  canPublish,
  isPublishing,
  selectedLesson,
  selectedLessonPublicUrl,
  selectedTopic,
  selectedTopicPublicUrl,
  onPublishTopic,
}: {
  canPublish: boolean;
  isPublishing: boolean;
  selectedLesson: ContentNode | null | undefined;
  selectedLessonPublicUrl: string;
  selectedTopic: ContentNode | null;
  selectedTopicPublicUrl: string;
  onPublishTopic: () => void;
}) {
  if (!selectedTopicPublicUrl) {
    return (
      <EmptyWorkspaceNotice
        title="공개할 주제가 없습니다."
        description="콘텐츠 라이브러리에서 주제를 먼저 선택하세요."
      />
    );
  }

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-slate-950">학생 공개</h2>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            WordPress 페이지나 글에 주제 쇼트코드 하나를 넣으면 학생이 목차에서 레슨을 골라 읽을 수 있습니다.
          </p>
        </div>
        <span
          className={[
            "rounded-full border px-3 py-1 text-sm font-semibold",
            selectedTopic?.status === "published"
              ? "border-emerald-200 bg-emerald-50 text-emerald-700"
              : "border-amber-200 bg-amber-50 text-amber-700",
          ].join(" ")}
        >
          {selectedTopic?.status === "published" ? "주제 공개 중" : "주제 비공개"}
        </span>
      </div>

      <div className="mt-4 grid gap-3 md:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-sm font-semibold text-slate-950">주제 공개 주소</p>
          <code className="mt-2 block overflow-x-auto rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
            {selectedTopicPublicUrl}
          </code>
          <p className="mt-2 font-mono text-xs text-slate-500">주제 ID {selectedTopic?.id}</p>
        </div>
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-sm font-semibold text-slate-950">선택 레슨 정보</p>
          {selectedLesson ? (
            <>
              <p className="mt-2 text-sm text-slate-700">{selectedLesson.title}</p>
              <p className="mt-1 font-mono text-xs text-slate-500">레슨 ID {selectedLesson.id}</p>
              {selectedLessonPublicUrl ? (
                <code className="mt-2 block overflow-x-auto rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
                  {selectedLessonPublicUrl}
                </code>
              ) : null}
            </>
          ) : (
            <p className="mt-2 text-sm text-slate-600">선택한 레슨이 없습니다.</p>
          )}
        </div>
      </div>

      {selectedTopic ? (
        canPublish ? (
          <button
            className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
            disabled={isPublishing}
            type="button"
            onClick={onPublishTopic}
          >
            {isPublishing ? "공개 처리 중" : "주제와 하위 콘텐츠 전체 공개"}
          </button>
        ) : (
          <p className="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
            공개 상태 확인은 가능하지만 상태 변경에는 읽기자료 공개 권한이 필요합니다.
          </p>
        )
      ) : null}
    </section>
  );
}

function ArchiveWorkspacePage({
  nodes,
  busy,
  notice,
  onDelete,
  onRefresh,
  onRestore,
}: {
  nodes: ContentNode[];
  busy: boolean;
  notice: string;
  onDelete: (node: ContentNode) => void;
  onRefresh?: () => void;
  onRestore: (node: ContentNode) => void;
}) {
  return (
    <section className="min-w-0 space-y-4" aria-label="보관한 읽기자료">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-950">보관한 항목 {nodes.length}개</h2>
        </div>
        {onRefresh ? (
          <button
            className="inline-flex size-10 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            aria-label="보관함 새로고침"
            title="새로고침"
            disabled={busy}
            type="button"
            onClick={onRefresh}
          >
            <RefreshCw className="size-4" aria-hidden="true" />
          </button>
        ) : null}
      </div>
      <p role="status" className="break-words text-sm text-slate-600">{notice}</p>
      <div>
        <ArchivedNodesPanel
          nodes={nodes}
          busy={busy}
          onDelete={onDelete}
          onRestore={onRestore}
        />
      </div>
    </section>
  );
}

function EmptyWorkspaceNotice({
  actionLabel,
  description,
  title,
  onAction,
}: {
  actionLabel?: string;
  description: string;
  title: string;
  onAction?: () => void;
}) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center shadow-sm">
      <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
      {actionLabel && onAction ? (
        <button
          className="mt-4 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
          type="button"
          onClick={onAction}
        >
          {actionLabel}
        </button>
      ) : null}
    </div>
  );
}

function OutlineNodeHeader({
  actionLabel,
  childCount,
  isBulkSelected,
  isCollapsed,
  node,
  onAdd,
  onArchive,
  onDelete,
  onEdit,
  onSelectBulkBranch,
  onSelectBulkNode,
  onToggle,
}: {
  actionLabel: string;
  childCount: number;
  isBulkSelected: boolean;
  isCollapsed: boolean;
  node: ContentNode;
  onAdd: () => void;
  onArchive: (node: ContentNode) => void;
  onDelete: (node: ContentNode) => void;
  onEdit: (node: ContentNode) => void;
  onSelectBulkBranch: (checked: boolean) => void;
  onSelectBulkNode: (checked: boolean) => void;
  onToggle: () => void;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex min-w-0 items-center gap-3">
        <label className="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600">
          <input
            checked={isBulkSelected}
            type="checkbox"
            onChange={(event) => onSelectBulkNode(event.target.checked)}
          />
          선택
        </label>
        <button
          className="flex min-w-0 items-center gap-3 text-left"
          type="button"
          onClick={onToggle}
        >
        <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-sm font-bold text-slate-500">
          {isCollapsed ? "+" : "-"}
        </span>
        <span className="min-w-0">
          <span className="flex min-w-0 flex-wrap items-center gap-2">
            <span className="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">
              {nodeLabels[node.type]}
            </span>
            <span className="truncate font-semibold text-slate-950">{node.title}</span>
            <span className={statusBadgeClass(node.status)}>
              {contentStatusLabels[node.status]}
            </span>
          </span>
          <span className="mt-1 block font-mono text-[11px] text-slate-500">
            ID {node.id} · 하위 {childCount}개
          </span>
        </span>
        </button>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button
          className="rounded-md border border-emerald-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
          type="button"
          onClick={() => onSelectBulkBranch(true)}
        >
          하위까지 선택
        </button>
        <button
          className="rounded-md border border-blue-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50"
          type="button"
          onClick={onAdd}
        >
          {actionLabel}
        </button>
        <OutlineActionButtons
          node={node}
          onArchive={onArchive}
          onDelete={onDelete}
          onEdit={onEdit}
        />
      </div>
    </div>
  );
}

function OutlineActionButtons({
  node,
  onArchive,
  onDelete,
  onEdit,
}: {
  node: ContentNode;
  onArchive: (node: ContentNode) => void;
  onDelete: (node: ContentNode) => void;
  onEdit: (node: ContentNode) => void;
}) {
  return (
    <>
      <button
        className="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
        type="button"
        onClick={() => onEdit(node)}
      >
        수정
      </button>
      <button
        className="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
        type="button"
        onClick={() => onArchive(node)}
      >
        보관
      </button>
      <button
        className="rounded-md border border-red-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50"
        type="button"
        onClick={() => onDelete(node)}
      >
        삭제
      </button>
    </>
  );
}

function StatPill({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
      <p className="text-xs font-semibold text-slate-500">{label}</p>
      <p className="mt-1 text-lg font-semibold text-slate-950">{value}</p>
    </div>
  );
}

function topicTreeStats(tree: ContentTree): { chapters: number; lessons: number; sections: number } {
  return tree.chapters.reduce(
    (stats, chapter) => {
      stats.chapters += 1;
      stats.sections += chapter.sections.length;
      stats.lessons += chapter.sections.reduce(
        (count, section) => count + section.lessons.length,
        0,
      );

      return stats;
    },
    { chapters: 0, lessons: 0, sections: 0 },
  );
}

function statusBadgeClass(status: ContentStatus): string {
  const base = "rounded-full border px-2.5 py-1 text-xs font-semibold";

  if (status === "published") {
    return `${base} border-emerald-200 bg-emerald-50 text-emerald-700`;
  }

  if (status === "archived") {
    return `${base} border-slate-200 bg-slate-100 text-slate-600`;
  }

  if (status === "approved") {
    return `${base} border-blue-200 bg-blue-50 text-blue-700`;
  }

  if (status === "review") {
    return `${base} border-amber-200 bg-amber-50 text-amber-700`;
  }

  return `${base} border-slate-200 bg-white text-slate-600`;
}

function ArchivedNodesPanel({
  nodes,
  busy,
  onDelete,
  onRestore,
}: {
  nodes: ContentNode[];
  busy: boolean;
  onDelete: (node: ContentNode) => void;
  onRestore: (node: ContentNode) => void;
}) {
  return (
    <div className="min-w-0">
      {nodes.length === 0 ? (
        <p className="rounded-md border border-dashed border-slate-300 bg-white px-3 py-3 text-xs leading-5 text-slate-500">
          보관한 항목이 없습니다.
        </p>
      ) : (
        <div className="space-y-2">
          {nodes.map((node) => (
            <div
              key={node.id}
              role="group"
              aria-label={`${node.title} 보관 항목`}
              className="rounded-md border border-slate-200 bg-white p-3 shadow-sm"
            >
              <div className="min-w-0">
                <p className="break-words text-sm font-semibold text-slate-900">{node.title}</p>
                <p className="mt-1 break-all text-xs text-slate-500">
                  {nodeLabels[node.type]} · ID {node.id}
                </p>
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <button
                  className="inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 disabled:opacity-40"
                  disabled={busy}
                  type="button"
                  onClick={() => onRestore(node)}
                >
                  <RotateCcw className="size-4" aria-hidden="true" />초안으로 복원
                </button>
                <button
                  className="inline-flex items-center gap-2 rounded-md border border-red-200 bg-white px-2.5 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:opacity-40"
                  disabled={busy}
                  type="button"
                  onClick={() => onDelete(node)}
                >
                  <Trash2 className="size-4" aria-hidden="true" />영구 삭제
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function TopicTree({
  collapsedNodeIds,
  selectedLessonId,
  showActions = true,
  tree,
  onAdd,
  onArchive,
  onDelete,
  onEdit,
  onSelectLesson,
  onSelectTopic,
  onToggle,
}: {
  collapsedNodeIds: Set<string>;
  selectedLessonId: string | null;
  showActions?: boolean;
  tree: ContentTree;
  onAdd: (target: AddTarget) => void;
  onArchive: (node: ContentNode) => void;
  onDelete: (node: ContentNode) => void;
  onEdit: (node: ContentNode) => void;
  onSelectLesson: (lessonId: string) => void;
  onSelectTopic?: (tree: ContentTree) => void;
  onToggle: (nodeId: string) => void;
}) {
  const isTopicCollapsed = collapsedNodeIds.has(tree.topic.id);

  return (
    <section className="rounded-lg border border-slate-200 bg-slate-50">
      <TreeRow
        actionLabel="챕터 추가"
        depth={0}
        hasChildren={tree.chapters.length > 0}
        isCollapsed={isTopicCollapsed}
        node={tree.topic}
        onAdd={showActions ? () => onAdd({ type: "chapter", parentId: tree.topic.id }) : undefined}
        onArchive={showActions ? () => onArchive(tree.topic) : undefined}
        onDelete={showActions ? () => onDelete(tree.topic) : undefined}
        onEdit={showActions ? () => onEdit(tree.topic) : undefined}
        onSelect={onSelectTopic ? () => onSelectTopic(tree) : undefined}
        onToggle={() => onToggle(tree.topic.id)}
      />
      {!isTopicCollapsed ? (
        <div className="space-y-1 px-2 pb-2">
          {tree.chapters.map((chapter) => {
            const isChapterCollapsed = collapsedNodeIds.has(chapter.chapter.id);

            return (
              <div key={chapter.chapter.id}>
                <TreeRow
                  actionLabel="장 추가"
                  depth={1}
                  hasChildren={chapter.sections.length > 0}
                  isCollapsed={isChapterCollapsed}
                  node={chapter.chapter}
                  onAdd={showActions ? () => onAdd({ type: "section", parentId: chapter.chapter.id }) : undefined}
                  onArchive={showActions ? () => onArchive(chapter.chapter) : undefined}
                  onDelete={showActions ? () => onDelete(chapter.chapter) : undefined}
                  onEdit={showActions ? () => onEdit(chapter.chapter) : undefined}
                  onToggle={() => onToggle(chapter.chapter.id)}
                />
                {!isChapterCollapsed
                  ? chapter.sections.map((section) => {
                      const isSectionCollapsed = collapsedNodeIds.has(section.section.id);

                      return (
                        <div key={section.section.id}>
                          <TreeRow
                            actionLabel="레슨 추가"
                            depth={2}
                            hasChildren={section.lessons.length > 0}
                            isCollapsed={isSectionCollapsed}
                            node={section.section}
                            onAdd={showActions ? () => onAdd({ type: "lesson", parentId: section.section.id }) : undefined}
                            onArchive={showActions ? () => onArchive(section.section) : undefined}
                            onDelete={showActions ? () => onDelete(section.section) : undefined}
                            onEdit={showActions ? () => onEdit(section.section) : undefined}
                            onToggle={() => onToggle(section.section.id)}
                          />
                          {!isSectionCollapsed
                            ? section.lessons.map((lesson) => (
                                <TreeRow
                                  key={lesson.id}
                                  depth={3}
                                  isSelected={lesson.id === selectedLessonId}
                                  node={lesson}
                                  onArchive={showActions ? () => onArchive(lesson) : undefined}
                                  onDelete={showActions ? () => onDelete(lesson) : undefined}
                                  onEdit={showActions ? () => onEdit(lesson) : undefined}
                                  onSelect={() => onSelectLesson(lesson.id)}
                                />
                              ))
                            : null}
                        </div>
                      );
                    })
                  : null}
              </div>
            );
          })}
        </div>
      ) : null}
    </section>
  );
}

function TreeRow({
  actionLabel,
  depth,
  hasChildren,
  isCollapsed,
  isSelected,
  node,
  onAdd,
  onArchive,
  onDelete,
  onEdit,
  onSelect,
  onToggle,
}: {
  actionLabel?: string;
  depth: number;
  hasChildren?: boolean;
  isCollapsed?: boolean;
  isSelected?: boolean;
  node: ContentNode;
  onAdd?: () => void;
  onArchive?: () => void;
  onDelete?: () => void;
  onEdit?: () => void;
  onSelect?: () => void;
  onToggle?: () => void;
}) {
  const hasActions = Boolean(onAdd || onEdit || onArchive || onDelete);

  return (
    <div
      className={[
        "group relative rounded-md px-2 py-2 pr-12 text-sm",
        isSelected ? "bg-blue-100 text-blue-900" : "text-slate-700 hover:bg-white",
      ].join(" ")}
      style={{ paddingLeft: `${8 + depth * 16}px` }}
    >
      <div className="flex min-w-0 items-start gap-1">
        {hasChildren && onToggle ? (
          <button
            aria-label={`${node.title} 하위 목차 ${isCollapsed ? "펼치기" : "접기"}`}
            aria-expanded={!isCollapsed}
            className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-xs font-bold text-slate-500 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onToggle();
            }}
          >
            {isCollapsed ? "+" : "-"}
          </button>
        ) : (
          <span className="mt-0.5 block size-6 shrink-0" aria-hidden="true" />
        )}
        <button
          className="block min-w-0 flex-1 text-left"
          type="button"
          onClick={onSelect}
        >
          <span className="flex min-w-0 items-center gap-2">
            <span className="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-slate-500 ring-1 ring-slate-200">
              {nodeLabels[node.type]}
            </span>
            <span className="min-w-0 flex-1 truncate font-medium" title={node.title}>
              {node.title}
            </span>
          </span>
          <span className="mt-1 block truncate pl-1 font-mono text-[11px] text-slate-400">
            ID {node.id} · {contentStatusLabels[node.status]}
          </span>
        </button>
      </div>

      {hasActions ? (
        <div className="absolute right-1 top-2 z-10">
          <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
              <button
                aria-label={`${node.title} 목차 메뉴`}
                className="flex size-7 items-center justify-center rounded-md text-lg leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-900 data-[state=open]:bg-slate-100 data-[state=open]:text-slate-900"
                type="button"
                onClick={(event) => event.stopPropagation()}
              >
                ...
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
              align="end"
              className="z-[2147483000] w-36 text-xs"
              onClick={(event) => event.stopPropagation()}
              sideOffset={4}
            >
              {onAdd ? (
                <DropdownMenuItem
                  className="font-semibold text-slate-600"
                  onSelect={onAdd}
                >
                  {actionLabel}
                </DropdownMenuItem>
              ) : null}
              {onEdit ? (
                <DropdownMenuItem
                  className="font-semibold text-slate-600"
                  onSelect={onEdit}
                >
                  수정
                </DropdownMenuItem>
              ) : null}
              {onArchive ? (
                <DropdownMenuItem
                  className="font-semibold text-slate-600"
                  onSelect={onArchive}
                >
                  보관
                </DropdownMenuItem>
              ) : null}
              {onDelete ? (
                <DropdownMenuItem
                  className="font-semibold text-red-600 focus:bg-red-50 focus:text-red-700"
                  onSelect={onDelete}
                  variant="destructive"
                >
                  삭제
                </DropdownMenuItem>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      ) : null}
    </div>
  );
}

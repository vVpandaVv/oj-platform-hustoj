import type {
  ContentNode,
  ContentStatus,
  ContentDeleteResult,
  ContentImportLessonConflictPolicy,
  ContentImportLessonConflictPolicyMap,
  ContentNodeType,
  ContentPackage,
  ContentTree,
  LessonDocument,
  PackageImportPreviewResult,
  PackageImportResult,
} from "./types";

declare global {
  interface Window {
    contentsEditorAdmin?: {
      canPublish?: boolean;
      canReimportJson?: boolean;
      canImportServer?: boolean;
      maxUploadSize?: number;
      maxUploadSizeLabel?: string;
      nonce?: string;
      publicBaseUrl?: string;
      restNamespace?: string;
      restUrl?: string;
      version?: string;
    };
  }
}

const fallbackNamespaces = ["readcontents-library/v1", "contents-editor/v1"];

export class ContentApiError extends Error {
  readonly code: string | null;
  readonly data: unknown;
  readonly status: number;

  constructor(message: string, status: number, data: unknown) {
    super(message);
    this.name = "ContentApiError";
    this.code = restErrorCode(data);
    this.data = data;
    this.status = status;
  }
}

export function hasWordPressRest(): boolean {
  return Boolean(window.contentsEditorAdmin?.restUrl);
}

export function uploadLimitLabel(): string | null {
  const label = window.contentsEditorAdmin?.maxUploadSizeLabel;

  return typeof label === "string" && label.trim() ? label : null;
}

export function isOverUploadLimit(file: File): boolean {
  const maxUploadSize = window.contentsEditorAdmin?.maxUploadSize;

  return typeof maxUploadSize === "number" && maxUploadSize > 0 && file.size > maxUploadSize;
}

export async function request<T>(path: string, init: RequestInit = {}, emptyFallback?: unknown): Promise<T> {
  return requestWithNamespaceFallback(
    path,
    emptyFallback,
    (namespaceForRequest) =>
      fetch(namespaceForRequest, {
        ...init,
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          ...(window.contentsEditorAdmin?.nonce
            ? { "X-WP-Nonce": window.contentsEditorAdmin.nonce }
            : {}),
        },
      }),
  );
}

async function requestForm<T>(path: string, body: FormData, emptyFallback?: unknown): Promise<T> {
  return requestWithNamespaceFallback(
    path,
    emptyFallback,
    (namespaceForRequest) =>
      fetch(namespaceForRequest, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...(window.contentsEditorAdmin?.nonce
            ? { "X-WP-Nonce": window.contentsEditorAdmin.nonce }
            : {}),
        },
        body,
      }),
  );
}

async function requestWithNamespaceFallback<T>(
  path: string,
  emptyFallback: unknown,
  doRequest: (url: string) => Promise<Response>,
): Promise<T> {
  const namespaceCandidates = getRestNamespaceCandidates();
  let lastError: ContentApiError | Error | null = null;

  for (const ns of namespaceCandidates) {
    const restUrl = buildRestUrl(path, ns);

    if (!restUrl) {
      continue;
    }

    try {
      const response = await doRequest(restUrl);
      const data = await parseRestResponse(response, emptyFallback);

      if (!response.ok) {
        if (response.status === 404 && namespaceCandidates.indexOf(ns) < namespaceCandidates.length - 1) {
          lastError = new ContentApiError(errorMessageFromRestData(data), response.status, data);
          continue;
        }

        throw new ContentApiError(errorMessageFromRestData(data), response.status, data);
      }

      return data as T;
    } catch (error) {
      if (error instanceof ContentApiError && error.status === 404 && namespaceCandidates.length > 1) {
        lastError = error;
        continue;
      }

      if (error instanceof Error) {
        lastError = error;
      }

      throw error;
    }
  }

  if (lastError) {
    throw lastError;
  }

  throw new Error("WordPress REST 설정이 올바르지 않습니다.");
}

async function parseRestResponse(response: Response, emptyFallback?: unknown): Promise<unknown> {
  const text = await response.text();
  const contentType = response.headers.get("content-type") ?? "unknown";
  const responseSummary = responseBodySummary(text);

  if (!text.trim()) {
    if (response.ok) {
      return emptyFallback ?? null;
    }

    throw new ContentApiError(responseSummary || `요청이 실패했습니다. HTTP ${response.status}`, response.status, null);
  }

  try {
    return JSON.parse(text);
  } catch {
    if (contentType.includes("json")) {
      throw new ContentApiError(responseSummary || `요청이 실패했습니다. HTTP ${response.status}`, response.status, text);
    }

    if (response.ok) {
      throw new Error(["요청이 비정상적인 응답을 받았습니다.", `HTTP ${response.status}`, `상세 응답: ${responseSummary}`].filter(Boolean).join(" "));
    }

    throw new Error(responseSummary || `요청이 실패했습니다. HTTP ${response.status}`);
  }
}

function responseBodySummary(text: string): string {
  return text
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]*>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 260);
}

function errorMessageFromRestData(data: unknown): string {
  if (data && typeof data === "object" && "message" in data) {
    const message = (data as { message?: unknown }).message;

    if (typeof message === "string" && message.trim()) {
      return message;
    }
  }

  return "요청이 실패했습니다.";
}

function restErrorCode(data: unknown): string | null {
  if (data && typeof data === "object" && "code" in data) {
    const code = (data as { code?: unknown }).code;

    if (typeof code === "string" && code.trim()) {
      return code;
    }
  }

  return null;
}

function getRestNamespaceCandidates(): string[] {
  const configured = normalizeRestNamespace(window.contentsEditorAdmin?.restNamespace);

  if (!configured) {
    return [...fallbackNamespaces];
  }

  const namespaces = [configured, ...fallbackNamespaces];

  return Array.from(new Set(namespaces.map(normalizeRestNamespace).filter((value): value is string => Boolean(value))));
}

function normalizeRestNamespace(value: string | undefined): string | null {
  if (!value) {
    return null;
  }

  const normalized = value.replace(/^\/+/, "").replace(/\/+$/, "");

  return normalized.length > 0 ? normalized : null;
}

function buildRestUrl(path: string, namespace: string): string | null {
  const restUrl = window.contentsEditorAdmin?.restUrl;

  if (!restUrl) {
    return null;
  }

  const base = restUrl.replace(/\/+$/, "");
  const restNamespace = normalizeRestNamespace(namespace);
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;

  if (!restNamespace) {
    return null;
  }

  return `${base}/${restNamespace}${normalizedPath}`;
}

export const contentApi = {
  listTopics(includeTree = true): Promise<ContentTree[]> {
    const query = includeTree ? "?includeTree=1" : "";

    return request<ContentTree[]>(`/topics${query}`, {}, []);
  },

  listArchivedNodes(): Promise<ContentNode[]> {
    return request<ContentNode[]>("/nodes?status=archived", {}, []);
  },

  createTopic(title: string): Promise<ContentNode> {
    return request<ContentNode>("/topics", {
      method: "POST",
      body: JSON.stringify({ title }),
    });
  },

  createNode(input: {
    contentType?: string;
    order?: number;
    parentId: string;
    title: string;
    type: Exclude<ContentNodeType, "topic">;
  }): Promise<ContentNode> {
    return request<ContentNode>("/nodes", {
      method: "POST",
      body: JSON.stringify(input),
    });
  },

  updateNode(
    nodeId: string,
    input: Partial<Pick<ContentNode, "contentType" | "metadata" | "order" | "parentId" | "schemaVersion" | "slug" | "status" | "title">>,
  ): Promise<ContentNode> {
    return request<ContentNode>(`/nodes/${nodeId}`, {
      method: "PUT",
      body: JSON.stringify(input),
    });
  },

  bulkUpdateNodeStatus(
    nodeIds: string[],
    status: ContentStatus,
  ): Promise<{ count: number; status: ContentStatus; updated: ContentNode[] }> {
    return request<{ count: number; status: ContentStatus; updated: ContentNode[] }>("/nodes/bulk-status", {
      method: "POST",
      body: JSON.stringify({ nodeIds, status }),
    });
  },

  archiveNode(nodeId: string): Promise<ContentNode> {
    return request<ContentNode>(`/nodes/${nodeId}`, {
      method: "DELETE",
    });
  },

  deleteNode(nodeId: string): Promise<ContentDeleteResult> {
    return request<ContentDeleteResult>(`/nodes/${nodeId}/delete`, {
      method: "DELETE",
    });
  },

  restoreNode(nodeId: string): Promise<ContentNode> {
    return request<ContentNode>(`/nodes/${nodeId}/restore`, {
      method: "PUT",
    });
  },

  getLessonDocument(lessonId: string): Promise<LessonDocument> {
    return request<LessonDocument>(`/lessons/${lessonId}/document`);
  },

  saveLessonDocument(
    lessonId: string,
    input: Pick<LessonDocument, "assets" | "plateValue" | "revision" | "schemaVersion" | "visuals">,
  ): Promise<LessonDocument> {
    return request<LessonDocument>(`/lessons/${lessonId}/document`, {
      method: "PUT",
      body: JSON.stringify(input),
    });
  },

  resetLessonDocument(lessonId: string): Promise<LessonDocument> {
    return request<LessonDocument>(`/lessons/${lessonId}/document/reset`, {
      method: "POST",
    });
  },

  reimportLessonFromJson(nodeId: string): Promise<LessonDocument> {
    return request<LessonDocument>(`/nodes/${nodeId}/reimport-from-json`, {
      method: "POST",
    });
  },

  reimportAllLessonsFromJson(): Promise<{
    succeeded: string[];
    skipped: string[];
    failed: Record<string, string>;
  }> {
    return request(`/nodes/reimport-all-from-json`, {
      method: "POST",
    });
  },

  exportNodePackage(nodeId: string): Promise<ContentPackage> {
    return request<ContentPackage>(`/nodes/${nodeId}/package`);
  },

  exportTopicPackage(topicId: string): Promise<ContentPackage> {
    return request<ContentPackage>(`/topics/${topicId}/package`);
  },

  previewPackage(input: ContentPackage): Promise<PackageImportPreviewResult> {
    return request<PackageImportPreviewResult>("/packages/preview", {
      method: "POST",
      body: JSON.stringify(input),
    });
  },

  previewZipPackage(file: File): Promise<PackageImportPreviewResult> {
    const body = new FormData();
    body.append("package", file);

    return requestForm<PackageImportPreviewResult>("/packages/preview-zip", body);
  },

  importPackage(
    input: ContentPackage,
    options: {
      lessonConflictPolicy?: ContentImportLessonConflictPolicy;
      lessonConflictPolicyBySourceId?: ContentImportLessonConflictPolicyMap;
    } = {},
  ): Promise<PackageImportResult> {
    return request<PackageImportResult>("/packages/import", {
      method: "POST",
      body: JSON.stringify({ package: input, options }),
    });
  },

  importZipPackage(
    file: File,
    options: {
      lessonConflictPolicy?: ContentImportLessonConflictPolicy;
      lessonConflictPolicyBySourceId?: ContentImportLessonConflictPolicyMap;
    } = {},
  ): Promise<PackageImportResult> {
    const body = new FormData();
    body.append("package", file);
    if (options.lessonConflictPolicy) {
      body.append("lessonConflictPolicy", options.lessonConflictPolicy);
    }
    if (options.lessonConflictPolicyBySourceId) {
      body.append("lessonConflictPolicyBySourceId", JSON.stringify(options.lessonConflictPolicyBySourceId));
    }

    return requestForm<PackageImportResult>("/packages/import-zip", body);
  },
};




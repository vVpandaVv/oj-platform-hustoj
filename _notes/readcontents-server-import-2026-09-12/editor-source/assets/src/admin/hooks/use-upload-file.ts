import * as React from 'react';

import { toast } from 'sonner';

export type UploadedFile = {
  appUrl: string;
  key: string;
  name: string;
  size: number;
  type: string;
  url: string;
};

interface UseUploadFileProps {
  onUploadComplete?: (file: UploadedFile) => void;
  onUploadError?: (error: unknown) => void;
}

export function useUploadFile({
  onUploadComplete,
  onUploadError,
}: UseUploadFileProps = {}) {
  const [uploadedFile, setUploadedFile] = React.useState<UploadedFile>();
  const [uploadingFile, setUploadingFile] = React.useState<File>();
  const [progress, setProgress] = React.useState(0);
  const [isUploading, setIsUploading] = React.useState(false);

  async function uploadFile(file: File): Promise<UploadedFile | undefined> {
    setIsUploading(true);
    setUploadingFile(file);
    setProgress(10);

    try {
      const restUrl = window.contentsEditorAdmin?.restUrl?.replace(/\/+$/, '');
      const restNamespaces = getRestNamespaceCandidates();
      const nonce = window.contentsEditorAdmin?.nonce;

      if (!restUrl) {
        throw new Error('WordPress 미디어 업로드 설정을 찾을 수 없습니다.');
      }

      const body = new FormData();
      body.append('file', file, file.name);
      setProgress(35);
      let lastMessage = '';

      for (const namespace of restNamespaces) {
        const response = await fetch(`${restUrl}/${namespace}/media`, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
          },
          body,
        });
        setProgress(85);

        const text = await response.text();
        let data: unknown = null;

        try {
          data = text ? JSON.parse(text) : null;
        } catch {
          throw new Error('미디어 서버가 올바른 JSON 응답을 반환하지 않았습니다.');
        }

        if (!response.ok) {
          const message = data && typeof data === 'object' && 'message' in data
            ? String((data as { message?: unknown }).message ?? '')
            : '';
          lastMessage = message || '미디어를 업로드하지 못했습니다.';

          if (response.status === 404 && namespace !== restNamespaces[restNamespaces.length - 1]) {
            continue;
          }

          throw new Error(lastMessage);
        }

        if (!isUploadedFile(data)) {
          throw new Error('미디어 업로드 결과에 파일 주소가 없습니다.');
        }

        setProgress(100);
        setUploadedFile(data);
        onUploadComplete?.(data);

        return data;
      }

      if (lastMessage) {
        throw new Error(lastMessage);
      }

      throw new Error('미디어 업로드 경로를 찾지 못했습니다.');
    } catch (error) {
      toast.error(getErrorMessage(error));
      onUploadError?.(error);

      return undefined;
    } finally {
      setTimeout(() => setProgress(0), 150);
      setIsUploading(false);
      setUploadingFile(undefined);
    }
  }

  return {
    isUploading,
    progress,
    uploadedFile,
    uploadFile,
    uploadingFile,
  };
}

function getRestNamespaceCandidates(): string[] {
  const configured = normalizeRestNamespace(window.contentsEditorAdmin?.restNamespace);
  const fallback = ['readcontents-library/v1', 'contents-editor/v1'];

  if (!configured) {
    return fallback;
  }

  return Array.from(
    new Set([configured, ...fallback].map(normalizeRestNamespace).filter((value): value is string => Boolean(value))),
  );
}

function normalizeRestNamespace(value?: string): string | null {
  if (!value) {
    return null;
  }

  const normalized = value.replace(/^\/+/, '').replace(/\/+$/, '');
  return normalized || null;
}

function isUploadedFile(value: unknown): value is UploadedFile {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const file = value as Partial<UploadedFile>;

  return typeof file.url === 'string' && file.url.length > 0
    && typeof file.name === 'string';
}

export function getErrorMessage(err: unknown): string {
  if (err instanceof Error && err.message.trim()) {
    return err.message;
  }

  return '미디어를 업로드하지 못했습니다. 잠시 후 다시 시도하세요.';
}

export function showErrorToast(err: unknown) {
  return toast.error(getErrorMessage(err));
}

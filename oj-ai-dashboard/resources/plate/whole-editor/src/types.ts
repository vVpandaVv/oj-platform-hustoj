import type { Value } from 'platejs';

export type ResponseFieldKind =
  | 'short_text'
  | 'long_text'
  | 'single_choice'
  | 'multiple_choice'
  | 'checkbox'
  | 'number'
  | 'table'
  | 'image_upload'
  | 'file_upload'
  | 'drawing';

export interface ResponseField {
  id: string;
  name: string;
  kind: ResponseFieldKind;
  label: string;
  required: boolean;
  options?: string[];
  columns?: Array<{ input_type?: string; key: string; label: string; options?: string[] }>;
  row_count?: number;
}

export interface WorksheetSchema {
  version: number;
  engine: string;
  title: string;
  plate_value: Value;
  document_html: string;
  response_fields: ResponseField[];
  blocks: unknown[];
}

export interface BuilderConfig {
  defaultSchema: Partial<WorksheetSchema>;
  strings?: Record<string, string>;
}

declare global {
  interface Window {
    ojAiDashboardWorksheetPlateBuilder?: BuilderConfig;
  }
}


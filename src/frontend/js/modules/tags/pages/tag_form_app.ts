/**
 * Tag create/edit form — reads and writes through `/api/v1/tags/{type}`.
 *
 * Replaces the same-origin POST to `/tags/new` and `/tags/{id}/edit`
 * (issue #262). Both tag types share this component; which one is in play
 * comes from the scaffold's config blob.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.4.0
 */

import Alpine from 'alpinejs';
import { t } from '@shared/i18n/translator';
import { TagsApi, type TagType } from '../api/tags_api';
import { readPageConfig } from '@shared/utils/page_config';

/** Config the scaffold ships. */
export interface TagFormConfig {
  type: TagType;
  isEdit: boolean;
  tagId: number;
  baseUrl: string;
}

/** Alpine component state. */
export interface TagFormAppData {
  type: TagType;
  isEdit: boolean;
  tagId: number;
  baseUrl: string;
  tagText: string;
  tagComment: string;
  isLoading: boolean;
  isSaving: boolean;
  error: string;
  init(): Promise<void>;
  load(): Promise<void>;
  save(): Promise<void>;
  cancel(): void;
  charCount(): number;
  charCountLabel(): string;
  charCountClass(): string;
}

/** Read the scaffold's config blob. */
function readConfig(): TagFormConfig {
  const cfg = readPageConfig<TagFormConfig>('tag-form-config', {
    type: 'term',
    isEdit: false,
    tagId: 0,
    baseUrl: '/tags'
  });
  // Anything but an explicit 'text' means the term-tag form.
  return { ...cfg, type: cfg.type === 'text' ? 'text' : 'term' };
}

/** Longest comment the server accepts; mirrored here only for the counter. */
const COMMENT_LIMIT = 200;

/**
 * Build the tag form component.
 *
 * @returns Alpine component data
 */
export function tagFormAppData(): TagFormAppData {
  return {
    type: 'term',
    isEdit: false,
    tagId: 0,
    baseUrl: '/tags',
    tagText: '',
    tagComment: '',
    isLoading: false,
    isSaving: false,
    error: '',

    async init(): Promise<void> {
      const config = readConfig();
      this.type = config.type;
      this.isEdit = config.isEdit;
      this.tagId = config.tagId;
      this.baseUrl = config.baseUrl;

      if (this.isEdit && this.tagId > 0) {
        await this.load();
      }
    },

    async load(): Promise<void> {
      this.isLoading = true;
      this.error = '';

      const response = await TagsApi.get(this.type, this.tagId);
      this.isLoading = false;

      if (response.error || !response.data || !response.data.tag) {
        this.error = (response.data && response.data.error)
          ? response.data.error
          : (response.error || t('tags.errors.load_failed'));
        return;
      }

      this.tagText = response.data.tag.text;
      this.tagComment = response.data.tag.comment;
    },

    async save(): Promise<void> {
      const text = this.tagText.trim();
      if (text === '') {
        this.error = t('tags.form_field_required');
        return;
      }

      this.isSaving = true;
      this.error = '';

      const response = this.isEdit
        ? await TagsApi.update(this.type, this.tagId, text, this.tagComment)
        : await TagsApi.create(this.type, text, this.tagComment);

      this.isSaving = false;

      if (response.error || !response.data || !response.data.success) {
        const body = response.data;
        this.error = (body && body.error)
          ? body.error
          : (response.error || t('tags.errors.action_failed'));
        return;
      }

      // The list anchors on the row that was just touched, as the retired
      // redirect did.
      window.location.href = this.isEdit
        ? `${this.baseUrl}#rec${this.tagId}`
        : this.baseUrl;
    },

    cancel(): void {
      window.location.href = this.isEdit
        ? `${this.baseUrl}#rec${this.tagId}`
        : this.baseUrl;
    },

    charCount(): number {
      return this.tagComment.length;
    },

    charCountLabel(): string {
      return `${this.charCount()}/${COMMENT_LIMIT} characters`;
    },

    charCountClass(): string {
      return this.charCount() > COMMENT_LIMIT ? 'has-text-danger' : 'has-text-grey';
    }
  };
}

Alpine.data('tagFormApp', tagFormAppData);

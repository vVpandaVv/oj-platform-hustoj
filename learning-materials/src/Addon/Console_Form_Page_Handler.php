<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use LearningMaterials\Materials\Material;
use LearningMaterials\Materials\Material_Access;
use LearningMaterials\Materials\Material_Repository;
use LearningMaterials\Materials\Material_Settings;

final class Console_Form_Page_Handler implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $repository = new Material_Repository();
        $material_id = (int) ($request->parameters()['material_id'] ?? 0);
        $material = null;
        $selected_group_ids = array();
        $title = __('자료 올리기', 'learning-materials');
        $list_url = home_url('/oj-console/addons/learning-materials');

        if ($material_id > 0) {
            $material = $repository->find($material_id);
            if (null === $material) {
                return Page_Response::make(
                    __('학습자료를 찾을 수 없습니다', 'learning-materials'),
                    Page_Response::empty_state(
                        __('학습자료를 찾을 수 없습니다', 'learning-materials'),
                        __('이미 삭제됐거나 주소가 바뀌었습니다.', 'learning-materials'),
                        $list_url,
                        __('목록으로', 'learning-materials')
                    ),
                    404
                );
            }
            $selected_group_ids = $repository->group_ids_for($material_id);
            $title = __('자료 편집', 'learning-materials');
        }

        $choices = (new Material_Access())->group_choices($request->actorUserId());
        $error_message = $request->query()['lmt_error'] ?? '';
        $error_message = is_string($error_message) ? $error_message : '';

        ob_start();
        ?>
        <div class="lmt-console">
            <header class="ojp-page-header">
                <div>
                    <p class="ojp-eyebrow"><?php esc_html_e('학습자료', 'learning-materials'); ?></p>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p><?php esc_html_e('그룹을 고르지 않으면 로그인한 모든 사용자에게 보입니다.', 'learning-materials'); ?></p>
                </div>
            </header>

            <?php if ('' !== $error_message) : ?>
                <div class="ojp-notice ojp-notice--danger" role="alert"><strong><?php esc_html_e('저장하지 못했습니다', 'learning-materials'); ?></strong><p><?php echo esc_html($error_message); ?></p></div>
            <?php endif; ?>

            <form class="ojp-form-stack lmt-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="learning_materials_save">
                <?php if ($material instanceof Material) : ?>
                    <input type="hidden" name="material_id" value="<?php echo (int) $material->id; ?>">
                <?php endif; ?>
                <?php wp_nonce_field('learning_materials_save'); ?>

                <div class="ojp-field">
                    <label for="lmt-title"><?php esc_html_e('제목', 'learning-materials'); ?></label>
                    <input class="ojp-input" id="lmt-title" name="title" maxlength="200" required value="<?php echo esc_attr($material->title ?? ''); ?>">
                </div>

                <div class="ojp-field">
                    <label for="lmt-description"><?php esc_html_e('설명', 'learning-materials'); ?></label>
                    <textarea class="ojp-textarea" id="lmt-description" name="description" maxlength="4000"><?php echo esc_textarea($material->description ?? ''); ?></textarea>
                </div>

                <div class="ojp-field">
                    <label for="lmt-category"><?php esc_html_e('분류', 'learning-materials'); ?></label>
                    <input class="ojp-input" id="lmt-category" name="category" maxlength="120" value="<?php echo esc_attr($material->category ?? ''); ?>" placeholder="<?php esc_attr_e('예: 정보 / 인공지능', 'learning-materials'); ?>">
                </div>
                <div class="ojp-field">
                    <label for="lmt-tags"><?php esc_html_e('태그', 'learning-materials'); ?></label>
                    <input class="ojp-input" id="lmt-tags" name="tags" maxlength="500" value="<?php echo esc_attr(implode(', ', $material->tags ?? array())); ?>" placeholder="<?php esc_attr_e('쉼표로 구분 · 최대 10개', 'learning-materials'); ?>">
                </div>
                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('공개 설정', 'learning-materials'); ?></legend>
                    <div class="ojp-field">
                        <label for="lmt-publication-status"><?php esc_html_e('상태', 'learning-materials'); ?></label>
                        <select class="ojp-select" id="lmt-publication-status" name="publication_status">
                            <?php foreach (array('draft' => __('초안', 'learning-materials'), 'published' => __('공개', 'learning-materials'), 'archived' => __('보관', 'learning-materials')) as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($material->publication_status ?? 'draft', $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ojp-field">
                        <label for="lmt-publish-from"><?php esc_html_e('공개 시작', 'learning-materials'); ?></label>
                        <input class="ojp-input" id="lmt-publish-from" type="datetime-local" name="publish_from" value="<?php echo esc_attr(Material_Settings::local_date($material->publish_from ?? null, wp_timezone())); ?>">
                    </div>
                    <div class="ojp-field">
                        <label for="lmt-publish-until"><?php esc_html_e('공개 종료', 'learning-materials'); ?></label>
                        <input class="ojp-input" id="lmt-publish-until" type="datetime-local" name="publish_until" value="<?php echo esc_attr(Material_Settings::local_date($material->publish_until ?? null, wp_timezone())); ?>">
                    </div>
                    <p class="ojp-field-help"><?php echo esc_html(sprintf(__('사이트 시간대: %s · 기간을 비우면 제한 없음', 'learning-materials'), wp_timezone()->getName())); ?></p>
                    <label class="ojp-toggle-row">
                        <input type="checkbox" name="is_pinned" value="1" <?php checked($material->is_pinned ?? false); ?>>
                        <span><?php esc_html_e('목록 상단에 고정', 'learning-materials'); ?></span>
                    </label>
                </fieldset>

                <div class="ojp-field">
                    <label for="lmt-file"><?php esc_html_e('파일', 'learning-materials'); ?></label>
                    <input class="ojp-input lmt-file-input" id="lmt-file" type="file" name="file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp">
                    <p class="ojp-field-help">
                        <?php
                        if ($material instanceof Material && ! $material->is_google_link()) {
                            printf(
                                /* translators: %s: current uploaded filename */
                                esc_html__('최대 50MB. 현재 파일: %s', 'learning-materials'),
                                esc_html($material->original_filename)
                            );
                        } else {
                            esc_html_e('PDF·PPT·Word·Excel·TXT·CSV·이미지 · 최대 50MB', 'learning-materials');
                        }
                        ?>
                    </p>
                </div>

                <div class="ojp-field">
                    <label for="lmt-source-url"><?php esc_html_e('Google 공유 링크', 'learning-materials'); ?></label>
                    <input class="ojp-input" id="lmt-source-url" type="url" name="source_url" maxlength="2048" value="<?php echo esc_attr($material->source_url ?? ''); ?>" placeholder="https://docs.google.com/…">
                    <p class="ojp-field-help"><?php esc_html_e('파일 또는 링크 중 하나를 입력하세요. 링크의 Google 공유 권한도 확인해 주세요.', 'learning-materials'); ?></p>
                </div>

                <fieldset class="ojp-fieldset">
                    <legend><?php esc_html_e('볼 수 있는 그룹', 'learning-materials'); ?></legend>
                    <?php if (array() === $choices) : ?>
                        <p class="ojp-field-help"><?php esc_html_e('고를 수 있는 그룹이 없습니다. 이대로 저장하면 모든 사용자에게 보입니다.', 'learning-materials'); ?></p>
                    <?php else : ?>
                        <div class="ojp-toggle-list">
                            <?php foreach ($choices as $group_id => $label) : ?>
                                <label class="ojp-toggle-row">
                                    <input type="checkbox" name="group_ids[]" value="<?php echo esc_attr($group_id); ?>" <?php checked(in_array($group_id, $selected_group_ids, true)); ?>>
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="ojp-field-help"><?php esc_html_e('아무것도 고르지 않으면 모든 사용자에게 보입니다.', 'learning-materials'); ?></p>
                    <?php endif; ?>
                </fieldset>

                <div class="lmt-form-actions">
                    <a class="ojp-button" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('취소', 'learning-materials'); ?></a>
                    <button type="submit" class="ojp-button ojp-button--primary">
                        <?php echo esc_html(null === $material ? __('올리기', 'learning-materials') : __('저장', 'learning-materials')); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php

        return Page_Response::make($title, (string) ob_get_clean());
    }
}

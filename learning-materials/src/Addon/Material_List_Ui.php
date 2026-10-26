<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use LearningMaterials\Materials\Material;

final class Material_List_Ui {
    public static function types(): array {
        return array('pdf' => 'PDF', 'presentation' => __('발표 자료', 'learning-materials'), 'document' => __('문서', 'learning-materials'),
            'spreadsheet' => __('스프레드시트', 'learning-materials'), 'text' => __('텍스트', 'learning-materials'),
            'image' => __('이미지', 'learning-materials'), 'google' => __('Google 링크', 'learning-materials'));
    }

    public static function states(): array {
        return array('draft' => __('초안', 'learning-materials'), 'published' => __('공개', 'learning-materials'),
            'scheduled' => __('공개 예정', 'learning-materials'), 'expired' => __('공개 종료', 'learning-materials'), 'archived' => __('보관', 'learning-materials'));
    }

    public static function filters(array $result, string $url, array $group_labels, bool $console = false): void {
        $filters = $result['filters'];
        $groups = array();
        foreach ($result['groups'] as $id) $groups[$id] = $group_labels[$id] ?? $id;
        $fields = array('type' => array(__('형식', 'learning-materials'), self::types()),
            'category' => array(__('분류', 'learning-materials'), array_combine($result['categories'], $result['categories']) ?: array()),
            'tag' => array(__('태그', 'learning-materials'), array_combine($result['tags'], $result['tags']) ?: array()),
            'group' => array(__('그룹', 'learning-materials'), $groups));
        if ($console) $fields['status'] = array(__('상태', 'learning-materials'), self::states());
        ?>
        <form class="lmt-filters" method="get" action="<?php echo esc_url($url); ?>">
            <div class="ojp-field lmt-search-field">
                <label for="lmt-search"><?php esc_html_e('자료 검색', 'learning-materials'); ?></label>
                <input class="ojp-input" id="lmt-search" type="search" name="q" maxlength="200" value="<?php echo esc_attr($filters['q']); ?>" placeholder="<?php esc_attr_e('제목·설명·태그 검색', 'learning-materials'); ?>">
            </div>
            <?php foreach ($fields as $name => [$label, $options]) : ?>
                <div class="ojp-field">
                    <label for="lmt-filter-<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
                    <select class="ojp-select" id="lmt-filter-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>">
                        <option value=""><?php esc_html_e('전체', 'learning-materials'); ?></option>
                        <?php foreach ($options as $value => $text) : ?>
                            <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($filters[$name], (string) $value); ?>><?php echo esc_html($text); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="lmt-filter-actions">
                <button class="ojp-button ojp-button--primary" type="submit"><?php esc_html_e('검색', 'learning-materials'); ?></button>
                <a class="ojp-button" href="<?php echo esc_url($url); ?>"><?php esc_html_e('초기화', 'learning-materials'); ?></a>
            </div>
        </form>
        <p class="lmt-result-count"><?php echo esc_html(sprintf(__('총 %s개', 'learning-materials'), (string) $result['total'])); ?></p>
        <?php
    }

    public static function pagination(array $result, string $url): void {
        if ($result['pages'] <= 1) return;
        $args = array_filter($result['filters'], static fn ($v) => $v !== '');
        ?>
        <nav class="lmt-pagination" aria-label="<?php esc_attr_e('학습자료 페이지', 'learning-materials'); ?>">
            <?php if ($result['page'] > 1) : ?>
                <a class="ojp-button" rel="prev" href="<?php echo esc_url(add_query_arg(array_merge($args, array('lmt_page' => $result['page'] - 1)), $url)); ?>"><?php esc_html_e('이전', 'learning-materials'); ?></a>
            <?php endif; ?>
            <span aria-current="page"><?php echo esc_html($result['page'] . ' / ' . $result['pages']); ?></span>
            <?php if ($result['page'] < $result['pages']) : ?>
                <a class="ojp-button" rel="next" href="<?php echo esc_url(add_query_arg(array_merge($args, array('lmt_page' => $result['page'] + 1)), $url)); ?>"><?php esc_html_e('다음', 'learning-materials'); ?></a>
            <?php endif; ?>
        </nav>
        <?php
    }

    public static function badges(Material $material, bool $state = false): void {
        echo '<div class="lmt-badges">';
        $labels = array();
        if ($material->is_pinned) $labels[] = __('고정', 'learning-materials');
        if ($state) $labels[] = self::states()[$material->publication_state()] ?? $material->publication_status;
        if ($material->category !== '') $labels[] = $material->category;
        foreach ($material->tags as $tag) $labels[] = '#' . $tag;
        foreach ($labels as $label) echo '<span class="ojp-status-badge ojp-status-badge--neutral">' . esc_html($label) . '</span>';
        echo '</div>';
    }
}

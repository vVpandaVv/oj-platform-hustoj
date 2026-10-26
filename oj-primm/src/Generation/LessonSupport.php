<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

use DomainException;

/** Only publicPayload() may project generated help material to a learner. */
final class LessonSupport
{
    public static function normalize(array $support, string $source, string $kind): array
    {
        if (($support['kind'] ?? null) !== $kind) { throw new DomainException('생성된 도움 활동의 방식이 요청과 다릅니다.'); }
        if ($kind === 'code_ordering') {
            LessonSchema::fields($support, ['kind', 'ordering_blocks']);
            if (! is_array($support['ordering_blocks']) || ! array_is_list($support['ordering_blocks'])
                || count($support['ordering_blocks']) < 2 || count($support['ordering_blocks']) > 60) {
                throw new DomainException('생성된 코드 배열은 2개부터 60개의 조각이 필요합니다.');
            }
            $support = ['kind' => $kind, 'blocks' => array_map(static fn ($code): array =>
                ['id' => bin2hex(random_bytes(16)), 'code' => LessonSchema::code($code, '배열 조각')], $support['ordering_blocks'])];
        }
        return self::validateNormalized($support, $source, $kind);
    }

    public static function validateNormalized(array $support, string $source, string $kind): array
    {
        if (($support['kind'] ?? null) !== $kind || ! in_array($kind, LessonSchema::KINDS, true)) { throw new DomainException('도움 활동 방식을 확인해 주세요.'); }
        if ($kind === 'none') { LessonSchema::fields($support, ['kind']); return $support; }
        if ($kind === 'code_ordering') {
            LessonSchema::fields($support, ['kind', 'blocks']);
            if (! is_array($support['blocks']) || ! array_is_list($support['blocks']) || count($support['blocks']) < 2 || count($support['blocks']) > 60) { throw new DomainException('배열 조각 수를 확인해 주세요.'); }
            $seen = [];
            $blocks = [];
            foreach ($support['blocks'] as $block) {
                $block = LessonSchema::map($block, '배열 조각'); LessonSchema::fields($block, ['id', 'code']);
                if (! is_string($block['id']) || preg_match('/^[a-f0-9]{32}$/D', $block['id']) !== 1 || isset($seen[$block['id']])) { throw new DomainException('배열 조각 ID가 중복되었거나 올바르지 않습니다.'); }
                $seen[$block['id']] = true;
                $code = LessonSchema::code($block['code'], '배열 조각');
                if ($code !== $block['code']) { throw new DomainException('배열 조각의 줄바꿈이 정규형과 일치하지 않습니다.'); }
                $blocks[] = ['id' => $block['id'], 'code' => $code];
            }
            if (implode('', array_column($blocks, 'code')) !== $source) { throw new DomainException('배열 조각을 연결한 코드가 수정 활동 코드와 일치하지 않습니다.'); }
            return ['kind' => $kind, 'blocks' => $blocks];
        }
        LessonSchema::fields($support, ['kind', 'template', 'blanks']);
        $template = LessonSchema::code($support['template'], '빈칸 템플릿');
        if (! is_array($support['blanks']) || ! array_is_list($support['blanks']) || count($support['blanks']) < 1 || count($support['blanks']) > 30) { throw new DomainException('빈칸은 1개부터 30개까지 필요합니다.'); }
        $blanks = [];
        foreach ($support['blanks'] as $blank) {
            $blank = LessonSchema::map($blank, '빈칸'); LessonSchema::fields($blank, ['id', 'label', 'kind', 'max_bytes', 'answer']);
            $id = $blank['id'];
            if (! is_string($id) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id) !== 1 || isset($blanks[$id])
                || ! in_array($blank['kind'], ['inline', 'block'], true) || ! is_int($blank['max_bytes']) || $blank['max_bytes'] < 1 || $blank['max_bytes'] > 8192) {
                throw new DomainException('빈칸의 ID·종류·길이를 확인해 주세요.');
            }
            $answer = LessonSchema::text($blank['answer'], $blank['max_bytes'], '빈칸 기준 응답');
            if ($blank['kind'] === 'inline' && str_contains($answer, "\n")) { throw new DomainException('한 줄 빈칸의 기준 응답에는 줄바꿈을 넣을 수 없습니다.'); }
            $blanks[$id] = ['id' => $id, 'label' => LessonSchema::text($blank['label'], 160, '빈칸 이름'),
                'kind' => $blank['kind'], 'max_bytes' => $blank['max_bytes'], 'answer' => $answer];
        }
        preg_match_all('/\{\{OJ_BLANK:([a-zA-Z][a-zA-Z0-9_-]{0,63})\}\}/', $template, $matches);
        $tokens = $matches[1];
        if (count($tokens) !== count($blanks) || count(array_unique($tokens)) !== count($tokens)
            || array_diff($tokens, array_keys($blanks)) !== [] || array_diff(array_keys($blanks), $tokens) !== []) { throw new DomainException('빈칸 템플릿과 빈칸 설정이 일치하지 않습니다.'); }
        $filled = preg_replace_callback('/\{\{OJ_BLANK:([a-zA-Z][a-zA-Z0-9_-]{0,63})\}\}/', static fn ($m): string => $blanks[$m[1]]['answer'], $template);
        if ($filled !== $source) { throw new DomainException('빈칸 기준 응답을 대입한 코드가 수정 활동 코드와 일치하지 않습니다.'); }
        return ['kind' => $kind, 'template' => $template, 'blanks' => array_values($blanks)];
    }

    /** Public template version binds public content only, never a hidden answer/order. */
    public static function publicPayload(array $lesson): array
    {
        [$support, $language] = self::material($lesson);
        if ($support['kind'] === 'none') { return ['kind' => 'none']; }
        if ($support['kind'] === 'code_ordering') {
            $canonical = $support['blocks'];
            usort($canonical, static fn ($a, $b): int => strcmp($a['id'], $b['id']));
            $hash = self::hash(['kind' => $support['kind'], 'language' => $language, 'blocks' => $canonical]);
            $blocks = $support['blocks'];
            for ($i = count($blocks) - 1; $i > 0; --$i) { $j = random_int(0, $i); [$blocks[$i], $blocks[$j]] = [$blocks[$j], $blocks[$i]]; }
            return ['kind' => 'code_ordering', 'language' => $language, 'template_hash' => $hash, 'blocks' => $blocks];
        }
        $blanks = array_map(static function (array $blank): array { unset($blank['answer']); return $blank; }, $support['blanks']);
        $public = ['kind' => 'code_completion', 'language' => $language, 'starter_code' => $support['template'], 'blanks' => $blanks];
        $public['template_hash'] = self::hash($public);
        return $public;
    }

    /** Reconstructing a learner response is not a correctness or execution verdict. */
    public static function assemble(array $lesson, array $answer): array
    {
        [$support, $language] = self::material($lesson);
        if ($support['kind'] === 'none') { throw new DomainException('이 수업에는 생성된 도움 활동이 없습니다.'); }
        $public = self::publicPayload($lesson);
        $required = $support['kind'] === 'code_ordering' ? ['template_hash', 'block_ids'] : ['template_hash', 'answers'];
        LessonSchema::fields($answer, $required);
        if (! is_string($answer['template_hash']) || ! hash_equals($public['template_hash'], $answer['template_hash'])) { throw new DomainException('도움 활동의 버전이 바뀌었습니다. 다시 불러와 주세요.'); }
        if ($support['kind'] === 'code_ordering') {
            $ids = $answer['block_ids'];
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) !== count($support['blocks'])) { throw new DomainException('모든 코드 조각을 한 번씩 배치해 주세요.'); }
            $byId = array_column($support['blocks'], 'code', 'id');
            $source = '';
            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($byId[$id])) { throw new DomainException('중복되었거나 알 수 없는 코드 조각입니다.'); }
                $source .= $byId[$id]; unset($byId[$id]);
            }
        } else {
            $values = $answer['answers'];
            if (! is_array($values)) { throw new DomainException('빈칸 응답을 확인해 주세요.'); }
            $blanks = array_column($support['blanks'], null, 'id');
            if (array_diff(array_keys($values), array_keys($blanks)) !== [] || array_diff(array_keys($blanks), array_keys($values)) !== []) { throw new DomainException('모든 빈칸에 한 번씩 답해 주세요.'); }
            foreach ($values as $id => $value) {
                $values[$id] = LessonSchema::text($value, $blanks[$id]['max_bytes'], '빈칸 응답');
                if ($blanks[$id]['kind'] === 'inline' && str_contains($values[$id], "\n")) { throw new DomainException('한 줄 빈칸에는 줄바꿈을 넣을 수 없습니다.'); }
            }
            $source = preg_replace_callback('/\{\{OJ_BLANK:([a-zA-Z][a-zA-Z0-9_-]{0,63})\}\}/', static fn ($m): string => $values[$m[1]], $support['template']);
        }
        if (! is_string($source) || strlen($source) > 16384) { throw new DomainException('복원한 코드가 허용 크기를 초과합니다.'); }
        return ['language' => $language, 'template_hash' => $public['template_hash'], 'source' => $source,
            'verification' => ['status' => 'not_executed', 'note' => '코드를 복원했으며 실행·채점 정답은 확인하지 않았습니다.']];
    }

    private static function material(array $lesson): array
    {
        $language = $lesson['language'] ?? '';
        if (! is_string($language) || ! in_array($language, LessonSchema::LANGUAGES, true)) { throw new DomainException('생성 수업의 언어를 확인해 주세요.'); }
        $modify = null;
        foreach (($lesson['stages'] ?? []) as $stage) { if (is_array($stage) && ($stage['key'] ?? '') === 'modify') { $modify = $stage; break; } }
        $support = LessonSchema::map($lesson['support'] ?? null, '도움 활동');
        $source = LessonSchema::code($modify['starter_code'] ?? '', '수정 활동 코드');
        return [self::validateNormalized($support, $source, (string) ($support['kind'] ?? '')), $language];
    }
    private static function hash(array $public): string { return hash('sha256', json_encode($public, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
}

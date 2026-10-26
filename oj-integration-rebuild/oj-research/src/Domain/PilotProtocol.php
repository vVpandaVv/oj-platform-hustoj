<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Domain;

use DomainException;

/** A new protocol discriminator keeps earlier independent-rating studies unchanged. */
final class PilotProtocol
{
    public const TYPE = 'classroom_pilot.v2';
    public const PHASES = ['draft'=>'연구 준비', 'baseline'=>'기본 학습', 'pretest'=>'사전 평가', 'allocation_ready'=>'집단 배정',
        'allocated'=>'수업 준비', 'practice'=>'수업 적용', 'between_lessons'=>'차시 종료', 'posttest'=>'사후 평가',
        'completed'=>'결과 확인', 'paused'=>'일시 중단', 'archived'=>'보관', 'cancelled'=>'중단'];
    public const AI = ['level'=>'수준별 AI', 'common'=>'공통 AI', 'off'=>'AI 사용 안 함'];
    public const REC = ['personal'=>'개인별 추천', 'common'=>'공통 문제 순서', 'teacher'=>'교사 지정 문제'];
    public const ACTIVE = ['pretest','practice','posttest'];
    public const NOTES = ['question'=>'연구 질문', 'environment'=>'연구 대상·학교·학년·기기 환경', 'period'=>'연구 기간·집단별 일정',
        'recruitment'=>'모집·포함·제외 기준', 'citation'=>'기존 학생 수준 평가 연구 출처', 'baseline_content'=>'기본 알고리즘 학습 내용',
        'dataset'=>'자료 구축·분류·검수 절차', 'equivalence'=>'사전·사후 문항 동형성', 'teacher'=>'교사 지원·해설 공개 조건',
        'analysis'=>'주요 분석·공변량·학급 구조·효과 크기', 'missing'=>'결측·철회·미해결 처리 계획',
        'consent'=>'동의 문서·자료 보관·철회 절차', 'additional'=>'추가 연구 조건·외부 도구·면담·선택 평가'];

    public static function is(array $study): bool { return ($study['data']['protocol']['type'] ?? '') === self::TYPE; }
    public static function ids(mixed $value): array
    {
        if (is_string($value)) {
            if (trim($value) === '') { return []; }
            if (! preg_match('/^\s*\d+(?:[\s,]+\d+)*\s*$/D', $value)) { throw new DomainException('문제·그룹 번호는 쉼표나 공백으로 구분해 주세요.'); }
            $value = preg_split('/[\s,]+/', trim($value));
        }
        if (! is_array($value) || count($value) > 500) { throw new DomainException('번호 목록을 확인해 주세요.'); }
        foreach ($value as $id) { if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) { throw new DomainException('번호는 양의 정수여야 합니다.'); } }
        return array_values(array_unique(array_map('intval', $value)));
    }
    public static function text(mixed $value, int $max = 12000): string
    {
        if (! is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1) { throw new DomainException('입력 내용의 길이와 문자 형식을 확인해 주세요.'); }
        return trim($value);
    }
    public static function choice(mixed $value, array $options): string
    {
        if (! is_string($value) || ! in_array($value, $options, true)) { throw new DomainException('설정 항목을 확인해 주세요.'); }
        return $value;
    }
    public static function integer(mixed $value, int $min, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) { throw new DomainException('숫자 입력 범위를 확인해 주세요.'); }
        return (int) $value;
    }
    public static function validate(array $input): array
    {
        $p = ['type'=>self::TYPE, 'groups'=>self::ids($input['groups'] ?? []), 'raters'=>[], 'materials'=>[]];
        $p['title'] = self::text($input['title'] ?? '', 191);
        if ($p['title'] === '' || $p['groups'] === []) { throw new DomainException('연구 제목과 대상 그룹을 선택해 주세요.'); }
        foreach (['practice','pretest','posttest'] as $key) { $p[$key] = self::ids($input[$key] ?? []); }
        foreach (['pretest','posttest'] as $key) {
            if (array_intersect($p['practice'], $p[$key]) !== []) { throw new DomainException('연습 문제와 공통 평가 문제를 분리해 주세요.'); }
        }
        $p['design'] = self::choice($input['design'] ?? 'custom', array_keys(PilotDesign::LABELS));
        $p['covariance'] = self::choice($input['covariance'] ?? 'auto', ['auto','hc3','cluster']);
        foreach (['A','B'] as $arm) {
            $g = $input['arms'][$arm] ?? [];
            $p['arms'][$arm] = ['name'=>self::text($g['name'] ?? ($arm === 'A' ? '실험집단' : '비교집단'), 100),
                'ai'=>self::choice($g['ai'] ?? ($arm === 'A' ? 'level' : ($p['design']==='adaptation'?'common':'off')), array_keys(self::AI)),
                'recommendation'=>self::choice($g['recommendation'] ?? ($p['design']!=='custom'?'common':($arm === 'A' ? 'personal' : 'teacher')), array_keys(self::REC)),
                'weight'=>self::integer($g['weight'] ?? 1,1,10), 'minutes'=>self::integer($g['minutes'] ?? 45,1,300),
                'conditions'=>self::text($g['conditions'] ?? '')];
        }
        $p['lessons_count'] = self::integer($input['lessons_count'] ?? 6,1,100);
        $p['test_minutes'] = self::integer($input['test_minutes'] ?? 45,1,300);
        $p['request_limit'] = self::integer($input['request_limit'] ?? 3,0,100);
        $p['allocation'] = self::choice($input['allocation'] ?? 'stratified', ['stratified','random','class','manual']);
        $p['level_timing'] = self::choice($input['level_timing'] ?? 'baseline', ['baseline','current']);
        $p['scoring'] = self::choice($input['scoring'] ?? 'ac', ['ac','pass_rate']);
        $p['errors'] = self::choice($input['errors'] ?? 'first', ['first','all']);
        $p['pause_time'] = self::choice($input['pause_time'] ?? 'exclude', ['exclude','include']);
        $p['survey_max'] = self::integer($input['survey_max'] ?? 5,2,10);
        $p['survey_items'] = array_values(array_filter(array_map('trim', explode("\n", self::text($input['survey_items'] ?? "문제 해결 방법을 이해하는 데 도움이 되었다.\n학습 활동에 만족한다.\n다음 수업에도 참여하고 싶다.")))));
        if (count($p['survey_items']) < 1 || count($p['survey_items']) > 30) { throw new DomainException('공통 설문 문항은 1~30개로 구성해 주세요.'); }
        foreach (['pretest','posttest'] as $phase) { foreach (['A','B'] as $arm) {
            $p['support'][$phase][$arm] = self::choice($input['support'][$phase][$arm] ?? 'off', ['off','level','common']);
        } }
        if ($p['support']['pretest']['A'] !== $p['support']['pretest']['B']) { throw new DomainException('배정 전 사전 평가의 AI 조건은 두 집단이 같아야 합니다.'); }
        $p['common_instructions'] = self::text($input['common_instructions'] ?? '현재 제출의 오류 원인과 관련 개념을 설명하고 스스로 확인할 질문을 제시한다. 학생 수준 정보는 사용하지 않는다. 완성된 정답 코드를 제공하지 않는다.');
        foreach (self::NOTES as $key=>$label) { $p['notes'][$key] = self::text($input['notes'][$key] ?? ''); }
        if ($p['design']!=='custom' && ($issues=PilotDesign::issues($p))!==[]) {throw new DomainException(implode(' ', $issues).' 다른 조건이 필요하면 직접 설계를 선택하세요.');}
        if ($p['allocation']==='class' && $p['covariance']==='hc3') {throw new DomainException('학급 단위 배정에는 학급 보정 또는 자동 분석을 선택하세요.');}
        return $p;
    }

    public static function allocate(array $people, string $mode, array $arms, string $seed, array $manual = []): array
    {
        if (count($people) < 2) { throw new DomainException('두 집단으로 배정하려면 2명 이상 필요합니다.'); }
        $result = []; $strata = [];
        foreach ($people as $person) {
            if ($mode === 'manual') {
                $result[$person['id']] = self::choice($manual[$person['id']] ?? '', ['A','B']);
            } else {
                $key = match ($mode) { 'class'=>(string)$person['group_id'], 'stratified'=>(string)($person['baseline']['level'] ?? 'insufficient'), default=>'all' };
                $strata[$key][] = $person;
            }
        }
        $counts = ['A'=>0,'B'=>0];
        uksort($strata, static fn ($a,$b)=>strcmp(hash_hmac('sha256',(string)$a,$seed),hash_hmac('sha256',(string)$b,$seed)));
        foreach ($strata as $key=>$members) {
            usort($members, static fn ($a,$b)=>strcmp(hash_hmac('sha256',(string)$a['id'],$seed),hash_hmac('sha256',(string)$b['id'],$seed)));
            $local = ['A'=>0,'B'=>0]; $classArm = null;
            foreach ($members as $person) {
                $pool = $mode === 'stratified' ? $local : $counts;
                $diff = $pool['A'] / $arms['A']['weight'] - $pool['B'] / $arms['B']['weight'];
                $arm = $diff < 0 ? 'A' : ($diff > 0 ? 'B' : ($counts['A'] <= $counts['B'] ? 'A' : 'B'));
                if ($mode === 'class') { $classArm ??= $arm; $arm = $classArm; }
                $result[$person['id']] = $arm; ++$counts[$arm]; ++$local[$arm];
            }
        }
        if (count(array_unique($result)) !== 2) { throw new DomainException('두 집단 모두에 학생이 배정되어야 합니다. 학급 배정은 2개 이상의 그룹이 필요합니다.'); }
        return $result;
    }
}

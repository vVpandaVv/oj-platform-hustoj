"""Summarize exported production records without inventing human scores."""
import copy
import hashlib
import json
import random
import statistics
from pathlib import Path

folder = Path(__file__).resolve().parents[1] / 'oj-platform/docs/research-feedback-validation-20260911'
plan = json.loads((folder/'run-plan.json').read_text(encoding='utf-8'))
live = json.loads((folder/'live-verification.json').read_text(encoding='utf-8'))
records, sources = {}, []
for level in plan['matrix']:
    path = folder/f'raw-{level}.json'
    packet = json.loads(path.read_text(encoding='utf-8'))
    sources.append({'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'exported_at': packet['exported_at']})
    for record in packet['records']:
        assert record['level'] == level
        assert record['input']['submission']['source'] == packet['cases'][record['case_key']]['source']
        assert 'actor_id' not in record
        records[record['id']] = record
records = sorted(records.values(), key=lambda r: (r['started_at'], r['id']))
pilot = [r for r in records if r['assignment']['task_type'] == plan['task']]
pair_checks, missing_pairs = [], []
for level, cases in plan['matrix'].items():
    for case in cases:
        pair = [r for r in pilot if r['level'] == level and r['case_key'] == case]
        if not pair:
            missing_pairs.append({'level':level,'case_key':case,'reason':'provider_request_limit_reached'})
            continue
        assert len(pair) == 2 and {r['variant'] for r in pair} == {'baseline','candidate'}, (level,case)
        old, new = sorted(pair, key=lambda r:r['variant'])
        a, b = copy.deepcopy(old['input']), copy.deepcopy(new['input'])
        del a['response_policy']['instructions'], b['response_policy']['instructions']
        equal_input, equal_model = a == b, old['assignment'] == new['assignment']
        assert equal_input and equal_model
        pair_checks.append({'level':level, 'case_key':case, 'same_input_except_instructions':equal_input, 'same_assignment':equal_model, 'both_outputs_received':'output' in old and 'output' in new, 'baseline_id':old['id'], 'candidate_id':new['id']})
assert len(pilot) + 2*len(missing_pairs) == plan['planned_outputs']
assert all(all(v is None for v in r['human_review'].values()) for r in records)
statistics_by_variant = {}
for variant in plan['variants']:
    items = [r for r in pilot if r['variant'] == variant]
    received = [r for r in items if 'output' in r]
    elapsed = [r['elapsed_ms']/1000 for r in items]
    statistics_by_variant[variant] = {
        'attempts':len(items), 'received':len(received),
        'platform_accepted':sum(r['status']=='generated' for r in items),
        'all_automatic_checks_pass':sum(bool(r['checks']) and all(r['checks'].values()) for r in items),
        'elapsed_seconds_mean':round(statistics.mean(elapsed),3),
        'elapsed_seconds_median':round(statistics.median(elapsed),3),
        'elapsed_seconds_min':min(elapsed), 'elapsed_seconds_max':max(elapsed),
        'received_seconds_mean':round(statistics.mean(r['elapsed_ms']/1000 for r in received),3) if received else None,
        'received_seconds_min':min((r['elapsed_ms']/1000 for r in received),default=None),
        'received_seconds_max':max((r['elapsed_ms']/1000 for r in received),default=None),
        'teacher_review_count':0,
    }
previous = {r['id']:r for r in live['records']}
summaries = []
for r in records:
    summary = {k:r[k] for k in ('id','case_key','level','variant','status','started_at','completed_at','elapsed_ms','input_hash','checks') if k in r}
    summary['assignment'] = r['assignment']
    if r['id'] in previous:
        summary['max_output_tokens'] = previous[r['id']]['max_output_tokens']
    else:
        summary['max_output_tokens'] = plan['max_output_tokens']
    for key in ('output','model','validation_error','error'):
        if key in r: summary[key] = r[key]
    summaries.append(summary)
live.update({
    'platform_version':'2.0.0-dev.106',
    'package_sha256':'2b3395bdc3e473204ef7f63541421a4197a9a37bc5c8a84394c2842e8a19ecb2',
    'observed_at': max(r['completed_at'] for r in records),
    'successful_outputs':sum('output' in r for r in records),
    'teacher_reviews':0, 'pilot_model':plan['model'],
    'pilot_statistics':statistics_by_variant, 'raw_sources':sources,
    'pair_verification':pair_checks, 'unexecuted_pairs':missing_pairs, 'records':summaries,
    'planned_pilot_requests':plan['planned_outputs'], 'executed_pilot_requests':len(pilot),
    'complete_response_pairs':sum(p['both_outputs_received'] for p in pair_checks),
    'stop_reason':'Gemini 후보 재검사에서 AI Provider 요청 한도에 도달했습니다 메시지 확인. 고급 6건 미실행.',
    'interpretation':'합성 사례 단회 요청에서 응답과 실패를 수집했으나 제공자 요청 한도로 계획 일부 미실행. 독립 교사 평가·학습 효과·통계적 우위성은 확인하지 않았다.',
})
(folder/'live-verification.json').write_text(json.dumps(live,ensure_ascii=False,indent=2),encoding='utf-8')
blinded, key = [], []
shuffled = list(pilot)
random.Random(20260911).shuffle(shuffled)
for index,r in enumerate(shuffled,1):
    review_id=f'R{index:03d}'
    blinded.append({'review_id':review_id,'case_key':r['case_key'],'intended_level':r['level'],'status':r['status'],'output':r.get('output'), 'scores':copy.deepcopy(r['human_review'])})
    key.append({'review_id':review_id,'record_id':r['id'],'variant':r['variant'],'guide_key':r['guide']['key']})
(folder/'teacher-review-blank.json').write_text(json.dumps({'scope':'평가자용: 조건 이름 가림, 점수는 미평가','responses':blinded},ensure_ascii=False,indent=2),encoding='utf-8')
(folder/'teacher-review-key.json').write_text(json.dumps({'scope':'운영자용 조건 대응표. 독립 평가 전에 평가자에게 제공하지 않음.','keys':key},ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'pilot':statistics_by_variant,'matched_pairs':len(pair_checks),'raw_records':len(records)},ensure_ascii=False,indent=2))

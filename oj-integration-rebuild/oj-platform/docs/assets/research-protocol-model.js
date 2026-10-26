(function (host) {
  'use strict';
  const ai = { level: '학생 수준별 AI 피드백', common: '수준을 반영하지 않는 공통 AI 피드백', off: 'AI 피드백 미사용', custom: '연구자 지정 AI 조건' };
  const rec = { personal: '개인별 문제 추천', common: '공통 문제 추천', teacher: '교사 지정 문제', custom: '연구자 지정 문제 제공' };
  const defaults = {
    schemaVersion: 1,
    title: '학생 수준별 AI 피드백 및 문제 추천을 활용한 중학교 알고리즘 수업 연구',
    question: '두 집단의 학습 성취와 오류, 문제 해결 시간, 학습 만족도는 어떻게 다른가?',
    school: '', grade: '중학교', subject: '정보', recruitment: '', eligibility: '', period: '',
    consent: '', retention: '', environment: '', otherConditions: '',
    topics: '입출력·순차, 조건, 반복', objective: '기본 알고리즘을 이해하고 프로그램으로 문제를 해결한다.',
    datasetVersion: '', source: '기존 OJ 알고리즘 문제', license: '', practiceCount: '',
    inclusion: '수업 목표에 부합하며 교사가 내용과 채점 자료를 확인한 문제',
    exclusion: '사전·사후 평가 문항, 해설 공개 범위가 불명확한 문제',
    difficulty: '기존 OJ 난이도 척도와 문제 메타데이터 유지',
    materials: '문제 ID·버전, 주제·목표, 선수 개념, 난이도, 정답 코드, 해설, 수준별 피드백 근거, 오류 유형, 출처·이용 범위',
    review: '교사 내용 검토, 정답 코드의 테스트 통과 확인, 오답·경계값 점검, 예비 풀이', reviewers: '',
    groundTruth: '채점 판정과 개념 오류 분류를 구분하며 AI가 추정한 오류 원인은 검수 전 확정하지 않는다.',
    feedbackSource: 'existing', guideVersion: '',
    beginner: '오류 가능 위치와 확인 행동을 구체적으로 안내',
    intermediate: '변수 값·분기·실행 흐름을 추적하는 질문',
    advanced: '경계값·예외 입력·자료형 범위·복잡도 자기 점검',
    insufficient: '수준을 단정하지 않고 결과의 의미와 핵심 개념 확인',
    allocation: 'stratified', allocationDetail: '학급 내 기존 수준·사전 점수를 고려하여 두 집단에 배정', ratio: '1:1',
    levelTiming: 'current', assessmentCitation: '', seed: '', balance: '기존 수준, 사전 성취, 학급',
    baselineLessons: '2', baselineMinutes: '45', baselineContent: '공통 기본 알고리즘 학습',
    preProblems: '', postProblems: '', preMinutes: '45', postMinutes: '45',
    equivalence: '동일한 학습 목표·내용 비중·난이도 구성을 갖춘 서로 다른 사전·사후 문항을 검수한다.',
    preAiA: 'off', preAiB: 'off', postAiA: 'off', postAiB: 'off',
    preRecA: 'off', preRecB: 'off', postRecA: 'off', postRecB: 'off',
    preset: 'combined',
    groupA: { name: '실험집단', plannedN: '', classCount: '', classes: '', ai: 'level', recommendation: 'personal', lessons: '6', minutes: '45', teacher: '공통 교사 지원 원칙', tasks: '승인된 연습 자료집', release: '연구 계획에서 정한 공개 시점', extra: '' },
    groupB: { name: '비교집단', plannedN: '', classCount: '', classes: '', ai: 'off', recommendation: 'teacher', lessons: '6', minutes: '45', teacher: '공통 교사 지원 원칙', tasks: '승인된 연습 자료집', release: '연구 계획에서 정한 공개 시점', extra: '' },
    model: '', promptVersion: '', requestLimit: '', feedbackTrigger: '확정된 제출 판정 뒤 학생 요청',
    answerPolicy: '정답 코드 원문은 기본 비공개, 학생 공개 승인된 설명을 근거로 사용',
    recommendationPolicy: '수업 주제·기존 수준·난이도·미해결 이력을 활용',
    adherence: '실제 AI 제공·열람, 추천 노출·선택, 교사 개입, 장애와 조건 변경을 기록',
    additionalProcedure: '', primary: '공통 평가 정답률', scoring: 'ac', scoringDetail: '',
    errorDenominator: 'first', errorCustom: '', timeStart: 'start', timePause: '명시적 일시중단 제외',
    unsolved: '미해결 수·해결 비율을 제시하고 종료까지의 관찰 시간을 검열 자료로 보관',
    surveyScale: '1~5점', surveyItems: '이번 수업 활동에 만족한다.\n문제 해결 활동이 흥미로웠다.\n학습 화면을 사용하기 편했다.\n같은 방식의 수업에 다시 참여하고 싶다.',
    featureItems: 'AI 설명이 코드 수정에 도움이 되었다.\n제공된 문제가 나의 학습에 적절했다.',
    surveyReference: '', primaryAnalysis: '공통 사후 성취를 사전 성취와 함께 분석하고 집단 차이와 불확실성을 보고',
    analysisUnit: 'student', analysisDetail: '', missing: '미응시·시스템 장애·철회·미응답은 0점과 구별하고 사유·인원을 보고',
    analysisSet: '원배정 집단을 기준으로 분석하며 실제 사용량별 분석은 탐색적으로 구분',
    fidelity: 'AI 성공·실패·대기, 추천 적합성, 수업 완수, 교사 준비 시간·개입·개선 의견',
    extension: '', resultNotes: ''
  };
  const allocationLabels = { stratified: '학생 단위 층화 무작위 배정', random: '학생 단위 무작위 배정', class_random: '학급 단위 무작위 배정', matched: '기존 학급의 대응 비교', manual: '연구자 지정 배정' };
  const allocationDescriptions = {stratified: defaults.allocationDetail, random:'참여 학생을 사전에 정한 비율에 따라 무작위로 두 집단에 배정', class_random:'학급을 배정 단위로 하여 두 조건에 무작위 배정하고 학급 특성과 군집 구조를 기록', matched:'기존 학급의 사전 성취와 환경을 확인하여 비교 가능한 학급을 대응시키고 초기 차이를 기록', manual:'연구자가 지정한 배정 기준·절차·사유를 기록'};
  const numberPaths = ['practiceCount', 'reviewers', 'baselineLessons', 'baselineMinutes', 'preProblems', 'postProblems', 'preMinutes', 'postMinutes', 'requestLimit', 'groupA.plannedN', 'groupB.plannedN', 'groupA.classCount', 'groupB.classCount', 'groupA.lessons', 'groupB.lessons', 'groupA.minutes', 'groupB.minutes'];
  const enums = {
    feedbackSource: ['existing', 'custom'], allocation: Object.keys(allocationLabels), levelTiming: ['current', 'baseline'],
    preset: ['combined', 'feedback', 'recommendation', 'custom'], scoring: ['ac', 'partial', 'rubric'], errorDenominator: ['first', 'all', 'custom'],
    timeStart: ['start', 'open', 'submit'], analysisUnit: ['student', 'class', 'custom']
  };
  ['groupA','groupB'].forEach(g => { enums[g+'.ai']=Object.keys(ai); enums[g+'.recommendation']=Object.keys(rec); });
  ['pre','post'].forEach(p => ['A','B'].forEach(g => { enums[p+'Ai'+g]=['off','follow','level','common']; enums[p+'Rec'+g]=['off','follow','personal','common']; }));
  const clone = x => JSON.parse(JSON.stringify(x));
  const get = (s, path) => path.split('.').reduce((v,k)=>v[k],s);
  function set(s,path,value){const parts=path.split('.');let target=s;parts.slice(0,-1).forEach(k=>target=target[k]);target[parts.at(-1)]=value;}
  function paths(obj,prefix=''){return Object.entries(obj).flatMap(([k,v])=>v&&typeof v==='object'?paths(v,prefix+k+'.'):[prefix+k]);}
  const editablePaths=paths(defaults).filter(k=>k!=='schemaVersion');
  function normalize(input){
    if(!input||typeof input!=='object'||Array.isArray(input)||input.schemaVersion!==1)throw new Error('지원하는 연구 설정 파일(버전 1)이 아닙니다.');
    if(['groupA','groupB'].some(key=>!input[key]||typeof input[key]!=='object'||Array.isArray(input[key])))throw new Error('A와 B 두 집단의 조건이 모두 있어야 합니다.');
    const normalized=clone(defaults);
    for(const path of editablePaths){let value;try{value=get(input,path);}catch{value=undefined;}if(value===undefined)continue;if(typeof value!=='string'||value.length>12000)throw new Error('설정 형식 또는 길이를 확인하세요: '+path);if(enums[path]&&!enums[path].includes(value))throw new Error('지원하지 않는 선택값: '+path);if(numberPaths.includes(path)&&value!==''&&!/^\d+$/.test(value))throw new Error('인원·문항·시간은 0 이상의 정수로 입력하세요: '+path);set(normalized,path,value);}
    if(normalized.feedbackSource==='existing')['beginner','intermediate','advanced','insufficient'].forEach(key=>normalized[key]=defaults[key]);
    return normalized;
  }
  const filled=(value,label)=>String(value??'').trim()||'['+label+']';
  const n=(value,label)=>filled(value,label);
  const groupName=(s,g)=>filled(s['group'+g].name,'집단 '+g+' 이름');
  const aiLabel=(g)=>ai[g.ai]+(g.ai==='custom'?' ('+filled(g.extra,'상세 조건')+')':'');
  const recLabel=(g)=>rec[g.recommendation]+(g.recommendation==='custom'?' ('+filled(g.extra,'상세 조건')+')':'');
  function phasePolicy(s,p,g){const ag=s[p+'Ai'+g],rg=s[p+'Rec'+g],group=s['group'+g];return {ai:ag==='follow'?group.ai:ag,rec:rg==='follow'?group.recommendation:rg};}
  function policyLabel(s,p,g){const v=phasePolicy(s,p,g);return (v.ai==='off'?'AI 미사용':ai[v.ai])+' / '+(v.rec==='off'?'추천 미사용':rec[v.rec]);}
  function preset(s,name){
    s.preset=name;
    if(name==='combined'){s.groupA.ai='level';s.groupA.recommendation='personal';s.groupB.ai='off';s.groupB.recommendation='teacher';}
    if(name==='feedback'){s.groupA.ai='level';s.groupB.ai='common';s.groupA.recommendation=s.groupB.recommendation='personal';}
    if(name==='recommendation'){s.groupA.ai=s.groupB.ai='level';s.groupA.recommendation='personal';s.groupB.recommendation='teacher';}
  }
  function comparison(s){
    const a=s.groupA,b=s.groupB,changed=[];
    if(a.ai!==b.ai)changed.push('피드백 조건');if(a.recommendation!==b.recommendation)changed.push('문제 제공 조건');
    if(a.lessons!==b.lessons||a.minutes!==b.minutes)changed.push('학습 시간');
    if(a.teacher!==b.teacher)changed.push('교사 지원');if(a.tasks!==b.tasks)changed.push('문제 자료');if(a.release!==b.release)changed.push('해설 공개 시점');if(a.extra!==b.extra)changed.push('추가 조건');
    return changed.length?'비교에서 달라지는 항목: '+changed.join(', '):'현재 두 집단에 설정된 처치 조건이 같습니다.';
  }
  function warnings(s){
    const list=[];
    if(s.groupA.ai===s.groupB.ai&&s.groupA.recommendation===s.groupB.recommendation)list.push('두 집단의 AI·문제 제공 조건이 같습니다. 다른 비교 요인을 추가 조건에 기록하세요.');
    if(s.groupA.name===s.groupB.name)list.push('집단 이름이 같습니다. 내보내기에서는 A/B 식별자를 함께 사용합니다.');
    if(s.groupA.lessons!==s.groupB.lessons||s.groupA.minutes!==s.groupB.minutes||s.groupA.teacher!==s.groupB.teacher||s.groupA.tasks!==s.groupB.tasks||s.groupA.release!==s.groupB.release)list.push('집단 간 시간·교사 지원·자료·공개 시점 차이가 있습니다. 이 차이를 통제하거나 연구 요인으로 명시하세요.');
    if(['pre','post'].some(p=>['A','B'].some(g=>phasePolicy(s,p,g).ai!=='off'||['personal','common'].includes(phasePolicy(s,p,g).rec))))list.push('평가 중 AI 또는 추천이 허용됩니다. 결과는 해당 지원 조건에서의 성취이며 독립 수행 성취로 표현하지 않습니다.');
    if(['class_random','matched'].includes(s.allocation)){list.push('학급 단위 배정·비교에서는 학급 내 상관을 반영한 분석 계획이 필요합니다.');if(s.groupA.classCount==='1'||s.groupB.classCount==='1')list.push('한 조건에 학급이 하나이면 학급 차이와 처치 효과를 분리하기 어렵습니다. 시범 비교의 한계를 보고하세요.');}
    if(s.timeStart==='submit')list.push('첫 제출부터의 시간은 전체 풀이 시간이 아니라 제출 이후 수정·해결 시간입니다.');
    if(s.errorDenominator==='custom'&&!s.errorCustom.trim())list.push('사용자 정의 오류 지표의 분자·분모·제외 기준을 입력하세요.');
    if(s.scoring!=='ac'&&!s.scoringDetail.trim())list.push('부분점수·루브릭 채점의 배점과 판정 기준이 아직 입력되지 않았습니다.');
    if(s.feedbackSource==='custom')list.push('연구 전용 지침은 기존 수준 평가를 바꾸지 않습니다. 사용 지침과 버전을 별도로 확정해야 합니다.');
    return list;
  }
  function scoreDefinition(s){return s.scoring==='ac'?'제한 시간 내 모든 테스트를 통과한 문항 수 ÷ 배정 문항 수 × 100. 문항별 동등 배점. 응시한 평가의 미해결 문항은 0점, 평가 전체 미응시는 결측.':s.scoring==='partial'?'사전에 확정한 하위 목표·테스트 묶음별 부분점수: '+filled(s.scoringDetail,'배점표 및 판정 기준'):'별도 기준표에 따른 채점: '+filled(s.scoringDetail,'평가 요소·배점·채점자·불일치 처리');}
  function errorDefinition(s){if(s.errorDenominator==='custom')return filled(s.errorCustom,'분자·분모·오류 범위·비교 단위');const d=s.errorDenominator==='first'?'문항별 최초 유효제출':'모든 유효제출';return d+' 중 확정 오류 판정 비율. 감소율=(사전 오류율−사후 오류율)÷사전 오류율×100. 사전 0이면 산출 불가, 음수면 오류 증가. 오류율 변화(%p)·분모·미제출 수를 함께 보고.';}
  function timeDefinition(s){const starts={start:'풀이 시작 버튼의 서버 기록',open:'문제 첫 열람 기록',submit:'첫 유효제출 시각'};return starts[s.timeStart]+'부터 최초 정답 판정된 제출의 제출 시각까지. '+filled(s.timePause,'중단 처리')+'. '+filled(s.unsolved,'미해결 처리')+'.';}
  function section(id,title,subsections,tables){return {id,title,subsections,tables};}
  function table(title,headers,rows){return {title,headers,rows};}
  function buildSections(s){
    const A=groupName(s,'A')+' (A)',B=groupName(s,'B')+' (B)',a=s.groupA,b=s.groupB;
    const guides=s.feedbackSource==='existing'?'기존 OJ에서 선택된 수준별 피드백 지침을 그대로 연결하며, 실제 선택된 지침·버전은 연구 시작 시 확인한다.':'연구 전용 수준별 피드백 지침을 사용하고 원문과 버전을 보관한다. 기존 학생 수준 평가의 계산식·경계값은 변경하지 않는다.';
    const scope=comparison(s);
    const outcomesSupported=['A','B'].some(g=>{const p=phasePolicy(s,'post',g);return p.ai!=='off'||['personal','common'].includes(p.rec);});
    const data=section('materials','교육자료 개발 및 문제 데이터셋 구축',[
      ['자료의 범위와 선정','본 연구는 '+filled(s.source,'자료 출처')+'를 기반으로 '+filled(s.topics,'학습 주제')+'에 관한 교육자료를 구성한다. 학습 목표는 “'+filled(s.objective,'학습 목표')+'”이다. 연습 문제는 '+n(s.practiceCount,'연습 문제 수')+'개이며, 포함 기준은 '+filled(s.inclusion,'포함 기준')+', 제외 기준은 '+filled(s.exclusion,'제외 기준')+'이다.'],
      ['자료 구성과 검수','문제별로 '+filled(s.materials,'자료 구성 항목')+'를 구조화한다. 난이도는 '+filled(s.difficulty,'난이도 기준')+'를 따른다. 검수 인원은 '+n(s.reviewers,'검수 인원')+'명이며, '+filled(s.review,'검수 절차')+'를 수행한다. '+filled(s.groundTruth,'오류 분류 기준')],
      ['수준별 피드백 설계',guides+' 피드백은 학생 수준에 따라 설명의 구체성·질문·지원 정도를 다르게 구성한다. 모든 학생이 동일한 힌트를 순서대로 여는 방식으로 정의하지 않는다. '+(s.feedbackSource==='existing'?'다음 표는 기존 기본 지침의 설명용 요약이며 현재 운영 설정을 읽어 온 값이 아니다.':'다음 표에 연구 전용 지침의 요약을 제시한다.')],
      ['자료 버전과 접근 범위','자료집 버전은 '+filled(s.datasetVersion,'자료집 버전')+', 지침 버전은 '+filled(s.guideVersion,'지침 버전')+'으로 기록한다. 출처·이용 범위는 '+filled(s.license,'출처별 이용 범위')+'로 관리한다. 정답 코드와 평가 문항의 접근을 분리하며 '+filled(s.answerPolicy,'정답 공개 원칙')+'.']
    ],[
      table('표 1. 문제 데이터셋의 구성',['구분','구조화 내용'],[['학습 범위',s.topics],['학습 목표',s.objective],['연습 문제 수',n(s.practiceCount,'미입력')],['난이도 기준',s.difficulty],['문제별 자료',s.materials],['오류 분류',s.groundTruth],['검수 절차',s.review],['버전',filled(s.datasetVersion,'미확정')]]),
      table('표 2. 학생 수준별 피드백 설계',['기존 수준','피드백 초점','제공 원칙'],[['초급',s.beginner,'해당 수준의 지침을 선택'],['중급',s.intermediate,'해당 수준의 지침을 선택'],['고급',s.advanced,'해당 수준의 지침을 선택'],['자료 부족',s.insufficient,'수준을 임의로 추정하지 않음']])
    ]);
    const pre=section('baseline','연구 대상·설계 및 사전 절차',[
      ['연구 대상과 환경','연구 대상은 '+filled(s.school,'학교명')+'의 '+filled(s.grade,'학년')+' '+filled(s.subject,'교과')+' 수업 참여 학생이다. 모집 절차는 '+filled(s.recruitment,'모집 절차')+', 참여 기준은 '+filled(s.eligibility,'참여 기준')+'이다. 계획 인원은 '+A+' '+n(a.plannedN,'A 계획 인원')+'명, '+B+' '+n(b.plannedN,'B 계획 인원')+'명이다. 연구 기간은 '+filled(s.period,'연구 기간')+'이며 수업 환경은 '+filled(s.environment,'기기·교실·네트워크 환경')+'이다.'],
      ['두 집단 구성','연구는 두 집단의 사전·사후 비교로 구성한다. '+allocationLabels[s.allocation]+'을 사용하며 계획 배정 비율은 '+filled(s.ratio,'배정 비율')+'이다. 세부 절차는 '+filled(s.allocationDetail,'배정 절차')+'이며, '+filled(s.balance,'균형 확인 변수')+'를 확인한다. 무작위화 재현 정보는 '+filled(s.seed,'해당 시 무작위화 기록')+'로 보관한다. 초급·중급·고급은 학생의 속성이며 A/B 집단과 별개로 기록한다.'],
      ['기본 학습과 수준 자료','사전 기본 학습은 '+n(s.baselineLessons,'기본 학습 차시')+'차시, 차시당 '+n(s.baselineMinutes,'차시 시간')+'분으로 계획하며 '+filled(s.baselineContent,'기본 학습 내용')+'을 제공한다. 선행 연구에서 개발된 기존 학생 수준 평가를 변경 없이 활용하고 시작 시점 결과를 보관한다. 선행 연구의 출처는 '+filled(s.assessmentCitation,'학생 수준 평가 선행 연구 서지정보')+'이다.'],
      ['사전 평가 및 참여 관리','사전 평가는 '+n(s.preProblems,'사전 문항 수')+'문항, '+n(s.preMinutes,'사전 제한 시간')+'분으로 구성한다. 지원 조건은 '+A+': '+policyLabel(s,'pre','A')+'; '+B+': '+policyLabel(s,'pre','B')+'이다. 참여·철회 절차는 '+filled(s.consent,'참여 안내·동의·철회 절차')+'이며 자료 보관은 '+filled(s.retention,'보관 기간·접근 권한')+'에 따른다.']
    ],[table('표 3. 연구 대상과 집단 구성',['항목',A,B],[['계획 인원',n(a.plannedN,'미입력'),n(b.plannedN,'미입력')],['학급 수',n(a.classCount,'미입력'),n(b.classCount,'미입력')],['대상 학급·그룹',filled(a.classes,'미입력'),filled(b.classes,'미입력')],['실제 배정 인원','[실제 A 배정 인원]','[실제 B 배정 인원]'],['초급/중급/고급/자료 부족','[수준별 실제 인원]','[수준별 실제 인원]'],['사전 평가 지원',policyLabel(s,'pre','A'),policyLabel(s,'pre','B')]]),table('표 4. 사전 동질성 기술표',['변수',A,B,'비교 결과'],[['사전 정답률','[n, 평균, 표준편차]','[n, 평균, 표준편차]','[초기 차이와 보정 계획]'],['학생 수준 분포','[수준별 빈도]','[수준별 빈도]','[분포 차이]'],['기타 기초 특성','[측정값]','[측정값]','[확인 결과]']])]);
    const operation=section('classroom','수업 적용 및 집단별 처치',[
      ['비교 조건','연구 질문은 “'+filled(s.question,'연구 질문')+'”이다. '+A+'에는 '+aiLabel(a)+'와 '+recLabel(a)+', '+B+'에는 '+aiLabel(b)+'와 '+recLabel(b)+'를 적용한다. '+scope+'. 여러 조건이 함께 달라지면 그 결합 조건을 비교 대상으로 해석한다.'],
      ['수준별 피드백 적용','AI 조건이 수준별로 설정된 집단에는 학생 수준에 해당하는 지침을 연결한다. 수준 참조 시점은 '+(s.levelTiming==='current'?'피드백·추천 시점의 기존 평가 결과':'연구 시작 시 보관한 기존 평가 결과')+'이며 사용한 결과와 지침 버전을 기록한다. 초급·중급·고급 피드백은 서로 다른 지원 방식이다. 특정 초급 안내에 확인 순서가 포함되더라도 전체 학생의 공통 힌트 공개 순서를 의미하지 않는다.'],
      ['수업 운영과 구현 조건','학생은 기존 OJ에서 문제를 풀고 제출·채점한다. AI 제공 계기는 '+filled(s.feedbackTrigger,'요청 시점')+', 요청 한도는 '+(s.requestLimit==='0'?'무제한':filled(s.requestLimit,'요청 한도 또는 무제한 여부'))+'로 설정한다. 모델은 '+filled(s.model,'모델·버전')+', 지침 식별자는 '+filled(s.promptVersion,'프롬프트 버전')+'이다. 추천은 '+filled(s.recommendationPolicy,'추천 정책')+'을 따른다.'],
      ['실행 충실도와 환경 차이',filled(s.adherence,'실행 충실도 기록')+'한다. 각 집단의 학습 시간·교사 도움·문제 범위·해설 공개 시점과 추가 조건을 표로 명시한다. 수업 절차의 추가 사항은 '+filled(s.additionalProcedure,'해당 없음 또는 추가 절차')+'이다. 기타 환경 조건은 '+filled(s.otherConditions,'해당 없음 또는 추가 조건')+'이다.']
    ],[table('표 5. 집단별 수업 조건',['조건',A,B],[['AI 피드백',aiLabel(a),aiLabel(b)],['문제 제공',recLabel(a),recLabel(b)],['차시 수',n(a.lessons,'미입력'),n(b.lessons,'미입력')],['차시당 시간',n(a.minutes,'미입력')+'분',n(b.minutes,'미입력')+'분'],['교사 지원',a.teacher,b.teacher],['문제 범위',a.tasks,b.tasks],['해설 공개',a.release,b.release],['추가 조건',filled(a.extra,'없음/미입력'),filled(b.extra,'없음/미입력')]]),table('표 6. 연구 절차와 기록',['단계','활동','기록'],[['자료 구축','문제 선별·분류·검수','자료집·지침·문항·테스트 버전'],['사전','공통 기본 학습·사전 평가·집단 배정','기존 수준·사전 점수·배정·참여 상태'],['적용','집단별 조건으로 OJ 학습','제출·판정·풀이 세션·AI·추천·교사 개입'],['사후','사후 평가·설문·운영 검토','성취·오류·시간·만족도·누락 사유']])]);
    const outcome=section('outcomes','측정 도구·분석 계획 및 연구 결과 작성 틀',[
      ['사후 평가와 측정 도구','주요 결과 지표는 '+filled(s.primary,'주요 결과 지표')+'이다. 사후 평가는 '+n(s.postProblems,'사후 문항 수')+'문항, '+n(s.postMinutes,'사후 제한 시간')+'분으로 구성한다. '+filled(s.equivalence,'사전·사후 문항 구성 원칙')+' 지원 조건은 '+A+': '+policyLabel(s,'post','A')+'; '+B+': '+policyLabel(s,'post','B')+'이다. '+(outcomesSupported?'결과는 설정된 학습 지원이 있는 상태에서의 성취로 해석한다.':'AI·추천 없이 측정한 공통 과제의 성취를 분석한다.')],
      ['분석 계획',filled(s.primaryAnalysis,'분석 방법')+'한다. 분석 단위는 '+({student:'학생',class:'학급 및 학급 내 학생 구조',custom:'연구자 정의'}[s.analysisUnit])+', 세부 방법은 '+filled(s.analysisDetail,'모형·보정 변수·효과 크기·불확실성 산출 방법')+'이다. '+filled(s.analysisSet,'분석 대상 기준')+'. '+filled(s.missing,'결측 처리')+'. 추천 연습 문제의 성과는 공통 평가와 분리하고 주제·난이도별 과정 지표로 보고한다.'],
      ['설문과 현장 적합성','학습 만족도는 '+filled(s.surveyScale,'응답 척도')+'로 측정하며 공통 문항은 두 집단에 동일하게 제공한다. 기능 경험 문항은 실제 사용 조건에 해당하는 학생에게만 제공한다. 설문 출처·검토 정보는 '+filled(s.surveyReference,'설문 출처 또는 자체 제작·예비 검토 정보')+'이다. 현장 적합성은 '+filled(s.fidelity,'운영 지표')+'로 살펴본다. 미사용과 무응답은 점수와 구별한다.'],
      ['연구 결과 문안 — 실측값 입력 필요','분석 대상은 '+A+' [분석 n]명과 '+B+' [분석 n]명이었다. 주요 지표인 '+filled(s.primary,'지표')+'의 집단별 사전·사후 결과는 표 8과 같다. 설정된 분석에 따른 집단 차이는 [추정치, 단위]였으며 불확실성은 [신뢰구간 또는 해당 보고값]이었다. 오류·시간·만족도 결과는 [실측값과 분석 결과]로 나타났다. 참여·미응시·결측은 [인원 및 사유]였으며 현장 적용에서 [관찰된 사실]이 확인되었다. 이 문안의 대괄호는 실측 자료 확인 후 작성하며 효과 방향·유의성을 자동 생성하지 않는다.'],
      ['추가 분석과 결과 메모',filled(s.extension,'해당 없음 또는 지연 검사·설명 평가·면담 등 추가 분석')+'. 실제 결과 메모: '+filled(s.resultNotes,'실측 자료를 확인한 내용만 기입')]
    ],[
      table('표 7. 측정 지표의 조작적 정의',['지표','산출·측정 기준','보고 항목'],[['성취',scoreDefinition(s),'학생별 점수·정답률, 사후−사전(%p), 집단 차이'],['오류',errorDefinition(s),'오류율·감소율, 분모·미제출, 오류별 분포'],['문제 해결 시간',timeDefinition(s),'해결 비율·미해결 수, 시간 분포. 채점 실행 ms와 구별'],['학습 만족도',s.surveyScale+'; 공통 문항과 사용 기능 문항 구분','문항별 분포·평균, 응답 n·응답률']]),
      table('표 8. 학습 결과 입력 틀',['지표',A+' 사전',A+' 사후',B+' 사전',B+' 사후','집단 비교'],[['정답률','[n, 평균, SD]','[n, 평균, SD]','[n, 평균, SD]','[n, 평균, SD]','[차이·효과크기·불확실성]'],['오류율','[오류/유효 제출]','[오류/유효 제출]','[오류/유효 제출]','[오류/유효 제출]','[변화·감소율·분모]'],['해결 시간','[해결 n, 중앙값]','[해결 n, 중앙값]','[해결 n, 중앙값]','[해결 n, 중앙값]','[미해결·검열 반영]'],['만족도','해당 없음','[응답 n, 분포]','해당 없음','[응답 n, 분포]','[공통 문항 비교]']]),
      table('표 9. 설문 문항 초안',['대상','문항'],[...s.surveyItems.split('\n').filter(x=>x.trim()).map(x=>['두 집단 공통',x]),...s.featureItems.split('\n').filter(x=>x.trim()).map(x=>['해당 기능을 사용한 학생',x])])
    ]);
    return [data,pre,operation,outcome];
  }
  function escapeMarkdown(value){return String(value??'').replace(/\|/g,'\\|').replace(/\r?\n/g,'<br>');}
  function markdown(s,only){
    const selected=buildSections(s).filter(x=>!only||x.id===only);
    let out='# '+s.title+'\n\n> 연구 설정 기반 초안. 계획값과 실제 수행·결과는 구별하여 확인해야 한다. 기존 OJ 운영 설정과 연결되지 않은 설계 도구에서 작성되었다.\n\n';
    for(const part of selected){out+='## '+part.title+'\n\n';for(const [title,text] of part.subsections)out+='### '+title+'\n\n'+text+'\n\n';for(const t of part.tables){out+='### '+t.title+'\n\n| '+t.headers.map(escapeMarkdown).join(' | ')+' |\n| '+t.headers.map(()=>'---').join(' | ')+' |\n';for(const row of t.rows)out+='| '+row.map(escapeMarkdown).join(' | ')+' |\n';out+='\n';}}
    out+='## 연구 조건 점검\n\n'+comparison(s)+'\n\n'+warnings(s).map(x=>'- '+x).join('\n')+'\n';
    return out;
  }
  const api={defaults,clone,get,set,normalize,editablePaths,ai,rec,allocationLabels,allocationDescriptions,phasePolicy,preset,comparison,warnings,buildSections,markdown};
  if(typeof module==='object'&&module.exports)module.exports=api;else host.OJResearchProtocol=api;
})(typeof globalThis!=='undefined'?globalThis:this);

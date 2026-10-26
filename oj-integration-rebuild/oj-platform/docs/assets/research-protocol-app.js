(function () {
  'use strict';
  const root=document.getElementById('research-protocol-app'),M=globalThis.OJResearchProtocol;
  if(!root||!M)return;
  let state=M.clone(M.defaults),active='materials';
  const storageKey='oj-research-protocol-draft-v1';
  let saveStatus='local';
  try{const saved=localStorage.getItem(storageKey);if(saved)state=M.normalize(JSON.parse(saved));}catch{saveStatus='temporary';}
  const labels={materials:'① 문제 자료',baseline:'② 사전',classroom:'③ 수업 적용',outcomes:'④ 결과 분석'};
  const safe=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const field=(path,label,type='text',options=null,note='')=>({path,label,type,options,note});
  const select=(path,label,options,note='')=>field(path,label,'select',options,note);
  const text=(path,label,note='')=>field(path,label,'textarea',null,note);
  const num=(path,label,note='')=>field(path,label,'number',null,note);
  const group=(title,fields,open=false)=>({title,fields,open});
  const assessmentOptions={off:'사용하지 않음',follow:'해당 집단의 수업 조건 적용',level:'수준별 AI',common:'공통 AI'};
  const recOptions={off:'사용하지 않음',follow:'해당 집단의 수업 조건 적용',personal:'개인별 추천',common:'공통 추천'};
  function groupFields(key){return [field(key+'.name','집단 이름'),num(key+'.plannedN','계획 인원'),num(key+'.classCount','학급 수'),field(key+'.classes','대상 학급·OJ 그룹'),select(key+'.ai','AI 피드백',M.ai),select(key+'.recommendation','문제 제공',M.rec),num(key+'.lessons','적용 차시 수'),num(key+'.minutes','차시당 시간(분)'),text(key+'.teacher','교사 지원 조건'),text(key+'.tasks','문제 범위'),field(key+'.release','해설 공개 시점'),text(key+'.extra','집단별 추가 조건','자체 AI·추천 조건을 선택했다면 구체적인 처치를 작성하세요.')];}
  const formGroups={
    materials:[
      group('자료집 범위',[field('topics','학습 주제'),text('objective','학습 목표'),num('practiceCount','연습 문제 수'),field('datasetVersion','자료집 버전'),field('source','자료 출처')],true),
      group('선정·분류·검수',[text('inclusion','포함 기준'),text('exclusion','제외 기준'),text('difficulty','난이도 기준'),text('materials','문제별 자료 항목'),text('groundTruth','오류 유형 정의'),num('reviewers','검수 인원'),text('review','검수·예비 적용 절차'),text('license','출처별 이용 범위')]),
      group('수준별 피드백 자료',[select('feedbackSource','피드백 지침 연결',{existing:'기존 OJ 지침 사용',custom:'연구 전용 지침 정의'},'학생 수준 평가 알고리즘은 변경하지 않습니다.'),field('guideVersion','선택한 지침·버전'),text('beginner','초급: 지원 방식 요약'),text('intermediate','중급: 지원 방식 요약'),text('advanced','고급: 지원 방식 요약'),text('insufficient','자료 부족: 지원 방식 요약'),text('answerPolicy','정답·해설 공개 원칙')])
    ],
    baseline:[
      group('연구 대상·환경',[field('school','학교'),field('grade','대상 학년'),field('subject','교과'),field('period','연구 기간'),text('recruitment','모집 절차'),text('eligibility','참여 기준'),text('environment','기기·교실·네트워크 환경')],true),
      group('두 집단 배정',[select('allocation','배정 방식',M.allocationLabels),field('ratio','배정 비율'),text('allocationDetail','구체적인 배정 절차'),field('balance','균형 확인 변수'),field('seed','무작위화 기록·시드(해당 시)'),field('assessmentCitation','기존 수준 평가 선행 연구 출처')]),
      group('집단 인원·학급',[field('groupA.name','A 집단 이름'),num('groupA.plannedN','A 계획 인원'),num('groupA.classCount','A 학급 수'),field('groupA.classes','A 대상 그룹'),field('groupB.name','B 집단 이름'),num('groupB.plannedN','B 계획 인원'),num('groupB.classCount','B 학급 수'),field('groupB.classes','B 대상 그룹')]),
      group('기본 학습·사전 평가',[num('baselineLessons','기본 학습 차시'),num('baselineMinutes','기본 학습 차시당 분'),text('baselineContent','기본 학습 내용'),num('preProblems','사전 문항 수'),num('preMinutes','사전 제한 시간(분)'),select('preAiA','사전: A의 AI',assessmentOptions),select('preAiB','사전: B의 AI',assessmentOptions),select('preRecA','사전: A의 추천',recOptions),select('preRecB','사전: B의 추천',recOptions)]),
      group('참여·자료 관리',[text('consent','참여 안내·동의·철회'),text('retention','보관 기간·접근 권한')])
    ],
    classroom:[
      group('비교 목적',[select('preset','조건 예시 불러오기',{combined:'수준별 AI·추천 ↔ 기존 학습',feedback:'수준별 AI ↔ 공통 AI (추천 동일)',recommendation:'개인별 추천 ↔ 교사 지정 (수준별 AI 동일)',custom:'직접 설정'},'조건 예시는 AI·문제 제공만 바꾸며 인원·시간·기타 설정은 유지합니다.'),text('question','연구 질문'),select('levelTiming','적용 중 수준 참조',{current:'요청 시점의 기존 수준 결과',baseline:'시작 시 보관한 기존 수준 결과'})],true),
      group('A 집단 조건',groupFields('groupA'),true),
      group('B 집단 조건',groupFields('groupB'),true),
      group('AI·추천 세부 설정',[field('model','AI 모델·버전'),field('promptVersion','프롬프트·공통 지침 버전'),num('requestLimit','요청 한도(0: 무제한)'),text('feedbackTrigger','피드백 제공 시점·요청 방식'),text('recommendationPolicy','추천 정책'),text('adherence','실행 충실도 기록')]),
      group('추가 연구 조건',[text('additionalProcedure','추가 수업 절차'),text('otherConditions','기타 조건','수업 장소, 그룹별 일정, 교사 변경, 외부 도구 등 필요한 조건을 자유롭게 기록합니다.')])
    ],
    outcomes:[
      group('사후 평가',[num('postProblems','사후 문항 수'),num('postMinutes','사후 제한 시간(분)'),text('equivalence','사전·사후 문항 구성·동형성'),select('postAiA','사후: A의 AI',assessmentOptions),select('postAiB','사후: B의 AI',assessmentOptions),select('postRecA','사후: A의 추천',recOptions),select('postRecB','사후: B의 추천',recOptions)],true),
      group('지표 정의',[field('primary','주요 결과 지표'),select('scoring','채점 방식',{ac:'전 테스트 통과·문항 동등 배점',partial:'하위 목표별 부분점수',rubric:'별도 기준표·평가자 채점'}),text('scoringDetail','배점·판정·평가자 기준'),select('errorDenominator','오류율 분모',{first:'문항별 최초 유효제출',all:'모든 유효제출',custom:'직접 정의'}),text('errorCustom','사용자 정의 오류 기준'),select('timeStart','시간 측정 시작',{start:'풀이 시작 버튼',open:'문제 첫 열람',submit:'첫 유효제출 (수정 시간)'}),text('timePause','일시중단·유휴 시간 처리'),text('unsolved','미해결·시간 누락 처리')]),
      group('학습 만족도',[field('surveyScale','응답 척도'),text('surveyItems','두 집단 공통 문항(한 줄에 하나)'),text('featureItems','기능 사용 경험 문항(한 줄에 하나)'),text('surveyReference','설문 출처·예비 검토')]),
      group('분석 계획',[text('primaryAnalysis','주요 분석 방법'),select('analysisUnit','분석 단위',{student:'학생',class:'학급 구조 반영',custom:'직접 정의'}),text('analysisDetail','모형·보정·효과 크기·불확실성'),text('missing','결측·철회 처리'),text('analysisSet','분석 포함 기준'),text('fidelity','현장 적합성 지표'),text('extension','추가 지표·지연 검사·면담 등')]),
      group('결과 작성',[text('resultNotes','실측 결과 메모','결과 표는 입력 틀입니다. 실측값이나 통계적 유의성을 자동으로 만들지 않습니다.')])
    ]
  };
  function fieldHtml(f){const id='rp-'+f.path.replace(/\./g,'-'),value=M.get(state,f.path);let control;
    if(f.type==='select')control='<select id="'+id+'" data-path="'+f.path+'">'+Object.entries(f.options).map(([key,label])=>'<option value="'+safe(key)+'"'+(value===key?' selected':'')+'>'+safe(label)+'</option>').join('')+'</select>';
    else if(f.type==='textarea')control='<textarea id="'+id+'" data-path="'+f.path+'" rows="3" maxlength="12000">'+safe(value)+'</textarea>';
    else control='<input id="'+id+'" data-path="'+f.path+'" type="'+f.type+'"'+(f.type==='number'?' min="0" step="1"':'')+' value="'+safe(value)+'">';
    return '<div class="rp-field"><label for="'+id+'">'+safe(f.label)+'</label>'+control+(f.note?'<small>'+safe(f.note)+'</small>':'')+'</div>';
  }
  root.innerHTML='<div class="rp-chrome"><span>OJ Console / 연구 설계</span><span class="rp-badge">논문 작성 구조 · 두 집단</span></div><header class="rp-header"><div><p class="rp-eyebrow">학생 수준별 피드백 · 연구 설정과 논문 문안 연결</p><h1>연구 조건을 설정하고 논문 구조로 정리</h1><p>각 단계의 왼쪽에서 조건을 입력하면 오른쪽의 문안·표가 바뀝니다. 기본값은 계획 예시이며 대괄호는 아직 확인되지 않은 값입니다.</p></div><div class="rp-status">설계 도구 · 운영 OJ 미연동</div></header><div class="rp-project"><label for="rp-title">연구 제목</label><input id="rp-title" data-path="title" value="'+safe(state.title)+'"><div class="rp-actions"><button type="button" id="rp-download-md">전체 문안·표 내려받기</button><button type="button" id="rp-export">설정 내보내기</button><button type="button" id="rp-import">설정 불러오기</button><input type="file" id="rp-file" accept="application/json,.json" hidden></div></div><nav class="rp-tabs" aria-label="논문 구성 단계">'+Object.entries(labels).map(([key,label])=>'<button type="button" data-tab="'+key+'" aria-controls="rp-workspace" aria-pressed="'+String(key===active)+'">'+label+'</button>').join('')+'</nav><div id="rp-comparison" class="rp-comparison"></div><div id="rp-warnings" class="rp-warnings" aria-live="polite"></div><div class="rp-workspace" id="rp-workspace"><aside class="rp-settings"><div class="rp-column-title">연구 환경·조건 설정</div><div id="rp-form"></div></aside><article class="rp-paper"><div class="rp-paper-toolbar"><span>논문 작성용 초안 · 계획 기준</span><button type="button" id="rp-copy">이 단계 문안·표 복사</button></div><div id="rp-example" hidden></div><div id="rp-preview"></div></article></div><div id="rp-fallback" hidden><label for="rp-copy-text">복사할 문안·표</label><textarea id="rp-copy-text" rows="10" readonly></textarea></div><footer class="rp-footer"><span id="rp-status" role="status"></span><a href="research-integration.html">현재 로컬 구현 범위</a><span>실제 집단 배정·수업 제어·성과 수집은 OJ 추가 연결 대상</span></footer>';
  const form=root.querySelector('#rp-form'),preview=root.querySelector('#rp-preview');
  function tableHtml(t){return '<section class="rp-table-section"><h3>'+safe(t.title)+'</h3><div class="rp-table-wrap"><table><thead><tr>'+t.headers.map(h=>'<th scope="col">'+safe(h)+'</th>').join('')+'</tr></thead><tbody>'+t.rows.map(row=>'<tr>'+row.map(v=>'<td>'+safe(v).replace(/\n/g,'<br>')+'</td>').join('')+'</tr>').join('')+'</tbody></table></div></section>';}
  function paperHtml(part){return '<h2>'+safe(part.title)+'</h2>'+part.subsections.map(([title,content])=>'<section class="rp-prose"><h3>'+safe(title)+'</h3><p>'+safe(content)+'</p></section>').join('')+part.tables.map(tableHtml).join('');}
  function persist(){try{localStorage.setItem(storageKey,JSON.stringify(state));status('이 브라우저에 초안 자동 저장 · 운영 설정은 변경되지 않음');}catch{status('임시 편집 중 · 보관하려면 설정 내보내기를 사용하세요.');}}
  function status(message){root.querySelector('#rp-status').textContent=message;}
  function updatePreview(){const part=M.buildSections(state).find(x=>x.id===active);preview.innerHTML=paperHtml(part);root.querySelector('#rp-comparison').textContent=M.comparison(state);const messages=M.warnings(state);root.querySelector('#rp-warnings').innerHTML=messages.length?'<details><summary>조건 해석 확인 '+messages.length+'건</summary><ul>'+messages.map(x=>'<li>'+safe(x)+'</li>').join('')+'</ul></details>':'';}
  function applyFieldState(){
    form.querySelectorAll('[data-path]').forEach(input=>{const path=input.dataset.path;input.readOnly=state.feedbackSource==='existing'&&['beginner','intermediate','advanced','insufficient'].includes(path);});
  }
  const levelExamples={beginner:'n이 3일 때 반복에서 i가 1, 2, 3까지 모두 나오는지 확인해 보세요. 마지막 수가 합에 포함되어야 합니다.',intermediate:'n=3인 경우 i와 total의 변화를 추적해 보세요. 문제의 포함 범위와 반복 조건이 일치하나요?',advanced:'최소 입력과 경계 입력에서 합의 불변식이 유지되는지 검토해 보세요. 반복 종료 조건에 반례가 있는지 확인해 보세요.',insufficient:'이 문제에서 합에 포함해야 하는 수의 범위를 확인해 보세요. 현재 제출만으로 학생 수준을 단정하지 않습니다.'};
  function showExample(){const box=root.querySelector('#rp-example');box.hidden=active!=='classroom';if(box.hidden)return;box.innerHTML='<div class="rp-example-title">수준에 따른 피드백 차이 · 같은 오류의 설명용 예시</div><label for="rp-level">학생 수준</label><select id="rp-level"><option value="beginner">초급</option><option value="intermediate">중급</option><option value="advanced">고급</option><option value="insufficient">자료 부족</option></select><p id="rp-example-text" aria-live="polite"></p><small>실제 모델 출력·현재 운영 지침이 아닌 예시입니다. 공통 힌트의 순차 공개를 뜻하지 않습니다.</small>';const picker=box.querySelector('select');const show=()=>{box.querySelector('#rp-example-text').textContent=levelExamples[picker.value];};picker.addEventListener('change',show);show();}
  function renderForm(){form.innerHTML=formGroups[active].map(g=>'<details'+(g.open?' open':'')+'><summary>'+safe(g.title)+'</summary>'+g.fields.map(fieldHtml).join('')+'</details>').join('');applyFieldState();showExample();updatePreview();}
  root.addEventListener('input',event=>{const input=event.target;if(!input.dataset.path||input.tagName==='SELECT')return;if(input.type==='number'&&!input.validity.valid){status('인원·문항·시간 입력값을 확인하세요.');return;}M.set(state,input.dataset.path,input.value);if(/^group[AB]\.(ai|recommendation)$/.test(input.dataset.path))state.preset='custom';updatePreview();persist();});
  root.addEventListener('change',event=>{const input=event.target;if(!input.dataset.path||input.tagName!=='SELECT')return;M.set(state,input.dataset.path,input.value);if(input.dataset.path==='allocation'&&Object.values(M.allocationDescriptions).includes(state.allocationDetail)){state.allocationDetail=M.allocationDescriptions[input.value];const detail=form.querySelector('[data-path="allocationDetail"]');if(detail)detail.value=state.allocationDetail;}if(input.dataset.path==='feedbackSource'&&input.value==='existing'){['beginner','intermediate','advanced','insufficient'].forEach(key=>state[key]=M.defaults[key]);renderForm();}else if(input.dataset.path==='preset'){M.preset(state,input.value);renderForm();}else{if(/^group[AB]\.(ai|recommendation)$/.test(input.dataset.path)){state.preset='custom';const presetSelect=form.querySelector('[data-path="preset"]');if(presetSelect)presetSelect.value='custom';}applyFieldState();updatePreview();}persist();});
  root.querySelectorAll('[data-tab]').forEach(button=>button.addEventListener('click',()=>{active=button.dataset.tab;root.querySelectorAll('[data-tab]').forEach(item=>item.setAttribute('aria-pressed',String(item===button)));root.querySelector('#rp-fallback').hidden=true;renderForm();}));
  function download(name,content,type){const blob=new Blob([content],{type}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=name;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);status('내려받기를 요청했습니다: '+name);}
  root.querySelector('#rp-export').addEventListener('click',()=>download('oj-research-conditions.json',JSON.stringify(state,null,2),'application/json;charset=utf-8'));
  root.querySelector('#rp-download-md').addEventListener('click',()=>download('oj-research-manuscript.md',M.markdown(state),'text/markdown;charset=utf-8'));
  root.querySelector('#rp-copy').addEventListener('click',async()=>{const plain=M.markdown(state,active);try{if(globalThis.ClipboardItem&&navigator.clipboard?.write){const item=new ClipboardItem({'text/html':new Blob([preview.innerHTML],{type:'text/html'}),'text/plain':new Blob([plain],{type:'text/plain'})});await navigator.clipboard.write([item]);}else{if(!navigator.clipboard?.writeText)throw new Error('clipboard');await navigator.clipboard.writeText(plain);}status('현재 단계의 문안·표를 복사했습니다.');}catch{root.querySelector('#rp-fallback').hidden=false;const area=root.querySelector('#rp-copy-text');area.value=plain;area.focus();area.select();status('브라우저의 자동 복사가 지원되지 않습니다. 선택된 문안을 직접 복사하거나 내려받으세요.');}});
  root.querySelector('#rp-import').addEventListener('click',()=>root.querySelector('#rp-file').click());
  root.querySelector('#rp-file').addEventListener('change',async event=>{const file=event.target.files[0];if(!file)return;try{if(file.size>300000)throw new Error('설정 파일은 300KB 이하여야 합니다.');const next=M.normalize(JSON.parse(await file.text()));state=next;root.querySelector('#rp-title').value=state.title;renderForm();persist();status('두 집단의 설정을 불러왔습니다. 현재 초안을 교체했습니다.');}catch(error){status('불러오지 못했습니다. 현재 초안은 유지됩니다. '+error.message);}finally{event.target.value='';}});
  renderForm();status(saveStatus==='temporary'?'임시 편집 · 설정을 내보내 보관하세요.':'기본값은 계획 예시 · 이 브라우저의 초안만 자동 저장');
})();

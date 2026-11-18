export const TOOLS_HELP = `출력 분석 · 가상 파일 도구
Linux: ip addr | grep -n inet / cat / ls / pwd / echo / hostname / history
grep [-E|-F] [-ivnxcwq] [-A N|-B N|-C N] [-m N] [-e PATTERN] [-f 가상파일] PATTERN [가상파일...]
head|tail [-n N] [가상파일] / wc [-lwmc] [가상파일]
sort [-rnu] [가상파일] / uniq [-c|-d|-u] [가상파일] / cut -d 구분자 -f 목록 [-s] [가상파일]
diff [-u] 가상파일 가상파일
IOS 조회: show ... | include|exclude|begin|section 정규식
FortiOS 조회: show|get|지원 diagnose ... | grep [-invfcABC] PATTERN
모든 관리 장비: lab help / lab files / lab cat 가상파일 / lab logs / lab history / lab stats / lab status / lab diff
lab cat /config/running.conf | grep -n interface | head -n 5
IOS 추가 도구: show running-config | lab grep -n interface
동기 조회에만 파이프를 사용합니다. ping·traceroute·tcpdump의 실시간 출력 파이프는 미지원입니다.
가상 파일은 선택 장비의 현재 설정·상태·관측으로 생성되며 읽기 전용입니다. 실제 서버 파일·셸에 접근하지 않습니다.
정규식은 제한된 BRE/ERE·문자 클래스·반복·앵커를 지원합니다. 역참조·lookaround·PCRE는 미지원입니다.
명령/필터 합계 8단계, 텍스트 200,000자·4000줄, 패턴 256자, NFA 512상태·공유 연산 예산을 적용합니다.`;

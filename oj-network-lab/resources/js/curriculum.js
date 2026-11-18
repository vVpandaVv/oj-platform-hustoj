import {LESSONS} from './scenarios.js?ver=1.4.0-rc.4';

// The reading-material builder checks this same allowlist before publishing links.
export const CURRICULUM = [
    {id: 'l01', scenario: 'empty', title: '두 PC와 스위치를 연결하고 첫 LAN을 인계하기', goal: '두 PC의 양방향 왕복을 확인하고 포트 중지·복구와 내부 웹 서비스를 시험한 뒤 구성을 인계한다.'},
    {id: 'l02', scenario: 'lan', title: '끊긴 케이블과 꺼진 포트를 구분하고 MAC 위치를 추적하기', goal: '케이블 단절과 포트 중지를 따로 진단하고 PC-B를 옮긴 뒤 스위치의 MAC 학습 위치를 확인한다.'},
    {id: 'l03', scenario: 'lan', title: '주소와 마스크를 바꾸어 같은 망 판단 오류를 고치기', goal: '주소와 접두사를 바꾸며 각 PC의 같은 망 판단을 계산하고 양쪽 판단이 어긋난 장애를 복구한다.'},
    {id: 'l04', scenario: 'empty', title: '라우터 양쪽 LAN을 연결하고 반환 경로까지 복구하기', goal: '두 LAN의 주소·기본 경로·IP 전달을 구성하고 양방향 ping과 HTTP 응답으로 반환 경로까지 확인한다.'},
    {id: 'l05', scenario: 'ios-switch', title: '포트를 중지하고 복구한 뒤 재부팅할 설정을 저장하기', goal: 'PC-B만 격리했다가 복구하고 실행 설정과 시작 설정을 대조해 재부팅 뒤의 정상 상태를 인계한다.'},
    {id: 'l06', scenario: 'ios-switch', title: '두 스위치에 VLAN을 나누고 트렁크의 누락을 복구하기', goal: '두 스위치의 액세스 VLAN과 트렁크 허용 목록을 설정해 운영 통신·방문자 격리·누락 복구를 검증한다.'},
    {id: 'l07', scenario: 'ios-router', title: '두 라우터에 정적 경로를 넣고 잘못된 최장 일치와 반환 경로를 고치기', goal: '두 라우터에 양방향 정적 경로를 만들고 목적지별 최장 일치 오류와 반환 경로 장애를 구별해 고친다.'},
    {id: 'l08', scenario: 'ios-router', title: '라우터 사이 포트를 점검하며 정적 경로를 우회하고 원복하기', goal: '주 연결 장애를 확인한 뒤 정적 경로를 예비 연결로 옮겨 서비스를 복구하고 원래 경로로 되돌린다.'},
    {id: 'l09', scenario: 'dhcp', title: 'DHCP 풀과 배포 옵션을 바꾸고 주소 배정을 복구하기', goal: '배정된 주소·경로·DNS를 기록하고 잘못된 DNS 옵션과 주소 풀을 고쳐 재배정과 웹 응답을 따로 확인한다.'},
    {id: 'l10', scenario: 'dhcp', title: 'DNS 레코드와 웹 서비스를 변경하고 실패 지점을 가르기', goal: '안내 페이지의 이름과 본문을 바꾸고 DNS 레코드 삭제와 웹 서비스 중지의 실패를 나누어 복구한다.'},
    {id: 'l11', scenario: 'firewall', title: 'Linux nft로 웹 허용 범위를 좁히고 NAT 누락을 복구하기', goal: '지정 PC와 서버 사이의 HTTP만 허용하고 NAT 누락·출발 주소 변경을 구분해 허용과 차단을 모두 검증한다.'},
    {id: 'l12', scenario: 'fortios', title: 'FortiOS 주소 객체와 NAT 정책을 구성하고 규칙 순서를 검수하기', goal: '주소 객체와 NAT 정책으로 HTTP를 허용하고 정책 순서의 영향을 검수한 뒤 임시 PING 예외를 회수한다.'},
    {id: 'l13', scenario: 'vpn', title: '두 지점의 WireGuard 경로와 가상 키 오류를 고쳐 서버까지 연결하기', goal: '경로 삭제·AllowedIPs 변경·가상 키 불일치를 따로 재현하고 터널 왕복과 서버 응답을 복구한다.'},
    {id: 'l14', scenario: 'ipsec', title: 'FortiOS IPsec의 제안·가상 PSK·선택자 오류를 나누어 복구하기', goal: '제안·가상 PSK·트래픽 선택자를 하나씩 바꾸어 중단 단계를 찾고 경로와 정책을 유지하며 서버 응답을 복구한다.'},
    {id: 'l15', scenario: 'windows', title: 'Windows의 주소·DNS·게이트웨이를 설정하고 원격 서버 장애를 고치기', goal: '자동 주소를 승인된 고정 주소로 바꾸고 로컬·원격 시험으로 DNS 오류와 게이트웨이 오류를 구분해 고친다.'},
    {id: 'l16', scenario: 'windows', title: 'Windows 인바운드 규칙을 좁게 열고 Block 우선순위를 검증하기', goal: 'WIN-Web의 TCP 80을 필요한 단말에만 열고 프로필·출발 주소·Block 우선순위를 검증한 뒤 시험 규칙을 정리한다.'},
    {id: 'l17', scenario: 'routing', title: '관찰 지점과 필터를 정하고 웹 연결 장애를 가려내기', goal: '관찰 위치·주소·포트·필터를 맞추어 웹 서비스 중지와 포트 변경을 진단하고 패킷이 안 보이는 이유를 구분한다.'},
    {id: 'l18', scenario: 'lan', title: '링크 조건을 하나씩 바꾸고 지연·손실 수용 시험하기', goal: '링크 속도·지연·손실을 하나씩 바꾸어 RTT와 응답 수를 측정하고 정해 둔 수용 기준과 측정 한계를 기록한다.'},
    {id: 'l19', scenario: 'broken', title: '서버의 반환 경로 장애를 증거로 찾고 한 조건만 고치기', goal: '요청 도착과 반환 실패의 증거로 잘못된 다음 홉을 고치고 더 구체적인 경로 오류도 구별해 복구한다.'},
    {id: 'l20', scenario: 'empty', title: '빈 구성에서 사무실 웹망을 만들고 장애 복구까지 인계하기', goal: '빈 구성에서 두 PC의 내부 웹망을 만들고 케이블 단절·서버 주소 변경을 복구한 뒤 정상 파일을 다시 열어 인계한다.'},
    {id: 'l21', scenario: 'ipv6', title: 'IPv6 주소부터 이웃 탐색과 MTU 장애 복구까지 직접 구성하기', goal: 'IPv6 주소와 기본 경로를 직접 구성하고 전달 중지·반환 경로·MTU 장애를 증거로 구분해 복구한다.'},
    {id: 'l22', scenario: 'ospf', title: 'OSPF 이웃과 경로를 구성하고 비용 변경과 자동 우회를 검증하기', goal: '세 라우터의 OSPF를 설정하고 비용·포트·영역 불일치를 바꿔 자동 우회와 복구를 검증한다.'},
    {id: 'l23', scenario: 'bgp', title: 'BGP 피어와 경로 광고를 구성하고 정책·철회 장애를 복구하기', goal: '세 AS의 BGP 피어와 경로 광고를 설정하고 AS 불일치·Weight 정책·광고 철회를 구별해 복구한다.'},
];

export function resolveLaunch(search) {
    const params = new URLSearchParams(search), exercises = params.getAll('exercise'), scenarios = params.getAll('scenario');
    const exercise = exercises.length === 1 ? CURRICULUM.find(item => item.id === exercises[0]) || null : null;
    const lesson = LESSONS.find(item => item.id === (exercise?.scenario || (scenarios.length === 1 ? scenarios[0] : ''))) || null;
    return {exercise, lesson, invalid: exercises.length > 0 && !exercise || !lesson && scenarios.length > 0};
}

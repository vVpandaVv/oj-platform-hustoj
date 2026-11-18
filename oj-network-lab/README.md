# OJ Network Lab

별도 Linux 실습 서버 없이 브라우저에서 네트워크를 구성하고 명령어와 패킷으로 장애 원인을 확인하는 OJ Platform 애드온입니다. 패킷 계산은 Web Worker에서 수행하고 WordPress는 로그인·권한과 본인 프로젝트 저장만 담당합니다. 외부 AI·실제 네트워크·실행 서버를 사용하지 않습니다.

## 설치와 진입

PHP 8.3, WordPress 6.6, OJ Platform 2.5.9 및 Addon API 1.9 이상이 필요합니다. 새 사이트에서는 플랫폼 활성화 후 WordPress 관리자 → OJ Platform 설치 → 신규 설치까지 완료하고 로그인 계정의 프로필 보완을 마칩니다. 공식 릴리스 킷에서 만든 ZIP을 WordPress 관리자에서 업로드하고 사이트별로 활성화합니다. 교사는 플랫폼의 네트워크 실습 메뉴(`/oj-console/addons/oj-network-lab`), 학생은 `/oj-addons/oj-network-lab/learn`에서 이용합니다. shortcode는 제공하지 않습니다.

플랫폼 역할·권한에서 **네트워크 실습 참여** 또는 **네트워크 실습 교사**를 배정합니다. 애드온 권한은 공개 `AddonCapabilityKeyV1` 변환을 사용하며 WordPress 저장 키는 `oj_addon_oj_network_lab_learn`, `oj_addon_oj_network_lab_teach`입니다. 학생 역할에 권한을 자동 추가하지 않습니다. 교사 화면도 본인 프로젝트를 다루며 다른 사용자의 프로젝트를 열 수 없습니다.

## 실습 방법

학생 주소에 `?scenario=dhcp`처럼 내장 예제 ID가 있으면 해당 구성·기본 장비·실습 목표를 바로 엽니다. 교재 링크를 다시 열면 예제의 기본 설정에서 시작합니다. 링크 탭에서 변경한 구성은 별도의 로컬 복구 사본으로 보관하며 일반 메뉴의 마지막 초안과 분리됩니다. 이름이 없거나 유효하지 않으면 일반 진입 동작을 사용합니다.

교재별 링크는 `?exercise=l01`~`l23`을 사용합니다. `resources/js/curriculum.js`의 `CURRICULUM` 배열이 `{id, scenario, title, goal}`을 정의합니다. 부모 교재 빌더도 같은 배열을 읽어 초기 scenario와 링크를 검증합니다. 유효한 exercise가 scenario보다 우선하고, 알 수 없거나 중복된 exercise는 기존 scenario 또는 일반 초안 복원으로 돌아갑니다. URL은 허용목록 선택에만 쓰고 임의 제목·코드·네트워크 주소를 실행하지 않습니다.

목표 영역은 새로 연 예제가 교재 링크의 시나리오와 일치할 때만 해당 단원 제목·업무 목표를 표시하며, 내장 예제 안내는 **기본 예제 참고**에서 펼칩니다. 다른 예제를 열면 그 예제의 일반 목표만 표시합니다. 서버 프로젝트·파일·로컬 복구 사본을 불러오면 출처 단원을 추측하지 않고 일반 불러오기·복구 안내만 표시합니다. **다음에 열 예제** 선택창은 **예제 열기**의 대상이며 현재 저장본의 종류를 뜻하지 않습니다. 선택만 바꾸면 현재 구성과 목표는 그대로입니다.

예제 변경이나 저장본 불러오기 후에도 그 탭의 교재 복구 문맥은 유지합니다. 링크를 다시 열면 초기 구성으로 시작하며, 계속 작업할 때는 **내 프로젝트 → 해당 실습의 복구 사본**을 선택합니다. 서버 프로젝트 목록은 기존 계정 단위로 공유합니다.

1. 기초 예제 또는 07 IOS 스위치·08 IOS 라우터·09 FortiOS 정책·NAT·10 FortiOS IPsec·11 Windows CMD·PowerShell·방화벽 예제를 엽니다.
2. 장비를 선택하거나 터미널의 장비 선택을 바꿉니다. `help`로 지원 명령어를 확인합니다.
   명령은 출력 바로 다음 프롬프트에서 입력하고 Enter로 실행합니다. 터미널의 빈 공간을 클릭해 입력할 수 있으며 긴 명령은 자동 줄바꿈됩니다. 상단 실행 버튼·화살표 이력·Tab 완성·Ctrl+C 중단·Ctrl+L 화면 지우기도 제공합니다.
3. `ping -c 2 192.168.10.20`, `traceroute -I 192.168.20.10`, `curl http://203.0.113.10` 등을 실행합니다.
4. 패킷 타임라인과 상태 패널에서 ARP·MAC·경로·conntrack·NAT·가상 VPN 상태를 확인합니다. 자동 진행을 끄면 한 단계씩 실행할 수 있습니다.
5. 장비 추가, 케이블 연결, 포트·주소·VLAN·경로·서비스 설정을 변경합니다. CLI와 GUI는 같은 설정을 바꾸며 해당 장비의 관련 캐시·세션을 갱신합니다. 다른 장비와 가상 시간은 유지하며 장비 삭제 시 진행 중 통신을 취소합니다.
6. 파일 내보내기 또는 서버 저장으로 설정을 보관합니다. 저장 파일은 진행 중 패킷·타이머를 포함하지 않으며 복원 시 실행 상태를 새로 시작합니다.

장비별 문법·모드·저장 방식은 [CLI-GUIDE.md](CLI-GUIDE.md)와 화면 도움말을 기준으로 합니다. 새 장비의 권장 CLI는 라우터·스위치에 IOS, 방화벽에 FortiOS입니다. 기존 프로젝트의 Linux 프로필은 유지합니다. 프로젝트 저장은 실행 설정·시작 설정을 함께 보관하며 장비의 설정 저장 명령을 대신하지 않습니다. 허브는 관리 CLI가 없습니다. nftables는 `ip`·`inet` family, filter/NAT base chain, IPv4 주소·포트·인터페이스·연결 상태 조건과 accept/drop/reject/masquerade/snat/dnat의 부분집합을 지원합니다. hook priority는 숫자로 지정합니다. 사용자 스크립트·셸·eval은 실행하지 않습니다.

출력 검색·정렬·개수·필드 추출을 위한 `grep`, `head`, `tail`, `wc`, `sort`, `uniq`, `cut`, `cat`, 가상 경로·이력·설정 비교 도구도 제공합니다. Linux에서는 직접 입력하고 IOS·FortiOS에서는 공통 `lab` 접두사를 사용합니다. 제조사 기본 출력 필터도 지원합니다. 예를 들어 `ip addr | grep -n inet`, `show running-config | include interface`, `show firewall policy | grep -f HTTP`, `lab logs | grep drop`을 실행할 수 있습니다. 지원 옵션·가상 파일·부하 제한은 [TOOLS-GUIDE.md](TOOLS-GUIDE.md)를 확인합니다. 읽기 전용 분석은 추가 서버 요청을 만들지 않습니다.

PC·서버는 Windows 프로필도 선택할 수 있습니다. 같은 장비에서 CMD와 PowerShell을 전환하며 주소·경로·DNS·방화벽을 공유합니다. ipconfig·ping·tracert·route·nslookup·findstr, PowerShell 네트워크 조회/설정·객체 파이프, Windows 방화벽 프로필·규칙을 제공합니다. Test-NetConnection은 가상 ICMP·TCP 핸드셰이크 결과를 사용합니다. 기본 인바운드 차단, 명시적 Block 우선순위, 기존 허용 연결의 응답을 계산합니다. 상세 문법·예제·제약은 [WINDOWS-GUIDE.md](WINDOWS-GUIDE.md)를 확인합니다. 실제 Windows·PowerShell 실행 파일을 설치하거나 실행하지 않습니다.

## VPN 모델

예제 10의 FortiOS IPsec은 기존 WireGuard와 별도의 교육용 IKEv2·ESP 상태 모델입니다. PSK·proposal·DH/PFS·선택자·WAN 경로·정책에 따른 성공·실패를 계산합니다. 실제 암호화는 수행하지 않으며 고정 IPv4 피어·선택자 한 쌍·제한된 재시도와 SA 수명만 지원합니다. 가상 키만 입력하세요. 상세 범위는 CLI 안내서를 확인하세요.

VPN 예제의 가상 개인키는 `sim-a`, `sim-b`이며 공개키는 각각 `sim-public:sim-a`, `sim-public:sim-b`입니다. `wg show`, `wg set`, `ip route`로 설정과 상태를 다룹니다. `sim key sim-a`로 가상 공개키를 확인할 수 있습니다.

모델은 외부 UDP 경로와 방화벽, 피어 키 관계, 핸드셰이크·캡슐화·수신 출발 주소 검사와 내부 복귀 경로를 계산합니다. 실제 암호화는 하지 않습니다. AllowedIPs와 OS 경로를 분리하며 `wg-quick up`은 명시한 피어의 경로를 추가합니다. full tunnel은 endpoint의 외부 host route를 보존하는 단순 모델이며 Linux의 전체 policy routing·fwmark 동작을 구현하지 않습니다. `wg-quick down`은 해당 인터페이스의 정적 경로를 제거합니다. 가상 핸드셰이크는 120초 후 새 통신 때 갱신하며 keepalive 주기 실행은 미지원입니다. 키를 실제 VPN에 사용하지 마세요.

## 지원 범위와 한계

- IPv4, 인터페이스당 주소 하나, 정적 라우팅과 forwarding, ARP, L2 MAC 학습·만료, access/trunk VLAN을 제공합니다.
- ICMP echo·TTL 오류, UDP DNS A 조회·60초 캐시, 같은 L2 영역의 DHCP DORA, HTTP와 TCP SYN·ACK·RST·단순 종료·제한된 재전송을 모델링합니다.
- HTTP용 TCP 부분 모델이며 전체 RFC TCP, 수신 윈도·혼잡 제어·정밀 바이트 스트림·모든 종료 예외를 구현하지 않습니다. 출력 RTT는 가상 시간이며 성능 측정값이 아닙니다.
- Linux Ethernet의 IPv6 정적 주소·경로, NS/NA 이웃 캐시, ICMPv6 Echo·Hop Limit·Packet Too Big과 출구 MTU를 계산합니다. 자동 주소 구성·DAD·DHCPv6·IPv6 방화벽·TCP/DNS 응용·자동 PMTU 캐시는 미지원입니다. `ping -6 -s`로 크기를 직접 바꿉니다.
- IOS 라우터의 단일 영역 OSPFv2는 직접 연결된 일대일 링크의 영역·주소·MTU·Router ID·ACL 조건을 검사하고 LSDB 요약과 SPF 비용 경로를 만듭니다. eBGP는 직접 연결된 서로 다른 AS의 양방향 peer 조건, 정확한 로컬 접두사 광고, AS_PATH 순환 방지, Weight·AS_PATH 길이 선택, 광고 철회를 계산합니다. 실제 데이터는 이 경로를 따라 전송됩니다.
- 동적 라우팅은 설정 변화 후 가상 500ms에 안정 상태를 계산하는 교육용 모델입니다. 실제 Hello/Dead·Hold 타이머, TCP 179 연결·패킷 인코딩, LSA 종류·전파 지연·중간 미세 순환을 재현하지 않습니다. DR/BDR·ABR·OSPFv3·iBGP·MP-BGP·route-map·ECMP·VRF는 미지원입니다. 부분 손실은 데이터 프레임에 적용하며 제어 모델에서는 포트 단절과 100% 손실을 도달 불가로 판단합니다.
- 물리 허브의 CSMA/CD·케이블 전기 신호·반이중 대역폭 공유, STP/RSTP, IPv4 MTU·단편화, DHCP 자동 갱신·만료, VPN의 실제 암호화·주기 keepalive, HTTPS/TLS·SSH는 미지원입니다.
- 허브는 프레임 전파 모델입니다. L2 루프는 IP TTL로 숨기지 않고 전달 횟수·사건 한도에 도달하면 진단과 함께 정지합니다.
- `tcpdump`와 관측 내보내기는 합성 JSON trace이며 실제 PCAP이 아닙니다. 외부 VPN UDP 관측에는 내부 평문을 출력하지 않고 터널 종단의 decap 이벤트에서 내부 패킷을 표시합니다.
- 자유 구성·내장 실습 14개와 본인 프로젝트 저장을 제공합니다. `ipv6`·`ospf`·`bgp` 예제와 교재 L21~L23은 기존 예제를 변경하지 않습니다. 학급별 배정·교사 보고서·공식 채점·실제 Linux/Windows 실행은 이 버전에 포함되지 않습니다.

## 자원과 저장 정책

기본 상한은 장비 20개, 링크 40개, 장비당 인터페이스 10개·규칙 64개, 대기 사건 20,000개·실행 초기화 전 총 사건 100,000개입니다. 한도 초과는 통신 오답과 구분한 정지 상태입니다. 엔진은 활성 작업이 없으면 멈추고 숨긴 탭에서는 일시정지합니다. 출력은 터미널당 400줄, trace는 엔진 1000개·화면 최근 150개로 제한합니다.

IndexedDB에 계정·사이트별 일반 초안과 최대 20개 일반 복구 사본을 보관합니다. 교재 exercise별 복구 사본도 각각 최대 20개이며 다른 단원의 저장·정리에 영향을 주지 않습니다. **로컬 초안 삭제**는 현재 일반/교재 문맥에만 적용합니다. 브라우저 삭제·저장 공간 정책으로 유실될 수 있습니다. 서버에는 변경이 있을 때만 약 30~33초 간격으로 자동 저장하며 수동 저장도 가능합니다. 요청은 최대 256 KiB, 서버 프로젝트는 사용자당 최대 20개입니다. 서버 저장 실패는 로컬 저장과 구분해 표시하고 새 요청의 중복 저장과 다중 탭의 버전 충돌을 방지합니다. 충돌하면 서버본 불러오기 또는 새 사본 저장을 선택합니다. 외부 서버와 패킷 단위 통신·상태 폴링은 하지 않습니다.

## 운영과 삭제

1.0.0-rc.1~rc.3의 schema 1 프로젝트와 로컬 초안은 Linux 프로필을 유지하며 schema 2로 변환합니다. 장비 실행 설정과 시작 설정을 함께 보관하고 콘솔 모드는 새로 시작합니다. DB 테이블 구조는 schema 1을 유지합니다. 서버 저장을 누르면 기존 프로젝트 번호를 유지하며 현재 버전으로 저장합니다. 실행 중 패킷·타이머는 복원하지 않습니다.

WordPress **설정 → OJ Network Lab**에서 서버 보관 일수와 삭제 정책을 정합니다. 0일은 무기한이며 보관 기간을 정하면 WordPress 일일 예약 작업이 만료 프로젝트를 최대 500개씩 지웁니다. **도구 → 사이트 건강**에서 저장소 상태를 확인합니다. 저장·삭제·설정 변경은 `oj_network_lab_audit` 이벤트를 제공하고 구형 공통 감사 함수가 있으면 함께 기록합니다. 현재 공개 Addon API의 메뉴·권한·개인정보 재고를 사용하며 플랫폼 내부 DB에 의존하지 않습니다.

서버 저장은 사용자 식별자, 제목, 네트워크 설정과 가상 키, revision·시각을 전용 테이블에 보관합니다. 명령어와 패킷 원문은 서버에 저장하지 않습니다. WordPress 개인정보 내보내기·삭제와 계정 삭제 처리를 제공합니다. 비활성화는 예약 작업을 해제하고 기록을 보존합니다. 플러그인 삭제도 기본 보존이며 설정에서 데이터 제거를 켠 경우 해당 사이트의 테이블·설정을 지웁니다. 브라우저 초안은 실습 화면에서 따로 삭제합니다.

## 개발 검증과 패키지

`node oj-network-lab/tests/routing.mjs`는 IPv6 주소·NS/NA·왕복·MTU 장애와 복구, OSPF 비용·영역·링크·중복 ID, BGP 피어·정책·광고 철회·경로 보존을 실제 엔진으로 검증합니다. 이전 1.3 프로젝트는 같은 schema 2로 유지하며 새 설정만 기본값으로 추가합니다.

`node oj-network-lab/tests/curriculum.mjs`는 교재 매핑·URL 선택·이전 파일 호환·모듈 버전을 검사합니다. `node oj-network-lab/tests/goals.mjs`는 브라우저 없이 실제 목표 표시·불러오기 핸들러를 단위 검증하고 예제 선택 의미·복구 문맥·초안 보호를 정적 회귀 검사합니다. `node oj-network-lab/tests/exercises.cjs`는 로컬 페이지 핸들러 fixture에서 초기 진입·문맥별 복구·삭제·보관 한도·모바일 화면을 Playwright로 검사합니다. 운영 WordPress를 검증한 결과는 아닙니다. 그림은 기본 `_notes/tmp/oj-network-lab-exercises`에 남고, 이 검사와 기존 `browser.cjs`의 결과 위치는 `OJNL_TEST_OUTPUT`으로 바꿀 수 있습니다.

`node oj-network-lab/tests/windows.mjs`는 Windows 호스트·방화벽·객체 파이프를 검사합니다. 실제 findstr·PowerShell이 있으면 고정된 텍스트·객체 사례만 대조하며 운영체제 네트워크·방화벽 설정은 변경하지 않습니다. 검증용 실행 파일은 플러그인에 포함하지 않습니다.

`node oj-network-lab/tests/engine.mjs`, `node oj-network-lab/tests/devices.mjs`, `node oj-network-lab/tests/tools.mjs`와 `D:\php\php.exe -d extension=pdo_sqlite oj-network-lab/tests/backend.php`로 엔진·출력 분석·저장·권한·충돌·플랫폼 DTO 계약을 검사합니다. 도구 검사는 설치된 GNU 실행 파일을 사용할 수 있으면 지원 문법의 일부를 대조하며 `OJNL_GNU_TOOLS`로 그 경로를 지정합니다. GNU 프로그램은 검증에만 사용하고 플러그인에 포함하지 않습니다. `node oj-network-lab/tests/browser.cjs`는 실제 페이지 핸들러 출력과 Web Worker를 브라우저에서 시험하며 REST는 테스트용 저장소로 대체합니다. Playwright 모듈·브라우저 경로는 `PLAYWRIGHT_MODULE`, `PLAYWRIGHT_CHROMIUM_EXECUTABLE`로 지정할 수 있습니다.

`node oj-network-lab/tests/wordpress-real.cjs`는 실제 WordPress 원본, MariaDB의 새 데이터 폴더와 PHP 8.3을 사용해 플랫폼 설치 마법사·ZIP 업로드·활성화·학생 로그인·실제 REST 저장·주요 실습을 검사합니다. WordPress 함수나 REST 응답을 대체하지 않습니다. 기존 운영 사이트에는 접속하지 않고 루프백 포트와 `_notes/tmp`의 일회용 사이트를 사용하며 종료 시 프로세스와 임시 자료를 정리합니다. WordPress ZIP·MariaDB·PHP 경로는 `OJNL_REAL_WP_ZIP`, `OJNL_REAL_MARIA`, `OJNL_REAL_PHP`로 바꿀 수 있습니다. 초기 프로필은 기존 등록 이름과 이메일을 유지해 작성합니다.

설치 ZIP은 `_release-kit\release.cmd package -Plugin oj-network-lab`, 안내서는 `release.cmd docs`, 최종 정책 검사는 `release.cmd check`로만 수행합니다. 운영 서버의 웹 서버·플러그인 조합·캐시·접근 정책은 배포 환경에서 별도로 확인합니다.

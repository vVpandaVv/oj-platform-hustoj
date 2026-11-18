# 출력 분석과 가상 파일 도구

실습 장비의 설정·상태·로그에서 필요한 내용을 찾는 도구입니다. 계산은 브라우저 Worker에서 수행하며 명령 실행을 위해 WordPress 서버나 실제 장비에 접속하지 않습니다. GNU grep·coreutils의 일부 문법, IOS XE 출력 필터, FortiOS grep을 참고해 구현했습니다. 실제 바이너리나 펌웨어를 실행하는 방식은 아닙니다.

## 장비별 입력 방법

Windows PC·서버에는 CMD의 findstr, PowerShell의 Select-String과 객체 파이프도 제공합니다. 공통 lab 도구는 Windows에서도 사용합니다. Windows 문법·가상 C:\Lab 경로·방화벽은 [WINDOWS-GUIDE.md](WINDOWS-GUIDE.md)를 확인합니다.

| 선택한 CLI | 기본 출력 분석 | 공통 도구 |
| --- | --- | --- |
| Linux | `ip addr \| grep -n inet` | 도구 이름을 직접 입력하거나 `lab` 사용 |
| IOS 스위치·라우터 | `show ... \| include/exclude/begin/section 정규식` | `lab` 접두사 사용 |
| FortiOS 방화벽 | `show/get/지원 diagnose ... \| grep 패턴` | `lab` 접두사 사용 |
| 허브 | 관리 콘솔 없음 | 지원하지 않음 |

IOS·FortiOS에도 `lab` 도구를 제공하지만 실제 제조사 CLI에 Linux 셸이 있다는 뜻은 아닙니다. `lab`은 이 시뮬레이터의 공통 분석 기능입니다. 화면의 **분석 도구**, `lab help`에서 도움말을 보고 Tab으로 명령·옵션·가상 경로를 완성할 수 있습니다.

```text
# Linux: 주소가 표시된 줄과 기본 경로 확인
ip addr | grep -n inet
ip route | grep default
cat /state/interfaces.txt | head -n 5

# IOS: enable 후 설정 검색
enable
show running-config | include ^hostname|^interface
show running-config | section interface GigabitEthernet1/0/2
show ip interface brief | exclude unassigned
show running-config | lab grep -n interface | lab head -n 5
lab diff

# FortiOS: HTTP 정책 블록과 세션 검색
show firewall policy | grep -f HTTP
diagnose sys session list | grep -i established
lab cat /state/interfaces.txt | grep -n port1

# 모든 관리 장비: 통신 실행 후 관측 분석
lab files
lab logs | grep -i drop | tail -n 10
lab stats
lab history | tail -n 5
```

위의 `#` 설명 줄은 실행할 명령이 아닙니다. 예제·선택 장비·실행한 통신에 따라 검색 결과가 없을 수 있습니다. IOS 설정 파일 조회와 `lab diff`에는 enable이 필요합니다.

## 지원 도구와 옵션

| 도구 | 지원 범위 |
| --- | --- |
| `grep` | 기본 BRE, `-E` ERE, `-F` 문자열 검색, `-i -v -n -c -x -w -q`, `-A/-B/-C N`, `-m N`, `-e 패턴`, `-f 가상패턴파일`, `-h -H`, `--` |
| `head`, `tail` | `-n N`, 기본 10줄. `head -N`, `tail -n +N`도 지원 |
| `wc` | `-l` 줄바꿈 수, `-w` 단어 수, `-m` 문자 수, `-c` UTF-8 바이트 수. 기본은 줄·단어·바이트 |
| `sort` | `-r` 역순, `-n` 앞부분 숫자 비교, `-u` 중복 제거 |
| `uniq` | 인접한 같은 줄 처리. `-c` 횟수, `-d` 중복 줄만, `-u` 한 번 나온 줄만 |
| `cut` | `-d 구분자 -f 필드목록`, `-s`. 필드는 1부터 시작하며 `1,3`, `2-4`, `2-`, `-3` 형식 |
| `cat` | 가상 파일 출력·연결, `-n` 줄 번호. 파이프 입력도 사용 |
| `ls`, `pwd` | 가상 경로 목록, 가상 루트 `/` 표시. `ls -1` 지원 |
| `echo` | 문자열 표시, `-n`. 독립된 `$?` 인수는 직전 동기 명령 상태로 확장 |
| `hostname`, `history` | 선택 장비 이름 조회, 현재 실행의 최근 명령 조회 |
| `diff [-u] 파일1 파일2` | 두 가상 파일 비교. `-u` 생략 시에도 단일 unified hunk로 표시 |

Linux에서는 위 명령을 직접 입력합니다. IOS·FortiOS에서는 `lab grep ...`, `lab sort ...`처럼 사용합니다. `lab`으로 시작한 파이프에서는 뒤의 공통 필터에 `lab`을 생략할 수 있습니다. 제조사 명령으로 시작한 경우에는 제조사 기본 필터 또는 `lab`을 명시합니다.

`lab files`는 전체 가상 파일 경로, `lab logs`는 해당 장비 관측, `lab history`는 해당 장비 이력, `lab stats`는 가상 시간·사건·캐시 통계를 표시합니다. 통계는 실제 서버 CPU·메모리 사용량이 아닙니다. `lab diff`는 시작 설정과 실행 설정을 비교하며 시작 설정이 없는 Linux 프로필에서는 두 파일을 직접 지정합니다.

`grep`은 일치하면 상태 0, 없으면 1, 구문·한도 오류는 2입니다. `diff`는 같으면 0, 다르면 1입니다. `lab status` 또는 Linux의 `echo $?`로 직전 상태를 확인합니다. 파이프 상태는 마지막 단계 기준입니다. 비동기 ping·traceroute 등의 상태는 명령 접수 기준이며 최종 통신 성공 여부는 그 명령의 결과를 확인합니다. 작은따옴표 또는 역슬래시로 보호한 `$?`는 그대로 출력합니다. 일반 환경변수·명령 치환은 지원하지 않습니다.

## 제조사 필터의 차이

IOS는 `include`가 일치 줄, `exclude`가 불일치 줄, `begin`이 첫 일치부터 끝까지, `section`이 일치한 줄이 속한 최상위 설정 블록을 표시합니다. 유일한 축약도 허용합니다. 패턴은 제한된 ERE 문법입니다. `include ^hostname|^interface`의 `|`는 정규식 OR로 처리하며 공통 필터를 이어 쓸 때는 `| lab ...`을 사용합니다.

FortiOS 기본 `grep`은 `-i -n -v -f -c -A/-B/-C N`을 지원합니다. **여기의 `-f`는 일치 설정이 속한 config/edit 블록과 상위 문맥을 표시하는 옵션**입니다. `-v`, `-c`, 줄 단위 문맥 옵션과 함께 쓸 수 없습니다. 공통 도구의 `lab grep -f 파일`은 GNU 방식의 패턴 파일 읽기이므로 의미가 다릅니다. 기본 FortiOS grep은 파이프 입력만 받습니다.

## 읽기 전용 가상 파일

| 경로 | 내용 |
| --- | --- |
| `/config/running.conf` | 현재 설정. IOS·FortiOS는 해당 CLI 형식, Linux는 모델 설정 JSON |
| `/config/startup.conf` | IOS·FortiOS 시작 설정 |
| `/etc/hostname`, `/etc/resolv.conf` | 장비 이름, 설정된 DNS 서버 |
| `/state/interfaces.txt`, `/state/routes.txt` | 인터페이스·경로 |
| `/state/arp.txt`, `/state/mac.txt` | 현재 유효한 ARP·MAC 항목 |
| `/state/sessions.txt`, `/state/nat.txt`, `/state/vpn.txt` | 모델의 연결·NAT·VPN 상태 |
| `/var/log/network.log` | 해당 장비에서 관측된 최근 사건 |
| `/history/commands.txt` | 해당 장비의 최근 명령 최대 100개 |

파일은 선택한 장비의 모델에서 조회 시 생성합니다. 운영 서버 파일·다른 장비 파일을 읽을 수 없으며 파일을 수정·업로드·다운로드하는 셸은 없습니다. 설정에는 프로젝트에 저장된 가상 VPN 키도 포함될 수 있으므로 실습용 값만 사용합니다. 이력·로그·종료 상태는 프로젝트 저장에 포함하지 않고 새로 시작합니다. 로그는 엔진의 최근 trace 최대 1000개 중 선택 장비의 항목이며 영구 감사 로그가 아닙니다.

## 실행 범위와 부하 제한

파이프는 지원하는 **동기 조회 출력**에만 사용합니다. IOS show/do show, FortiOS show·일부 get/diagnose·execute log display, Linux ip 조회·bridge 조회·nft list ruleset·conntrack -L·ss·wg show 등이 대상입니다. 설정 변경과 `ping`, `traceroute`, `tcpdump`의 실시간 출력은 파이프에 연결할 수 없습니다. 통신을 단독 실행한 뒤 `lab logs` 등으로 분석합니다. 모든 필터의 문법·패턴을 먼저 검사하므로 잘못된 파이프가 앞 명령의 설정을 변경하지 않습니다.

명령 한 줄 2048자, 명령·필터 합계 8단계, 단계별 텍스트 200,000자·4000줄, 패턴 256자, NFA 512상태, 공유 작업 예산 8,000,000회를 적용합니다. 문맥 옵션은 최대 100줄입니다. 초과 시 오류로 중단하며 터미널 표시는 기존 400줄 제한을 유지합니다. 분석 명령은 설정 revision을 올리지 않고 추가 저장 요청을 만들지 않습니다.

정규식은 문자·점·앵커·그룹·OR·문자 클래스·일부 POSIX 클래스·반복을 지원하며 `{m,n}`의 상한은 64입니다. 사용자 패턴은 역추적 정규식 엔진 대신 작업 예산이 있는 NFA로 처리합니다. 역참조·lookaround·PCRE·로케일별 문자 동치·정렬 규칙은 지원하지 않습니다. 문자 처리와 숫자 정렬은 JavaScript 문자열·수치 범위의 제약을 받으며 GNU의 모든 유니코드·로케일·고정밀 숫자 동작과 같지 않습니다. `sort -n`은 앞부분 숫자를 부동소수점으로 비교하는 단순 모델입니다.

셸 스크립트·리다이렉션·`;`, `&&`, `||`·명령 치환·와일드카드 파일 확장·쓰기·설치 명령·`tail -f`는 지원하지 않습니다. 미지원 명령과 옵션은 오류로 표시합니다. 이 기능으로 실제 운영 네트워크나 서버를 관리하지는 않습니다.

문법 참고: [GNU grep](https://www.gnu.org/software/grep/manual/grep.html), [GNU coreutils](https://www.gnu.org/software/coreutils/manual/coreutils.html), [Cisco IOS XE 출력 필터](https://www.cisco.com/c/en/us/td/docs/routers/access/isr1100/software/configuration/guide/isr1100-sw-config/using_iosxe_software.html), [FortiOS CLI 기본](https://docs.fortinet.com/document/fortigate/7.4.5/administration-guide/896276/cli-basics).

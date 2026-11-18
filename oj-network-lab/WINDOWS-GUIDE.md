# Windows CMD·PowerShell·방화벽 실습

PC·서버에 Windows 학습 프로필을 선택하면 CMD와 PowerShell을 사용할 수 있습니다. 두 셸은 같은 주소·경로·DNS·서비스·방화벽을 공유합니다. 계산은 브라우저 Worker에서 수행하며 별도 Windows 서버·VM·PowerShell 설치는 필요하지 않습니다. 실제 Windows OS·프로그램·관리자 권한을 실행하는 기능은 아닙니다.

## 시작하기

예제 **11 · Windows CMD·PowerShell·방화벽**을 엽니다. WIN-PC, Linux DHCP·DNS 서버, WIN-Web이 스위치로 연결됩니다. WIN-PC의 첫 셸은 CMD이며 `C:\Lab>`로 표시합니다.

```text
ipconfig /renew
ipconfig /all | findstr /I /C:"IPv4 Address"
nslookup winweb.lab
powershell
```

터미널 상단 선택으로도 전환합니다. PowerShell은 `PS C:\Lab>`로 표시하며 cmd 또는 PowerShell의 exit로 CMD에 돌아갑니다. 재개 시 셸·가상 현재 디렉터리·이력·진행 중 통신은 새로 시작합니다. CMD의 exit, 셸 전환의 /c·-Command·스크립트 인수는 미지원입니다.

WIN-PC의 PowerShell에서 다음을 실행합니다. 처음에는 WIN-Web의 인바운드 차단으로 실패합니다.

```powershell
Get-NetIPAddress | Where-Object { $_.AddressFamily -eq 'IPv4' } | Select-Object InterfaceAlias,IPAddress
Test-NetConnection winweb.lab -Port 80
```

장비를 **WIN-Web**으로 바꾸고 PowerShell을 선택한 뒤 실행합니다.

```powershell
New-NetFirewallRule -Name Web -DisplayName 'Lab HTTP' -Protocol TCP -LocalPort 80 -Profile Private -Direction Inbound -Action Allow
Set-NetConnectionProfile -InterfaceAlias Ethernet -NetworkCategory Private
Get-NetFirewallRule -Name Web | Get-NetFirewallPortFilter
```

WIN-PC에서 다시 TCP 검사를 실행하면 `TcpTestSucceeded : True`입니다. TCP 검사는 SYN·SYN-ACK·ACK·종료를 계산하며 HTTP 요청을 보내지 않습니다. 수신 포트는 모델의 HTTP 서비스 포트이며 임의 Windows 프로세스의 수신 포트는 만들지 않습니다. 닫힌 포트의 RST와 방화벽 차단은 모두 False지만 타임라인에서 원인을 구분합니다.

WIN-Web에서 아래 규칙을 추가하면 Allow가 있어도 차단됩니다. Disable 또는 Remove로 복구합니다.

```powershell
New-NetFirewallRule -Name DenyWeb -DisplayName 'Block HTTP' -Protocol TCP -LocalPort 80 -Direction Inbound -Action Block
Disable-NetFirewallRule -Name DenyWeb
Remove-NetFirewallRule -Name DenyWeb -Confirm:$false
```

## CMD 지원 범위

| 명령 | 지원 동작 |
| --- | --- |
| `ipconfig [/all]` | IPv4·마스크·게이트웨이, all은 MAC·DHCP·DNS 포함 |
| `ipconfig /renew [어댑터]`, `/release [어댑터]` | DHCP DORA·주소/경로/자동 DNS 취득과 해제 |
| `ipconfig /displaydns`, `/flushdns` | 현재 모델의 DNS 캐시 조회·비우기 |
| `ping [-n 1~4] 주소` | 기본 4회, IPv4·호스트 이름·localhost |
| `tracert [-d] [-h 1~8] 주소` | 기본 8홉·ICMP·홉당 1회·숫자 주소 |
| `nslookup 이름 [DNS주소]` | 비대화형 DNS A 조회 |
| `arp -a`, `arp -d IP 또는 *` | 현재 장비의 ARP 조회·삭제 |
| `route print` | 운영 중 인터페이스의 연결·정적 경로·합산 메트릭 |
| `route add 망 mask 마스크 게이트웨이 [metric N] [if N]` | 정적 경로 생성. if 생략 시 게이트웨이 서브넷에서 선택 |
| `route delete 망` | 해당 네트워크 주소의 정적 경로 삭제 |
| `netstat [-a -n -o -r]` | TCP 연결·a 수신 포트·r 경로, 조합 옵션 |
| `getmac`, `hostname`, `ver` | 가상 MAC·호스트 이름·시뮬레이션 안내 |
| `cls`, `echo 문자열` | 화면 지우기·출력. `%errorlevel%`은 직전 동기 상태 |
| `dir [경로]`, `cd [경로]`, `type 파일` | 읽기 전용 C:\Lab 파일·디렉터리 |
| `findstr` | `/I /V /N /X /L /R`, `/C:패턴`, 여러 검색어·가상 파일·파이프 |

명령·옵션은 대소문자를 구분하지 않습니다. ping/tracert의 -n·-h·-d·-4는 / 표기도 허용하고 route -4 print도 지원합니다. 연속 ping·가변 대기 시간·tracert 30홉/3회 탐침·역방향 DNS·대화형 stdin은 미지원입니다. netstat -o의 PID는 실제 프로세스가 없어 `-`입니다. UDP 소켓 통계·프로세스 목록도 제공하지 않습니다. route -p·Windows 영구 경로 저장소·재부팅은 미지원이며 프로젝트 저장은 현재 가상 경로 설정을 보관합니다.

findstr은 기본 단순 정규식, /L 문자열, /C: 단일 문자열 검색입니다. /R /C:는 정규식으로 처리합니다. 점·별표·앵커·문자 클래스·단어 경계 부분집합이며 GNU grep 전체 문법과 다릅니다. 실제 findstr의 ASCII 사례를 대조했고 코드페이지·바이너리 파일·모든 유니코드 처리까지 재현하지는 않습니다.

## PowerShell 네트워크 조회·설정

| 구분 | 지원 명령 |
| --- | --- |
| 조회 | Get-NetAdapter, Get-NetIPConfiguration, Get-NetIPAddress, Get-NetIPInterface, Get-NetRoute, Get-NetNeighbor, Get-NetTCPConnection, Get-DnsClientServerAddress, Get-NetConnectionProfile |
| 주소 | New-NetIPAddress, Set-NetIPAddress, Remove-NetIPAddress |
| DHCP·메트릭 | Set-NetIPInterface -Dhcp Enabled/Disabled 또는 -InterfaceMetric N |
| 어댑터 | Enable-NetAdapter/Disable-NetAdapter -Name 이름 |
| 정적 경로 | New-NetRoute, Set-NetRoute, Remove-NetRoute |
| DNS | Set-DnsClientServerAddress -ServerAddresses IP 또는 -ResetServerAddresses |
| 활성 프로필 | Set-NetConnectionProfile -NetworkCategory Public/Private |
| 진단 | Resolve-DnsName 이름 [-Server IP] [-Type A], Test-NetConnection 주소 [-Port N 또는 -TraceRoute] [-Hops 1~8] [-InformationLevel Quiet/Detailed] |

네트워크 조회·설정은 `-InterfaceAlias Ethernet` 또는 `-InterfaceIndex 1`로 대상을 고릅니다. 두 번째부터 `Ethernet 2` 등이며 공백은 따옴표로 묶습니다. 저장·케이블의 물리 포트 ID eth0 등과 별칭·인덱스를 매핑합니다. Get-NetAdapter는 -Name, Get-NetIPConfiguration은 -All·-Detailed, Get-NetIPAddress는 -IPAddress, Get-NetRoute는 -DestinationPrefix, Get-NetTCPConnection은 -State·-LocalPort·-RemotePort를 지원합니다. 명령·매개변수 대소문자는 구분하지 않으며 매개변수 축약은 미지원입니다.

프로필 교체 시 호스트 이름을 영문·숫자·하이픈·밑줄 32자 이내로 정리하며 영문자로 시작하지 않으면 WIN-을 붙입니다.

```powershell
New-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.110 -PrefixLength 24 -DefaultGateway 192.168.10.1
Set-NetIPAddress -InterfaceAlias Ethernet -IPAddress 192.168.10.110 -PrefixLength 25
Set-DnsClientServerAddress -InterfaceAlias Ethernet -ServerAddresses 192.168.10.1
New-NetRoute -DestinationPrefix 192.168.20.0/24 -InterfaceAlias Ethernet -NextHop 192.168.10.1 -RouteMetric 10
Set-NetRoute -DestinationPrefix 192.168.20.0/24 -RouteMetric 20
Remove-NetRoute -DestinationPrefix 192.168.20.0/24 -Confirm:$false
```

인터페이스당 IPv4·DNS 서버 하나를 지원합니다. 기존 정적 주소는 New 전에 Remove-NetIPAddress로 지웁니다. DHCP 주소에 New-NetIPAddress를 적용하면 DHCP를 해제합니다. Set-NetIPAddress의 IPAddress는 **기존 주소 선택 조건**이고 PrefixLength를 변경합니다. 기본 게이트웨이는 같은 서브넷이어야 합니다. 경로는 최장 접두사 → 경로 메트릭+인터페이스 메트릭 순입니다.

DHCP Enabled는 켜진 어댑터의 주소 취득을 시작합니다. 수동 DNS는 갱신으로 덮어쓰지 않고 ResetServerAddresses는 마지막 DHCP 응답의 DNS로 돌아갑니다. GUI에서 DHCP를 켠 뒤에는 /renew를 실행합니다. DHCP 자동 갱신·APIPA·DAD·Windows 저장소별 경로 수명은 미지원입니다. localhost/127.0.0.1·활성 자체 주소는 내부 전달로 처리하며 케이블·호스트 방화벽 판정과 구분합니다.

## Windows 방화벽

Get-NetFirewallProfile·Set-NetFirewallProfile은 Domain·Private·Public의 Enabled, DefaultInboundAction, DefaultOutboundAction을 다룹니다. Name 또는 Profile 생략 시 세 프로필이며 `-Profile Domain,Private,Public`처럼 선택할 수 있습니다. 기본은 모두 On·Inbound Block·Outbound Allow입니다. 활성 네트워크 범주는 Public·Private만 지원합니다. Domain 설정은 보관하지만 도메인 가입·인증은 구현하지 않아 활성화하지 않습니다.

New-NetFirewallRule은 DisplayName이 필수이고 Name 생략 시 SimRule-N ID를 만듭니다. 기본값은 Enabled True·Inbound·Allow·Profile Any·Protocol Any입니다. 지원 조건은 다음과 같습니다.

- Name·DisplayName·Enabled True/False, Direction Inbound/Outbound, Action Allow/Block
- Profile Any 또는 Domain/Private/Public 목록, Protocol Any/TCP/UDP/ICMPv4
- LocalAddress·RemoteAddress: IP·CIDR·LocalSubnet·Any 목록
- LocalPort·RemotePort: TCP/UDP의 숫자·시작-끝 범위·Any 목록
- IcmpType: ICMPv4 숫자 형식·Any 목록, InterfaceAlias: 별칭 목록·Any

목록은 쉼표로 구분합니다. Set-NetFirewallRule -Name ID로 조건을 바꾸고 표시 이름은 -NewDisplayName으로 바꿉니다. Enable/Disable/Remove-NetFirewallRule도 Name 또는 DisplayName을 지정합니다. 선택 조건은 와일드카드 *·?를 지원하며 일치한 모든 규칙이 대상입니다. 변경 명령은 -WhatIf로 검증만 하고 설정·패킷·revision을 유지할 수 있습니다. -Confirm:$false도 지원하며 UAC·관리자 승격은 재현하지 않습니다.

Get-NetFirewallRule은 Name·DisplayName·Enabled·Direction·Action·Profile 객체입니다. 포트·주소는 Get-NetFirewallPortFilter·Get-NetFirewallAddressFilter로 따로 조회하며 규칙 객체를 받는 파이프도 지원합니다.

```powershell
Get-NetFirewallRule | Where-Object Enabled -eq $true | Select-Object Name,Action,Profile
Get-NetFirewallRule -Name Web | Get-NetFirewallAddressFilter
Set-NetFirewallProfile -Profile Public -DefaultInboundAction Block -DefaultOutboundAction Allow -WhatIf
```

판정은 **명시적 Block → 기존 허용 연결·연관 ICMP 응답 → 명시적 Allow → 프로필 기본 동작**입니다. 프로필 Off는 해당 필터를 생략합니다. 규칙 순서 번호는 없습니다. 설정 변경은 연결 캐시를 비워 새 판정에 반영하며 거부된 패킷의 conntrack 기록은 응답 허용 근거가 되지 않습니다. 기본 CoreNet-DHCP-In 규칙은 UDP 68/67 DHCP 응답을 허용하며 제거·비활성화할 수 있습니다.

앱·프로그램·서비스·사용자·GPO/MDM·IPsec 인증·WFP 전체 계층·로그 파일 설정은 미지원입니다. 미지원 매개변수는 무시하지 않고 오류로 거부합니다. `lab logs | grep "Windows Firewall"`과 타임라인에서 실제 모델 판정 근거를 봅니다. 최근 관측이며 영구 Windows 이벤트 로그는 아닙니다.

## 객체 파이프·가상 파일·제한

PowerShell 조회는 문자열·숫자·불리언·배열 속성의 모델 객체를 전달합니다. 실제 CIM 객체·.NET 형식 변환·PowerShell 런타임은 실행하지 않습니다.

| 필터 | 지원 범위 |
| --- | --- |
| Where-Object | `{ $_.속성 -eq 값 }`, `속성 -eq 값`, `-Property 속성 -EQ -Value 값`. 단일 eq/ne/gt/ge/lt/le/like/notlike/match/notmatch 비교 |
| Select-Object | Property 또는 위치 속성 목록, First·Last·ExpandProperty·Unique |
| Sort-Object | Property 목록, Descending·Unique |
| Measure-Object | Count, 숫자 Property의 Sum·Minimum·Maximum·Average |
| Format-Table/Format-List | 선택 속성 표시. 이후 텍스트로 전달 |
| Out-String | 객체 표시를 텍스트로 변환, -Stream도 허용 |
| Select-String | Pattern, SimpleMatch·CaseSensitive·NotMatch·Quiet·List. 가상 Path 또는 파이프 텍스트 |

where/select/sort/measure/ft/fl/sls 별칭도 지원합니다. 텍스트는 Select-String/findstr, 객체는 속성으로 검색합니다. Get-Content·Get-ChildItem·Get/Set-Location·Get-History·Write-Output·Clear-Host·Get-Help도 제공합니다. 작은따옴표의 $는 리터럴입니다. Write-Output의 $?·$true·$false·$null은 제한적으로 지원합니다. 일반 변수·복합 조건·배열 스크립트·함수·루프·명령 치환·중첩 블록·CimSession·원격 실행은 미지원입니다.

파이프는 동기 조회만 받습니다. New/Set/Remove/Enable/Disable, Test-NetConnection·Resolve-DnsName·ping·tracert는 단독 실행합니다. 필터 문법·패턴을 먼저 검증하며 쓰기 파이프 대신 별도 설정 명령을 사용합니다.

파일은 C:\Lab\config\running.conf, C:\Lab\state\interfaces.txt·routes.txt·firewall.txt, C:\Lab\var\log\network.log, C:\Lab\history\commands.txt 등입니다. dir/Get-ChildItem, type/Get-Content로 읽습니다. 실제 C:\Windows·다른 드라이브·UNC·Lab 밖 상위 경로는 접근하지 못합니다. Windows에는 IOS/FortiOS startup 파일·copy/write/reload를 제공하지 않습니다. 공통 lab 도구는 기존 /config·/state 경로를 사용합니다.

한 줄 2048자·8단계·텍스트 200,000자/4000줄·객체 4000개·패턴 256자·NFA 512상태·공유 작업 예산 8,000,000회를 제한합니다. 정규식은 제한된 NFA이며 .NET·PCRE·역참조·lookaround 전체를 지원하지 않습니다. 이력은 장비별 100개, 화면 출력은 400줄입니다. 동기 상태는 0 성공·findstr 미일치 1·오류 2이며 비동기 상태는 접수 기준입니다. 실제 PowerShell 오류/경고 스트림과 모든 형식 강제 변환·종료 상태는 재현하지 않습니다.

기준 자료: Microsoft [Windows 명령](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/windows-commands), [NetTCPIP](https://learn.microsoft.com/en-us/powershell/module/nettcpip/), [주소 추가](https://learn.microsoft.com/en-us/powershell/module/nettcpip/new-netipaddress), [연결 검사](https://learn.microsoft.com/en-us/powershell/module/nettcpip/test-netconnection), [방화벽 규칙](https://learn.microsoft.com/en-us/windows/security/operating-system-security/network-security/windows-firewall/rules), [방화벽 명령](https://learn.microsoft.com/en-us/windows/security/operating-system-security/network-security/windows-firewall/configure-with-command-line), [객체 파이프](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_pipelines). 지원 목록·한도가 이 구현의 기준이며 실제 Windows 전 명령의 동등성을 보증하지 않습니다.

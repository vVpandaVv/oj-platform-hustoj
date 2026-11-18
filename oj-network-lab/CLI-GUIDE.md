# 장비 CLI 실습 안내

교육용 가상 장비의 지원 문법을 설명합니다. Cisco IOS XE 17.12·FortiOS 7.4 계열의 명령 부분집합을 직접 해석합니다. 제조사 인증 시뮬레이터가 아니며 실제 펌웨어·셸·SSH 서버를 실행하지 않습니다. 실물 모델의 모든 명령·기본값·출력·성능과 일치하지 않습니다.

## 시작과 콘솔

PC·서버용 Windows CMD·PowerShell과 Windows 방화벽은 [WINDOWS-GUIDE.md](WINDOWS-GUIDE.md)를 확인합니다. Windows 프로필 안에서 셸을 전환하며 네트워크 상태를 공유합니다.

- 예제 07 IOS 스위치, 08 IOS 라우터, 09 FortiOS 정책·NAT, 10 FortiOS IPsec을 엽니다. 해당 장비가 먼저 선택됩니다. 통신 확인은 터미널 장비를 PC-A로 바꿔 수행합니다.
- 새 장비의 `권장 CLI`는 스위치·라우터에 IOS, 방화벽에 FortiOS, PC·서버에 Linux를 선택합니다. IOS의 새 빈 라우터는 포트가 shutdown 상태입니다. 예제는 미리 설정된 장비입니다.
- 기존 1.0.0-rc.1~rc.3 프로젝트는 Linux 프로필을 유지합니다. `프로필 교체`는 물리 포트·주소·유효한 경로를 유지하고 정책·논리 포트·시작 설정을 초기화합니다. 먼저 사본을 보관하세요.
- `?`는 현재 모드의 문법을 표시하고 Tab은 완성합니다. IOS의 `en`, `conf t`, `sh ip route`, `Gi...` 같은 유일한 축약을 지원하며 모호한 축약을 거부합니다. FortiOS 키워드는 전체 문법을 입력합니다.
- 화살표 이력은 장비별로 유지합니다. Ctrl+C는 해당 장비의 입력·현재 통신·미확정 편집을 중단합니다. 다른 장비의 통신은 계속합니다.
- 명령은 터미널 출력 다음 줄의 프롬프트에서 입력합니다. 화면의 빈 공간을 클릭하거나 키보드로 입력 위치에 이동한 뒤 Enter로 실행합니다. 긴 명령은 자동 줄바꿈되며 실행 버튼은 터미널 상단에 있습니다. 별도의 하단 입력창은 없습니다.
- Ctrl+L은 화면만 지우고 입력 중인 명령을 유지합니다. 입력 문자 일부를 선택한 Ctrl+C는 복사로 사용하며, 선택하지 않은 Ctrl+C는 중단입니다. Shift+Tab으로 입력 위치를 벗어날 수 있습니다. 이력에서 아래 화살표로 돌아오면 작성 중이던 명령을 복원합니다. 과거 출력을 보려고 위로 스크롤한 상태는 새 상태 갱신에도 유지하고, 새 입력을 시작하면 현재 프롬프트로 이동합니다.
- 여러 줄 붙여넣기는 100줄, 한 줄 2048자까지 순서대로 실행합니다. 오류 줄에서 중지하며 앞선 유효 명령은 이미 적용된 상태입니다. 동기 조회의 출력 파이프를 지원하며 리다이렉션·셸 스크립트는 실행하지 않습니다.
- Linux의 `grep` 등 분석 도구, IOS의 `include/exclude/begin/section`, FortiOS의 `grep`, 모든 관리 장비의 공통 `lab` 도구는 [TOOLS-GUIDE.md](TOOLS-GUIDE.md)를 확인합니다. `lab help`와 터미널의 **분석 도구**에서도 안내합니다. 장비의 실제 셸을 여는 기능은 아닙니다.

## IOS 모드와 저장

`Device>`에서 `enable` → `Device#`에서 `configure terminal` → `(config)#`에서 `interface`, `vlan`, `ip access-list`로 하위 모드에 들어갑니다. `exit`는 상위 모드, `end`는 권한 EXEC 모드로 이동합니다. 일부 `do show ...`도 지원합니다. enable은 실습 내 권한 모드이며 실제 관리자 암호·AAA를 구현하지 않습니다.

```text
enable
configure terminal
hostname LAB-SW
vlan 10
name STUDENTS
exit
interface GigabitEthernet1/0/1
switchport mode access
switchport access vlan 10
no shutdown
end
show vlan brief
copy running-config startup-config
```

`show running-config`는 현재 설정, `show startup-config`는 재부팅에 사용할 설정입니다. `copy running-config startup-config`·`write memory`로 저장합니다. copy의 파일명 확인·파일시스템은 단순화했습니다. `reload`는 미저장 변경이 있으면 yes/no를 묻고 이어 `[confirm]`에서 빈 Enter를 받아 시작 설정으로 돌아갑니다. 다른 응답으로 취소할 수 있습니다.

**WordPress 서버 저장·자동 저장은 실행 설정과 시작 설정을 함께 보관하며 장비 저장 명령을 대신하지 않습니다.** 페이지 재개는 실행 설정을 열고 콘솔 모드를 초기화합니다. 상단 실행 초기화는 현재 설정을 유지하고 패킷·캐시·시간을 초기화합니다. 장비 재부팅은 시작 설정을 복원합니다.

## IOS 스위치

| 모드 | 지원 문법 |
| --- | --- |
| EXEC | `show interfaces [포트]`, `show ip interface brief`, `show vlan brief`, `show mac address-table`, `clear mac address-table dynamic` |
| 전역 | `vlan ID`, `no vlan ID`, `interface 포트`, `interface VlanID`, `ip routing`, `no ip routing` |
| VLAN | `name 이름` |
| 인터페이스 | `description 설명`, `no description`, `shutdown`, `no shutdown`, `switchport`, `no switchport`, `switchport mode access|trunk` |
| VLAN 포트 | `switchport access vlan ID`, `switchport trunk native vlan ID`, `switchport trunk allowed vlan 목록|all|none`, `switchport trunk allowed vlan add|remove 목록` |
| SVI·L3 포트 | `ip address IP MASK`, `no ip address` |

가상 스위치는 물리 포트 6개가 기본이며 실제 Catalyst의 포트 재고·예약 VLAN·하드웨어를 재현하지 않습니다. 포트는 `Gi1/0/1` 형태입니다. access VLAN·native VLAN·태그·허용 목록이 전달에 반영됩니다. access VLAN 설정은 VLAN을 생성하며 trunk VLAN은 별도 생성합니다. 명시 목록은 최대 64개입니다. all에서 add/remove를 쓰려면 먼저 명시 목록을 지정합니다.

SVI는 VLAN이 존재하고 연결된 활성 member 포트가 있어야 up이 됩니다. ip routing을 켜면 SVI 사이의 IPv4 라우팅을 제공합니다. 물리 스위치 포트의 IP는 먼저 no switchport가 필요합니다. DTP·VTP·STP·LACP·port-security는 미지원이며 L2 루프는 사건 한도로 중지합니다.

## IOS 라우터·ACL·NAT

라우터의 포트 이름은 `Gi0/0` 형태입니다. 실제 제조사 모델의 포트 재고와 다르므로 `show ip interface brief`로 확인하세요.

- 주소·상태: `interface Gi0/0` → `ip address 192.168.10.1 255.255.255.0` → `no shutdown`.
- 경로: `ip route NET MASK GATEWAY|포트`, 같은 명령 앞의 `no`로 삭제합니다. 미해결 next-hop은 설정에 남고 활성 경로에서 빠집니다. 재귀 next-hop·ECMP·VRF는 미지원입니다. OSPF/BGP 지원 범위는 아래 확장 절을 확인합니다.
- 서브인터페이스: `interface Gi0/0.10`, `encapsulation dot1Q 10`, IP 설정으로 router-on-a-stick을 구성합니다. 캡슐화 전에는 활성화되지 않습니다. native 서브인터페이스·QinQ는 미지원입니다.
- ACL: `ip access-list standard NAME` 또는 `ip access-list extended NAME`. 하위에서 `[순서] permit|deny ...`, `no 순서`를 사용합니다. standard는 출발 주소, extended는 `ip|icmp|tcp|udp|ospf 출발 도착 [eq 포트]`를 지원합니다. 포트 조건은 TCP·UDP만 허용합니다. OSPF와 TCP 179 필터는 교육용 이웃 성립 조건에도 반영됩니다.
- ACL 주소는 `any`, `host IP`, `IP WILDCARD` 형식이며 연속된 범위의 wildcard만 지원합니다. 예: `10 deny icmp any any`, `20 permit tcp 192.168.10.0 0.0.0.255 host 192.168.20.10 eq 80`.
- 인터페이스에서 `ip access-group NAME in|out`, `no ip access-group NAME in|out`으로 연결합니다. 정의한 ACL만 연결할 수 있습니다. 낮은 순서부터 검사하고 나머지는 implicit deny입니다. 상태 비저장 필터이며 inbound는 DNAT 전, outbound는 SNAT 전에 적용합니다. `show access-lists`는 일치 횟수를 표시합니다.
- 번호형 `access-list 1~99 permit|deny 출발`, `access-list 100~199 permit|deny 프로토콜 출발 도착`도 지원합니다.
- NAT/PAT: 인터페이스의 `ip nat inside|outside`, 전역의 `ip nat inside source list ACL interface 포트 overload`. 같은 명령 앞의 no로 해제합니다. inside→outside 전달에 적용합니다.
- 정적 포트 전달: `ip nat inside source static tcp|udp 내부IP 내부포트 외부IP 외부포트`. NAT pool·route-map·정적 1:1 NAT는 미지원입니다.
- 조회: `show ip route`, `show ip arp`, `show ip nat translations`, `show interfaces`, `show version`. 진단은 `ping IP`, ICMP 기반 `traceroute IP`입니다. extended ping·TCP traceroute·모든 show 옵션은 미지원입니다.

## FortiOS 설정과 정책

물리 포트는 port1·port2·port3입니다. 주소 없는 빈 가상 템플릿을 제공하며 실물 장비의 초기 관리 IP·포트 구성을 재현하지 않습니다.

```text
config firewall policy
    edit 0
        set name "ALLOW-PING"
        set srcintf "port1"
        set dstintf "port2"
        set srcaddr "all"
        set dstaddr "all"
        set service "PING"
        set schedule "always"
        set action accept
        set nat enable
        set logtraffic all
    next
end
```

edit 0은 새 번호를 배정합니다. set은 편집 사본을 바꾸고 next는 항목을 확정해 테이블로, end는 확정해 루트로 돌아갑니다. abort는 현재 미확정 편집만 취소합니다. 이미 next로 적용한 다른 항목은 남습니다. 필수 참조가 빠지면 확정을 거부하며 편집을 계속할 수 있습니다.

| config 대상 | 지원 필드 |
| --- | --- |
| `system interface` | 기존 포트 edit, ip IP MASK, status up/down, alias, mode static, allowaccess ping; ip/alias/allowaccess/status unset |
| `system global` | hostname, cfg-save automatic/manual |
| `router static` | dst NET MASK, gateway IP, device 포트, distance 1~255 |
| `firewall address` | subnet NET MASK |
| `firewall service custom` | tcp-portrange 또는 udp-portrange의 단일 포트·연속 범위 하나 |
| `firewall policy` | name, srcintf/dstintf 각 하나 또는 any, srcaddr/dstaddr/service 목록, action accept/deny, nat·status enable/disable, schedule always, logtraffic all/disable |

객체 이름은 영문·숫자·공백·점·밑줄·하이픈의 지원 범위입니다. 주소 all, 서비스 ALL·PING·HTTP·HTTPS·DNS(UDP 53)·SSH는 매칭용 기본 객체입니다. 서비스 이름 HTTPS·SSH는 포트 조건이며 실제 TLS·SSH를 실행하지 않습니다. 참조 중 객체 삭제는 거부합니다. 테이블에서 `delete ID|이름`, 정책에서 `move ID before|after ID`를 제공합니다.

정책 목록의 첫 일치를 적용하고 나머지는 implicit deny입니다. 허용 세션의 응답을 통과시키며 정책 NAT는 SNAT/PAT입니다. 정책 변경 시 해당 장비의 세션을 초기화해 새 정책으로 검사합니다. 실물의 session-dirty·ASIC offload·UTM까지 재현하지 않습니다. VIP/DNAT·central NAT·VDOM·HA·SSL VPN은 미지원입니다.

조회: `show [config 대상]`, `get system status`, `get router info routing-table all`, `diagnose sys session list|clear`, `execute log display`. 로그는 최근 합성 관측입니다. `execute ping IP`, `execute traceroute IP`도 제공합니다. 장비 자체의 ping 수신은 인터페이스의 allowaccess ping이 필요하며 통과 정책과 별도로 처리합니다.

automatic은 항목 확정 시 저장 설정도 갱신합니다. manual은 실행 설정만 바꾸며 `execute cfg save`로 저장합니다. `execute reboot`·`execute cfg reload`의 y 확인은 저장 설정을 복원합니다. revert·저장 타이머는 미지원입니다.

## FortiOS IPsec 모델

예제 10에서 PC-A의 `ping 192.168.20.10` 후 FW-A의 `diagnose vpn tunnel list`, `diagnose vpn ike gateway list`를 조회합니다. 초기 키는 가상 값 sim-ipsec-only입니다.

- `config vpn ipsec phase1-interface`: edit 터널이름, set interface port2, remote-gw IP, ike-version 2, proposal aes256-sha256/aes128-sha256, dhgrp 14/19, psksecret 가상키, keylife 초.
- `config vpn ipsec phase2-interface`: edit 이름, set phase1name 터널이름, src-subnet NET MASK, dst-subnet NET MASK, proposal, pfs enable/disable, keylifeseconds 초.
- 상대편은 반대 src/dst 선택자와 같은 가상 PSK·proposal·DH/PFS를 설정합니다. 터널 이름은 달라도 됩니다. phase1 확정 시 해당 가상 IPsec 포트가 생깁니다. 정적 경로의 device를 이 포트로 지정하고 LAN↔IPsec 정책을 각각 만듭니다. WAN peer 경로가 터널로 돌아가면 연결하지 않습니다.
- 고정 IPv4 피어·물리 WAN, IKEv2 PSK, 피어당 터널 하나와 대칭 선택자 한 쌍을 지원합니다. 수명은 60~86400초입니다. IKE_SA_INIT → IKE_AUTH → CHILD_SA, 제한된 재시도·시간 초과, ESP 전달·종단 복원·단순 재전송 차단, 만료 후 새 통신의 재협상을 계산합니다.
- **AES·DH·HMAC·실제 암호화·보안 인증은 구현하지 않습니다.** 모델 인증값은 학습용 비교값입니다. 가상 키만 사용하세요. 설정 조회·프로젝트 내보내기에 가상 PSK가 포함됩니다.
- 외부 관측에는 outer UDP 500·ESP 주소, 종단 decap에는 내부 주소를 표시합니다. NAT-T·UDP 4500·인증서·EAP·DPD·상시 keepalive·정밀 rekey·복수 CHILD_SA·실제 replay window는 미지원입니다. 주소 없는 IPsec 포트에 출발 NAT를 켜면 실패합니다.

## 검증과 한계

명령은 실제 가상 설정과 통신 결과를 바꾸며 미지원 문법은 거부합니다. CLI 모드·이력·세션·패킷·통계는 프로젝트와 구분해 재개 시 새로 시작합니다. GUI는 해당 장비의 캐시·세션을 갱신하고 다른 장비와 시간을 유지합니다. 장비 삭제는 진행 중 통신을 취소합니다.

명령→통신 결과→저장→재부팅과 기존 자료 호환을 자동 검사합니다. 실제 Cisco/Fortinet 장비 또는 정식 펌웨어와 전 명령을 대조하지 않았습니다. IPv6·OSPF/BGP는 아래 명시된 부분 모델이며 실제 수렴 시간·물리 성능과 제조사 예외 동작의 동일성을 보장하지 않습니다.

## IPv6: Linux Ethernet

`ipv6` 예제는 PC-A, Router-A, Server-B의 선만 연결된 상태입니다. IPv6 주소는 없습니다. Linux PC·서버·라우터에서 다음 문법을 지원하며 설정 창의 IPv6 항목도 같은 설정을 편집합니다.

```text
ip -6 addr add 2001:db8:10::10/64 dev eth0
ip -6 addr show
ip -6 addr del 2001:db8:10::10/64 dev eth0
ip -6 addr flush dev eth0
ip -6 route add default via 2001:db8:10::1 dev eth0
ip -6 route replace default via fe80::1 dev eth0 metric 1024
ip -6 route del default
ip -6 route show
ip -6 neigh show
ip -6 neigh flush
sysctl -w net.ipv6.conf.all.forwarding=1
ping -6 -c 1 -s 1200 2001:db8:20::10
traceroute -6 -I 2001:db8:20::10
ip link set dev eth1 mtu 1280
```

포트당 IPv6 주소는 최대 8개입니다. IPv6가 설정된 포트의 링크 로컬 주소는 학습용 EUI-64 규칙으로 생성하며 실제 운영체제의 개인정보 보호 주소 생성 방식과는 다릅니다. 링크 로컬 목적지는 `fe80::주소%eth0`처럼 범위를 지정합니다. NS/NA를 프레임으로 전송하고 이웃 캐시는 30초 모델 수명 후 다시 확인합니다. DAD·RA·SLAAC는 자동 실행하지 않으므로 중복 검출을 보증하지 않습니다.

Echo 전체 크기는 40+8+`-s` 바이트입니다. 라우터 출구 MTU 초과는 Packet Too Big으로 반환하고, 출발지 포트에서부터 너무 크면 로컬 오류가 됩니다. 자동 단편화·경로 MTU 캐시는 없으므로 크기를 직접 줄여 다시 시험합니다. IPv4와 IPv6 전달 스위치는 별개입니다. IPv6 방화벽·응용 서비스는 미지원입니다.

## OSPFv2와 eBGP: IOS 라우터

`ospf`·`bgp` 예제는 R1–R2–R3 삼각형과 양끝 LAN의 IPv4 주소까지 준비합니다. 동적 라우팅은 비어 있습니다. `enable` → `configure terminal` 뒤 아래 문법을 사용하고, 하위 모드에서 `exit`로 전역 설정으로 돌아갑니다.

```text
router ospf 1
router-id 1.1.1.1
network 10.0.12.0 0.0.0.3 area 0
passive-interface Gi0/0
no passive-interface Gi0/0
exit
interface Gi0/1
ip ospf cost 10
end
show ip ospf neighbor
show ip ospf database
show ip ospf interface
show ip route
```

network는 목적지 목록이 아니라 로컬 주소가 범위에 들어오는 포트를 영역에 넣는 설정입니다. 겹치는 범위는 가장 긴 접두사를 적용하는 학습 모델입니다. 프로세스 ID는 이웃끼리 같을 필요가 없고 같은 링크의 영역·주소 범위·마스크·MTU는 맞아야 합니다. Router ID는 모델 전체에서 중복되지 않아야 합니다. 기본 비용 10은 자동 대역폭 계산값이 아닙니다. `no network NET WILDCARD area AREA`, `no ip ospf cost`, `no router ospf 1`로 제거합니다. 직접 케이블로 연결된 라우터 간 일대일 인접 관계만 지원하며 DR/BDR·영역 간 요약은 구현하지 않습니다.

```text
configure terminal
router bgp 65001
bgp router-id 1.1.1.1
neighbor 10.0.12.2 remote-as 65002
neighbor 10.0.12.2 weight 200
network 192.0.2.0 mask 255.255.255.0
neighbor 10.0.12.2 shutdown
no neighbor 10.0.12.2 shutdown
end
show ip bgp summary
show ip bgp
show ip route
copy running-config startup-config
```

양쪽 remote-as와 링크 조건이 맞아야 Established가 됩니다. network는 정확히 같은 접두사가 로컬 연결·정적·OSPF 경로에 있을 때만 광고합니다. 자기 AS가 포함된 수신 경로는 제외합니다. 선택 기준은 Weight가 큰 경로, AS_PATH가 짧은 경로, 이웃 IPv4가 작은 경로 순이며 전체 제조사 best-path 알고리즘이 아닙니다. Weight는 다른 라우터에 전달되지 않습니다. 학습 모델은 명시한 피어에 선택 경로를 재광고하며 운영망의 접두사 필터·RPKI·명시적 수출입 정책을 대신하지 않습니다.

`no network NET mask MASK`, `no neighbor IP`, `no router bgp AS`를 지원합니다. 변화·철회는 가상 500ms 수렴 사건에서 반영되므로 자동 진행이 끝난 뒤 show와 ping을 비교합니다. C/S/O/B로 출처를 구분하고 같은 접두사는 연결 0·정적 1·eBGP 20·OSPF 110 순의 학습용 관리 거리로 선택합니다. 최장 접두사 일치가 거리보다 먼저입니다. 원본 프로젝트·시작 설정은 보존하며 학습 경로·캐시는 불러올 때 다시 계산합니다. 실제 타이머·LSA 패킷·TCP 179, OSPFv3·iBGP·MP-BGP·정책 route-map은 미지원입니다.

공식 참고: [Cisco IOS XE CLI](https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst9300/software/release/17-12/command_reference/b_1712_9300_cr/using_the_command_line_interface.html), [FortiOS CLI](https://docs.fortinet.com/document/fortigate/7.4.5/administration-guide/896276/cli-basics), [FortiOS 저장](https://docs.fortinet.com/document/fortigate/7.4.5/administration-guide/228450/using-configuration-save-mode), [FortiOS 정책](https://docs.fortinet.com/document/fortigate/7.4.5/administration-guide/656084/firewall-policy).

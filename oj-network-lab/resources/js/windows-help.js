export const WINDOWS_HELP = `Windows CMD / PowerShell · 가상 IPv4 호스트
같은 PC·서버에서 cmd / powershell 명령 또는 셸 선택으로 전환합니다.
CMD: ipconfig [/all|/displaydns|/flushdns|/renew [어댑터]|/release [어댑터]]
ping [-n 1~4] 주소 / tracert [-d] [-h 1~8] 주소 / nslookup 이름 [DNS주소]
arp -a / arp -d IP|* / route print / route add 망 mask 마스크 게이트웨이 [metric N] [if N] / route delete 망
netstat [-a -n -o -r] / getmac / hostname / ver / cls / echo / dir / cd / type 가상파일
조회 출력 | findstr [/I /V /N /X /L /R] [/C:패턴] 패턴
PowerShell 조회: Get-NetAdapter / Get-NetIPConfiguration [-All] / Get-NetIPAddress
Get-NetIPInterface / Get-NetRoute / Get-NetNeighbor / Get-NetTCPConnection
Get-DnsClientServerAddress / Get-NetConnectionProfile / Get-NetFirewallProfile / Get-NetFirewallRule
Get-NetFirewallPortFilter / Get-NetFirewallAddressFilter / Get-Content / Get-ChildItem / Get-Location / Get-History
주소: New-NetIPAddress -InterfaceAlias Ethernet -IPAddress IPv4 -PrefixLength N [-DefaultGateway IPv4]
Set-NetIPAddress -InterfaceAlias Ethernet -IPAddress 기존IPv4 -PrefixLength N / Remove-NetIPAddress -InterfaceAlias Ethernet
Set-NetIPInterface -InterfaceAlias Ethernet -Dhcp Enabled|Disabled [-InterfaceMetric N]
Enable-NetAdapter|Disable-NetAdapter -Name Ethernet
New-NetRoute -DestinationPrefix CIDR -InterfaceAlias Ethernet -NextHop IPv4 [-RouteMetric N]
Set-NetRoute -DestinationPrefix CIDR -RouteMetric N / Remove-NetRoute -DestinationPrefix CIDR
Set-DnsClientServerAddress -InterfaceAlias Ethernet -ServerAddresses IPv4 또는 -ResetServerAddresses
Set-NetConnectionProfile -InterfaceAlias Ethernet -NetworkCategory Public|Private
Resolve-DnsName 이름 [-Server IPv4] / Test-NetConnection 주소 [-Port N|-TraceRoute] [-InformationLevel Quiet|Detailed]
방화벽: New-NetFirewallRule -DisplayName 이름 [-Name ID] -Direction Inbound|Outbound -Action Allow|Block
  [-Protocol TCP|UDP|ICMPv4|Any] [-LocalPort 포트·범위] [-RemotePort 포트·범위]
  [-LocalAddress IP·CIDR·LocalSubnet·Any] [-RemoteAddress IP·CIDR·LocalSubnet·Any]
  [-Profile Public,Private|Any] [-Enabled True|False] [-IcmpType 8] [-InterfaceAlias Ethernet]
Set-NetFirewallRule / Enable-NetFirewallRule / Disable-NetFirewallRule / Remove-NetFirewallRule -Name ID
Set-NetFirewallProfile [-Profile Domain,Private,Public] [-Enabled True|False] [-DefaultInboundAction Allow|Block] [-DefaultOutboundAction Allow|Block]
변경 명령은 -WhatIf를 지원합니다. 삭제·비활성화는 대상을 지정합니다. -Confirm:$false 지원.
객체 조회 | Where-Object { $_.속성 -eq 값 } | Select-Object 속성1,속성2
Sort-Object -Property 속성 / Measure-Object / Format-Table / Format-List / Out-String / Select-String 패턴
가상 파일은 C:\\Lab 아래에서 읽습니다. 공통 lab 도구는 /config·/state 등의 경로를 사용합니다.
Windows 프로그램·.NET·스크립트·원격 실행·실제 파일 쓰기는 실행하지 않습니다. 네트워크 조회의 동기 출력만 파이프로 연결합니다.
기본 Public 방화벽: 인바운드 Block·아웃바운드 Allow·DHCP 응답 허용 규칙. 명시적 Block이 Allow보다 우선합니다.
IPv4 주소·DNS는 인터페이스당 하나, 필터 8단계·텍스트/객체/패턴·작업 예산을 제한합니다. 상세 범위는 WINDOWS-GUIDE.md를 확인하세요.`;

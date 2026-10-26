# 번들 데이터 출처 확인 기록 — 2026-09-08

검토 대상은 `resources/sample-datasets/`의 CSV 29개다. **29개 모두 고정 commit의 upstream 파일과 대응을 확인했다. 25개는 원본 바이트가 같고, 4개는 CP949를 UTF-8로 변환하면 같다. 재배포 권리 확인은 완료되지 않았다.** 이 문서는 출처 조사 결과이며 배포 승인서가 아니다. 자체 코드 라이선스 결정은 사용자 요청대로 보류한다.

## 확인 방법과 범위

GitHub 공개 API에서 각 저장소 기본 브랜치의 commit과 전체 트리(`truncated=false`)를 조회했다. 해당 commit의 raw CSV를 HTTPS로 읽어 SHA-256 및 전체 바이트를 로컬 파일과 비교했다. 네 KOSIS/MDIS 파일은 오류를 무시하지 않는 `raw.decode('cp949').encode('utf-8')` 결과와 비교했다. 행·열·개행을 변경하지 않았다. 같은 파일은 EUC-KR 디코딩으로도 일치하므로 과거 실제 변환 도구·명칭까지 특정한 것은 아니다.

로컬 파일 29개 모두 기존 [dataset-inventory.json](dataset-inventory.json)의 바이트 수·SHA-256과 일치한다. 아래 commit은 **이번에 일치를 확인한 revision**이며 최초 수집 시점의 revision을 입증하지 않는다. 네트워크에서 읽은 CSV는 메모리에서 비교했으며 플러그인 코드·원본 CSV·기존 인벤토리·라이선스는 변경하지 않았다.

## 고정 저장소 revision

| 기호 | 저장소와 확인 commit | 저장소 수준 선언 확인 |
|---|---|---|
| S | [mwaskom/seaborn-data](https://github.com/mwaskom/seaborn-data/tree/71e2436a092d714350de0fc409ca8a8714e7e78f) — `71e2436a092d714350de0fc409ca8a8714e7e78f` | GitHub license 값 null; 트리에 LICENSE/COPYING/NOTICE 파일 없음. README에서 공통 데이터 라이선스 선언 미확인. |
| O | [corazzon/OpenDataWrangling](https://github.com/corazzon/OpenDataWrangling/tree/804772172a6288597b43d2160c92eacbb8bd067f) — `804772172a6288597b43d2160c92eacbb8bd067f` | GitHub license 값 null; 트리에 LICENSE/COPYING/NOTICE 파일 없음. README에서 공통 데이터 라이선스 선언 미확인. |
| C | [corazzon/COVID-19-eda](https://github.com/corazzon/COVID-19-eda/tree/ba8d70a923685545c5c3e6a094125ef2ca169197) — `ba8d70a923685545c5c3e6a094125ef2ca169197` | GitHub license 값 null; 트리에 LICENSE/COPYING/NOTICE 파일 없음. README에서 공통 데이터 라이선스 선언 미확인. |
| K | [corazzon/kostat-eda](https://github.com/corazzon/kostat-eda/tree/1502f8c70ac1dcc0788ac8b18427a0ea49e9daf6) — `1502f8c70ac1dcc0788ac8b18427a0ea49e9daf6` | GitHub license 값 null; 트리에 LICENSE/COPYING/NOTICE 파일 없음. README에서 공통 데이터 라이선스 선언 미확인. |
| F | [corazzon/finance-data-analysis](https://github.com/corazzon/finance-data-analysis/tree/48431d8094962329643963ce2a1d983ca77e0e87) — `48431d8094962329643963ce2a1d983ca77e0e87` | README에 CC BY-NC-SA 배지. 버전·CSV 적용 범위는 미확인. |

GitHub의 `license: null`이나 라이선스 파일 부재는 이용허락 부재의 최종 판정이 아니다. 실제 README도 확인했으며 F의 배지는 그 과정에서 발견했다. [Seaborn README](https://github.com/mwaskom/seaborn-data/blob/71e2436a092d714350de0fc409ca8a8714e7e78f/README.md)는 예제용 저장소임을 설명하며 일부 데이터가 원본에서 수정됐음을 알린다. 라이브러리 코드의 라이선스를 CSV 전체에 적용하지 않는다.

## 파일별 동일성

`원본 일치`는 raw SHA-256과 아래 로컬 SHA-256이 같음을 뜻한다. `변환 후 일치`인 네 파일의 원본 SHA-256은 다음 표에 별도로 적었다. 파일 링크는 모두 위 고정 commit의 raw 파일이다. **이 표의 29개 모두 재배포 검토 상태는 pending이며, 선언된 조건과 남은 확인은 다음 절을 따른다.**

| 번들 파일 | upstream 파일 | 비교 결과 | 로컬 SHA-256 |
|---|---|---|---|
| `anagrams.csv` | S: [anagrams.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/anagrams.csv) | 원본 일치 | `b482ed07f06c201f83ce9c44c24a33e6e413195e01d45f34ca65f7f6b22fb8d3` |
| `anscombe.csv` | S: [anscombe.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/anscombe.csv) | 원본 일치 | `a0c1f636aa0347101de76271e7efe4c86a22ef28cda62886eaff23a1bf1924b1` |
| `attention.csv` | S: [attention.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/attention.csv) | 원본 일치 | `5c1de4b2a7cb7a9521145074815e0f3824f2d11786e72fc843f6fcb24701bc19` |
| `brain_networks.csv` | S: [brain_networks.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/brain_networks.csv) | 원본 일치 | `538dd9952e03837a78242494cee2583c3eaf3fe5978a60ebad05235bc60a0c82` |
| `car_crashes.csv` | S: [car_crashes.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/car_crashes.csv) | 원본 일치 | `78ac44c0f6d407bda2d646a65447d119994d34afa11bb9f689833031bc4869c7` |
| `diamonds.csv` | S: [diamonds.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/diamonds.csv) | 원본 일치 | `9574730b03aba241d899c4a97511c5061b19358fab89510774fb6c24168345c4` |
| `dots.csv` | S: [dots.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/dots.csv) | 원본 일치 | `dd8ed5e18358ec23250ecc877c98d0212c0419d0152e1bf5387b20341059842b` |
| `dowjones.csv` | S: [dowjones.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/dowjones.csv) | 원본 일치 | `8b1bc96432981689eb6d00de1909fb1f61aa82064418a39104ec186dfd22c539` |
| `exercise.csv` | S: [exercise.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/exercise.csv) | 원본 일치 | `d67ff5896d7d262bba3ec0bd7a8db410e56afc96a0436e08dfe53016d5f1e4e6` |
| `flights.csv` | S: [flights.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/flights.csv) | 원본 일치 | `237d834127d9c6355630d8f443a7a2377b5925923010009b59809ba0b67f4fac` |
| `fmri.csv` | S: [fmri.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/fmri.csv) | 원본 일치 | `8a0bfdce94daa31c95ae9f49ca6a2a3ac39e2fe85719c892cb0b06bca94ffe3e` |
| `geyser.csv` | S: [geyser.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/geyser.csv) | 원본 일치 | `ce8f6bd15967c9a3dee345aaf268f6b92623abb1e1d313e04d79b720aa6b8bd6` |
| `glue.csv` | S: [glue.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/glue.csv) | 원본 일치 | `0c6b840da0a2c7fba3422d3b6867492bc8e61dd99dec7a276f6108774c81744e` |
| `healthexp.csv` | S: [healthexp.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/healthexp.csv) | 원본 일치 | `ba4178979b7b0c0f0f793fe7999b3e2303cd6e47a545b1957a2501cbc2ca2b62` |
| `iris.csv` | S: [iris.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/iris.csv) | 원본 일치 | `9cc1c345c71bcc9b486b74cbf6063fa66f4bb5e0f603a4b3c3471ec2e5e8e355` |
| `kosis_airline_company.csv` | O: [data/kosis-airline-company.csv](https://raw.githubusercontent.com/corazzon/OpenDataWrangling/804772172a6288597b43d2160c92eacbb8bd067f/data/kosis-airline-company.csv) | CP949→UTF-8 변환 후 일치 | `d12f3ac6f66329c1296c06624e694d0363a25c68c3b36f06ab1c521a8f60ed97` |
| `kosis_customer_price.csv` | K: [kosis_customer_price_2015100.csv](https://raw.githubusercontent.com/corazzon/kostat-eda/1502f8c70ac1dcc0788ac8b18427a0ea49e9daf6/kosis_customer_price_2015100.csv) | CP949→UTF-8 변환 후 일치 | `23e79fa5d70a90ddacabecc40e62208bb86f17e258dc2df5892965b593bdd343` |
| `kosis_economic_index.csv` | O: [data/kosis-economic-index.csv](https://raw.githubusercontent.com/corazzon/OpenDataWrangling/804772172a6288597b43d2160c92eacbb8bd067f/data/kosis-economic-index.csv) | CP949→UTF-8 변환 후 일치 | `b6dc78a55b943195415bbfebbd8925d0864196f16f03a185081a7559e50a7250` |
| `kosis_job_seeker_giveup.csv` | K: [mdis_give_up_job_seeker.csv](https://raw.githubusercontent.com/corazzon/kostat-eda/1502f8c70ac1dcc0788ac8b18427a0ea49e9daf6/mdis_give_up_job_seeker.csv) | CP949→UTF-8 변환 후 일치 | `2974f942296cdaa36a3f94ccb6fcceec658fc7ddffdf2af5e2314fad51beffde` |
| `krx_listed_companies.csv` | F: [krx.csv](https://raw.githubusercontent.com/corazzon/finance-data-analysis/48431d8094962329643963ce2a1d983ca77e0e87/krx.csv) | 원본 일치 | `48d7a9fcd9014c2781f6f73cfc01c6e193d9186d6671519e19d268e5412e5aa0` |
| `mpg.csv` | S: [mpg.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/mpg.csv) | 원본 일치 | `c14b8b855ea7ee86cb9736bf8caaf281c4685ca08826f3eb2acaccaaf40f0d5a` |
| `penguins.csv` | S: [penguins.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/penguins.csv) | 원본 일치 | `e07636bd8af74260099ea2f8678e2eabbf35def579940cc76f67061ee16c06c1` |
| `planets.csv` | S: [planets.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/planets.csv) | 원본 일치 | `a6d10044887e17396974525a366f5fa2e4b34df70f491e64eb9943de0e3d3825` |
| `seaice.csv` | S: [seaice.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/seaice.csv) | 원본 일치 | `a6ea8fad59199919f3ab3ece99b46dc7484e58824f30af2924316205b411e509` |
| `seoul_bike_rental_gu.csv` | O: [seoul-bike/data/bike_rent_station_gu.csv](https://raw.githubusercontent.com/corazzon/OpenDataWrangling/804772172a6288597b43d2160c92eacbb8bd067f/seoul-bike/data/bike_rent_station_gu.csv) | 원본 일치 | `c4d7ed34afa5fd2f00e7f0e127e0acc959f57691bc2b8dc2f7e966a1208d8997` |
| `seoul_covid19.csv` | C: [covid-19-seoul.csv](https://raw.githubusercontent.com/corazzon/COVID-19-eda/ba8d70a923685545c5c3e6a094125ef2ca169197/covid-19-seoul.csv) | 원본 일치 | `571c30fda7b2f9e814ba3b65a6e7cf624d66b6ed7c18ddf80e3464b419d785ee` |
| `taxis.csv` | S: [taxis.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/taxis.csv) | 원본 일치 | `08d6d71784dbaa2651fee37fc03389754194c05d72d2d19cbc2c799dea6ac09d` |
| `tips.csv` | S: [tips.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/tips.csv) | 원본 일치 | `e54cc4d2ce1bff65d32ca60b3e4b802e06bde1d7e7caf6f796f6bf7370e863b0` |
| `titanic.csv` | S: [titanic.csv](https://raw.githubusercontent.com/mwaskom/seaborn-data/71e2436a092d714350de0fc409ca8a8714e7e78f/titanic.csv) | 원본 일치 | `81787d320d7f7b03df935e91de8bd19e11d45c5bbcab86ef4d4a76dc91b7d4f2` |

| 변환된 번들 파일 | 변환 전 upstream SHA-256 |
|---|---|
| `kosis_airline_company.csv` | `6c01ad214008ab87408e292db7d699847e206e526f660225abb2478e43d41c02` |
| `kosis_customer_price.csv` | `29798dd79098b0679ab1bced35d0ddff083d38f4c691c074ca1292584728cd58` |
| `kosis_economic_index.csv` | `cb061021a3c74c3675326d02565b892fb9f1e05e5581e4ad7aebe564242fef8b` |
| `kosis_job_seeker_giveup.csv` | `34afbce845e4201c0fadb37b7e0114cd29aaa56ab436329983f4879a837d335e` |

## 선언된 조건과 남은 권리 확인

| 대상 | 이번에 직접 확인한 근거 | 남은 확인 |
|---|---|---|
| `iris.csv` | [UCI Iris](https://archive.ics.uci.edu/dataset/53/iris)는 데이터에 CC BY 4.0을 선언한다. | UCI 버전과 seaborn 변환 대응, 저자·DOI·변경 고지 정리. UCI 원본과의 전체 내용 비교는 미실시. |
| `penguins.csv` | [palmerpenguins 공식 페이지](https://allisonhorst.github.io/palmerpenguins/)는 데이터에 CC0를 선언하고 Gorman 및 Palmer Station LTER 출처와 인용·이용 안내를 제공한다. | 원자료 버전 및 seaborn의 열/값 변환 대응과 출처 고지 정리. 원자료와의 전체 내용 비교는 미실시. |
| `car_crashes.csv` | [FiveThirtyEight README](https://github.com/fivethirtyeight/data/blob/master/README.md)는 별도 고지가 없으면 데이터에 CC BY 4.0, 코드에 MIT를 적용한다고 구분한다. [bad-drivers 원자료](https://github.com/fivethirtyeight/data/tree/master/bad-drivers)도 확인했다. | seaborn이 연결한 Kaggle 사본·원자료·변환의 대응, 별도 예외와 원자료 제공기관 조건 확인, 출처 고지 정리. |
| `fmri.csv` | [연구자 분석 저장소](https://github.com/mwaskom/Waskom_CerebCortex_2017)는 README에 코드용 BSD 3항 형태의 조건을 제시한다. | 데이터에도 해당 허락이 적용되는지, 원자료와 변환·인용 조건 확인. 코드 조건을 데이터 허락으로 확정하지 않음. |
| `krx_listed_companies.csv` | [직접 upstream README](https://github.com/corazzon/finance-data-analysis/blob/48431d8094962329643963ce2a1d983ca77e0e87/README.md)에 CC BY-NC-SA 배지가 있다. 법적 본문이나 버전은 지정하지 않고 일반 설명으로 연결한다. | 비상업·동일조건 표지의 정확한 버전과 CSV 적용 범위, 원기관 KRX 상장회사 자료의 별도 권리를 확인해야 함. 상용 재배포 가능으로 처리하지 않음. |
| `kosis_airline_company.csv`, `kosis_customer_price.csv`, `kosis_economic_index.csv` | upstream 노트북에서 KOSIS 통계표 링크 확인(아래 표). [KOSIS 이용지침](https://kosis.kr/serviceInfo/useGuide.do)은 국내 통계정보의 사용·재사용·재배포 및 상업적 활용을 허용하면서 출처 명시와 예외를 규정한다. 국제·북한 통계는 재배포 금지이며 가공 없는 유료 판매도 금지한다. | 각 CSV와 통계표/조사명/작성기관/작성시점 대응, 실제 수집일과 가공 내역, 예외 해당 여부 확인. 이번 조사일을 과거 원본 수집일로 기재하지 않음. |
| `kosis_job_seeker_giveup.csv` | 직접 원본은 `mdis_give_up_job_seeker.csv`이며 저장소 노트북도 **MDIS 경제활동인구조사 구직단념자**로 설명한다. [해당 저장소](https://github.com/corazzon/kostat-eda/tree/1502f8c70ac1dcc0788ac8b18427a0ea49e9daf6) | 원자료의 MDIS 제공 유형과 약정, 원자료/집계자료 구분, 재배포·재식별 금지 등의 적용 확인. KOSIS의 거시 통계정보 이용지침만으로 허락을 확정하지 않음. |
| `seoul_bike_rental_gu.csv` | [upstream README](https://github.com/corazzon/OpenDataWrangling/blob/804772172a6288597b43d2160c92eacbb8bd067f/README.md)는 대여 이력과 대여소 정보를 서로 다른 원천으로 연결한다. 구별 대여소 CSV는 위 고정본과 일치한다. | 해당 대여소 원천·수집시점·가공 및 구별 정보 결합 내역, 정확한 자료 항목에 대한 서울시/따릉이 이용조건 확인. 이력 자료 허락을 대여소 자료에 자동 적용하지 않음. |
| `seoul_covid19.csv` | [upstream README](https://github.com/corazzon/COVID-19-eda/blob/ba8d70a923685545c5c3e6a094125ef2ca169197/README.md)는 서울시 코로나19 현황 페이지를 수집 원천으로 표시한다. | 해당 시점 자료의 재배포 조건과 현재 공개 상태, 행 단위 정보의 운영상 공개 범위 확인. 과거 공개됐다는 사실만으로 현재 재배포를 승인하지 않음. |
| 나머지 seaborn 18개 | README에 일부 출처 링크가 있으며 개별 라이선스 검토는 미완료다. 아래 목록을 후속 원천 조사 대상으로 남긴다. | 원기관 자료별 이용허락·필수 고지·가공 내역 확인. `brain_networks`, `flights`는 README의 부분 출처 목록에도 항목이 없어 추가 추적 필요. |

### KOSIS 원본 후보 통계표

아래 링크는 고정 commit의 분석 노트북에서 추출했다. 실제 CSV의 모든 행·시점과 동일한 추출 결과인지는 아직 확인하지 않았다.

| 번들 파일 | 노트북이 연결하는 통계표 |
|---|---|
| `kosis_customer_price.csv` | [orgId=101, DT_1J17112](https://kosis.kr/statHtml/statHtml.do?orgId=101&tblId=DT_1J17112&conn_path=I2) |
| `kosis_airline_company.csv` | [orgId=381, DT_920005_B005](https://kosis.kr/statHtml/statHtml.do?orgId=381&tblId=DT_920005_B005&conn_path=I2), [DT_920005_B008](https://kosis.kr/statHtml/statHtml.do?orgId=381&tblId=DT_920005_B008&conn_path=I2); 회사/지역 파일 대응을 추가 확인할 것 |
| `kosis_economic_index.csv` | [orgId=101, DT_1C8015](https://kosis.kr/statHtml/statHtml.do?orgId=101&tblId=DT_1C8015&conn_path=I2) |

### 개별 조건 미확인 seaborn 18개

| 파일 | 후속 조사 원천(저장소 README의 출처 표시) |
|---|---|
| `anagrams.csv`, `attention.csv`, `exercise.csv` | [psych252](https://psych252.github.io/) |
| `anscombe.csv` | Anscombe quartet 원자료·수록물 추적 필요 |
| `brain_networks.csv`, `flights.csv` | README 부분 출처 목록에 없음 |
| `diamonds.csv` | [ggplot2 diamonds](https://ggplot2.tidyverse.org/reference/diamonds.html) |
| `dots.csv` | [Shadlen Lab](https://shadlenlab.columbia.edu/resources/RoitmanDataCode.html) |
| `dowjones.csv` | [FRED M1109BUSM293NNBR](https://fred.stlouisfed.org/series/M1109BUSM293NNBR) |
| `geyser.csv` | [R faithful](https://stat.ethz.ch/R-manual/R-devel/library/datasets/html/faithful.html) |
| `glue.csv` | [GLUE leaderboard](https://gluebenchmark.com/leaderboard) |
| `healthexp.csv` | [Our World in Data](https://ourworldindata.org/grapher/life-expectancy-vs-health-expenditure) |
| `mpg.csv` | [data.world cars-data](https://data.world/dataman-udit/cars-data) |
| `planets.csv` | [NASA exoplanet catalog](https://exoplanets.nasa.gov/exoplanet-catalog/) |
| `seaice.csv` | [NSIDC](https://nsidc.org/arcticseaicenews/sea-ice-tools/) |
| `taxis.csv` | [NYC TLC](https://www.nyc.gov/site/tlc/about/tlc-trip-record-data.page) |
| `tips.csv` | R reshape2 패키지의 tips 원자료·조건 확인 필요 |
| `titanic.csv` | [Kaggle Titanic](https://www.kaggle.com/c/titanic/data) |

## 운영·배포 판단에 넘길 사항

1. 출처 동일성 단계는 29/29 완료했다. 개별 재배포 승인 단계는 완료하지 않았다. 인벤토리의 `pending`을 `approved`로 변경할 근거로 이 조사만 사용하지 않는다.
2. 우선 확인 대상은 CC BY-NC-SA 배지가 있는 KRX 사본, MDIS 구직단념자 자료, 행 단위 서울 코로나19 자료다. 원기관·가공자에게 문의가 필요하면 운영자가 검토할 정확한 파일과 요청 범위를 위 고정본으로 제시할 수 있다. 이번 작업에서 외부에 문의를 보내지는 않았다.
3. 권리 확인이 끝나지 않은 샘플의 공개 배포 제외 또는 자체 제작 데이터로의 교체를 검토한다. 라이선스 결정 보류 상태에서 기존 코드·샘플 파일의 라이선스를 임의로 부여하지 않는다. 설치된 사용자 데이터의 삭제 근거로도 사용하지 않는다.

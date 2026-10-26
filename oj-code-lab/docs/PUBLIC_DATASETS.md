# 공개 배포용 샘플 데이터

검토일: 2026-09-09. 공개 릴리스 ZIP에 포함할 샘플 CSV는 `penguins.csv` 1개다. [public-datasets.json](public-datasets.json)의 allowlist가 배포 대상과 기대 SHA-256을 명시한다. 기존 로컬 CSV 29개와 이미 설치된 사용자 데이터는 삭제하지 않는다. 이 문서는 데이터의 기존 이용허락을 확인하며 OJ Code Lab 자체 코드에 라이선스를 부여하지 않는다.

## 배포 대상과 근거

| 항목 | 확인 결과 |
|---|---|
| 파일 | `resources/sample-datasets/penguins.csv` |
| 크기·구조 | 13,478바이트, 헤더를 제외한 344행, 7열 |
| 배포 파일 SHA-256 | `e07636bd8af74260099ea2f8678e2eabbf35def579940cc76f67061ee16c06c1` |
| 이용허락 | CC0 1.0 Universal (`CC0-1.0`) |
| 공식 저장소 revision | `8957207b78d6ccd1b4654a9dd9c9041b657478ab` |
| 공식 CSV SHA-256 | `f204db2c753b0937caac3cb35258562c14f073e4bbc76be24b4c51ce22767a93` |
| 공식 CSV 크기 | 15,241바이트 |
| 동일성 검증 | 아래 변환으로 기존 배포 파일의 전체 바이트 재현 |

공식 palmerpenguins 저장소의 같은 revision에서 [데이터 CC0 선언](https://github.com/allisonhorst/palmerpenguins/blob/8957207b78d6ccd1b4654a9dd9c9041b657478ab/README.md#license), [DESCRIPTION의 CC0 표기](https://github.com/allisonhorst/palmerpenguins/blob/8957207b78d6ccd1b4654a9dd9c9041b657478ab/DESCRIPTION), [CC0 법적 본문](https://github.com/allisonhorst/palmerpenguins/blob/8957207b78d6ccd1b4654a9dd9c9041b657478ab/LICENSE.md)을 확인했다. [본문 사본](licenses/palmerpenguins-CC0-1.0.md)은 원본 6,966바이트를 변경 없이 보존하며 SHA-256은 `8e2c443dd9aea6fcd6c293dbf66935bd5ef502fc0ca9b466c773cf9316d5e04c`다.

[Creative Commons의 CC0 설명](https://creativecommons.org/publicdomain/zero/1.0/)은 상업적 이용을 포함한 복제·변경·재배포를 허용한다. [GNU의 라이선스 목록](https://www.gnu.org/philosophy/license-list.html#CC0)은 CC0의 공공영역 선언과 보충적 허락을 GPL과 호환되는 것으로 설명한다. 이 확인은 데이터 파일에 관한 것이며 독립 플러그인 코드의 라이선스 선택과 별개다.

## 출처·변경 고지

원자료 제공: **Kristen B. Gorman 및 Palmer Station Antarctica LTER**. 교육용 palmerpenguins 패키지 작성: **Allison Marie Horst, Alison Presmanes Hill, Kristen B. Gorman**.

- 패키지: Horst AM, Hill AP, Gorman KB (2020), *palmerpenguins: Palmer Archipelago (Antarctica) penguin data*. [DOI 10.5281/zenodo.3960218](https://doi.org/10.5281/zenodo.3960218).
- 관련 연구: Gorman KB, Williams TD, Fraser WR (2014), *Ecological sexual dimorphism and environmental variability within a community of Antarctic penguins (genus Pygoscelis)*, PLoS ONE 9(3):e90081. [연구 DOI](https://doi.org/10.1371/journal.pone.0090081).
- 원자료 인용: Palmer Station Antarctica LTER and K. Gorman (2020), 2007–2009년 Palmer Archipelago 성체 펭귄 측정 자료. Environmental Data Initiative의 [Adélie ver. 5](https://doi.org/10.6073/pasta/98b16d7d563f265cb52372c8ca99e60f), [Gentoo ver. 5](https://doi.org/10.6073/pasta/7fca67fb28d56ee2ffa3d9370ebda689), [Chinstrap ver. 6](https://doi.org/10.6073/pasta/c14dfcfada8ea13a17536e73eb6fbe9e).

배포 파일은 공식 CSV의 `year` 열을 제외하고 `species` 순서로 안정 정렬한 형태다. `NA`는 빈 필드로, `sex` 값은 대문자로 나타냈다. 그 외 필드 값과 종별 행 순서는 보존한다. 아래 절차가 기존 seaborn 사본과 완전히 일치하므로 데이터 값이나 파일을 교체하지 않았다. 이 비교는 검토 시점에 변환을 재현한 기록이며 과거 실제 수집일·변환 도구를 입증하지 않는다. upstream 그림이나 로고는 포함하지 않는다.

공식 안내는 연구 결과물의 적절한 인용과 필요시 저자와의 협력을 권장한다. 연구·발표에 활용할 때 [palmerpenguins 이용 안내](https://allisonhorst.github.io/palmerpenguins/#additional-data-use-information)와 [LTER Data Access Policy](https://lternet.edu/data-access-policy/)를 함께 참고한다. 현재 LTER 정책은 데이터별 메타데이터가 CC BY 또는 CC0를 정할 수 있다고 설명하므로, 다른 LTER 자료 전체를 이 파일의 CC0 대상으로 확대하지 않는다.

## 변환·동일성 재현

플러그인 루트에서 Python 3로 실행한다. HTTPS에서 고정 revision을 읽어 메모리에서 비교하며 파일을 쓰지 않는다. 각 해시와 전체 바이트 일치를 모두 확인한다.

```python
from pathlib import Path
import csv
import hashlib
import io
from urllib.request import urlopen

revision = "8957207b78d6ccd1b4654a9dd9c9041b657478ab"
url = ("https://raw.githubusercontent.com/allisonhorst/palmerpenguins/"
       + revision + "/inst/extdata/penguins.csv")
with urlopen(url, timeout=30) as response:
    source = response.read()
assert hashlib.sha256(source).hexdigest() == (
    "f204db2c753b0937caac3cb35258562c14f073e4bbc76be24b4c51ce22767a93"
)
rows = list(csv.reader(io.StringIO(source.decode("utf-8"), newline="")))
assert len(rows) == 345 and rows[0][-1] == "year"
output = io.StringIO(newline="")
writer = csv.writer(output, lineterminator="\n")
writer.writerow(rows[0][:-1])
for row in sorted(rows[1:], key=lambda row: row[0]):
    fields = ["" if value == "NA" else value for value in row[:-1]]
    fields[-1] = fields[-1].upper()
    writer.writerow(fields)
expected = output.getvalue().encode("utf-8")
actual = Path("resources/sample-datasets/penguins.csv").read_bytes()
assert expected == actual
assert len(actual) == 13478
assert hashlib.sha256(actual).hexdigest() == (
    "e07636bd8af74260099ea2f8678e2eabbf35def579940cc76f67061ee16c06c1"
)
print("PASS: 344 rows; transformed source equals all local CSV bytes")
```

## 공개 ZIP에서 제외하는 28개

개발 저장소에 보관한 2026-09-08 출처 확인 기록(`docs/DATASET_PROVENANCE_2026-09-08.md`, 공개 ZIP 미포함)은 29개 파일의 upstream 동일성을 확인했으나 개별 재배포 조건은 미완료로 남겼다. 이번 검토는 `penguins.csv`의 조건과 변환 대응을 해결한다. 나머지 28개는 [allowlist의 excluded 목록](public-datasets.json)에 명시하며 공개 ZIP에서 제외한다. 배포 제외는 해당 자료가 반드시 사용 금지라는 판정이 아니다. 개별 허락·원자료 대응·필수 고지·GPL 호환성을 확인한 뒤 명시적으로 추가할 수 있다.

`dataset-inventory.json`은 기존 29개 로컬 파일의 최초 점검 기록으로 유지한다. 새 공개 배포 대상은 `public-datasets.json`을 기준으로 삼는다. 공개 패키지의 기본 샘플 복구는 패키지에 실제 포함된 펭귄 데이터만 복구하며, 기존 사이트의 수업 데이터 삭제나 자동 교체를 수행하지 않는다.

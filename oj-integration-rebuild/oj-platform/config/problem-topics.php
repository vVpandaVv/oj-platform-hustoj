<?php

declare(strict_types=1);

return [
    ['key' => 'implementation', 'label' => '구현', 'category' => null, 'aliases' => ['기초 구현']],
    ['key' => 'search', 'label' => '탐색', 'category' => null, 'aliases' => ['검색', 'search']],
    ['key' => 'data-structures', 'label' => '자료구조', 'category' => null, 'aliases' => ['해시']],
    ['key' => 'recursion', 'label' => '재귀와 점화식', 'category' => null, 'aliases' => ['recursive', '재귀 호출']],
    ['key' => 'graph', 'label' => '그래프', 'category' => null, 'aliases' => ['graph', '그래프 이론']],
    ['key' => 'math', 'label' => '수학', 'category' => null, 'aliases' => ['산술', '공식', 'mathematics']],

    ['key' => 'simulation', 'label' => '시뮬레이션', 'category' => 'implementation', 'aliases' => ['모의 실행', 'simulation']],
    ['key' => 'brute-force', 'label' => '완전 탐색', 'category' => 'implementation', 'aliases' => ['브루트포스', '전수 조사']],
    ['key' => 'backtracking', 'label' => '백트래킹', 'category' => 'implementation', 'aliases' => ['가지치기', 'backtracking']],
    ['key' => 'greedy', 'label' => '탐욕법', 'category' => 'implementation', 'aliases' => ['그리디', 'greedy']],
    ['key' => 'sorting', 'label' => '정렬', 'category' => 'implementation', 'aliases' => ['sort', '정렬 알고리즘']],

    ['key' => 'binary-search', 'label' => '이분 탐색', 'category' => 'search', 'aliases' => ['이진 탐색', '매개변수 탐색']],
    ['key' => 'two-pointers', 'label' => '투 포인터', 'category' => 'search', 'aliases' => ['두 포인터', 'two pointers']],

    ['key' => 'union-find', 'label' => '분리 집합', 'category' => 'data-structures', 'aliases' => ['서로소 집합', 'DSU', 'union find']],
    ['key' => 'bitmask', 'label' => '비트마스킹', 'category' => 'data-structures', 'aliases' => ['비트 집합', 'bitmask']],
    ['key' => 'strings', 'label' => '문자열', 'category' => 'data-structures', 'aliases' => ['문자열 처리', 'string']],
    ['key' => 'prefix-sum', 'label' => '누적 합', 'category' => 'data-structures', 'aliases' => ['부분 합', 'prefix sum']],

    ['key' => 'divide-and-conquer', 'label' => '분할 정복', 'category' => 'recursion', 'aliases' => ['divide and conquer']],
    ['key' => 'dynamic-programming', 'label' => '동적 계획법', 'category' => 'recursion', 'aliases' => ['DP', '동적 프로그래밍']],

    ['key' => 'graph-traversal', 'label' => '그래프 탐색', 'category' => 'graph', 'aliases' => ['DFS', 'BFS', '깊이 우선', '너비 우선']],
    ['key' => 'shortest-path', 'label' => '최단 경로', 'category' => 'graph', 'aliases' => ['다익스트라', '벨만 포드', '플로이드 워셜']],
    ['key' => 'minimum-spanning-tree', 'label' => '최소 신장 트리', 'category' => 'graph', 'aliases' => ['MST', '크루스칼', '프림']],
    ['key' => 'topological-sort', 'label' => '위상 정렬', 'category' => 'graph', 'aliases' => ['위상 순서', 'DAG 정렬']],
    ['key' => 'tree', 'label' => '트리', 'category' => 'graph', 'aliases' => ['이진 트리', '트리 순회']],
    ['key' => 'network-flow', 'label' => '네트워크 플로우', 'category' => 'graph', 'aliases' => ['최대 유량', '최소 컷', 'flow']],
    ['key' => 'matching', 'label' => '매칭', 'category' => 'graph', 'aliases' => ['이분 매칭', 'bipartite matching']],

    ['key' => 'number-theory', 'label' => '정수론', 'category' => 'math', 'aliases' => ['약수와 배수']],
    ['key' => 'combinatorics', 'label' => '조합론', 'category' => 'math', 'aliases' => ['순열', '조합', '경우의 수']],
    ['key' => 'geometry', 'label' => '기하', 'category' => 'math', 'aliases' => ['평면 기하', '좌표 기하']],
    ['key' => 'computational-geometry', 'label' => '계산 기하', 'category' => 'math', 'aliases' => ['CCW', '선분 교차', '볼록 껍질']],
    ['key' => 'probability', 'label' => '확률', 'category' => 'math', 'aliases' => ['확률론', '기댓값']],
    ['key' => 'game-theory', 'label' => '게임 이론', 'category' => 'math', 'aliases' => ['님 게임', '승패 상태']],
    ['key' => 'matrix-exponentiation', 'label' => '행렬 거듭제곱', 'category' => 'math', 'aliases' => ['행렬 제곱', 'matrix power']],
    ['key' => 'fft', 'label' => '고속 푸리에 변환', 'category' => 'math', 'aliases' => ['FFT', 'NTT']],

    ['key' => 'ternary-search', 'label' => '삼분 탐색', 'category' => 'search', 'aliases' => ['삼등분 탐색', 'ternary search']],
    ['key' => 'sliding-window', 'label' => '슬라이딩 윈도우', 'category' => 'search', 'aliases' => ['sliding window']],
    ['key' => 'meet-in-the-middle', 'label' => '반씩 나눠 풀기', 'category' => 'search', 'aliases' => ['meet in the middle']],

    ['key' => 'segment-tree', 'label' => '세그먼트 트리', 'category' => 'data-structures', 'aliases' => ['구간 트리', 'segment tree']],
    ['key' => 'fenwick-tree', 'label' => '펜윅 트리', 'category' => 'data-structures', 'aliases' => ['BIT', 'binary indexed tree']],
    ['key' => 'priority-queue', 'label' => '우선순위 큐', 'category' => 'data-structures', 'aliases' => ['힙', 'heap']],
    ['key' => 'trie', 'label' => '트라이', 'category' => 'data-structures', 'aliases' => ['trie', '접두사 트리']],
    ['key' => 'monotonic-stack', 'label' => '단조 스택', 'category' => 'data-structures', 'aliases' => ['단조 큐', 'monotonic stack']],

    ['key' => 'lca', 'label' => '최소 공통 조상', 'category' => 'graph', 'aliases' => ['LCA', 'lowest common ancestor']],
    ['key' => 'scc', 'label' => '강한 연결 요소', 'category' => 'graph', 'aliases' => ['SCC', '타잔', '코사라주']],
    ['key' => 'euler-tour', 'label' => '오일러 경로·회로', 'category' => 'graph', 'aliases' => ['오일러 경로', '오일러 회로']],
    ['key' => '2-sat', 'label' => '2-SAT', 'category' => 'graph', 'aliases' => ['2 sat', '불리언 충족']],

    ['key' => 'string-algorithms', 'label' => '문자열 알고리즘', 'category' => null, 'aliases' => ['문자열 매칭', 'string matching']],
    ['key' => 'kmp', 'label' => 'KMP', 'category' => 'string-algorithms', 'aliases' => ['커누스-모리스-프랫', '문자열 패턴 매칭']],
    ['key' => 'rabin-karp', 'label' => '라빈-카프', 'category' => 'string-algorithms', 'aliases' => ['해시 기반 문자열 매칭']],
    ['key' => 'suffix-array', 'label' => '접미사 배열', 'category' => 'string-algorithms', 'aliases' => ['suffix array', '접미사 트리']],
    ['key' => 'aho-corasick', 'label' => '아호-코라식', 'category' => 'string-algorithms', 'aliases' => ['다중 패턴 매칭']],

    ['key' => 'basic-syntax', 'label' => '기초 문법', 'category' => null, 'aliases' => ['프로그래밍 기초', '문법 학습']],
    ['key' => 'output', 'label' => '출력', 'category' => 'basic-syntax', 'aliases' => ['화면 출력', 'print', 'Hello World']],
    ['key' => 'input-output', 'label' => '입력과 출력', 'category' => 'basic-syntax', 'aliases' => ['표준 입출력', 'I/O']],
    ['key' => 'variables-and-types', 'label' => '변수와 자료형', 'category' => 'basic-syntax', 'aliases' => ['변수', '자료형', '형변환']],
    ['key' => 'operators', 'label' => '연산자', 'category' => 'basic-syntax', 'aliases' => ['산술 연산자', '비교 연산자', '논리 연산자']],
    ['key' => 'conditionals', 'label' => '조건문', 'category' => 'basic-syntax', 'aliases' => ['if문', '분기문']],
    ['key' => 'loops', 'label' => '반복문', 'category' => 'basic-syntax', 'aliases' => ['for문', 'while문', '반복 구조']],
    ['key' => 'functions', 'label' => '함수', 'category' => 'basic-syntax', 'aliases' => ['함수 정의', '매개변수', '반환값']],
    ['key' => 'arrays-basics', 'label' => '배열 기초', 'category' => 'basic-syntax', 'aliases' => ['1차원 배열', '리스트']],
    ['key' => 'string-basics', 'label' => '문자열 기초', 'category' => 'basic-syntax', 'aliases' => ['문자열 출력', '문자열 조작 기초']],
    ['key' => '2d-arrays', 'label' => '2차원 배열', 'category' => 'basic-syntax', 'aliases' => ['다차원 배열', '행렬 배열']],
    ['key' => 'recursive-functions', 'label' => '재귀 함수', 'category' => 'basic-syntax', 'aliases' => ['재귀 함수 작성', '기초 재귀']],

    ['key' => 'constructive-algorithms', 'label' => '구성적 알고리즘', 'category' => 'implementation', 'aliases' => ['constructive algorithms']],

    ['key' => 'stack', 'label' => '스택', 'category' => 'data-structures', 'aliases' => ['stack']],
    ['key' => 'queue', 'label' => '큐', 'category' => 'data-structures', 'aliases' => ['queue']],
    ['key' => 'deque', 'label' => '덱', 'category' => 'data-structures', 'aliases' => ['deque', '양방향 큐']],
    ['key' => 'hash-map', 'label' => '해시맵', 'category' => 'data-structures', 'aliases' => ['해시 테이블', 'hash map']],
    ['key' => 'linked-list', 'label' => '연결 리스트', 'category' => 'data-structures', 'aliases' => ['링크드 리스트', 'linked list']],
    ['key' => 'sqrt-decomposition', 'label' => '제곱근 분할법', 'category' => 'data-structures', 'aliases' => ['sqrt decomposition', '버킷']],
    ['key' => 'coordinate-compression', 'label' => '좌표 압축', 'category' => 'data-structures', 'aliases' => ['coordinate compression']],
    ['key' => 'sparse-table', 'label' => '스파스 테이블', 'category' => 'data-structures', 'aliases' => ['sparse table', 'RMQ']],
    ['key' => 'segment-tree-lazy', 'label' => '레이지 프로퍼게이션', 'category' => 'data-structures', 'aliases' => ['lazy propagation', '구간 갱신 세그먼트 트리']],

    ['key' => 'memoization', 'label' => '메모이제이션', 'category' => 'recursion', 'aliases' => ['memoization', '메모화']],
    ['key' => 'tree-dp', 'label' => '트리 DP', 'category' => 'recursion', 'aliases' => ['tree dp']],
    ['key' => 'bitmask-dp', 'label' => '비트마스크 DP', 'category' => 'recursion', 'aliases' => ['bitmask dp']],
    ['key' => 'digit-dp', 'label' => '자릿수 DP', 'category' => 'recursion', 'aliases' => ['digit dp']],
    ['key' => 'interval-dp', 'label' => '구간 DP', 'category' => 'recursion', 'aliases' => ['interval dp']],
    ['key' => 'knapsack', 'label' => '배낭 문제', 'category' => 'recursion', 'aliases' => ['knapsack', '0/1 냅색']],

    ['key' => 'bridge-and-articulation-point', 'label' => '단절점과 단절선', 'category' => 'graph', 'aliases' => ['cut vertex', 'bridge', 'articulation point']],
    ['key' => 'bipartite-check', 'label' => '이분 그래프 판정', 'category' => 'graph', 'aliases' => ['bipartite graph', '이분 그래프']],
    ['key' => 'heavy-light-decomposition', 'label' => '헤비-라이트 분할', 'category' => 'graph', 'aliases' => ['HLD', 'heavy light decomposition']],
    ['key' => 'centroid-decomposition', 'label' => '센트로이드 분할', 'category' => 'graph', 'aliases' => ['centroid decomposition']],
    ['key' => 'tree-diameter', 'label' => '트리의 지름', 'category' => 'graph', 'aliases' => ['tree diameter']],

    ['key' => 'euclidean-algorithm', 'label' => '유클리드 호제법', 'category' => 'math', 'aliases' => ['GCD', '유클리드 알고리즘']],
    ['key' => 'extended-euclidean', 'label' => '확장 유클리드 호제법', 'category' => 'math', 'aliases' => ['extended euclidean', '모듈러 역원']],
    ['key' => 'modular-arithmetic', 'label' => '모듈러 연산', 'category' => 'math', 'aliases' => ['모듈러 산술', 'modular arithmetic']],
    ['key' => 'primality-test', 'label' => '소수 판정', 'category' => 'math', 'aliases' => ['에라토스테네스의 체', '소수 찾기']],
    ['key' => 'diophantine-equation', 'label' => '디오판토스 방정식', 'category' => 'math', 'aliases' => ['diophantine equation']],
    ['key' => 'inclusion-exclusion', 'label' => '포함과 배제의 원리', 'category' => 'math', 'aliases' => ['inclusion exclusion', '포함배제']],
    ['key' => 'catalan-number', 'label' => '카탈랑 수', 'category' => 'math', 'aliases' => ['catalan number']],

    ['key' => 'z-algorithm', 'label' => 'Z 알고리즘', 'category' => 'string-algorithms', 'aliases' => ['z algorithm', 'Z 함수']],
    ['key' => 'manacher', 'label' => '매내커 알고리즘', 'category' => 'string-algorithms', 'aliases' => ['manacher', '최장 회문 부분 문자열']],
];

<?php

declare(strict_types=1);

// Real, measured scikit-learn results (labeling_game_data.json ->
// scripts/edu-game/generate_labeling_game_data.py). The labeling_batch's
// true_label values and the performance_by_correct_count table are the
// server-only "answer key" — Activity_Definition::publicView() must
// strip both before anything is sent to the browser.

return [
    'key' => 'titanic_survival',
    'title' => '타이타닉 생존자 예측',
    'difficulty' => 'hard',
    'dataset_label' => 'Titanic 승객 데이터셋 (실제 승객 기록)',
    'description' => '승객의 좌석 등급, 성별, 나이, 요금 등 정보를 보고 생존 여부를 직접 분류해 보세요. 생존자가 비생존자보다 적어 클래스 불균형이 존재합니다.',
    'feature_names' => [
        'pclass',
        'sex',
        'age',
        'sibsp',
        'parch',
        'fare',
        'embarked',
    ],
    'feature_labels' => [
        'pclass' => '객실 등급 (1~3등급)',
        'sex' => '성별 (0=여성, 1=남성)',
        'age' => '나이',
        'sibsp' => '형제자매·배우자 수',
        'parch' => '부모·자녀 수',
        'fare' => '요금',
        'embarked' => '탑승 항구 (0=S, 1=C, 2=Q)',
    ],
    'labels' => [
        'did_not_survive',
        'survived',
    ],
    'label_labels' => [
        'did_not_survive' => '사망',
        'survived' => '생존',
    ],
    'models' => [
        [
            'key' => 'knn',
            'label' => 'k-최근접 이웃 (kNN)',
            'description' => '새 데이터와 가장 가까운 k개의 기존 데이터를 찾아 다수결로 분류합니다.',
            'stats' => [
                'speed' => 3,
                'interpretability' => 3,
                'data_need' => 2,
            ],
        ],
        [
            'key' => 'decision_tree',
            'label' => '결정 트리',
            'description' => '데이터를 조건에 따라 나뭇가지처럼 나누며 분류 규칙을 스스로 만듭니다.',
            'stats' => [
                'speed' => 4,
                'interpretability' => 5,
                'data_need' => 2,
            ],
        ],
        [
            'key' => 'logistic_regression',
            'label' => '로지스틱 회귀',
            'description' => '각 특징에 가중치를 곱해 더한 값으로 분류 확률을 계산합니다.',
            'stats' => [
                'speed' => 5,
                'interpretability' => 4,
                'data_need' => 3,
            ],
        ],
    ],
    'labeling_batch' => [
        [
            'id' => 0,
            'features' => [
                'pclass' => 2.0,
                'sex' => 1.0,
                'age' => 70.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 10.5,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 1,
            'features' => [
                'pclass' => 3.0,
                'sex' => 0.0,
                'age' => 41.0,
                'sibsp' => 0.0,
                'parch' => 5.0,
                'fare' => 39.69,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 2,
            'features' => [
                'pclass' => 3.0,
                'sex' => 1.0,
                'age' => 4.0,
                'sibsp' => 3.0,
                'parch' => 2.0,
                'fare' => 27.9,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 3,
            'features' => [
                'pclass' => 3.0,
                'sex' => 1.0,
                'age' => 29.7,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 7.9,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 4,
            'features' => [
                'pclass' => 3.0,
                'sex' => 1.0,
                'age' => 26.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 7.9,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 5,
            'features' => [
                'pclass' => 1.0,
                'sex' => 1.0,
                'age' => 35.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 512.33,
                'embarked' => 1.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 6,
            'features' => [
                'pclass' => 3.0,
                'sex' => 1.0,
                'age' => 25.0,
                'sibsp' => 1.0,
                'parch' => 0.0,
                'fare' => 7.78,
                'embarked' => 0.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 7,
            'features' => [
                'pclass' => 3.0,
                'sex' => 0.0,
                'age' => 29.7,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 7.75,
                'embarked' => 2.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 8,
            'features' => [
                'pclass' => 2.0,
                'sex' => 1.0,
                'age' => 25.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 13.0,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 9,
            'features' => [
                'pclass' => 2.0,
                'sex' => 1.0,
                'age' => 18.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 11.5,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 10,
            'features' => [
                'pclass' => 1.0,
                'sex' => 0.0,
                'age' => 44.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 27.72,
                'embarked' => 1.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 11,
            'features' => [
                'pclass' => 2.0,
                'sex' => 1.0,
                'age' => 35.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 10.5,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 12,
            'features' => [
                'pclass' => 3.0,
                'sex' => 1.0,
                'age' => 29.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 9.5,
                'embarked' => 0.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 13,
            'features' => [
                'pclass' => 1.0,
                'sex' => 0.0,
                'age' => 36.0,
                'sibsp' => 1.0,
                'parch' => 2.0,
                'fare' => 120.0,
                'embarked' => 0.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 14,
            'features' => [
                'pclass' => 1.0,
                'sex' => 0.0,
                'age' => 21.0,
                'sibsp' => 2.0,
                'parch' => 2.0,
                'fare' => 262.38,
                'embarked' => 1.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 15,
            'features' => [
                'pclass' => 1.0,
                'sex' => 1.0,
                'age' => 80.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 30.0,
                'embarked' => 0.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 16,
            'features' => [
                'pclass' => 1.0,
                'sex' => 0.0,
                'age' => 22.0,
                'sibsp' => 0.0,
                'parch' => 2.0,
                'fare' => 49.5,
                'embarked' => 1.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 17,
            'features' => [
                'pclass' => 2.0,
                'sex' => 0.0,
                'age' => 36.0,
                'sibsp' => 0.0,
                'parch' => 0.0,
                'fare' => 13.0,
                'embarked' => 0.0,
            ],
            'true_label' => 'survived',
        ],
        [
            'id' => 18,
            'features' => [
                'pclass' => 3.0,
                'sex' => 1.0,
                'age' => 26.0,
                'sibsp' => 2.0,
                'parch' => 0.0,
                'fare' => 8.66,
                'embarked' => 0.0,
            ],
            'true_label' => 'did_not_survive',
        ],
        [
            'id' => 19,
            'features' => [
                'pclass' => 2.0,
                'sex' => 0.0,
                'age' => 4.0,
                'sibsp' => 1.0,
                'parch' => 1.0,
                'fare' => 23.0,
                'embarked' => 0.0,
            ],
            'true_label' => 'survived',
        ],
    ],
    'performance_by_correct_count' => [
        'knn' => [
            [
                'correct_count' => 0,
                'accuracy' => 0.2276,
                'precision' => 0.1977,
                'recall' => 0.3301,
                'f1' => 0.2473,
                'confusion_matrix' => [
                    [
                        27,
                        138,
                    ],
                    [
                        69,
                        34,
                    ],
                ],
            ],
            [
                'correct_count' => 1,
                'accuracy' => 0.2696,
                'precision' => 0.2156,
                'recall' => 0.3376,
                'f1' => 0.26,
                'confusion_matrix' => [
                    [
                        37,
                        128,
                    ],
                    [
                        68,
                        35,
                    ],
                ],
            ],
            [
                'correct_count' => 2,
                'accuracy' => 0.2833,
                'precision' => 0.2238,
                'recall' => 0.3532,
                'f1' => 0.2692,
                'confusion_matrix' => [
                    [
                        40,
                        125,
                    ],
                    [
                        67,
                        36,
                    ],
                ],
            ],
            [
                'correct_count' => 3,
                'accuracy' => 0.3004,
                'precision' => 0.2317,
                'recall' => 0.3575,
                'f1' => 0.2767,
                'confusion_matrix' => [
                    [
                        44,
                        121,
                    ],
                    [
                        66,
                        37,
                    ],
                ],
            ],
            [
                'correct_count' => 4,
                'accuracy' => 0.3777,
                'precision' => 0.2509,
                'recall' => 0.3238,
                'f1' => 0.2721,
                'confusion_matrix' => [
                    [
                        68,
                        97,
                    ],
                    [
                        70,
                        33,
                    ],
                ],
            ],
            [
                'correct_count' => 5,
                'accuracy' => 0.4074,
                'precision' => 0.2882,
                'recall' => 0.3709,
                'f1' => 0.3126,
                'confusion_matrix' => [
                    [
                        71,
                        94,
                    ],
                    [
                        65,
                        38,
                    ],
                ],
            ],
            [
                'correct_count' => 6,
                'accuracy' => 0.3925,
                'precision' => 0.296,
                'recall' => 0.4442,
                'f1' => 0.3422,
                'confusion_matrix' => [
                    [
                        59,
                        106,
                    ],
                    [
                        57,
                        46,
                    ],
                ],
            ],
            [
                'correct_count' => 7,
                'accuracy' => 0.4354,
                'precision' => 0.3108,
                'recall' => 0.3985,
                'f1' => 0.3279,
                'confusion_matrix' => [
                    [
                        76,
                        89,
                    ],
                    [
                        62,
                        41,
                    ],
                ],
            ],
            [
                'correct_count' => 8,
                'accuracy' => 0.4348,
                'precision' => 0.3269,
                'recall' => 0.4056,
                'f1' => 0.3382,
                'confusion_matrix' => [
                    [
                        75,
                        90,
                    ],
                    [
                        61,
                        42,
                    ],
                ],
            ],
            [
                'correct_count' => 9,
                'accuracy' => 0.4783,
                'precision' => 0.3459,
                'recall' => 0.458,
                'f1' => 0.371,
                'confusion_matrix' => [
                    [
                        81,
                        84,
                    ],
                    [
                        56,
                        47,
                    ],
                ],
            ],
            [
                'correct_count' => 10,
                'accuracy' => 0.5118,
                'precision' => 0.4052,
                'recall' => 0.4922,
                'f1' => 0.4272,
                'confusion_matrix' => [
                    [
                        86,
                        79,
                    ],
                    [
                        52,
                        51,
                    ],
                ],
            ],
            [
                'correct_count' => 11,
                'accuracy' => 0.5426,
                'precision' => 0.4491,
                'recall' => 0.601,
                'f1' => 0.4978,
                'confusion_matrix' => [
                    [
                        84,
                        81,
                    ],
                    [
                        41,
                        62,
                    ],
                ],
            ],
            [
                'correct_count' => 12,
                'accuracy' => 0.5777,
                'precision' => 0.476,
                'recall' => 0.5748,
                'f1' => 0.5066,
                'confusion_matrix' => [
                    [
                        96,
                        69,
                    ],
                    [
                        44,
                        59,
                    ],
                ],
            ],
            [
                'correct_count' => 13,
                'accuracy' => 0.5799,
                'precision' => 0.4859,
                'recall' => 0.5796,
                'f1' => 0.5026,
                'confusion_matrix' => [
                    [
                        96,
                        69,
                    ],
                    [
                        43,
                        60,
                    ],
                ],
            ],
            [
                'correct_count' => 14,
                'accuracy' => 0.593,
                'precision' => 0.5103,
                'recall' => 0.6466,
                'f1' => 0.5424,
                'confusion_matrix' => [
                    [
                        92,
                        73,
                    ],
                    [
                        36,
                        67,
                    ],
                ],
            ],
            [
                'correct_count' => 15,
                'accuracy' => 0.6373,
                'precision' => 0.5481,
                'recall' => 0.5981,
                'f1' => 0.5518,
                'confusion_matrix' => [
                    [
                        109,
                        56,
                    ],
                    [
                        41,
                        62,
                    ],
                ],
            ],
            [
                'correct_count' => 16,
                'accuracy' => 0.6455,
                'precision' => 0.5666,
                'recall' => 0.6417,
                'f1' => 0.5803,
                'confusion_matrix' => [
                    [
                        107,
                        58,
                    ],
                    [
                        37,
                        66,
                    ],
                ],
            ],
            [
                'correct_count' => 17,
                'accuracy' => 0.6887,
                'precision' => 0.6339,
                'recall' => 0.6104,
                'f1' => 0.5999,
                'confusion_matrix' => [
                    [
                        122,
                        43,
                    ],
                    [
                        40,
                        63,
                    ],
                ],
            ],
            [
                'correct_count' => 18,
                'accuracy' => 0.7243,
                'precision' => 0.6682,
                'recall' => 0.6439,
                'f1' => 0.6448,
                'confusion_matrix' => [
                    [
                        128,
                        37,
                    ],
                    [
                        37,
                        66,
                    ],
                ],
            ],
            [
                'correct_count' => 19,
                'accuracy' => 0.7557,
                'precision' => 0.7076,
                'recall' => 0.6549,
                'f1' => 0.6741,
                'confusion_matrix' => [
                    [
                        135,
                        30,
                    ],
                    [
                        36,
                        67,
                    ],
                ],
            ],
            [
                'correct_count' => 20,
                'accuracy' => 0.7724,
                'precision' => 0.7188,
                'recall' => 0.6699,
                'f1' => 0.6935,
                'confusion_matrix' => [
                    [
                        138,
                        27,
                    ],
                    [
                        34,
                        69,
                    ],
                ],
            ],
        ],
        'decision_tree' => [
            [
                'correct_count' => 0,
                'accuracy' => 0.3433,
                'precision' => 0.2583,
                'recall' => 0.3786,
                'f1' => 0.3071,
                'confusion_matrix' => [
                    [
                        53,
                        112,
                    ],
                    [
                        64,
                        39,
                    ],
                ],
            ],
            [
                'correct_count' => 1,
                'accuracy' => 0.3608,
                'precision' => 0.2469,
                'recall' => 0.3175,
                'f1' => 0.2711,
                'confusion_matrix' => [
                    [
                        64,
                        101,
                    ],
                    [
                        70,
                        33,
                    ],
                ],
            ],
            [
                'correct_count' => 2,
                'accuracy' => 0.3961,
                'precision' => 0.265,
                'recall' => 0.3405,
                'f1' => 0.2925,
                'confusion_matrix' => [
                    [
                        71,
                        94,
                    ],
                    [
                        68,
                        35,
                    ],
                ],
            ],
            [
                'correct_count' => 3,
                'accuracy' => 0.3983,
                'precision' => 0.2739,
                'recall' => 0.3536,
                'f1' => 0.2992,
                'confusion_matrix' => [
                    [
                        70,
                        95,
                    ],
                    [
                        67,
                        36,
                    ],
                ],
            ],
            [
                'correct_count' => 4,
                'accuracy' => 0.4243,
                'precision' => 0.3144,
                'recall' => 0.4296,
                'f1' => 0.3587,
                'confusion_matrix' => [
                    [
                        69,
                        96,
                    ],
                    [
                        59,
                        44,
                    ],
                ],
            ],
            [
                'correct_count' => 5,
                'accuracy' => 0.4512,
                'precision' => 0.3146,
                'recall' => 0.3748,
                'f1' => 0.3327,
                'confusion_matrix' => [
                    [
                        82,
                        83,
                    ],
                    [
                        64,
                        39,
                    ],
                ],
            ],
            [
                'correct_count' => 6,
                'accuracy' => 0.4576,
                'precision' => 0.3369,
                'recall' => 0.4532,
                'f1' => 0.3718,
                'confusion_matrix' => [
                    [
                        76,
                        89,
                    ],
                    [
                        56,
                        47,
                    ],
                ],
            ],
            [
                'correct_count' => 7,
                'accuracy' => 0.4938,
                'precision' => 0.3563,
                'recall' => 0.4126,
                'f1' => 0.3665,
                'confusion_matrix' => [
                    [
                        90,
                        75,
                    ],
                    [
                        60,
                        42,
                    ],
                ],
            ],
            [
                'correct_count' => 8,
                'accuracy' => 0.4913,
                'precision' => 0.3653,
                'recall' => 0.4522,
                'f1' => 0.3914,
                'confusion_matrix' => [
                    [
                        85,
                        80,
                    ],
                    [
                        56,
                        47,
                    ],
                ],
            ],
            [
                'correct_count' => 9,
                'accuracy' => 0.5088,
                'precision' => 0.4024,
                'recall' => 0.5114,
                'f1' => 0.4273,
                'confusion_matrix' => [
                    [
                        84,
                        81,
                    ],
                    [
                        50,
                        53,
                    ],
                ],
            ],
            [
                'correct_count' => 10,
                'accuracy' => 0.4888,
                'precision' => 0.3696,
                'recall' => 0.4774,
                'f1' => 0.402,
                'confusion_matrix' => [
                    [
                        82,
                        83,
                    ],
                    [
                        54,
                        49,
                    ],
                ],
            ],
            [
                'correct_count' => 11,
                'accuracy' => 0.5262,
                'precision' => 0.4066,
                'recall' => 0.4998,
                'f1' => 0.435,
                'confusion_matrix' => [
                    [
                        90,
                        75,
                    ],
                    [
                        52,
                        51,
                    ],
                ],
            ],
            [
                'correct_count' => 12,
                'accuracy' => 0.5049,
                'precision' => 0.3981,
                'recall' => 0.524,
                'f1' => 0.4312,
                'confusion_matrix' => [
                    [
                        81,
                        84,
                    ],
                    [
                        49,
                        54,
                    ],
                ],
            ],
            [
                'correct_count' => 13,
                'accuracy' => 0.5241,
                'precision' => 0.4128,
                'recall' => 0.5544,
                'f1' => 0.4627,
                'confusion_matrix' => [
                    [
                        83,
                        82,
                    ],
                    [
                        46,
                        57,
                    ],
                ],
            ],
            [
                'correct_count' => 14,
                'accuracy' => 0.5417,
                'precision' => 0.4325,
                'recall' => 0.5883,
                'f1' => 0.4857,
                'confusion_matrix' => [
                    [
                        85,
                        80,
                    ],
                    [
                        42,
                        61,
                    ],
                ],
            ],
            [
                'correct_count' => 15,
                'accuracy' => 0.5743,
                'precision' => 0.4708,
                'recall' => 0.5714,
                'f1' => 0.4948,
                'confusion_matrix' => [
                    [
                        95,
                        70,
                    ],
                    [
                        44,
                        59,
                    ],
                ],
            ],
            [
                'correct_count' => 16,
                'accuracy' => 0.5749,
                'precision' => 0.463,
                'recall' => 0.5774,
                'f1' => 0.4973,
                'confusion_matrix' => [
                    [
                        95,
                        70,
                    ],
                    [
                        44,
                        59,
                    ],
                ],
            ],
            [
                'correct_count' => 17,
                'accuracy' => 0.5764,
                'precision' => 0.4683,
                'recall' => 0.5891,
                'f1' => 0.5112,
                'confusion_matrix' => [
                    [
                        94,
                        71,
                    ],
                    [
                        42,
                        61,
                    ],
                ],
            ],
            [
                'correct_count' => 18,
                'accuracy' => 0.6256,
                'precision' => 0.5213,
                'recall' => 0.5893,
                'f1' => 0.5409,
                'confusion_matrix' => [
                    [
                        107,
                        58,
                    ],
                    [
                        42,
                        61,
                    ],
                ],
            ],
            [
                'correct_count' => 19,
                'accuracy' => 0.6535,
                'precision' => 0.5451,
                'recall' => 0.6551,
                'f1' => 0.5881,
                'confusion_matrix' => [
                    [
                        108,
                        57,
                    ],
                    [
                        36,
                        67,
                    ],
                ],
            ],
            [
                'correct_count' => 20,
                'accuracy' => 0.6567,
                'precision' => 0.547,
                'recall' => 0.6214,
                'f1' => 0.5818,
                'confusion_matrix' => [
                    [
                        112,
                        53,
                    ],
                    [
                        39,
                        64,
                    ],
                ],
            ],
        ],
        'logistic_regression' => [
            [
                'correct_count' => 0,
                'accuracy' => 0.2836,
                'precision' => 0.1973,
                'recall' => 0.2816,
                'f1' => 0.232,
                'confusion_matrix' => [
                    [
                        47,
                        118,
                    ],
                    [
                        74,
                        29,
                    ],
                ],
            ],
            [
                'correct_count' => 1,
                'accuracy' => 0.2926,
                'precision' => 0.2062,
                'recall' => 0.3027,
                'f1' => 0.2441,
                'confusion_matrix' => [
                    [
                        47,
                        118,
                    ],
                    [
                        72,
                        31,
                    ],
                ],
            ],
            [
                'correct_count' => 2,
                'accuracy' => 0.2942,
                'precision' => 0.2112,
                'recall' => 0.3155,
                'f1' => 0.2516,
                'confusion_matrix' => [
                    [
                        46,
                        119,
                    ],
                    [
                        70,
                        32,
                    ],
                ],
            ],
            [
                'correct_count' => 3,
                'accuracy' => 0.3111,
                'precision' => 0.2313,
                'recall' => 0.3626,
                'f1' => 0.2787,
                'confusion_matrix' => [
                    [
                        46,
                        119,
                    ],
                    [
                        66,
                        37,
                    ],
                ],
            ],
            [
                'correct_count' => 4,
                'accuracy' => 0.329,
                'precision' => 0.2572,
                'recall' => 0.4189,
                'f1' => 0.3139,
                'confusion_matrix' => [
                    [
                        45,
                        120,
                    ],
                    [
                        60,
                        43,
                    ],
                ],
            ],
            [
                'correct_count' => 5,
                'accuracy' => 0.3625,
                'precision' => 0.2681,
                'recall' => 0.4053,
                'f1' => 0.3125,
                'confusion_matrix' => [
                    [
                        55,
                        110,
                    ],
                    [
                        61,
                        42,
                    ],
                ],
            ],
            [
                'correct_count' => 6,
                'accuracy' => 0.3931,
                'precision' => 0.2717,
                'recall' => 0.3789,
                'f1' => 0.3039,
                'confusion_matrix' => [
                    [
                        66,
                        99,
                    ],
                    [
                        64,
                        39,
                    ],
                ],
            ],
            [
                'correct_count' => 7,
                'accuracy' => 0.4089,
                'precision' => 0.3056,
                'recall' => 0.4381,
                'f1' => 0.346,
                'confusion_matrix' => [
                    [
                        64,
                        101,
                    ],
                    [
                        58,
                        45,
                    ],
                ],
            ],
            [
                'correct_count' => 8,
                'accuracy' => 0.4377,
                'precision' => 0.3185,
                'recall' => 0.4284,
                'f1' => 0.3545,
                'confusion_matrix' => [
                    [
                        73,
                        92,
                    ],
                    [
                        59,
                        44,
                    ],
                ],
            ],
            [
                'correct_count' => 9,
                'accuracy' => 0.4525,
                'precision' => 0.3461,
                'recall' => 0.5061,
                'f1' => 0.3998,
                'confusion_matrix' => [
                    [
                        69,
                        96,
                    ],
                    [
                        51,
                        52,
                    ],
                ],
            ],
            [
                'correct_count' => 10,
                'accuracy' => 0.4785,
                'precision' => 0.3727,
                'recall' => 0.5415,
                'f1' => 0.4195,
                'confusion_matrix' => [
                    [
                        72,
                        93,
                    ],
                    [
                        47,
                        56,
                    ],
                ],
            ],
            [
                'correct_count' => 11,
                'accuracy' => 0.505,
                'precision' => 0.419,
                'recall' => 0.574,
                'f1' => 0.457,
                'confusion_matrix' => [
                    [
                        76,
                        89,
                    ],
                    [
                        44,
                        59,
                    ],
                ],
            ],
            [
                'correct_count' => 12,
                'accuracy' => 0.5784,
                'precision' => 0.4779,
                'recall' => 0.4976,
                'f1' => 0.4562,
                'confusion_matrix' => [
                    [
                        104,
                        61,
                    ],
                    [
                        52,
                        51,
                    ],
                ],
            ],
            [
                'correct_count' => 13,
                'accuracy' => 0.5808,
                'precision' => 0.4833,
                'recall' => 0.5347,
                'f1' => 0.4756,
                'confusion_matrix' => [
                    [
                        101,
                        64,
                    ],
                    [
                        48,
                        55,
                    ],
                ],
            ],
            [
                'correct_count' => 14,
                'accuracy' => 0.5945,
                'precision' => 0.5082,
                'recall' => 0.6129,
                'f1' => 0.5251,
                'confusion_matrix' => [
                    [
                        96,
                        69,
                    ],
                    [
                        40,
                        63,
                    ],
                ],
            ],
            [
                'correct_count' => 15,
                'accuracy' => 0.633,
                'precision' => 0.5338,
                'recall' => 0.6371,
                'f1' => 0.5702,
                'confusion_matrix' => [
                    [
                        104,
                        61,
                    ],
                    [
                        37,
                        66,
                    ],
                ],
            ],
            [
                'correct_count' => 16,
                'accuracy' => 0.6707,
                'precision' => 0.5718,
                'recall' => 0.6379,
                'f1' => 0.5911,
                'confusion_matrix' => [
                    [
                        114,
                        51,
                    ],
                    [
                        37,
                        66,
                    ],
                ],
            ],
            [
                'correct_count' => 17,
                'accuracy' => 0.6692,
                'precision' => 0.578,
                'recall' => 0.6449,
                'f1' => 0.5948,
                'confusion_matrix' => [
                    [
                        113,
                        52,
                    ],
                    [
                        37,
                        66,
                    ],
                ],
            ],
            [
                'correct_count' => 18,
                'accuracy' => 0.6997,
                'precision' => 0.6133,
                'recall' => 0.6391,
                'f1' => 0.6171,
                'confusion_matrix' => [
                    [
                        122,
                        43,
                    ],
                    [
                        37,
                        66,
                    ],
                ],
            ],
            [
                'correct_count' => 19,
                'accuracy' => 0.7073,
                'precision' => 0.6141,
                'recall' => 0.6876,
                'f1' => 0.6427,
                'confusion_matrix' => [
                    [
                        119,
                        46,
                    ],
                    [
                        32,
                        71,
                    ],
                ],
            ],
            [
                'correct_count' => 20,
                'accuracy' => 0.7164,
                'precision' => 0.6116,
                'recall' => 0.7184,
                'f1' => 0.6607,
                'confusion_matrix' => [
                    [
                        118,
                        47,
                    ],
                    [
                        29,
                        74,
                    ],
                ],
            ],
        ],
    ],
    'baseline_accuracy' => 0.2276,
    'best_accuracy' => 0.7724,
    'retry_penalty_per_attempt' => 5,
];

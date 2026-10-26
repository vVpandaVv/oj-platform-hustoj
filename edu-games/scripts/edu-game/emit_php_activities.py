"""
Converts labeling_game_data.json (real, measured scikit-learn results) into
the PHP activity config files consumed by Activity_Definition::fromArray().
"""
import json

MODELS = [
    {
        "key": "knn",
        "label": "k-최근접 이웃 (kNN)",
        "description": "새 데이터와 가장 가까운 k개의 기존 데이터를 찾아 다수결로 분류합니다.",
        "stats": {"speed": 3, "interpretability": 3, "data_need": 2},
    },
    {
        "key": "decision_tree",
        "label": "결정 트리",
        "description": "데이터를 조건에 따라 나뭇가지처럼 나누며 분류 규칙을 스스로 만듭니다.",
        "stats": {"speed": 4, "interpretability": 5, "data_need": 2},
    },
    {
        "key": "logistic_regression",
        "label": "로지스틱 회귀",
        "description": "각 특징에 가중치를 곱해 더한 값으로 분류 확률을 계산합니다.",
        "stats": {"speed": 5, "interpretability": 4, "data_need": 3},
    },
]

IRIS_FEATURE_LABELS = {
    "sepal_length": "꽃받침 길이 (cm)",
    "sepal_width": "꽃받침 너비 (cm)",
    "petal_length": "꽃잎 길이 (cm)",
    "petal_width": "꽃잎 너비 (cm)",
}
IRIS_LABEL_LABELS = {
    "setosa": "세토사 (setosa)",
    "versicolor": "버시컬러 (versicolor)",
    "virginica": "버지니카 (virginica)",
}

TITANIC_FEATURE_LABELS = {
    "pclass": "객실 등급 (1~3등급)",
    "sex": "성별 (0=여성, 1=남성)",
    "age": "나이",
    "sibsp": "형제자매·배우자 수",
    "parch": "부모·자녀 수",
    "fare": "요금",
    "embarked": "탑승 항구 (0=S, 1=C, 2=Q)",
}
TITANIC_LABEL_LABELS = {
    "did_not_survive": "사망",
    "survived": "생존",
}

META = {
    "iris_classification": {
        "title": "붓꽃 품종 분류",
        "difficulty": "easy",
        "dataset_label": "Iris 붓꽃 데이터셋 (150개 샘플, scikit-learn 내장)",
        "description": "꽃받침과 꽃잎의 길이·너비 4가지 특징으로 붓꽃의 품종(setosa/versicolor/virginica)을 직접 분류해 보세요.",
        "feature_labels": IRIS_FEATURE_LABELS,
        "label_labels": IRIS_LABEL_LABELS,
    },
    "titanic_survival": {
        "title": "타이타닉 생존자 예측",
        "difficulty": "hard",
        "dataset_label": "Titanic 승객 데이터셋 (실제 승객 기록)",
        "description": "승객의 좌석 등급, 성별, 나이, 요금 등 정보를 보고 생존 여부를 직접 분류해 보세요. 생존자가 비생존자보다 적어 클래스 불균형이 존재합니다.",
        "feature_labels": TITANIC_FEATURE_LABELS,
        "label_labels": TITANIC_LABEL_LABELS,
    },
}


def php_literal(value, indent=0):
    pad = "    " * indent
    pad_in = "    " * (indent + 1)
    if isinstance(value, bool):
        return "true" if value else "false"
    if value is None:
        return "null"
    if isinstance(value, (int, float)):
        return repr(value)
    if isinstance(value, str):
        escaped = value.replace("\\", "\\\\").replace("'", "\\'")
        return f"'{escaped}'"
    if isinstance(value, list):
        if not value:
            return "[]"
        items = ",\n".join(pad_in + php_literal(v, indent + 1) for v in value)
        return "[\n" + items + ",\n" + pad + "]"
    if isinstance(value, dict):
        if not value:
            return "[]"
        items = []
        for k, v in value.items():
            key_lit = php_literal(str(k), 0)
            items.append(pad_in + f"{key_lit} => " + php_literal(v, indent + 1))
        return "[\n" + ",\n".join(items) + ",\n" + pad + "]"
    raise TypeError(type(value))


def emit(activity_key, data, meta):
    config = {
        "key": activity_key,
        "title": meta["title"],
        "difficulty": meta["difficulty"],
        "dataset_label": meta["dataset_label"],
        "description": meta["description"],
        "feature_names": data["feature_names"],
        "feature_labels": meta["feature_labels"],
        "labels": data["labels"],
        "label_labels": meta["label_labels"],
        "models": MODELS,
        "labeling_batch": data["labeling_batch"],
        "performance_by_correct_count": data["performance_by_correct_count"],
        "baseline_accuracy": data["baseline_accuracy"],
        "best_accuracy": data["best_accuracy"],
        "retry_penalty_per_attempt": 5,
    }

    header = (
        "<?php\n\n"
        "declare(strict_types=1);\n\n"
        "// Real, measured scikit-learn results (labeling_game_data.json ->\n"
        "// scripts/edu-game/generate_labeling_game_data.py). The labeling_batch's\n"
        "// true_label values and the performance_by_correct_count table are the\n"
        "// server-only \"answer key\" — Activity_Definition::publicView() must\n"
        "// strip both before anything is sent to the browser.\n\n"
        "return "
    )
    body = php_literal(config, 0)
    return header + body + ";\n"


if __name__ == "__main__":
    with open("labeling_game_data.json", encoding="utf-8") as f:
        raw = json.load(f)

    for key, meta in META.items():
        data = raw[key]
        php = emit(key, data, meta)
        out_path = f"{key}.php"
        with open(out_path, "w", encoding="utf-8") as f:
            f.write(php)
        print(f"Wrote {out_path} ({len(php)} bytes)")

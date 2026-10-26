"""Offline, deterministic laboratory bank; never run on a student request."""
import hashlib
import itertools
import json
from pathlib import Path

import numpy as np
import sklearn
from sklearn.datasets import make_classification, make_moons, make_circles
from sklearn.dummy import DummyClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, confusion_matrix, f1_score, recall_score
from sklearn.model_selection import train_test_split
from sklearn.neighbors import KNeighborsClassifier
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.tree import DecisionTreeClassifier

ROOT = Path(__file__).resolve().parents[2]


def metrics(model, x, y):
    predicted = model.predict(x)
    return {
        "accuracy": round(100 * accuracy_score(y, predicted), 2),
        "macroF1": round(100 * f1_score(y, predicted, labels=[0, 1], average="macro", zero_division=0), 2),
        "recall": [round(100 * v, 2) for v in recall_score(y, predicted, labels=[0, 1], average=None, zero_division=0)],
        "matrix": confusion_matrix(y, predicted, labels=[0, 1]).tolist(),
        "count": len(y),
    }


def main():
    bank = {"engine": "scikit-learn", "engineVersion": sklearn.__version__, "seed": 20260911, "synthetic": True, "missions": {}}
    profiles = [
        ("직선 패턴 연구소", "균형", "linear", .5, [8, 6, 4], 0),
        ("곡선 패턴 연구소", "균형", "moons", .5, [8, 6, 4], 0),
        ("불균형 표본 연구소", "불균형", "linear", .72, [8, 6, 4], 0),
        ("고리 분포 연구소", "균형", "circles", .5, [5, 5, 3], 0),
        ("희귀 신호 관측소", "불균형", "linear", .82, [6, 5, 2], 0),
        ("소수 범주 품질 관문", "불균형", "linear", .25, [4, 7, 3], 0),
        ("뒤섞인 라벨 복구", "라벨 잡음", "linear", .5, [14, 13, 12], 0),
        ("곡선 자료 검수실", "라벨 잡음", "moons", .5, [12, 14, 10], 0),
        ("오염된 고리 표본", "라벨 잡음", "circles", .5, [10, 12, 12], 0),
        ("회전한 센서 환경", "분포 변화", "linear", .5, [4, 4, 2], 90),
        ("이동한 관측 영역", "분포 변화", "linear", .65, [5, 4, 2], 45),
        ("새 환경 곡선 적응", "분포 변화", "moons", .5, [4, 4, 1], 180),
    ]
    for mission, (title, chapter, shape, weight, noise, angle) in enumerate(profiles):
        seed = 20260911 + mission
        rng = np.random.default_rng(seed)
        if shape == 'moons':
            x, y = make_moons(n_samples=600, noise=0.13, random_state=seed)
        elif shape == 'circles':
            x, y = make_circles(n_samples=600, noise=.045, factor=.35, random_state=seed)
        else:
            x, y = make_classification(n_samples=600, n_features=2, n_redundant=0, n_informative=2, n_clusters_per_class=1, class_sep=1.8, weights=[weight, 1-weight], flip_y=0, random_state=seed)
        pool, rest = train_test_split(np.arange(len(y)), train_size=300, stratify=y, random_state=seed)
        validation, test = train_test_split(rest, train_size=150, stratify=y[rest], random_state=seed + 1)
        assert not (set(pool) & set(validation) or set(pool) & set(test) or set(validation) & set(test))
        by_class = [rng.permutation(pool[y[pool] == label]) for label in (0, 1)]
        ids = [by_class[0][:32], by_class[1][:32], np.concatenate([by_class[0][32:48], by_class[1][32:48]])]
        if angle:
            # Only recent training data and the disjoint evaluation sets change environment.
            theta = np.deg2rad(angle)
            rotation = np.array([[np.cos(theta), -np.sin(theta)], [np.sin(theta), np.cos(theta)]])
            changed = np.concatenate([ids[2], validation, test])
            x[changed] = x[changed] @ rotation.T + np.array([2.5, -1.5])
        noisy = []
        batches = []
        for i, selected in enumerate(ids):
            labels = y[selected].copy()
            flipped = rng.choice(len(labels), size=noise[i], replace=False)
            labels[flipped] = 1 - labels[flipped]
            noisy.append(labels)
            names = ["이전 환경 A 자료", "이전 환경 B 자료", "새 환경 균형 자료"] if angle else ["A 중심 수집", "B 중심 수집", "균형 수집"]
            batches.append({"id": i, "cost": [4, 4, 7][i], "reviewCost": 3, "name": names[i], "ids": selected.tolist(), "x": np.round(x[selected], 4).tolist(), "labels": labels.tolist(), "verifiedLabels": y[selected].tolist()})
        experiments = {}
        for status in itertools.product(range(3), repeat=3):
            selected_batches = [i for i, s in enumerate(status) if s]
            if not selected_batches:
                continue
            selected = np.concatenate([ids[i] for i in selected_batches])
            labels = np.concatenate([y[ids[i]] if status[i] == 2 else noisy[i] for i in selected_batches])
            for name, estimator in [("tree", DecisionTreeClassifier(max_depth=3, random_state=seed)), ("knn", KNeighborsClassifier(n_neighbors=5)), ("linear", LogisticRegression(max_iter=500, random_state=seed))]:
                if len(np.unique(labels)) == 1:
                    estimator = DummyClassifier(strategy="most_frequent")
                model = make_pipeline(StandardScaler(), estimator)
                model.fit(x[selected], labels)
                key = "".join(map(str, status)) + ":" + name
                cost = sum([4, 4, 7][i] + (3 if status[i] == 2 else 0) for i in selected_batches) + {"tree": 5, "knn": 6, "linear": 8}[name] + 3
                experiments[key] = {"training": metrics(model, x[selected], labels), "validation": metrics(model, x[validation], y[validation]), "test": metrics(model, x[test], y[test]), "cost": cost, "singleClass": len(np.unique(labels)) == 1}
        viable = [(key, result) for key, result in experiments.items() if result["validation"]["macroF1"] >= 85 and min(result["validation"]["recall"]) >= 75 and result["test"]["macroF1"] >= 85 and min(result["test"]["recall"]) >= 75]
        assert viable, f"Unsolvable mission {mission}"
        best_key, best = min(viable, key=lambda item: (item[1]["cost"], -item[1]["test"]["macroF1"]))
        context = "검증·최종 표본은 새 환경에서 수집했습니다. 이전 환경 자료와 최근 자료의 대표성을 비교하세요." if angle else {"균형": "두 범주의 표본 수는 비슷하지만 분포의 모양이 다릅니다.", "불균형": "한 범주가 드뭅니다. 전체 정확도뿐 아니라 각 범주의 재현율을 확보하세요.", "라벨 잡음": "수집 묶음의 라벨이 많이 섞였습니다. 검수 비용과 확보할 자료의 범위를 정하세요."}[chapter]
        bank["missions"][f"board-{mission + 1:02d}"] = {"title": title, "chapter": chapter, "context": context, "shape": shape, "environmentRotation": angle, "batches": batches, "experiments": experiments, "solution": best_key, "referenceCost": best["cost"], "splits": {"pool": pool.tolist(), "validation": validation.tolist(), "test": test.tolist()}, "datasetHash": hashlib.sha256(x.tobytes() + y.tobytes()).hexdigest()}
        print(f"board-{mission + 1:02d}: {len(experiments)} experiments, reference={best_key}, cost={best['cost']}, test F1={best['test']['macroF1']}")
    encoded = json.dumps(bank, ensure_ascii=False, separators=(",", ":"))
    output = ROOT / "src/Games/Modules/AI_Model_Lab/strategy-experiments.php"
    output.write_text("<?php\ndeclare(strict_types=1);\n// Generated offline by build-strategy-experiments.py; not public game configuration.\nreturn json_decode(<<<'EXPERIMENTS'\n" + encoded + "\nEXPERIMENTS, true, 512, JSON_THROW_ON_ERROR);\n", encoding="utf-8")


if __name__ == "__main__":
    main()

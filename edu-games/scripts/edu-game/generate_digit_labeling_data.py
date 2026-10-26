"""Generate versioned digit-labeling simulations on a held-out validation split.

Keep the test split untouched. Pair representative metrics with that trial's
integer confusion matrix, and retain means separately. Learners may defer
ambiguous digits; these simulations do not train on a learner's actual labels.
"""
import json
import warnings
import argparse
from pathlib import Path
import numpy as np
import sklearn
from emit_php_activities import php_literal, MODELS
from sklearn.datasets import load_digits
from sklearn.model_selection import train_test_split
from sklearn.neighbors import KNeighborsClassifier
from sklearn.tree import DecisionTreeClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, confusion_matrix
from sklearn.preprocessing import StandardScaler

warnings.filterwarnings("ignore")
RNG = np.random.RandomState(42)

MODEL_KEYS = ["knn", "decision_tree", "logistic_regression"]
TRIALS_PER_K = 40
DIGIT_CLASSES = [0, 1, 7]
N_PER_CLASS = 5


def build_model(model_key):
    if model_key == "knn":
        return KNeighborsClassifier(n_neighbors=3)
    if model_key == "decision_tree":
        return DecisionTreeClassifier(max_depth=6, random_state=42)
    if model_key == "logistic_regression":
        return LogisticRegression(max_iter=2000, random_state=42)
    raise ValueError(model_key)


def needs_scaling(model_key):
    return model_key in ("knn", "logistic_regression")


def evaluate_once(model_key, X_batch, y_batch, X_test, y_test, average):
    scaler = StandardScaler().fit(X_batch) if needs_scaling(model_key) else None
    Xtr = scaler.transform(X_batch) if scaler else X_batch
    Xte = scaler.transform(X_test) if scaler else X_test

    model = build_model(model_key)
    model.fit(Xtr, y_batch)
    pred = model.predict(Xte)

    acc = accuracy_score(y_test, pred)
    prec = precision_score(y_test, pred, average=average, zero_division=0)
    rec = recall_score(y_test, pred, average=average, zero_division=0)
    f1 = f1_score(y_test, pred, average=average, zero_division=0)
    cm = confusion_matrix(y_test, pred, labels=DIGIT_CLASSES).tolist()
    return acc, prec, rec, f1, cm


def pick_labeling_batch(X_trainval, y_trainval):
    indices = []
    for cls in DIGIT_CLASSES:
        cls_indices = np.where(y_trainval == cls)[0]
        chosen = RNG.choice(cls_indices, size=min(N_PER_CLASS, len(cls_indices)), replace=False)
        indices.extend(chosen.tolist())
    RNG.shuffle(indices)
    return indices


def corrupt_labels(y_true_batch, k):
    y = np.array(y_true_batch, copy=True)
    n = len(y)
    if k <= 0:
        return y
    flip_positions = RNG.choice(n, size=min(k, n), replace=False)
    for pos in flip_positions:
        true_cls = y[pos]
        other_classes = [c for c in DIGIT_CLASSES if c != true_cls]
        y[pos] = other_classes[RNG.randint(0, len(other_classes))]
    return y


def main():
    d = load_digits()
    X = np.asarray(d.data, dtype=float)  # 64 pixel intensities, 0-16
    y = np.asarray(d.target)

    mask = np.isin(y, DIGIT_CLASSES)
    X = X[mask]
    y = y[mask]

    train_ids, holdout_ids = train_test_split(np.arange(len(y)), test_size=0.4, random_state=42, stratify=y)
    validation_ids, test_ids = train_test_split(holdout_ids, test_size=0.5, random_state=43, stratify=y[holdout_ids])
    X_trainval, y_trainval = X[train_ids], y[train_ids]
    X_test, y_test = X[validation_ids], y[validation_ids]

    batch_idx = pick_labeling_batch(X_trainval, y_trainval)
    X_batch = X_trainval[batch_idx]
    y_batch_true = y_trainval[batch_idx]
    n = len(batch_idx)

    labeling_batch = []
    for i in range(n):
        pixels = X_batch[i]
        features = {}
        for r in range(8):
            for c in range(8):
                features[f"r{r}c{c}"] = round(float(pixels[r * 8 + c]), 2)
        labeling_batch.append({
            "id": i,
            "features": features,
            "true_label": str(int(y_batch_true[i])),
        })

    performance_by_correct_count = {}
    all_accuracies = []
    for model_key in MODEL_KEYS:
        per_k = []
        for correct in range(n + 1):
            wrong = n - correct
            accs, precs, recs, f1s, cms = [], [], [], [], []
            trials = 1 if wrong == 0 else TRIALS_PER_K
            for _ in range(trials):
                y_corrupted = corrupt_labels(y_batch_true, wrong)
                acc, prec, rec, f1, cm = evaluate_once(model_key, X_batch, y_corrupted, X_test, y_test, "macro")
                accs.append(acc)
                precs.append(prec)
                recs.append(rec)
                f1s.append(f1)
                cms.append(np.array(cm))
            representative = int(np.argmin(np.abs(np.asarray(accs) - np.mean(accs))))
            entry = {
                "correct_count": correct,
                "accuracy": float(accs[representative]),
                "precision": float(precs[representative]),
                "recall": float(recs[representative]),
                "f1": float(f1s[representative]),
                "confusion_matrix": cms[representative].tolist(),
                "mean_metrics": {name: float(np.mean(values)) for name, values in zip(["accuracy", "precision", "recall", "f1"], [accs, precs, recs, f1s])},
                "accuracy_std": float(np.std(accs)),
                "trials": trials,
            }
            per_k.append(entry)
            all_accuracies.append(entry["accuracy"])
        performance_by_correct_count[model_key] = per_k

    out = {
        "key": "digit_recognition_v2",
        "labels": [str(c) for c in DIGIT_CLASSES],
        "feature_names": [f"pixel row {r} col {c}" for r in range(8) for c in range(8)],
        "labeling_batch": labeling_batch,
        "performance_by_correct_count": performance_by_correct_count,
        "baseline_accuracy": round(min(all_accuracies), 4),
        "best_accuracy": round(max(all_accuracies), 4),
        "test_samples": int(len(X_test)),
        "simulation": {
            "version": "digit-v2-2026-09", "engine": "scikit-learn", "engineVersion": sklearn.__version__,
            "seed": 42, "validationSplitSeed": 43, "trialsPerCorruptionCount": TRIALS_PER_K,
            "trainingIds": train_ids[batch_idx].tolist(), "validationIds": validation_ids.tolist(), "reservedTestIds": test_ids.tolist(),
            "trainingCount": n, "validationCount": len(validation_ids), "reservedTestCount": len(test_ids),
            "matrixType": "representative-run", "metricAverage": "macro", "zeroDivision": 0,
            "preprocessing": "StandardScaler.fit(training batch only); tree: none",
            "representativeSelection": "trial nearest mean accuracy, first tie",
            "modelParameters": {"knn": {"n_neighbors": 3}, "decision_tree": {"max_depth": 6, "random_state": 42}, "logistic_regression": {"max_iter": 2000, "random_state": 42}},
        },
    }

    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path(__file__).resolve().parents[2] / "src/Games/Modules/AI_Model_Lab/activities/digit_recognition_v2.php")
    args = parser.parse_args()
    out.update({
        "title": "손글씨 라벨링과 품질 비교", "difficulty": "easy", "display_type": "pixel_grid_8x8",
        "dataset_label": "실제 8×8 손글씨 0·1·7, 균형 추출 15개",
        "description": "숫자 모양을 보고 라벨을 붙입니다. 이후에는 같은 정답 개수로 수행한 사전 실험을 비교합니다. 이번 입력으로 즉시 훈련한 결과는 아닙니다.",
        "feature_labels": {}, "label_labels": {str(c): f"숫자 {c}" for c in DIGIT_CLASSES},
        "models": MODELS, "retry_penalty_per_attempt": 0,
    })
    args.output.write_text("<?php\ndeclare(strict_types=1);\n// Generated by scripts/edu-game/generate_digit_labeling_data.py.\nreturn " + php_literal(out) + ";\n", encoding="utf-8")

    print("batch_size=", n, "baseline=", out["baseline_accuracy"], "best=", out["best_accuracy"])
    for model_key, series in performance_by_correct_count.items():
        accs = [e["accuracy"] for e in series]
        print(f"  {model_key}: accuracy from correct=0 -> {accs[0]}, correct={n} -> {accs[-1]}")
        print("   full curve:", accs)


if __name__ == "__main__":
    main()

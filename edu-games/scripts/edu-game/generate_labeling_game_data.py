"""
Generate real (not fabricated) data for the "label real samples, then train
and see the real resulting performance" redesign of the AI Model Lab game.

For each activity, a small fixed "labeling batch" of N real samples is
picked from the training split (with real feature values + real ground
truth labels). For every model and every possible count of correctly-
labeled samples in that batch (0..N), we run many trials that genuinely
corrupt exactly that many labels (chosen at random each trial), retrain the
model on the batch, and evaluate on the SAME held-out real test set used
throughout this project — then average the resulting metrics across trials.
This is a real empirical "how much does labeling quality actually affect
this model's real performance" curve, not a fabricated one.
"""
import json
import warnings
import numpy as np
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


def build_model(model_key):
    if model_key == "knn":
        return KNeighborsClassifier(n_neighbors=3)
    if model_key == "decision_tree":
        return DecisionTreeClassifier(max_depth=4, random_state=42)
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
    cm = confusion_matrix(y_test, pred, labels=sorted(set(y_test))).tolist()
    return acc, prec, rec, f1, cm


def pick_labeling_batch(X_trainval, y_trainval, n_per_class, class_values):
    """Stratified pick of a small real, fixed labeling batch (indices into X_trainval)."""
    indices = []
    for cls in class_values:
        cls_indices = np.where(y_trainval == cls)[0]
        chosen = RNG.choice(cls_indices, size=min(n_per_class, len(cls_indices)), replace=False)
        indices.extend(chosen.tolist())
    RNG.shuffle(indices)
    return indices


def corrupt_labels(y_true_batch, k, class_values):
    """Return a copy of y_true_batch with exactly k randomly-chosen entries
    flipped to a different (randomly chosen) valid class. k=0 returns the
    original labels unchanged (a perfectly-labeled batch)."""
    y = np.array(y_true_batch, copy=True)
    n = len(y)
    if k <= 0:
        return y
    flip_positions = RNG.choice(n, size=min(k, n), replace=False)
    for pos in flip_positions:
        true_cls = y[pos]
        other_classes = [c for c in class_values if c != true_cls]
        y[pos] = other_classes[RNG.randint(0, len(other_classes))]
    return y


def build_activity(activity_key, X, y, average, labels, feature_names, n_per_class, feature_display_names):
    X = np.asarray(X, dtype=float)
    y = np.asarray(y)
    class_values = sorted(set(y.tolist()))

    X_trainval, X_test, y_trainval, y_test = train_test_split(
        X, y, test_size=0.3, random_state=42, stratify=y
    )

    batch_idx = pick_labeling_batch(X_trainval, y_trainval, n_per_class, class_values)
    X_batch = X_trainval[batch_idx]
    y_batch_true = y_trainval[batch_idx]
    n = len(batch_idx)

    labeling_batch = []
    for i in range(n):
        feats = {name: round(float(X_batch[i][j]), 2) for j, name in enumerate(feature_display_names)}
        labeling_batch.append({
            "id": i,
            "features": feats,
            "true_label": labels[int(y_batch_true[i])] if isinstance(y_batch_true[i], (int, np.integer)) else str(y_batch_true[i]),
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
                y_corrupted = corrupt_labels(y_batch_true, wrong, class_values)
                acc, prec, rec, f1, cm = evaluate_once(model_key, X_batch, y_corrupted, X_test, y_test, average)
                accs.append(acc)
                precs.append(prec)
                recs.append(rec)
                f1s.append(f1)
                cms.append(np.array(cm))
            avg_cm = np.round(np.mean(cms, axis=0)).astype(int).tolist()
            entry = {
                "correct_count": correct,
                "accuracy": round(float(np.mean(accs)), 4),
                "precision": round(float(np.mean(precs)), 4),
                "recall": round(float(np.mean(recs)), 4),
                "f1": round(float(np.mean(f1s)), 4),
                "confusion_matrix": avg_cm,
            }
            per_k.append(entry)
            all_accuracies.append(entry["accuracy"])
        performance_by_correct_count[model_key] = per_k

    return {
        "key": activity_key,
        "labels": labels,
        "feature_names": feature_names,
        "labeling_batch": labeling_batch,
        "performance_by_correct_count": performance_by_correct_count,
        "baseline_accuracy": round(min(all_accuracies), 4),
        "best_accuracy": round(max(all_accuracies), 4),
        "test_samples": int(len(X_test)),
    }


def run_titanic():
    import urllib.request
    import csv
    import io

    url = "https://raw.githubusercontent.com/datasciencedojo/datasets/master/titanic.csv"
    with urllib.request.urlopen(url, timeout=20) as resp:
        raw = resp.read().decode("utf-8")
    reader = csv.DictReader(io.StringIO(raw))
    rows = list(reader)

    X = []
    y = []
    for r in rows:
        try:
            pclass = float(r["Pclass"])
            sex = 1.0 if r["Sex"].strip().lower() == "male" else 0.0
            age_raw = r.get("Age", "").strip()
            age = float(age_raw) if age_raw else 29.7
            sibsp = float(r.get("SibSp", 0) or 0)
            parch = float(r.get("Parch", 0) or 0)
            fare_raw = r.get("Fare", "").strip()
            fare = float(fare_raw) if fare_raw else 32.2
            embarked_raw = r.get("Embarked", "S").strip() or "S"
            embarked = {"S": 0.0, "C": 1.0, "Q": 2.0}.get(embarked_raw, 0.0)
            survived = int(r["Survived"])
        except (KeyError, ValueError):
            continue
        X.append([pclass, sex, age, sibsp, parch, fare, embarked])
        y.append(survived)

    return build_activity(
        "titanic_survival", X, y,
        average="binary",
        labels=["did_not_survive", "survived"],
        feature_names=["pclass", "sex", "age", "sibsp", "parch", "fare", "embarked"],
        n_per_class=10,
        feature_display_names=["pclass", "sex", "age", "sibsp", "parch", "fare", "embarked"],
    )


if __name__ == "__main__":
    out = {}
    try:
        out["titanic_survival"] = run_titanic()
    except Exception as e:
        print("Titanic fetch/build failed:", e)
        out["titanic_survival"] = None

    with open("labeling_game_data.json", "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=2)

    for key, activity in out.items():
        if activity is None:
            print(key, "FAILED")
            continue
        n = len(activity["labeling_batch"])
        print(key, "batch_size=", n, "baseline=", activity["baseline_accuracy"], "best=", activity["best_accuracy"])
        for model_key, series in activity["performance_by_correct_count"].items():
            accs = [e["accuracy"] for e in series]
            print(f"  {model_key}: accuracy from correct=0 -> {accs[0]}, correct={n} -> {accs[-1]}")

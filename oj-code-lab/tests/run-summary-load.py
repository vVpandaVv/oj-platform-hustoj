"""Bounded, paced concurrency check; not an HTTP or production capacity test."""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import subprocess
import time
from datetime import datetime, timezone


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("php", "php-ext", "wordpress", "config", "state", "private-dir", "report"):
        parser.add_argument("--" + name, required=True, type=Path)
    parser.add_argument("--workers", type=int, default=8)
    parser.add_argument("--requests", type=int, default=250)
    parser.add_argument("--interval-ms", type=int, default=40)
    args = parser.parse_args()
    if not (1 <= args.workers <= 16 and 1 <= args.requests <= 1000 and 0 <= args.interval_ms and args.requests * args.interval_ms <= 30000):
        parser.error("Workload must stay bounded")
    private = args.private_dir.resolve()
    private.mkdir(parents=True, exist_ok=False)
    environment = os.environ.copy()
    environment.update(OCL_LOAD_CONFIG=str(args.config.resolve()), OCL_LOAD_STATE=str(args.state.resolve()), OCL_LOAD_WP=str(args.wordpress.resolve()), OCL_LOAD_PRIVATE=str(private), OCL_LOAD_REQUESTS=str(args.requests), OCL_LOAD_INTERVAL_MS=str(args.interval_ms))
    command = [str(args.php.resolve()), "-d", "extension_dir=" + str(args.php_ext.resolve()), "-d", "extension=mysqli", "-d", "extension=openssl", "-d", "extension=mbstring", "-d", "memory_limit=256M", str(Path(__file__).with_name("summary-load.php").resolve())]
    processes, handles = [], []
    deadline = time.monotonic() + 60

    def single(mode):
        target = private / (mode + ".json")
        child_env = dict(environment, OCL_LOAD_RESULT=str(target))
        with (private / (mode + ".log")).open("wb") as output:
            result = subprocess.run(command + [mode], env=child_env, stdout=output, stderr=subprocess.STDOUT, timeout=max(1, deadline - time.monotonic()))
        if result.returncode != 0:
            raise RuntimeError(mode + " failed; private diagnostics retained")
        return json.loads(target.read_text(encoding="utf-8"))

    setup = None
    try:
        setup = single("setup")
        if setup["notebooks"] != 100 or setup["storedNotebookBytes"] < 200_000_000:
            raise RuntimeError("Required 100-notebook / 200 MB fixture was not created")
        for index in range(args.workers):
            handle = (private / ("worker-" + str(index) + ".log")).open("wb")
            handles.append(handle)
            child_env = dict(environment, OCL_LOAD_WORKER=str(index), OCL_LOAD_RESULT=str(private / ("worker-" + str(index) + ".json")))
            processes.append(subprocess.Popen(command + ["worker"], env=child_env, stdout=handle, stderr=subprocess.STDOUT))
        while not all((private / ("ready-" + str(index))).is_file() for index in range(args.workers)):
            if time.monotonic() >= deadline or any(process.poll() is not None for process in processes):
                raise RuntimeError("Workers did not reach the synchronized start barrier")
            time.sleep(0.025)
        pending = private / "start.pending"
        pending.write_text(json.dumps({"startAt": time.time() + 0.25}), encoding="utf-8")
        pending.replace(private / "start.json")
        for process in processes:
            if process.wait(timeout=max(1, deadline - time.monotonic())) != 0:
                raise RuntimeError("A worker failed; private diagnostics retained")
        results = [json.loads((private / ("worker-" + str(index) + ".json")).read_text(encoding="utf-8")) for index in range(args.workers)]
        latencies = sorted(value for worker in results for value in worker["latencyMs"])
        intervals = [value for worker in results for value in worker["intervals"]]
        elapsed = max(end for start, end in intervals) - min(start for start, end in intervals)
        events = sorted([(start, 1) for start, end in intervals] + [(end, -1) for start, end in intervals])
        concurrent = peak_concurrent = 0
        for _, delta in events:
            concurrent += delta
            peak_concurrent = max(peak_concurrent, concurrent)
        failures = sum(worker["failures"] for worker in results)
        rows = []
        for worker in results:
            ordered = sorted(worker["latencyMs"])
            rows.append({key: worker[key] for key in ("worker", "requests", "failures", "peakMemoryBytes", "maximumResponseBytes", "maximumQueryBytes", "summaryQueryCount", "unsafeProjectionDetected")})
            rows[-1]["p95Ms"] = ordered[math.ceil(len(ordered) * 0.95) - 1]
        report = {
            "reportVersion": 1, "generatedAt": datetime.now(timezone.utc).isoformat(),
            "scope": "isolated-loopback-mariadb-concurrent-real-notebook-summary-repository",
            "passed": failures == 0 and len(latencies) == args.workers * args.requests and peak_concurrent >= 2,
            "environment": setup, "workers": args.workers, "requestsPerWorker": args.requests,
            "requestIntervalMsPerWorker": args.interval_ms, "requests": len(latencies), "failures": failures,
            "measuredElapsedSeconds": round(elapsed, 6), "observedRequestsPerSecond": round(len(latencies) / elapsed, 3),
            "latencyMs": {"p50": latencies[math.ceil(len(latencies) * 0.50) - 1], "p95": latencies[math.ceil(len(latencies) * 0.95) - 1], "max": max(latencies)},
            "peakOverlappingRepositoryCalls": peak_concurrent,
            "maximumResponseBytes": max(worker["maximumResponseBytes"] for worker in results),
            "summaryOnlyProjectionVerified": all(not worker["unsafeProjectionDetected"] and worker["summaryQueryCount"] == args.requests for worker in results),
            "workerResults": rows,
            "sourceSha256": {"Notebook_Repository.php": hashlib.sha256((Path(__file__).parent.parent / "src/Database/Notebook_Repository.php").read_bytes()).hexdigest(), "summary-load.php": hashlib.sha256(Path(__file__).with_name("summary-load.php").read_bytes()).hexdigest()},
            "limitations": ["Paced local SQL workload; observed throughput is not maximum production capacity.", "WordPress core is bootstrapped independently in each PHP process. Actual summaries_for_users is called; REST, HTTP, platform permission checks, proxy, browser and full plugin startup are outside the measured workload.", "100 generated notebooks contain at least 200 MB of stored payload. Each request returns only 50 learners' summary fields.", "No production site access or real learner data. Database name, credentials and runtime paths are excluded."]
        }
        cleanup = single("cleanup")
        report["fixtureCleanup"] = cleanup
        report["passed"] = report["passed"] and cleanup["remainingRows"] == 0 and cleanup["removedRows"] == 100
        args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({key: report[key] for key in ("passed", "requests", "failures", "measuredElapsedSeconds", "observedRequestsPerSecond", "latencyMs", "peakOverlappingRepositoryCalls", "maximumResponseBytes")}, ensure_ascii=False))
        if not report["passed"]:
            raise RuntimeError("Concurrent summary acceptance failed")
    finally:
        for process in processes:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=3)
        for handle in handles:
            handle.close()


if __name__ == "__main__":
    main()

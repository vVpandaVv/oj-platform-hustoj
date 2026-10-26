const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const { spawnSync } = require("node:child_process");

const php = process.argv[2] || "php";
const root = path.resolve(__dirname, "..");
function bridge(action, input) {
    const result = spawnSync(php, [path.join(__dirname, "bridge.php"), action], {
        encoding: "utf8", input: JSON.stringify(input), maxBuffer: 8 * 1024 * 1024,
    });
    assert.equal(result.status, 0, result.stderr || String(result.error));
    return JSON.parse(result.stdout);
}
const definitions = bridge("rules");
let checks = 0;
function gameInstance(id, windowOverrides = {}) {
    const folder = id === "binary-conversion" ? "binary-arcade" : id;
    const source = fs.readFileSync(path.join(root, "blocks", folder, "view.js"), "utf8");
    const name = source.match(/class (\w+)/)[1];
    const context = {
        document: { readyState: "loading", addEventListener() {} },
        window: { setTimeout() {}, localStorage: { setItem() {} }, ...windowOverrides },
        performance: { now: () => 60000 },
    };
    vm.runInNewContext(source.replace("const init = () => {", `globalThis.GameClass = ${name}; const init = () => {`), context);
    const game = Object.create(context.GameClass.prototype);
    game.rules = definitions[id].rules;
    for (const method of ["render", "renderStats", "renderStatsOnly", "renderSubmitButton", "revealAnswer", "setFeedback"]) {
        game[method] = () => {};
    }
    return game;
}
function validate(id, data, expectedScore = data.score, action = "validate") {
    const result = bridge(action, { game: id, data });
    assert.equal(result.code, undefined, `${id}: ${JSON.stringify(result)}`);
    assert.equal(result.score, expectedScore, `${id}: browser/server score mismatch`);
    if (expectedScore < data.score) {
        assert.equal(result.meta.originalScore, data.score, `${id}: original score preserved`);
        assert.equal(result.meta.scoreCapped, true, `${id}: cap recorded`);
    }
    assert.equal(result.correctCount, data.correctCount, `${id}: correct count mismatch`);
    assert.equal(result.accuracy, data.accuracy, `${id}: accuracy mismatch`);
    checks++;
}

for (const id of ["outlier-detection", "missing-values", "data-visualization", "data-extraction", "data-structure-lab", "ai-classifier-lab"]) {
    for (const difficulty of ["easy", "normal", "hard"]) {
        for (const wrongRound of [-1, 0, 4]) {
            const game = gameInstance(id);
            game.state = { difficulty, score: 0, streak: 0, correctCount: 0, wrongCount: 0, history: [] };
            for (let i = 0; i < game.rules.rankedRounds; i++) {
                game.state.round = i + 1;
                const sample = game.pickRankedSample();
                const ok = i !== wrongRound;
                Object.assign(game.state, {
                    currentSample: sample,
                    selected: ok ? (sample.outlierIndexes || sample.answer) : [],
                    answers: ok ? sample.answers : {}, selectedMethod: ok ? sample.method : "wrong",
                    selectedColumns: ok ? sample.relevantColumns : [],
                    selectedProcessing: ok && sample.processingOptions ? sample.processingOptions.find((option) => option.correct).key : "wrong",
                    selectedChart: ok ? sample.chartType : "wrong", selectedInsight: ok ? sample.insight : "wrong",
                    selectedRule: ok ? sample.answerRule : "wrong", selectedLabel: ok ? sample.answerLabel : "wrong",
                });
                game.handleRankedSubmit(ok);
            }
            const run = {
                score: game.state.score, roundsPlayed: game.rules.rankedRounds, correctCount: game.state.correctCount,
                durationSeconds: 60, accuracy: Math.round(game.state.correctCount / game.rules.rankedRounds * 10000) / 100,
                meta: { difficulty, history: game.state.history, wrongCount: game.state.wrongCount },
            };
            validate(id, run, run.score, "grade");
            if (difficulty === "easy" && wrongRound === -1) {
                // The legacy /scores endpoint no longer takes quiz runs.
                assert.equal(bridge("validate", { game: id, data: run }).code, "edu_games_score_endpoint_not_allowed");
                checks++;
            }
        }
    }
}

for (const bits of [4, 6, 8]) {
    for (const rounds of [125, 1000]) {
        const game = gameInstance("binary-conversion");
        game.state = { level: 1, streak: 0 };
        let left = game.rules.levels[0].lines;
        let score = 0;
        for (let i = 0; i < rounds; i++) {
            score += game.scoreFor({ bitLength: bits });
            game.state.streak++;
            if (--left === 0) { game.state.level++; left = game.getLevelSettings().lines; }
        }
        if (rounds === 1000) assert.ok(score > 999999, "Long binary game exercises score cap");
        validate("binary-conversion", { score, roundsPlayed: rounds, correctCount: rounds, durationSeconds: Math.max(60, Math.ceil(rounds * 2.7)), accuracy: 100, meta: { maxBits: bits } }, Math.min(999999, score));
    }
}

for (const difficulty of ["easy", "normal", "hard"]) {
    const game = gameInstance("character-code");
    game.state = { difficulty, streak: 0, currentMistakes: 0, hintCount: 0, playType: "ranked" };
    let score = 0;
    const modes = Object.keys(game.rules.modes);
    for (let i = 0; i < 10; i++) {
        game.state.currentMode = modes[i % modes.length];
        game.state.challenge = { text: game.getDifficulty().phrases[i] };
        score += game.scoreForRound();
        game.state.streak++;
    }
    validate("character-code", { score, roundsPlayed: 10, correctCount: 10, durationSeconds: 180, accuracy: 90.91, meta: { difficulty, mistakes: 1 } });
}

for (const language of ["python", "c"]) {
    const game = gameInstance("code-typing");
    game.state = { mode: "assessment" };
    const sample = game.rules.samples.find((entry) => entry.mode === "assessment" && entry.language === language);
    const score = game.scoreForAssessment({ correct: 90, accuracy: 90, cpm: 18, mistakes: 10 });
    validate("code-typing", { score, roundsPlayed: 1, correctCount: 0, durationSeconds: 300, accuracy: 90,
        meta: { mode: "assessment", language, sampleId: sample.id, typedCharacters: 100, mistakes: 10, samplesCompleted: 0 } });
}
async function verifyBinarySaveMessages() {
    for (const score of [999998, 999999, 1000000, 1000001]) {
        const game = gameInstance("binary-conversion", {
            async fetch(url, options) {
                const submitted = JSON.parse(options.body);
                assert.equal(submitted.score, score, "Raw score is submitted for validation");
                assert.equal(submitted.sessionToken, "valid-token");
                return { ok: true, json: async () => ({ score: { score: Math.min(score, 999999) }, rank: 2 }) };
            },
        });
        game.config = { isLoggedIn: true, restUrl: "/test", sessionToken: "valid-token" };
        game.maxBits = 8;
        game.state = { score, totalSpawned: 1000, correctCount: 1000, durationSeconds: 2700, level: 80, modeHistory: [], problems: [], endLabel: "test" };
        game.renderOverlay = game.renderLeaderboard = () => {};
        game.loadLeaderboard = async () => {};
        await game.saveScore();
        assert.equal(game.state.score, score, "Result screen retains earned score");
        assert.equal(game.state.saveStatus, (score > 999999
            ? "저장 상한인 999,999점으로 기록되었습니다."
            : "점수가 저장되었습니다.") + " 현재 2위입니다.");
        assert.equal(game.state.isSaving, false);
        checks++;
    }
}
verifyBinarySaveMessages().then(() => {
    console.log(`PASS: ${checks} browser/server scoring comparisons and save checks using actual game methods.`);
}).catch((error) => {
    console.error(error);
    process.exitCode = 1;
});

/**
 * Seating arrangement engine — ported from seat-arranger_v2's random-search
 * constraint solver (front-row placement, "apart" pairs kept a minimum
 * distance apart, "buddy" pairs kept adjacent, locked/disabled seats). Pure
 * functions only: no DOM, no localStorage, no module-level state — the host
 * page owns the roster/session state and calls arrange() with it explicitly.
 */
window.HttSeatingEngine = (function () {
    'use strict';

    var DEFAULT_APART_GAP = 2;
    var MAX_APART_GAP = 10;
    // Used only when nobody requested a front seat, where the exact count is
    // moot (see arrange() for the demand-driven calculation used otherwise).
    var DEFAULT_FRONT_ROW_COUNT = 2;

    function makeSeatId(row, col) {
        return row + '-' + col;
    }

    function cleanText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function uniqueList(items) {
        var seen = {};
        var result = [];
        (items || []).forEach(function (item) {
            if (!item || seen[item]) {
                return;
            }
            seen[item] = true;
            result.push(item);
        });
        return result;
    }

    function clampNumber(value, min, max, fallback) {
        var number = Number.parseInt(value, 10);
        if (!Number.isFinite(number)) {
            return fallback;
        }
        return Math.min(max, Math.max(min, number));
    }

    function shuffle(items) {
        var result = items.slice();
        for (var i = result.length - 1; i > 0; i -= 1) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = result[i];
            result[i] = result[j];
            result[j] = tmp;
        }
        return result;
    }

    function parseNames(raw) {
        return uniqueList(
            String(raw)
                .split(/[\n,;，、\t]+/)
                .map(cleanText)
                .filter(Boolean)
        );
    }

    function findRosterName(value, roster) {
        var target = cleanText(value).toLocaleLowerCase();
        if (!target) {
            return '';
        }
        return roster.find(function (student) {
            return student.toLocaleLowerCase() === target;
        }) || '';
    }

    function parsePairLine(line, roster) {
        var clean = cleanText(line).replace(/[，、]/g, ',');
        if (!clean) {
            return [];
        }

        var separated = clean.split(/[,/|;]+/).map(cleanText).filter(Boolean);
        if (separated.length > 1) {
            return separated.map(function (part) {
                return findRosterName(part, roster);
            }).filter(Boolean);
        }

        var exact = findRosterName(clean, roster);
        if (exact) {
            return [exact];
        }
        return clean
            .split(/\s+-\s+|\s+/)
            .map(function (part) {
                return findRosterName(part, roster);
            })
            .filter(Boolean);
    }

    function parsePairs(raw, roster) {
        var pairs = [];
        var standaloneNames = [];

        function addPair(left, right) {
            if (!left || !right || left === right) {
                return;
            }
            var key = [left, right].sort().join('::');
            var exists = pairs.some(function (pair) {
                return pair.slice().sort().join('::') === key;
            });
            if (!exists) {
                pairs.push([left, right]);
            }
        }

        String(raw).split(/\r?\n/).forEach(function (line) {
            var names = parsePairLine(line, roster);
            if (names.length < 2) {
                if (names[0]) {
                    standaloneNames.push(names[0]);
                }
                return;
            }

            for (var index = 0; index + 1 < names.length; index += 2) {
                addPair(names[index], names[index + 1]);
            }
            if (names.length % 2 === 1) {
                standaloneNames.push(names[names.length - 1]);
            }
        });

        for (var i = 0; i + 1 < standaloneNames.length; i += 2) {
            addPair(standaloneNames[i], standaloneNames[i + 1]);
        }
        return pairs;
    }

    function parseConditionNames(raw, roster) {
        var names = [];
        String(raw)
            .split(/[\n,;，、/|\t]+/)
            .map(cleanText)
            .filter(Boolean)
            .forEach(function (entry) {
                var exact = findRosterName(entry, roster);
                if (exact) {
                    names.push(exact);
                    return;
                }
                entry.split(/\s+/).forEach(function (part) {
                    var matched = findRosterName(part, roster);
                    if (matched) {
                        names.push(matched);
                    }
                });
            });
        return uniqueList(names);
    }

    function isFrontSeat(seat) {
        return Boolean(seat.isFront);
    }

    /*
     * Two seats are desk-mates only if they're the fixed left-right pairing
     * of a real paired desk — row 0's columns 0&1 share a desk, 2&3 share
     * the next desk, and so on (1-indexed: "1,2번 / 3,4번 / 5,6번 짝").
     * Front-back neighbors or seats across an aisle (e.g. columns 1&2) are
     * NOT desk-mates even though they're geometrically adjacent — buddy
     * pairs must land on an actual shared desk, not just "next to" each
     * other in any direction.
     */
    function isDeskMate(a, b) {
        return a.row === b.row && a.col !== b.col && Math.floor(a.col / 2) === Math.floor(b.col / 2);
    }

    function isTouching(a, b) {
        var rowGap = Math.abs(a.row - b.row);
        var colGap = Math.abs(a.col - b.col);
        return Math.max(rowGap, colGap) === 1;
    }

    function isTooClose(a, b, includeDiagonal, apartGap) {
        var rowGap = Math.abs(a.row - b.row);
        var colGap = Math.abs(a.col - b.col);
        var minimumSeatDistance = (apartGap || DEFAULT_APART_GAP) + 1;
        var straightGap = rowGap === 0 ? colGap : (colGap === 0 ? rowGap : Infinity);

        if (isTouching(a, b)) {
            return true;
        }
        if (straightGap < minimumSeatDistance) {
            return true;
        }
        return Boolean(includeDiagonal) && rowGap < minimumSeatDistance && colGap < minimumSeatDistance;
    }

    function seatDistanceSquared(a, b) {
        var rowGap = a.row - b.row;
        var colGap = a.col - b.col;
        return rowGap * rowGap + colGap * colGap;
    }

    function removeSeat(seats, seatId) {
        return seats.filter(function (seat) {
            return seat.id !== seatId;
        });
    }

    function countStudentsBetween(map, a, b) {
        var count = 0;
        if (a.row === b.row) {
            var startCol = Math.min(a.col, b.col) + 1;
            var endCol = Math.max(a.col, b.col);
            for (var col = startCol; col < endCol; col += 1) {
                if (map.get(makeSeatId(a.row, col))) {
                    count += 1;
                }
            }
            return count;
        }
        if (a.col === b.col) {
            var startRow = Math.min(a.row, b.row) + 1;
            var endRow = Math.max(a.row, b.row);
            for (var row = startRow; row < endRow; row += 1) {
                if (map.get(makeSeatId(row, a.col))) {
                    count += 1;
                }
            }
            return count;
        }
        return Infinity;
    }

    function findAssignedSeat(map, seatIndex, student) {
        for (var entry of map.entries()) {
            if (entry[1] === student) {
                return seatIndex.get(entry[0]) || null;
            }
        }
        return null;
    }

    function apartSeatMetrics(student, seat, map, seatIndex, settings) {
        var touching = 0;
        var violations = 0;
        var closestDistance = 0;
        var hasPartner = false;

        settings.apartPairs.forEach(function (pair) {
            var left = pair[0];
            var right = pair[1];
            if (left !== student && right !== student) {
                return;
            }
            var otherName = left === student ? right : left;
            var otherSeat = findAssignedSeat(map, seatIndex, otherName);
            if (!otherSeat) {
                return;
            }

            var distance = seatDistanceSquared(seat, otherSeat);
            closestDistance = hasPartner ? Math.min(closestDistance, distance) : distance;
            hasPartner = true;
            if (isTouching(seat, otherSeat)) {
                touching += 1;
            }
            if (isTooClose(seat, otherSeat, settings.diagonal, settings.apartGap)) {
                violations += 1;
            }
        });

        return { touching: touching, violations: violations, closestDistance: closestDistance };
    }

    function chooseApartAwareSeat(student, seats, map, seatIndex, settings) {
        var ranked = shuffle(seats).map(function (seat) {
            var metrics = apartSeatMetrics(student, seat, map, seatIndex, settings);
            return {
                seat: seat,
                touching: metrics.touching,
                violations: metrics.violations,
                closestDistance: metrics.closestDistance,
                tieBreaker: Math.random(),
            };
        });

        ranked.sort(function (a, b) {
            return a.touching - b.touching
                || a.violations - b.violations
                || b.closestDistance - a.closestDistance
                || a.tieBreaker - b.tieBreaker;
        });
        return ranked[0] ? ranked[0].seat : null;
    }

    function chooseFrontSeat(student, seats, map, seatIndex, settings) {
        return chooseApartAwareSeat(student, seats, map, seatIndex, settings);
    }

    function chooseSeatForStudent(student, seats, map, seatIndex, settings) {
        var allowedSeats = settings.frontSet.has(student) ? seats.filter(isFrontSeat) : seats;
        return chooseApartAwareSeat(student, allowedSeats, map, seatIndex, settings);
    }

    function chooseFrontPairSeats(left, right, seats, map, seatIndex, settings) {
        var placements = [];
        for (var first = 0; first < seats.length; first += 1) {
            for (var second = first + 1; second < seats.length; second += 1) {
                [[seats[first], seats[second]], [seats[second], seats[first]]].forEach(function (order) {
                    var leftSeat = order[0];
                    var rightSeat = order[1];
                    var probe = new Map(map);
                    var leftMetrics = apartSeatMetrics(left, leftSeat, probe, seatIndex, settings);
                    probe.set(leftSeat.id, left);
                    var rightMetrics = apartSeatMetrics(right, rightSeat, probe, seatIndex, settings);
                    placements.push({
                        leftSeat: leftSeat,
                        rightSeat: rightSeat,
                        touching: leftMetrics.touching + rightMetrics.touching,
                        violations: leftMetrics.violations + rightMetrics.violations,
                        distance: seatDistanceSquared(leftSeat, rightSeat),
                        tieBreaker: Math.random(),
                    });
                });
            }
        }

        placements.sort(function (a, b) {
            return a.touching - b.touching
                || a.violations - b.violations
                || b.distance - a.distance
                || a.tieBreaker - b.tieBreaker;
        });

        var best = placements[0] || null;
        // Only commit when the top pick actually satisfies "떨어뜨리기" —
        // settling for "least-bad but still touching/violating" here would
        // permanently lock this pair into that violation for the whole
        // attempt (e.g. front-back seats one row apart, exactly the
        // "1행1열·2행1열" case). Returning null instead leaves both
        // students unplaced so the individual front-seat stage right after
        // — and, across the outer random-search retries, a differently
        // shuffled seat/processing order — gets a real shot at a
        // compliant arrangement instead of freezing this pair into
        // whatever the greedy pass order happened to leave over.
        if (best && (best.touching > 0 || best.violations > 0)) {
            return null;
        }
        return best;
    }

    function violatesApart(student, seat, map, seatIndex, settings) {
        return apartSeatMetrics(student, seat, map, seatIndex, settings).violations > 0;
    }

    function violatesPartial(student, seat, map, seatIndex, settings) {
        if (settings.frontSet.has(student) && !isFrontSeat(seat)) {
            return true;
        }
        return violatesApart(student, seat, map, seatIndex, settings);
    }

    function chooseBuddySeats(left, right, openSeats, map, seatIndex, settings) {
        var pairs = [];
        for (var i = 0; i < openSeats.length; i += 1) {
            for (var j = i + 1; j < openSeats.length; j += 1) {
                if (isDeskMate(openSeats[i], openSeats[j])) {
                    pairs.push([openSeats[i], openSeats[j]]);
                }
            }
        }

        var shuffledPairs = shuffle(pairs);
        for (var p = 0; p < shuffledPairs.length; p += 1) {
            var pair = shuffledPairs[p];
            var order = Math.random() > 0.5 ? [left, right] : [right, left];
            var firstMap = new Map(map);
            if (violatesPartial(order[0], pair[0], firstMap, seatIndex, settings)) {
                continue;
            }
            firstMap.set(pair[0].id, order[0]);
            if (violatesPartial(order[1], pair[1], firstMap, seatIndex, settings)) {
                continue;
            }
            return pair;
        }

        return null;
    }

    function constructCandidate(activeSeats, students, lockedAssignments, lockedNames, seatIndex, settings) {
        var map = new Map(lockedAssignments);
        var openSeats = shuffle(activeSeats.filter(function (seat) { return !map.has(seat.id); }));
        var remaining = shuffle(students.filter(function (student) { return !lockedNames.has(student); }));
        var notes = [];

        var pendingFront = {};
        shuffle(settings.frontNames.filter(function (student) { return remaining.indexOf(student) !== -1; }))
            .forEach(function (student) { pendingFront[student] = true; });

        // Buddy pairs go first, before individual front placement: if a
        // buddy pair is handled one student at a time (as front seats used
        // to be filled), whichever member wants the front row gets seated
        // alone the moment their turn comes up, and by the time the buddy
        // stage runs later they're already removed from `remaining` — so
        // they're silently never paired at all. At least one partner
        // needing a front seat still forces both onto the same desk, so
        // both must land in the front zone together.
        shuffle(settings.buddyPairs).forEach(function (pair) {
            var left = pair[0];
            var right = pair[1];
            if (!pendingFront[left] && !pendingFront[right]) {
                return;
            }
            if (remaining.indexOf(left) === -1 || remaining.indexOf(right) === -1) {
                return;
            }
            var pairSeats = chooseBuddySeats(left, right, openSeats.filter(isFrontSeat), map, seatIndex, settings);
            if (!pairSeats) {
                // Leave both in `remaining`/`pendingFront` — the front-seat
                // and generic buddy stages below still get a chance at them.
                return;
            }
            var flipped = Math.random() > 0.5;
            map.set(pairSeats[0].id, flipped ? right : left);
            map.set(pairSeats[1].id, flipped ? left : right);
            openSeats = removeSeat(removeSeat(openSeats, pairSeats[0].id), pairSeats[1].id);
            remaining = remaining.filter(function (name) { return name !== left && name !== right; });
            delete pendingFront[left];
            delete pendingFront[right];
        });

        shuffle(settings.apartPairs).forEach(function (pair) {
            var left = pair[0];
            var right = pair[1];
            if (!pendingFront[left] || !pendingFront[right]) {
                return;
            }
            var placement = chooseFrontPairSeats(left, right, openSeats.filter(isFrontSeat), map, seatIndex, settings);
            if (!placement) {
                return;
            }

            map.set(placement.leftSeat.id, left);
            map.set(placement.rightSeat.id, right);
            openSeats = removeSeat(removeSeat(openSeats, placement.leftSeat.id), placement.rightSeat.id);
            remaining = remaining.filter(function (name) { return name !== left && name !== right; });
            delete pendingFront[left];
            delete pendingFront[right];
        });

        shuffle(Object.keys(pendingFront)).forEach(function (student) {
            var frontSeats = openSeats.filter(isFrontSeat);
            // Fall back to any open seat when the front zone itself is out
            // of room, rather than dropping the student from the whole
            // arrangement — evaluateAssignment() already reports an unmet
            // front-row request as its own error, so this degrades to
            // "couldn't get a front seat" instead of "couldn't be seated
            // at all".
            var seat = chooseFrontSeat(student, frontSeats, map, seatIndex, settings)
                || chooseApartAwareSeat(student, openSeats, map, seatIndex, settings);
            remaining = remaining.filter(function (name) { return name !== student; });
            if (!seat) {
                notes.push(student + ' 학생을 앉힐 좌석이 없습니다.');
                return;
            }
            map.set(seat.id, student);
            openSeats = removeSeat(openSeats, seat.id);
        });

        shuffle(settings.buddyPairs).forEach(function (pair) {
            var left = pair[0];
            var right = pair[1];
            if (remaining.indexOf(left) === -1 || remaining.indexOf(right) === -1) {
                return;
            }
            var pairSeats = chooseBuddySeats(left, right, openSeats, map, seatIndex, settings);
            if (!pairSeats) {
                notes.push(left + ', ' + right + ' 조합을 붙여 앉힐 좌석이 부족합니다.');
                return;
            }
            var flipped = Math.random() > 0.5;
            map.set(pairSeats[0].id, flipped ? right : left);
            map.set(pairSeats[1].id, flipped ? left : right);
            openSeats = removeSeat(removeSeat(openSeats, pairSeats[0].id), pairSeats[1].id);
            remaining = remaining.filter(function (name) { return name !== left && name !== right; });
        });

        shuffle(remaining).forEach(function (student) {
            if (!openSeats.length) {
                return;
            }
            var seat = chooseSeatForStudent(student, openSeats, map, seatIndex, settings) || openSeats[0];
            map.set(seat.id, student);
            openSeats = removeSeat(openSeats, seat.id);
        });

        var placed = new Set(map.values());
        var unseated = students.filter(function (student) { return !placed.has(student); });
        return { map: map, notes: notes, unseated: unseated };
    }

    function evaluateAssignment(map, seatIndex, settings, unseated) {
        var unseatedErrors = unseated.map(function (student) {
            return student + ' 학생을 앉힐 좌석이 부족합니다.';
        });
        var front = [];
        var touchingApart = [];
        var apart = [];
        var soft = [];

        settings.frontNames.forEach(function (student) {
            var seat = findAssignedSeat(map, seatIndex, student);
            if (!seat || !isFrontSeat(seat)) {
                front.push(student + ' 학생이 1~2행에 배치되지 않았습니다.');
            }
        });

        settings.apartPairs.forEach(function (pair) {
            var left = pair[0];
            var right = pair[1];
            var leftSeat = findAssignedSeat(map, seatIndex, left);
            var rightSeat = findAssignedSeat(map, seatIndex, right);
            if (!leftSeat || !rightSeat) {
                return;
            }
            if (isTouching(leftSeat, rightSeat)) {
                touchingApart.push(left + ', ' + right + ' 조합이 서로 붙어 있습니다.');
            }
            if (isTooClose(leftSeat, rightSeat, settings.diagonal, settings.apartGap)) {
                apart.push(left + ', ' + right + ' 조합이 너무 가깝습니다.');
                return;
            }
            var studentsBetween = countStudentsBetween(map, leftSeat, rightSeat);
            if (Number.isFinite(studentsBetween) && studentsBetween < settings.apartGap) {
                apart.push(left + ', ' + right + ' 사이에 학생이 부족합니다.');
            }
        });

        settings.buddyPairs.forEach(function (pair) {
            var left = pair[0];
            var right = pair[1];
            var leftSeat = findAssignedSeat(map, seatIndex, left);
            var rightSeat = findAssignedSeat(map, seatIndex, right);
            if (leftSeat && rightSeat && !isDeskMate(leftSeat, rightSeat)) {
                soft.push(left + ', ' + right + ' 조합은 붙여 앉히지 못했습니다.');
            }
        });

        var uniqueFront = uniqueList(front);
        var uniqueTouchingApart = uniqueList(touchingApart);
        var uniqueApart = uniqueList(apart);
        return {
            front: uniqueFront,
            touchingApart: uniqueTouchingApart,
            apart: uniqueApart,
            hard: uniqueList(unseatedErrors.concat(uniqueFront, uniqueTouchingApart, uniqueApart)),
            soft: uniqueList(soft),
        };
    }

    function rankAssignment(candidate, evaluation) {
        return [
            candidate.unseated.length,
            evaluation.front.length,
            evaluation.touchingApart.length,
            evaluation.apart.length,
            evaluation.soft.length,
            candidate.notes.length,
        ];
    }

    function isBetterRank(candidate, current) {
        for (var index = 0; index < candidate.length; index += 1) {
            if (candidate[index] !== current[index]) {
                return candidate[index] < current[index];
            }
        }
        return false;
    }

    /**
     * @param {object} options
     * @param {number} options.rows
     * @param {number} options.cols
     * @param {string[]} options.students
     * @param {string[]} [options.disabledSeatIds]
     * @param {Object<string,string>} [options.lockedAssignments] seatId -> student name
     * @param {string[]} [options.frontNames]
     * @param {Array<[string,string]>} [options.apartPairs]
     * @param {number} [options.apartGap]
     * @param {Array<[string,string]>} [options.buddyPairs]
     * @param {boolean} [options.diagonal]
     */
    function arrange(options) {
        var rows = clampNumber(options.rows, 1, 20, 5);
        var cols = clampNumber(options.cols, 1, 20, 6);
        var students = uniqueList(options.students);
        var disabledSeatIds = new Set(options.disabledSeatIds || []);
        var lockedAssignmentsRaw = options.lockedAssignments || {};
        var frontNames = uniqueList(options.frontNames);

        // Demand-driven front-row zone: enough rows to seat every front
        // request, plus one spare row of slack. The extra row matters even
        // when everyone fits exactly (e.g. 6 requests, 6 columns → 1 row is
        // "enough" by headcount alone) because buddy pairs need a whole
        // free desk (2 seats) to land on together, not just any 1 leftover
        // seat — a zero-slack front zone full of odd singletons is exactly
        // the "seats exist but only one at a time" failure this replaces.
        var frontRowCount = frontNames.length > 0
            ? Math.min(rows, Math.ceil(frontNames.length / cols) + 1)
            : Math.min(rows, DEFAULT_FRONT_ROW_COUNT);

        var seats = [];
        for (var row = 0; row < rows; row += 1) {
            for (var col = 0; col < cols; col += 1) {
                var id = makeSeatId(row, col);
                seats.push({
                    id: id,
                    row: row,
                    col: col,
                    isFront: row < frontRowCount,
                    disabled: disabledSeatIds.has(id),
                    student: lockedAssignmentsRaw[id] || null,
                    locked: Boolean(lockedAssignmentsRaw[id]),
                });
            }
        }

        var activeSeats = seats.filter(function (seat) { return !seat.disabled; });
        if (!activeSeats.length) {
            return { error: '사용 가능한 좌석이 없습니다.' };
        }

        var settings = {
            frontNames: frontNames,
            frontSet: new Set(frontNames),
            apartPairs: options.apartPairs || [],
            apartGap: clampNumber(options.apartGap, 1, MAX_APART_GAP, DEFAULT_APART_GAP),
            buddyPairs: options.buddyPairs || [],
            diagonal: options.diagonal !== false,
        };

        var seatIndex = new Map(seats.map(function (seat) { return [seat.id, seat]; }));
        var roster = new Set(students);
        var lockedAssignments = new Map();
        var lockedNames = new Set();

        activeSeats.forEach(function (seat) {
            if (!seat.locked || !seat.student || !roster.has(seat.student) || lockedNames.has(seat.student)) {
                return;
            }
            if (settings.frontSet.has(seat.student) && !isFrontSeat(seat)) {
                return;
            }
            lockedAssignments.set(seat.id, seat.student);
            lockedNames.add(seat.student);
        });

        var lockedSeatIds = new Set(lockedAssignments.keys());
        var best = null;
        var attempts = Math.max(700, Math.min(3500, students.length * activeSeats.length * 4));

        for (var attempt = 0; attempt < attempts; attempt += 1) {
            var candidate = constructCandidate(activeSeats, students, lockedAssignments, lockedNames, seatIndex, settings);
            var evaluation = evaluateAssignment(candidate.map, seatIndex, settings, candidate.unseated);
            var rank = rankAssignment(candidate, evaluation);

            if (!best || isBetterRank(rank, best.rank)) {
                best = { map: candidate.map, notes: candidate.notes, unseated: candidate.unseated, evaluation: evaluation, rank: rank };
            }
            if (rank.every(function (value) { return value === 0; })) {
                break;
            }
        }

        if (!best) {
            return { error: '배치를 만들 수 없습니다.' };
        }

        seats.forEach(function (seat) {
            if (seat.disabled) {
                seat.student = null;
                seat.locked = false;
                return;
            }
            seat.student = best.map.get(seat.id) || null;
            seat.locked = Boolean(seat.student && lockedSeatIds.has(seat.id));
        });

        return { seats: seats, notes: best.notes, unseated: best.unseated, evaluation: best.evaluation };
    }

    return {
        arrange: arrange,
        parseNames: parseNames,
        parsePairs: parsePairs,
        parseConditionNames: parseConditionNames,
        makeSeatId: makeSeatId,
    };
})();

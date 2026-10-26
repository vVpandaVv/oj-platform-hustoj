/**
 * 조 짜기 (group-forming) engine. Two modes:
 *  - random: plain shuffle, round-robin into N groups.
 *  - balanced: snake draft by descending skill score (solved-problem count
 *    from the judge db) so total skill sums across groups end up close —
 *    same idea sports drafts use to keep teams even (pick order
 *    0,1,2,...,N-1,N-1,...,2,1,0,0,1,2,... so the group that picked last
 *    in one round picks first in the next).
 */
window.HttGroupingEngine = (function () {
    'use strict';

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

    function makeEmptyGroups(count) {
        var groups = [];
        for (var i = 0; i < count; i += 1) {
            groups.push([]);
        }
        return groups;
    }

    // Shuffles students within each run of equal skill score so ties don't
    // always draft in the same (e.g. alphabetical) order.
    function shuffleTies(ordered, skillByName) {
        var result = [];
        var i = 0;
        while (i < ordered.length) {
            var j = i;
            var skill = skillByName[ordered[i]] || 0;
            while (j < ordered.length && (skillByName[ordered[j]] || 0) === skill) {
                j += 1;
            }
            result = result.concat(shuffle(ordered.slice(i, j)));
            i = j;
        }
        return result;
    }

    /**
     * @param {string[]} students
     * @param {number} groupCount
     * @param {Object<string,number>|null} skillByName null => random mode
     * @returns {string[][]} groups, in draft order
     */
    function assign(students, groupCount, skillByName) {
        var count = Math.max(1, Math.min(students.length, Math.max(1, groupCount)));
        var groups = makeEmptyGroups(count);

        var ordered = skillByName
            ? shuffleTies(
                students.slice().sort(function (a, b) { return (skillByName[b] || 0) - (skillByName[a] || 0); }),
                skillByName
            )
            : shuffle(students);

        var round = 0;
        var pos = 0;
        ordered.forEach(function (student) {
            var groupIndex = (round % 2 === 0) ? pos : (count - 1 - pos);
            groups[groupIndex].push(student);
            pos += 1;
            if (pos >= count) {
                pos = 0;
                round += 1;
            }
        });

        return groups;
    }

    return { assign: assign };
})();

(function () {
    'use strict';

    var STORAGE_KEY = 'httStudentNameMode';
    var manualSequence = 0;

    function clean(value, fallback) {
        var result = String(value || '').replace(/\s+/g, ' ').trim();
        return result || String(fallback || '');
    }

    function selectedMode(root) {
        var input = root && root.querySelector('[data-field="student-name-mode"]');
        var value = input ? input.value : '';
        if (['display', 'management', 'anonymous'].indexOf(value) !== -1) {
            return value;
        }

        try {
            value = window.localStorage.getItem(STORAGE_KEY) || '';
        } catch (ignore) {
            value = '';
        }
        return ['display', 'management', 'anonymous'].indexOf(value) !== -1 ? value : 'display';
    }

    function saveMode(mode) {
        if (['display', 'management', 'anonymous'].indexOf(mode) === -1) {
            return;
        }
        try {
            window.localStorage.setItem(STORAGE_KEY, mode);
        } catch (ignore) {
            // Storage can be unavailable in hardened/private browser contexts.
        }
    }

    function baseName(entry, mode, index) {
        if (mode === 'anonymous') {
            return clean(entry.anonymousName, '학생 ' + String(index + 1).padStart(2, '0'));
        }
        if (mode === 'management') {
            return clean(entry.managementName, entry.displayName);
        }
        return clean(entry.displayName, '학생 #' + entry.id);
    }

    function makeNamesUnique(entries, mode) {
        var totals = {};
        var seen = {};

        entries.forEach(function (entry, index) {
            var name = baseName(entry, mode, index);
            totals[name] = (totals[name] || 0) + 1;
        });

        return entries.map(function (entry, index) {
            var base = baseName(entry, mode, index);
            seen[base] = (seen[base] || 0) + 1;
            return Object.assign({}, entry, {
                name: totals[base] > 1 ? base + ' (' + seen[base] + ')' : base,
            });
        });
    }

    function fromRoster(students, mode) {
        var entries = (Array.isArray(students) ? students : []).map(function (student, index) {
            var id = Number(student.id || 0);
            if (!Number.isInteger(id) || id < 1) {
                return null;
            }
            var displayName = clean(student.displayName || student.name, '학생 #' + id);
            return {
                id: id,
                displayName: displayName,
                managementName: clean(student.managementName, displayName),
                anonymousName: '학생 ' + String(index + 1).padStart(2, '0'),
                roster: true,
            };
        }).filter(Boolean);

        return makeNamesUnique(entries, mode || 'display');
    }

    function relabel(entries, mode) {
        return makeNamesUnique((Array.isArray(entries) ? entries : []).map(function (entry) {
            return Object.assign({}, entry);
        }), mode || 'display');
    }

    function lines(value) {
        return String(value || '').split(/\r?\n/).map(function (line) {
            return clean(line, '');
        }).filter(Boolean).slice(0, 500);
    }

    function fromText(value, currentEntries) {
        var names = lines(value);
        var current = Array.isArray(currentEntries) ? currentEntries : [];
        if (names.length === current.length && names.every(function (name, index) {
            return name === current[index].name;
        })) {
            return current.slice();
        }

        var available = {};
        current.forEach(function (entry) {
            if (!available[entry.name]) {
                available[entry.name] = [];
            }
            available[entry.name].push(entry);
        });

        var parsed = names.map(function (name, index) {
            var matched = available[name] && available[name].length ? available[name].shift() : null;
            if (matched) {
                return Object.assign({}, matched);
            }
            manualSequence += 1;
            return {
                id: 'manual:' + Date.now().toString(36) + ':' + manualSequence.toString(36),
                displayName: name,
                managementName: name,
                anonymousName: '학생 ' + String(index + 1).padStart(2, '0'),
                name: name,
                roster: false,
            };
        });

        return makeNamesUnique(parsed, 'display');
    }

    function toText(entries) {
        return (Array.isArray(entries) ? entries : []).map(function (entry) {
            return entry.name;
        }).join('\n');
    }

    function byName(entries) {
        return (Array.isArray(entries) ? entries : []).reduce(function (result, entry) {
            result[entry.name] = entry;
            return result;
        }, {});
    }

    function allRoster(entries) {
        return (Array.isArray(entries) ? entries : []).length > 0
            && entries.every(function (entry) {
                return entry.roster === true && Number.isInteger(Number(entry.id)) && Number(entry.id) > 0;
            });
    }

    function publicEntries(entries) {
        return (Array.isArray(entries) ? entries : []).map(function (entry) {
            return { id: entry.id, name: entry.name };
        });
    }

    function storedStudent(entry) {
        var name = clean(entry && entry.name, '학생');
        var displayName = clean(entry && entry.displayName, name);
        return {
            id: entry && entry.id,
            name: name,
            displayName: displayName,
            managementName: clean(entry && entry.managementName, displayName),
            anonymousName: clean(entry && entry.anonymousName, name),
        };
    }

    function identityKey(value) {
        return String(value === undefined || value === null ? '' : value);
    }

    function fromStored(students, currentEntries, mode) {
        var currentById = {};
        (Array.isArray(currentEntries) ? currentEntries : []).forEach(function (entry) {
            currentById[identityKey(entry.id)] = entry;
        });

        var entries = (Array.isArray(students) ? students : []).map(function (student) {
            if (!student || typeof student !== 'object') {
                return null;
            }
            var current = currentById[identityKey(student.id)] || null;
            var fallbackName = clean(student.name, current && current.name);
            if (!fallbackName) {
                return null;
            }
            var displayName = clean(
                current && current.displayName,
                clean(student.displayName, fallbackName)
            );
            return {
                id: student.id,
                displayName: displayName,
                managementName: clean(
                    current && current.managementName,
                    clean(student.managementName, displayName)
                ),
                anonymousName: clean(
                    current && current.anonymousName,
                    clean(student.anonymousName, fallbackName)
                ),
                roster: current ? current.roster === true : Number.isInteger(Number(student.id)) && Number(student.id) > 0,
            };
        }).filter(Boolean);

        return makeNamesUnique(entries, mode || 'display');
    }

    function conditionIndex(entries) {
        var index = {};
        (Array.isArray(entries) ? entries : []).forEach(function (entry) {
            var aliases = [
                entry.name,
                entry.displayName,
                entry.managementName,
                entry.anonymousName,
                '#' + entry.id,
            ];
            aliases.forEach(function (alias) {
                var key = clean(alias, '').toLocaleLowerCase();
                if (!key) {
                    return;
                }
                if (!index[key]) {
                    index[key] = [];
                }
                if (index[key].indexOf(entry.name) === -1) {
                    index[key].push(entry.name);
                }
            });
        });
        return index;
    }

    function resolveCondition(value, index) {
        var cleaned = clean(value, '');
        var matches = index[cleaned.toLocaleLowerCase()] || [];
        if (matches.length === 1) {
            return { name: matches[0], issue: '' };
        }
        if (matches.length > 1) {
            return {
                name: '',
                issue: '조건 항목 "' + cleaned + '"은 여러 학생과 일치합니다. 명단에 보이는 구분 이름 또는 #학생ID를 입력하세요.',
            };
        }
        return {
            name: '',
            issue: '조건 항목 "' + cleaned + '"을 명단에서 찾지 못했습니다.',
        };
    }

    function uniqueValues(values) {
        return values.filter(function (value, index) {
            return value && values.indexOf(value) === index;
        });
    }

    function resolveConditionParts(value, index) {
        var cleaned = clean(value, '').replace(/[，、]/g, ',');
        if (!cleaned) {
            return { names: [], issues: [] };
        }

        var separated = cleaned.split(/[,/|;]+/).map(function (part) {
            return clean(part, '');
        }).filter(Boolean);
        if (separated.length === 1) {
            var exact = resolveCondition(cleaned, index);
            if (exact.name) {
                return { names: [exact.name], issues: [] };
            }
            var words = cleaned.split(/\s+/).filter(Boolean);
            if (words.length === 1) {
                return { names: [], issues: [exact.issue] };
            }
            separated = words;
        }

        var names = [];
        var issues = [];
        separated.forEach(function (part) {
            var resolved = resolveCondition(part, index);
            if (resolved.name) {
                names.push(resolved.name);
            } else {
                issues.push(resolved.issue);
            }
        });
        return { names: names, issues: issues };
    }

    function parseConditionNames(value, entries) {
        var index = conditionIndex(entries);
        var names = [];
        var issues = [];
        String(value || '').split(/[\n,;，、/|\t]+/).forEach(function (part) {
            var resolved = resolveConditionParts(part, index);
            names = names.concat(resolved.names);
            issues = issues.concat(resolved.issues);
        });
        return { values: uniqueValues(names), issues: uniqueValues(issues) };
    }

    function parseConditionPairs(value, entries) {
        var index = conditionIndex(entries);
        var pairs = [];
        var standalone = [];
        var issues = [];

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

        String(value || '').split(/\r?\n/).forEach(function (line) {
            var resolved = resolveConditionParts(line, index);
            issues = issues.concat(resolved.issues);
            for (var position = 0; position + 1 < resolved.names.length; position += 2) {
                addPair(resolved.names[position], resolved.names[position + 1]);
            }
            if (resolved.names.length % 2 === 1) {
                standalone.push(resolved.names[resolved.names.length - 1]);
            }
        });
        for (var indexPosition = 0; indexPosition + 1 < standalone.length; indexPosition += 2) {
            addPair(standalone[indexPosition], standalone[indexPosition + 1]);
        }

        return { values: pairs, issues: uniqueValues(issues) };
    }

    function bindMode(root, onChange) {
        var selects = root ? Array.prototype.slice.call(root.querySelectorAll('[data-field="student-name-mode"]')) : [];
        if (!selects.length) {
            return;
        }
        var mode = selectedMode(root);
        try {
            var storedMode = window.localStorage.getItem(STORAGE_KEY) || '';
            if (['display', 'management', 'anonymous'].indexOf(storedMode) !== -1) {
                mode = storedMode;
            }
        } catch (ignore) {
            // Keep the selected/default value when storage is unavailable.
        }
        selects.forEach(function (select) {
            select.value = mode;
            select.addEventListener('change', function () {
                var nextMode = select.value;
                saveMode(nextMode);
                selects.forEach(function (other) {
                    other.value = nextMode;
                });
                if (typeof onChange === 'function') {
                    onChange(nextMode);
                }
            });
        });
    }

    window.HttStudentIdentities = {
        allRoster: allRoster,
        bindMode: bindMode,
        byName: byName,
        fromStored: fromStored,
        fromRoster: fromRoster,
        fromText: fromText,
        parseConditionNames: parseConditionNames,
        parseConditionPairs: parseConditionPairs,
        publicEntries: publicEntries,
        relabel: relabel,
        saveMode: saveMode,
        selectedMode: selectedMode,
        storedStudent: storedStudent,
        toText: toText,
    };
}());

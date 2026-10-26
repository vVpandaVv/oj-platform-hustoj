type FuzzyMatchOptions<T = string> = {
  caseSensitive?: boolean;
  extract?: (value: T) => string;
  post?: string;
  pre?: string;
};

type FuzzyMatchResult = {
  rendered: string;
  score: number;
};

export function match(
  pattern: string,
  input: string,
  options: Omit<FuzzyMatchOptions, 'extract'> = {}
): FuzzyMatchResult | null {
  let patternIndex = 0;
  const result: string[] = [];
  let totalScore = 0;
  let currentScore = 0;
  const pre = options.pre ?? '';
  const post = options.post ?? '';
  const compareString = options.caseSensitive ? input : input.toLowerCase();
  const comparePattern = options.caseSensitive ? pattern : pattern.toLowerCase();

  for (let index = 0; index < input.length; index += 1) {
    let character = input[index];

    if (compareString[index] === comparePattern[patternIndex]) {
      character = `${pre}${character}${post}`;
      patternIndex += 1;
      currentScore += 1 + currentScore;
    } else {
      currentScore = 0;
    }

    totalScore += currentScore;
    result.push(character);
  }

  if (patternIndex === comparePattern.length) {
    return {
      rendered: result.join(''),
      score: compareString === comparePattern ? Infinity : totalScore,
    };
  }

  return null;
}

export function test(pattern: string, input: string) {
  return match(pattern, input) !== null;
}

export function simpleFilter(pattern: string, values: string[]) {
  return values.filter((value) => test(pattern, value));
}

export function filter<T>(
  pattern: string,
  values: T[],
  options: FuzzyMatchOptions<T> = {}
) {
  if (!values || values.length === 0) {
    return [];
  }

  if (typeof pattern !== 'string') {
    return values;
  }

  return values
    .reduce<
      Array<{
        index: number;
        original: T;
        score: number;
        string: string;
      }>
    >((matches, value, index) => {
      const source = options.extract ? options.extract(value) : String(value);
      const rendered = match(pattern, source, options);

      if (rendered) {
        matches.push({
          string: rendered.rendered,
          score: rendered.score,
          index,
          original: value,
        });
      }

      return matches;
    }, [])
    .sort((a, b) => {
      const score = b.score - a.score;
      return score || a.index - b.index;
    });
}

export default { filter, match, simpleFilter, test };

type DebouncedFunction<T extends (...args: any[]) => any> = ((
  ...args: Parameters<T>
) => ReturnType<T> | undefined) & {
  cancel: () => void;
  flush: () => ReturnType<T> | undefined;
};

type DebounceOptions = {
  leading?: boolean;
  maxWait?: number;
  trailing?: boolean;
};

export default function debounce<T extends (...args: any[]) => any>(
  func: T,
  wait = 0,
  options: DebounceOptions = {}
): DebouncedFunction<T> {
  if (typeof func !== 'function') {
    throw new TypeError('Expected a function');
  }

  let lastArgs: Parameters<T> | undefined;
  let lastThis: unknown;
  let result: ReturnType<T> | undefined;
  let timerId: number | undefined;
  let maxTimerId: number | undefined;
  const leading = options.leading === true;
  const trailing = options.trailing !== false;

  const invoke = () => {
    const args = lastArgs;
    const thisArg = lastThis;

    lastArgs = undefined;
    lastThis = undefined;

    if (args) {
      result = func.apply(thisArg, args);
    }

    return result;
  };

  const clearTimers = () => {
    if (timerId !== undefined) {
      window.clearTimeout(timerId);
    }

    if (maxTimerId !== undefined) {
      window.clearTimeout(maxTimerId);
    }

    timerId = undefined;
    maxTimerId = undefined;
  };

  const trailingEdge = () => {
    timerId = undefined;

    if (trailing && lastArgs) {
      const value = invoke();

      if (maxTimerId !== undefined) {
        window.clearTimeout(maxTimerId);
        maxTimerId = undefined;
      }

      return value;
    }

    lastArgs = undefined;
    lastThis = undefined;
    return result;
  };

  const debounced = function (
    this: unknown,
    ...args: Parameters<T>
  ): ReturnType<T> | undefined {
    const shouldCallLeading = leading && timerId === undefined;

    lastArgs = args;
    lastThis = this;

    if (timerId !== undefined) {
      window.clearTimeout(timerId);
    }

    timerId = window.setTimeout(trailingEdge, wait);

    if (options.maxWait !== undefined && maxTimerId === undefined) {
      maxTimerId = window.setTimeout(() => {
        clearTimers();
        invoke();
      }, options.maxWait);
    }

    if (shouldCallLeading) {
      return invoke();
    }

    return result;
  } as DebouncedFunction<T>;

  debounced.cancel = () => {
    clearTimers();
    lastArgs = undefined;
    lastThis = undefined;
  };

  debounced.flush = () => {
    if (timerId === undefined) {
      return result;
    }

    clearTimers();
    return invoke();
  };

  return debounced;
}

type ThrottledFunction<T extends (...args: any[]) => any> = ((
  ...args: Parameters<T>
) => ReturnType<T> | undefined) & {
  cancel: () => void;
  flush: () => ReturnType<T> | undefined;
};

type ThrottleOptions = {
  leading?: boolean;
  trailing?: boolean;
};

export default function throttle<T extends (...args: any[]) => any>(
  func: T,
  wait = 0,
  options: ThrottleOptions = {}
): ThrottledFunction<T> {
  if (typeof func !== 'function') {
    throw new TypeError('Expected a function');
  }

  let lastArgs: Parameters<T> | undefined;
  let lastThis: unknown;
  let result: ReturnType<T> | undefined;
  let timerId: number | undefined;
  let lastCallTime = 0;
  const leading = options.leading !== false;
  const trailing = options.trailing !== false;

  const invoke = (time: number) => {
    const args = lastArgs;
    const thisArg = lastThis;

    lastArgs = undefined;
    lastThis = undefined;
    lastCallTime = time;

    if (args) {
      result = func.apply(thisArg, args);
    }

    return result;
  };

  const remainingWait = (time: number) => {
    const timeSinceLastCall = time - lastCallTime;
    return wait - timeSinceLastCall;
  };

  const shouldInvoke = (time: number) =>
    lastCallTime === 0 || time - lastCallTime >= wait || time - lastCallTime < 0;

  const timerExpired = () => {
    const time = Date.now();

    if (lastArgs && trailing) {
      invoke(time);
    }

    timerId = undefined;
  };

  const throttled = function (
    this: unknown,
    ...args: Parameters<T>
  ): ReturnType<T> | undefined {
    const time = Date.now();
    const isInvoking = shouldInvoke(time);

    lastArgs = args;
    lastThis = this;

    if (isInvoking) {
      if (timerId === undefined) {
        if (leading) {
          return invoke(time);
        }

        lastCallTime = time;
      }
    }

    if (timerId === undefined && trailing) {
      timerId = window.setTimeout(timerExpired, Math.max(0, remainingWait(time)));
    }

    return result;
  } as ThrottledFunction<T>;

  throttled.cancel = () => {
    if (timerId !== undefined) {
      window.clearTimeout(timerId);
    }

    timerId = undefined;
    lastArgs = undefined;
    lastThis = undefined;
    lastCallTime = 0;
  };

  throttled.flush = () => {
    if (timerId !== undefined) {
      window.clearTimeout(timerId);
      timerId = undefined;
    }

    return lastArgs ? invoke(Date.now()) : result;
  };

  return throttled;
}

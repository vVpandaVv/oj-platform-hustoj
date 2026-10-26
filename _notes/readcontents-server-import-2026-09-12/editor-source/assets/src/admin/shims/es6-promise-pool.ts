type PromisePoolEvent = {
  data: unknown;
  target: PromisePool;
  type: string;
};

type PromisePoolListener = (event: PromisePoolEvent) => void;

function toIterator(source: unknown, promiseCtor: PromiseConstructor) {
  if (
    source &&
    typeof source === 'object' &&
    'next' in source &&
    typeof source.next === 'function'
  ) {
    return source as Iterator<unknown>;
  }

  if (typeof source === 'function') {
    const maybeIterator = source();

    if (
      maybeIterator &&
      typeof maybeIterator === 'object' &&
      'next' in maybeIterator &&
      typeof maybeIterator.next === 'function'
    ) {
      return maybeIterator as Iterator<unknown>;
    }

    return {
      next() {
        const value = source();

        return value ? { value } : { done: true, value: undefined };
      },
    };
  }

  let consumed = false;

  return {
    next() {
      if (consumed) {
        return { done: true, value: undefined };
      }

      consumed = true;

      return { value: promiseCtor.resolve(source) };
    },
  };
}

export default class PromisePool {
  private readonly concurrencyLimit: number;
  private readonly iterator: Iterator<unknown>;
  private readonly listeners = new Map<string, Set<PromisePoolListener>>();
  private activeCount = 0;

  constructor(
    source: unknown,
    concurrency: number,
    options: { promise?: PromiseConstructor } = {}
  ) {
    if (!Number.isInteger(concurrency) || concurrency < 1) {
      throw new Error('Invalid concurrency');
    }

    this.concurrencyLimit = concurrency;
    this.iterator = toIterator(source, options.promise ?? Promise);
  }

  addEventListener(type: string, listener: PromisePoolListener) {
    const listeners = this.listeners.get(type) ?? new Set<PromisePoolListener>();
    listeners.add(listener);
    this.listeners.set(type, listeners);
  }

  removeEventListener(type: string, listener: PromisePoolListener) {
    this.listeners.get(type)?.delete(listener);
  }

  concurrency() {
    return this.concurrencyLimit;
  }

  active() {
    return this.activeCount > 0;
  }

  start() {
    return new Promise<void>((resolve, reject) => {
      let iteratorDone = false;

      const pump = () => {
        if (iteratorDone && this.activeCount === 0) {
          resolve();
          return;
        }

        while (!iteratorDone && this.activeCount < this.concurrencyLimit) {
          let next: IteratorResult<unknown>;

          try {
            next = this.iterator.next();
          } catch (error) {
            reject(error);
            return;
          }

          if (next.done) {
            iteratorDone = true;
            break;
          }

          this.activeCount += 1;

          Promise.resolve(next.value)
            .then((result) => {
              this.emit('fulfilled', { result });
            })
            .catch((error) => {
              this.emit('rejected', { error });
            })
            .finally(() => {
              this.activeCount -= 1;
              pump();
            });
        }
      };

      pump();
    });
  }

  private emit(type: string, data: unknown) {
    const listeners = this.listeners.get(type);

    if (!listeners) {
      return;
    }

    const event = { data, target: this, type };
    listeners.forEach((listener) => listener(event));
  }
}

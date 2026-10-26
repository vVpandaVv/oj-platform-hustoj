// Contracts for the two Lodash subpath imports used by the editor.
declare module 'lodash/cloneDeep.js' {
  export default function cloneDeep<T>(value: T): T;
}

declare module 'lodash/debounce.js' {
  interface DebounceOptions {
    leading?: boolean;
    maxWait?: number;
    trailing?: boolean;
  }

  interface DebouncedFunction<Args extends unknown[], Result, This> {
    (this: This, ...args: Args): Result | undefined;
    cancel(): void;
    flush(): Result | undefined;
  }

  export default function debounce<Args extends unknown[], Result, This>(
    func: (this: This, ...args: Args) => Result,
    wait?: number,
    options?: DebounceOptions
  ): DebouncedFunction<Args, Result, This>;
}

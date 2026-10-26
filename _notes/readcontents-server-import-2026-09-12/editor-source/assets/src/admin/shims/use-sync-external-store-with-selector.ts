import * as React from 'react';

function objectIs(x: unknown, y: unknown) {
  return Object.is ? Object.is(x, y) : x === y || (x !== x && y !== y);
}

export function useSyncExternalStoreWithSelector<
  Snapshot,
  Selection,
>(
  subscribe: (onStoreChange: () => void) => () => void,
  getSnapshot: () => Snapshot,
  getServerSnapshot: undefined | (() => Snapshot),
  selector: (snapshot: Snapshot) => Selection,
  isEqual?: (a: Selection, b: Selection) => boolean
) {
  const instRef = React.useRef<{
    hasValue: boolean;
    value: Selection | null;
  } | null>(null);

  if (instRef.current === null) {
    instRef.current = { hasValue: false, value: null };
  }

  const inst = instRef.current;

  const [getSelection, getServerSelection] = React.useMemo(() => {
    let hasMemo = false;
    let memoizedSnapshot: Snapshot;
    let memoizedSelection: Selection;

    const memoizedSelector = (nextSnapshot: Snapshot) => {
      if (!hasMemo) {
        hasMemo = true;
        memoizedSnapshot = nextSnapshot;
        const nextSelection = selector(nextSnapshot);

        if (isEqual && inst.hasValue && isEqual(inst.value as Selection, nextSelection)) {
          memoizedSelection = inst.value as Selection;
          return memoizedSelection;
        }

        memoizedSelection = nextSelection;
        return memoizedSelection;
      }

      const previousSelection = memoizedSelection;

      if (objectIs(memoizedSnapshot, nextSnapshot)) {
        return previousSelection;
      }

      const nextSelection = selector(nextSnapshot);

      if (isEqual && isEqual(previousSelection, nextSelection)) {
        memoizedSnapshot = nextSnapshot;
        return previousSelection;
      }

      memoizedSnapshot = nextSnapshot;
      memoizedSelection = nextSelection;
      return nextSelection;
    };

    const maybeGetServerSnapshot = getServerSnapshot ?? null;

    return [
      () => memoizedSelector(getSnapshot()),
      maybeGetServerSnapshot === null
        ? undefined
        : () => memoizedSelector(maybeGetServerSnapshot()),
    ] as const;
  }, [getSnapshot, getServerSnapshot, selector, isEqual, inst]);

  const value = React.useSyncExternalStore(
    subscribe,
    getSelection,
    getServerSelection
  );

  React.useEffect(() => {
    inst.hasValue = true;
    inst.value = value;
  }, [inst, value]);

  React.useDebugValue(value);

  return value;
}

export default { useSyncExternalStoreWithSelector };

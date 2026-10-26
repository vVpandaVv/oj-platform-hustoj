/* Fixed code blocks. DOM contract and mount API are shared with learning addons. */
(function (host) {
  'use strict';

  function mount(root) {
    if (root.ojCodeOrdering) { return root.ojCodeOrdering; }
    var list = root.querySelector('[data-oj-ordering-list]');
    var status = root.querySelector('[data-oj-ordering-status]');
    var storageKey = root.getAttribute('data-storage-key') || '';
    var form = root.closest('form');
    var dragged = null;
    var dropTarget = null;
    var dropAfter = false;
    var pointerDrag = null;
    if (!list) { throw new Error('코드 조각 목록을 찾을 수 없습니다.'); }
    var original = Array.prototype.slice.call(list.children);
    var ids = original.map(function (block) {
      return block.getAttribute('data-oj-ordering-block-id');
    });
    if (!ids.length || ids.some(function (id, index) { return !id || ids.indexOf(id) !== index; })) {
      throw new Error('코드 조각 목록이 올바르지 않습니다.');
    }
    original.forEach(function (block) {
      var code = block.querySelector('.ojp-code-ordering__code code');
      // Public fragments end in a structural LF. Keep real indentation and internal newlines.
      if (code && !code.parentElement.hasAttribute('data-oj-ordering-display-normalized')) {
        code.textContent = code.textContent.replace(/\n$/, '');
        code.parentElement.setAttribute('data-oj-ordering-display-normalized', '');
      }
    });

    function blocks() { return Array.prototype.slice.call(list.children); }
    function getOrder() {
      return blocks().map(function (block) { return block.getAttribute('data-oj-ordering-block-id'); });
    }
    function validOrder(order) {
      return Array.isArray(order) && order.length === ids.length && order.every(function (id, index) {
        return typeof id === 'string' && ids.indexOf(id) !== -1 && order.indexOf(id) === index;
      });
    }
    function save() {
      if (!storageKey) { return; }
      try { host.sessionStorage.setItem(storageKey, JSON.stringify(getOrder())); } catch (error) { /* Optional draft storage. */ }
    }
    function refresh(message, changed) {
      var current = blocks();
      current.forEach(function (block, index) {
        block.setAttribute('aria-label', '코드 조각 ' + (index + 1) + ' / ' + current.length);
        block.setAttribute('draggable', 'true');
        var position = block.querySelector('[data-oj-ordering-position]');
        if (position) { position.textContent = String(index + 1); }
        var up = block.querySelector('[data-oj-ordering-move="up"]');
        var down = block.querySelector('[data-oj-ordering-move="down"]');
        if (up) { up.disabled = index === 0; }
        if (down) { down.disabled = index === current.length - 1; }
      });
      if (status) { status.textContent = message || '모든 조각의 순서를 확인한 뒤 제출하세요.'; }
      if (changed) {
        save();
        root.dispatchEvent(new CustomEvent('oj-code-ordering-change', {
          bubbles: true, detail: { blockIds: getOrder() }
        }));
      }
    }
    function setOrder(order) {
      if (!validOrder(order)) { return false; }
      order.forEach(function (id) { list.appendChild(original[ids.indexOf(id)]); });
      refresh('', true);
      return true;
    }
    function moved(block) {
      var index = blocks().indexOf(block);
      refresh('코드 조각을 ' + (index + 1) + '번째로 이동했습니다.', true);
      block.focus();
    }
    function move(block, direction) {
      var current = blocks();
      var index = current.indexOf(block);
      var target = index + direction;
      if (index < 0 || target < 0 || target >= current.length) { return; }
      list.insertBefore(block, direction < 0 ? current[target] : current[target].nextSibling);
      moved(block);
    }
    function blockFor(target) {
      var block = target && target.closest ? target.closest('[data-oj-ordering-block-id]') : null;
      return block && block.parentNode === list ? block : null;
    }
    function clearDrop() {
      if (dropTarget) { dropTarget.classList.remove('is-drop-before', 'is-drop-after'); }
      dropTarget = null;
    }
    function indicate(clientY) {
      clearDrop();
      var candidates = blocks().filter(function (block) { return block !== dragged; });
      if (!candidates.length) { return; }
      dropTarget = candidates.find(function (block) {
        var bounds = block.getBoundingClientRect();
        return clientY < bounds.top + bounds.height / 2;
      }) || candidates[candidates.length - 1];
      var bounds = dropTarget.getBoundingClientRect();
      dropAfter = clientY >= bounds.top + bounds.height / 2;
      dropTarget.classList.add(dropAfter ? 'is-drop-after' : 'is-drop-before');
    }
    function commitDrop() {
      if (!dragged || !dropTarget) { return; }
      list.insertBefore(dragged, dropAfter ? dropTarget.nextSibling : dropTarget);
      moved(dragged);
      clearDrop();
    }
    function endDrag() {
      clearDrop();
      if (dragged) { dragged.classList.remove('is-dragging'); }
      dragged = null;
    }
    list.addEventListener('click', function (event) {
      var button = event.target.closest('[data-oj-ordering-move]');
      var block = blockFor(button);
      if (button && block) { move(block, button.getAttribute('data-oj-ordering-move') === 'up' ? -1 : 1); }
    });
    list.addEventListener('keydown', function (event) {
      var block = blockFor(event.target);
      if (block && event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
        event.preventDefault();
        move(block, event.key === 'ArrowUp' ? -1 : 1);
      }
    });
    list.addEventListener('dragstart', function (event) {
      dragged = blockFor(event.target);
      if (!dragged) { return; }
      dragged.classList.add('is-dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', dragged.getAttribute('data-oj-ordering-block-id'));
      }
    });
    list.addEventListener('dragover', function (event) {
      if (dragged) {
        event.preventDefault();
        if (event.dataTransfer) { event.dataTransfer.dropEffect = 'move'; }
        indicate(event.clientY);
      }
    });
    list.addEventListener('drop', function (event) {
      if (!dragged) { return; }
      event.preventDefault();
      indicate(event.clientY);
      commitDrop();
      endDrag();
    });
    list.addEventListener('dragleave', function (event) {
      if (!list.contains(event.relatedTarget)) { clearDrop(); }
    });
    list.addEventListener('dragend', endDrag);
    // Touch/pen dragging uses the handle; the row remains scrollable outside it.
    list.addEventListener('pointerdown', function (event) {
      var handle = event.target.closest('[data-oj-ordering-handle]');
      var block = blockFor(handle);
      if (!handle || !block || event.pointerType === 'mouse') { return; }
      pointerDrag = { id: event.pointerId, handle: handle, block: block, y: event.clientY, active: false };
      if (handle.setPointerCapture) { handle.setPointerCapture(event.pointerId); }
    });
    list.addEventListener('pointermove', function (event) {
      if (!pointerDrag || pointerDrag.id !== event.pointerId) { return; }
      if (!pointerDrag.active && Math.abs(event.clientY - pointerDrag.y) < 5) { return; }
      pointerDrag.active = true;
      dragged = pointerDrag.block;
      dragged.classList.add('is-dragging');
      event.preventDefault();
      indicate(event.clientY);
    });
    function endPointer(event) {
      if (!pointerDrag || pointerDrag.id !== event.pointerId) { return; }
      if (pointerDrag.active && event.type === 'pointerup') { indicate(event.clientY); commitDrop(); }
      var handle = pointerDrag.handle;
      if (handle.hasPointerCapture && handle.hasPointerCapture(event.pointerId)) { handle.releasePointerCapture(event.pointerId); }
      pointerDrag = null;
      endDrag();
    }
    list.addEventListener('pointerup', endPointer);
    list.addEventListener('pointercancel', endPointer);
    list.addEventListener('lostpointercapture', function (event) {
      if (pointerDrag && pointerDrag.id === event.pointerId) { pointerDrag = null; endDrag(); }
    });
    var restored = false;
    if (storageKey) {
      try { restored = setOrder(JSON.parse(host.sessionStorage.getItem(storageKey) || 'null')); } catch (error) { /* Keep the server-shuffled order. */ }
    }
    refresh(restored ? '저장한 코드 조각 순서를 불러왔습니다.' : '', false);
    // Persist the first server-shuffled order too, so refresh does not reshuffle a fresh attempt.
    save();
    if (form) {
      var submit = form.querySelector('[data-oj-ordering-submit]');
      if (submit) { submit.disabled = false; }
      form.addEventListener('submit', function (event) {
        if (!validOrder(getOrder())) {
          event.preventDefault();
          if (status) { status.textContent = '모든 코드 조각을 한 번씩 배열해 주세요.'; }
          return;
        }
        save();
      });
    }
    root.setAttribute('data-mounted', 'true');
    root.ojCodeOrdering = Object.freeze({ getOrder: getOrder, setOrder: setOrder });
    return root.ojCodeOrdering;
  }

  host.OJPlatformCodeOrdering = Object.freeze({ mount: mount });
})(typeof window !== 'undefined' ? window : globalThis);

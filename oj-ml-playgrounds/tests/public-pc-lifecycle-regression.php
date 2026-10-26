<?php

declare(strict_types=1);

require __DIR__ . '/public-pc-isolation-regression.php';

$root = dirname(__DIR__);
$handler = file_get_contents($root . '/src/Platform/Addon_Page_Handler.php');
$controller = file_get_contents($root . '/resources/js/public-pc.js');
$bootstrap = file_get_contents($root . '/resources/js/public-pc-bootstrap.js');
$addon = file_get_contents($root . '/resources/js/addon.js');
$css = file_get_contents($root . '/resources/css/addon.css');
$bridgePatch = file_get_contents($root . '/patches/ml-playgrounds-oj-bridge.patch');

foreach (compact('handler', 'controller', 'bootstrap', 'addon', 'css', 'bridgePatch') as $name => $source) {
    ojMlPublicPcAssert(is_string($source), 'Lifecycle source must be readable: ' . $name);
    ojMlPublicPcAssert(!str_starts_with($source, "\xEF\xBB\xBF"), 'Lifecycle source must not contain a UTF-8 BOM: ' . $name);
    ojMlPublicPcAssert(!str_contains($source, "\xEF\xBF\xBD"), 'Lifecycle source must not contain U+FFFD: ' . $name);
}

ojMlPublicPcAssert(
    str_contains($handler, 'Privacy_Url::resolve(')
        && str_contains($handler, "'data-privacy-url'          => \$privacy_notice['url']")
        && str_contains($handler, "'data-privacy-source'       => \$privacy_notice['source']"),
    'The shell and shared-device link must use the same resolved privacy notice.'
);
ojMlPublicPcAssert(
    str_contains($handler, '다운로드 폴더의 .mlpx·.csv·양식 .md 파일 직접 삭제')
        && str_contains($handler, 'OJ 로그아웃')
        && str_contains($handler, '모든 실습 탭과 브라우저 닫기'),
    'The shared-device notice must include the complete classroom closeout sequence.'
);

$globalPageHide = strpos($bootstrap, "window.addEventListener('pagehide', function (event)");
$domReady = strpos($bootstrap, "document.addEventListener('DOMContentLoaded'");
ojMlPublicPcAssert(
    is_int($globalPageHide) && is_int($domReady) && $globalPageHide < $domReady
        && str_contains($bootstrap, 'emergencyLockPublicPcRoots();')
        && substr_count($bootstrap, "window.addEventListener('pageshow'") === 1
        && !str_contains($bootstrap, "document.documentElement.style.visibility = 'hidden'")
        && str_contains($bootstrap, 'window.location.reload();'),
    'The BFCache guard must purge sensitive UI, keep status visible, and have exactly one reload owner.'
);
ojMlPublicPcAssert(
    str_contains($bootstrap, 'new window.AbortController()')
        && str_contains($bootstrap, 'apiFor(root, initGuard.signal)')
        && str_contains($bootstrap, 'CONTEXT_VERIFY_TIMEOUT_MS = 15000')
        && str_contains($bootstrap, 'signal: abort.signal')
        && str_contains($bootstrap, "signal.addEventListener('abort', forwardAbort, { once: true })")
        && str_contains($bootstrap, 'await controller.verify() !== true')
        && str_contains($bootstrap, "document.visibilityState !== 'visible'")
        && str_contains($bootstrap, 'initGuard.failClosed();'),
    'Initialization must be abortable and complete a visible-session revalidation before becoming ready.'
);
$monitorBlurStart = strpos($bootstrap, 'function onBlur()');
$monitorBlurEnd = strpos($bootstrap, 'function onVisible()', $monitorBlurStart ?: 0);
$monitorBlurBody = is_int($monitorBlurStart) && is_int($monitorBlurEnd)
    ? substr($bootstrap, $monitorBlurStart, $monitorBlurEnd - $monitorBlurStart)
    : '';
ojMlPublicPcAssert(
    str_contains($bootstrap, "window.addEventListener('blur', onBlur)")
        && str_contains($bootstrap, "window.removeEventListener('blur', onBlur)")
        && str_contains($bootstrap, 'blurTimer = window.setTimeout(function ()')
        && str_contains($bootstrap, 'function managedFrameOwnsFocus()')
        && str_contains($bootstrap, "typeof controller.ownsFocusedFrame === 'function'")
        && str_contains($monitorBlurBody, 'if (stopped || managedFrameOwnsFocus()) return;')
        && !str_contains($monitorBlurBody, 'suspendForBackground();')
        && str_contains($bootstrap, 'window.clearTimeout(blurTimer)')
        && str_contains($controller, 'function ownsFocusedFrame()')
        && str_contains($controller, 'document.activeElement === frame')
        && str_contains($controller, "return document.visibilityState === 'visible';"),
    'Ordinary window blur must keep the project visible while picker-specific focus checks remain protected.'
);
ojMlPublicPcAssert(
    str_contains($bootstrap, "String(target.type || '').toLowerCase() === 'file'")
        && str_contains($bootstrap, 'view.navigator.userActivation.isActive === true')
        && str_contains($bootstrap, "picker.state = 'open'")
        && str_contains($bootstrap, 'PICKER_MAX_MS = 120000')
        && str_contains($bootstrap, 'PICKER_VERIFY_MAX_MS = 15000')
        && str_contains($bootstrap, 'controller.verifyCovered()')
        && str_contains($bootstrap, 'replayPickerEvent(currentPicker)')
        && str_contains($bootstrap, "frameDocument.addEventListener('change', complete, true)")
        && str_contains($bootstrap, "root.addEventListener('change', onPickerCompletion, true)")
        && str_contains($bootstrap, "document.visibilityState !== 'visible'")
        && str_contains($bootstrap, 'blockPickerCompletion();'),
    'Native file pickers must use a bounded user-activated curtain and replay only after session revalidation.'
);
ojMlPublicPcAssert(
    str_contains($bootstrap, "delete root.dataset.restNonce")
        && str_contains($bootstrap, "delete root.dataset.attemptId")
        && str_contains($bootstrap, "delete root.dataset.assignmentId")
        && str_contains($bootstrap, "wipeButton.disabled = true")
        && str_contains($bootstrap, "content.textContent = ''")
        && str_contains($bootstrap, "frame.hidden = true"),
    'Early and terminal locks must remove identifiers, content, and all sensitive controls synchronously.'
);

$readyHandler = strpos($bootstrap, "event.data.type === 'oj-ml:ready'");
$attemptLookup = strpos($bootstrap, 'await knownAttempt', $readyHandler ?: 0);
$readyVerify = strpos($bootstrap, 'await controller.verify()', $attemptLookup ?: 0);
$canConfigure = strpos($bootstrap, 'controller.canConfigure', $readyVerify ?: 0);
$configurePost = strpos($bootstrap, 'contentWindow.postMessage', $canConfigure ?: 0);
ojMlPublicPcAssert(
    is_int($readyHandler) && is_int($attemptLookup) && is_int($readyVerify) && is_int($canConfigure) && is_int($configurePost)
        && $readyHandler < $attemptLookup && $attemptLookup < $readyVerify
        && $readyVerify < $canConfigure && $canConfigure < $configurePost
        && str_contains($bootstrap, 'privacyUrl: controller.privacyUrl'),
    'Ready messages must be revalidated immediately before configure and carry the filtered privacy URL.'
);

$initializeStart = strpos($controller, 'async function initialize');
$initializeEnd = strpos($controller, "Object.defineProperty(window, 'OjMlPublicPc'", $initializeStart ?: 0);
$initializeBody = is_int($initializeStart) && is_int($initializeEnd)
    ? substr($controller, $initializeStart, $initializeEnd - $initializeStart)
    : '';
ojMlPublicPcAssert(
    substr_count($initializeBody, 'unlock();') === 0,
    'The initial context fetch must remain locked; only verify success may unlock the controller.'
);
ojMlPublicPcAssert(
    str_contains($controller, 'var verificationGeneration = 0')
        && str_contains($controller, "var verificationMode = ''")
        && str_contains($controller, 'generation !== verificationGeneration')
        && str_contains($controller, "var background = options.background === true")
        && str_contains($controller, "verificationMode === 'strict'")
        && str_contains($controller, 'if (!background) lock();')
        && str_contains($controller, 'else if (!keepLocked && !unlock()) return null;')
        && str_contains($controller, 'if (button) button.disabled = true')
        && str_contains($controller, 'if (button) button.disabled = confirming')
        && str_contains($controller, 'setFrameBlocked(true);')
        && str_contains($controller, 'setFrameBlocked(false);')
        && str_contains($controller, 'frame.hidden = true;')
        && str_contains($controller, 'frame.hidden = false;'),
    'Strict verification must fail closed while background verification leaves the visible frame untouched.'
);
ojMlPublicPcAssert(
    str_contains($controller, 'function cover()')
        && str_contains($controller, "root.dataset.publicPcCovered = '1'")
        && str_contains($controller, 'function setFrameBlocked(blocked)')
        && str_contains($controller, 'frame.inert = blocked === true')
        && str_contains($controller, "frame.setAttribute('aria-hidden', 'true')")
        && str_contains($controller, 'function verifyCovered()')
        && str_contains($controller, 'wiping || suspended || !covered || generation !== verificationGeneration')
        && str_contains($controller, 'function uncover()')
        && str_contains($controller, 'clearCover();')
        && str_contains($controller, 'verifyCovered: verifyCovered')
        && str_contains($controller, 'cover: cover')
        && str_contains($controller, 'uncover: uncover'),
    'Picker coverage must keep the frame connected while invalidating stale verification and requiring a fresh context check.'
);

$verifyStart = strpos($controller, 'function verify(options)');
$verifyEnd = strpos($controller, 'function handleMessage', $verifyStart ?: 0);
$verifyBody = is_int($verifyStart) && is_int($verifyEnd)
    ? substr($controller, $verifyStart, $verifyEnd - $verifyStart)
    : '';
ojMlPublicPcAssert(
    substr_count($verifyBody, 'invalidate(') >= 2
        && substr_count($verifyBody, 'invalidate();') === 0
        && !str_contains($verifyBody, 'setStatus('),
    'Context mismatch and REST errors must delegate one terminal error status to invalidate().'
);
ojMlPublicPcAssert(
    str_contains($controller, 'function purgePrivateState()')
        && str_contains($controller, 'storage = null;')
        && str_contains($controller, "delete root.dataset.restNonce")
        && str_contains($controller, "delete root.dataset.assignmentId")
        && str_contains($controller, "content.textContent = ''")
        && str_contains($controller, 'get storage() { return terminal ? null : storage; }'),
    'Terminal invalidation must sever storage keys, identifiers, and previous-student DOM references.'
);

$invalidateStart = strpos($controller, 'function invalidate(message, kind)');
$invalidateEnd = strpos($controller, 'function sameContext', $invalidateStart ?: 0);
$invalidateBody = is_int($invalidateStart) && is_int($invalidateEnd)
    ? substr($controller, $invalidateStart, $invalidateEnd - $invalidateStart)
    : '';
ojMlPublicPcAssert(
    str_contains($invalidateBody, 'var firstTerminalStatus = !terminal;')
        && str_contains($invalidateBody, "root.dataset.publicPcTerminal = '1'")
        && str_contains($invalidateBody, 'firstTerminalStatus ? (message ||')
        && str_contains($invalidateBody, ": ''"),
    'Terminal invalidation must mark the root and publish its terminal status only once.'
);

ojMlPublicPcAssert(
    str_contains($controller, "new window.BroadcastChannel('ml-playgrounds:oj-storage-control-v1:' + scope)")
        && str_contains($controller, "'ml-playgrounds:oj-storage-wipe-v1:' + storage.scope")
        && str_contains($controller, "{ mode: 'exclusive', signal: abort.signal }")
        && str_contains($controller, "event.data.type === 'oj-ml:storage-revoked'")
        && str_contains($controller, 'await deleteWithExclusiveLock({ scope: scope, database: database });'),
    'No-frame deletion and peer revocation must use the shared revoke/exclusive-lock protocol.'
);

$wipeVerify = strpos($controller, 'var verified = await verify({ keepLocked: true });');
$wipeConfirm = strpos($controller, 'var confirmed = await requestWipeConfirmation();');
$wipeVerifyGate = strpos($controller, 'if (verified !== true)', $wipeVerify ?: 0);
$wipeState = strpos($controller, 'wiping = true;', $wipeVerifyGate ?: 0);
$wipeCall = strpos($controller, 'await wipe();', $wipeState ?: 0);
ojMlPublicPcAssert(
    is_int($wipeConfirm) && is_int($wipeVerify) && is_int($wipeVerifyGate) && is_int($wipeState) && is_int($wipeCall)
        && $wipeConfirm < $wipeVerify && $wipeVerify < $wipeVerifyGate && $wipeVerifyGate < $wipeState && $wipeState < $wipeCall,
    'Destructive wipe must revalidate the current account and focus before revoking or deleting a scope.'
);
ojMlPublicPcAssert(
    !str_contains($controller, 'window.confirm(')
        && str_contains($controller, 'dialog.showModal();')
        && str_contains($controller, 'root.appendChild(dialog);')
        && str_contains($controller, 'cancelButton.focus();')
        && str_contains($controller, "dialog.addEventListener('keydown', onKeyDown)")
        && str_contains($controller, "if (event.key !== 'Tab') return;")
        && str_contains($controller, "dialog.setAttribute('aria-labelledby'")
        && str_contains($controller, "dialog.setAttribute('aria-describedby'")
        && str_contains($controller, 'if (confirming || !canConfirmWipe()) return;')
        && str_contains($controller, 'if (cancelConfirmation) cancelConfirmation();')
        && str_contains($controller, "doc.addEventListener('visibilitychange', onVisibility)")
        && str_contains($controller, "window.addEventListener('pagehide', onPageHide)"),
    'Deletion consent must use an accessible cancellable modal outside hidden content, never native confirmation.'
);
ojMlPublicPcAssert(
    !str_contains($controller, "window.addEventListener('message'")
        && str_contains($bootstrap, 'controller.handleMessage(event)'),
    'Only the bootstrap dispatcher may deliver window messages to the storage controller.'
);
$exclusiveQueue = strpos($controller, 'lease = Promise.resolve(locks.request(');
$parentRevoke = strpos($controller, 'revokeStorage(storage.scope);', $exclusiveQueue ?: 0);
ojMlPublicPcAssert(
    is_int($exclusiveQueue) && is_int($parentRevoke) && $exclusiveQueue < $parentRevoke
        && str_contains($controller, "if (!revoked) throw new Error('OJ_STORAGE_NOT_READY')")
        && str_contains($controller, 'await Promise.race([lease, deadline]);')
        && str_contains($controller, "request.addEventListener('blocked', function () { if (onBlocked) onBlocked(); });")
        && str_contains($controller, 'lease.catch(function () {});'),
    'Parent deletion must queue exclusive ownership before revoke and retain it through blocked requests after UI timeout.'
);
ojMlPublicPcAssert(
    str_contains($css, '.oj-ml-app[data-public-pc-locked="1"] [data-oj-ml-content]')
        && str_contains($css, "\tvisibility: hidden;")
        && str_contains($css, "\topacity: 0;"),
    'Locked public-PC content must be completely unreadable, not merely dimmed.'
);
ojMlPublicPcAssert(
    str_contains($css, '.oj-ml-app[data-public-pc-covered="1"] .oj-ml-playground-frame')
        && str_contains($css, '.oj-ml-app[data-public-pc-covered="1"] [data-oj-ml-frame]::after')
        && str_contains($css, '파일 선택 후 로그인 상태를 확인하는 중입니다.')
        && str_contains($css, "\topacity: 0;")
        && str_contains($css, "\tpointer-events: none;")
        && str_contains($css, "\tbackground: var(--ojp-color-subtle, #f5f5f7);"),
    'A native picker must retain the iframe behind an opaque, non-interactive privacy curtain.'
);
$playgroundJavascript = '';
foreach (glob($root . '/resources/playground/assets/*.js') ?: array() as $asset) {
    $playgroundJavascript .= (string) file_get_contents($asset);
}
ojMlPublicPcAssert(
    substr_count($playgroundJavascript, 'type:`file`') === 11 && substr_count($addon, 'name="artifact" type="file"') === 1,
    'Picker capture must cover all eleven embedded inputs and the one parent submission input.'
);

$monitorStart = strpos($bootstrap, 'function monitorSession');
$monitorEnd = strpos($bootstrap, "window.addEventListener('message'", $monitorStart ?: 0);
$monitorBody = is_int($monitorStart) && is_int($monitorEnd)
    ? substr($bootstrap, $monitorStart, $monitorEnd - $monitorStart)
    : '';
ojMlPublicPcAssert(
    !str_contains($monitorBody, 'function reloadLocked')
        && str_contains($monitorBody, 'controller.verify(background ? { background: true } : undefined)')
        && str_contains($monitorBody, 'window.setInterval(function () { void verify(true); }, SESSION_CHECK_MS)')
        && str_contains($monitorBody, 'if (valid === false) stopLocked();')
        && !str_contains($monitorBody, "window.addEventListener('pageshow'")
        && preg_match(
            '/window\\.setTimeout\\s*\\(\\s*function\\s*\\(\\)\\s*\\{\\s*window\\.location\\.reload\\(\\);/s',
            $monitorBody
        ) !== 1,
    'Periodic session verification must be silent, preserve stale-null state, and never own reload.'
);
$storageMessageStart = strpos($controller, 'function handleMessage(event)');
$storageMessageEnd = strpos($controller, 'async function wipe()', $storageMessageStart ?: 0);
$storageMessageBody = is_int($storageMessageStart) && is_int($storageMessageEnd)
    ? substr($controller, $storageMessageStart, $storageMessageEnd - $storageMessageStart)
    : '';
ojMlPublicPcAssert(
    str_contains($controller, 'function matchesFrameMessage(event)')
        && str_contains($storageMessageBody, "event.data.type === 'oj-ml:storage-revoked'")
        && !str_contains($storageMessageBody, 'window.location.reload()'),
    'A revoked child bridge must become terminal without entering an automatic reload loop.'
);

$bootstrapStatusStart = strpos($bootstrap, 'function setStatus(root, text, kind)');
$bootstrapStatusEnd = strpos($bootstrap, 'function apiFor', $bootstrapStatusStart ?: 0);
$bootstrapStatusBody = is_int($bootstrapStatusStart) && is_int($bootstrapStatusEnd)
    ? substr($bootstrap, $bootstrapStatusStart, $bootstrapStatusEnd - $bootstrapStatusStart)
    : '';
$bootstrapTerminal = strpos($bootstrapStatusBody, 'publicPcTerminal');
$bootstrapStatusWrite = strpos($bootstrapStatusBody, 'status.textContent = text;');
ojMlPublicPcAssert(
    str_contains($bootstrapStatusBody, "if (root.dataset.publicPcTerminal === '1') return false;")
        && is_int($bootstrapTerminal) && is_int($bootstrapStatusWrite)
        && $bootstrapTerminal < $bootstrapStatusWrite,
    'Bootstrap status updates must never overwrite the terminal security message.'
);

$readyContractStart = strpos($bootstrap, 'function waitForPublicPc(root)');
$readyContractEnd = strpos($bootstrap, 'function guardInitialization', $readyContractStart ?: 0);
$readyContractBody = is_int($readyContractStart) && is_int($readyContractEnd)
    ? substr($bootstrap, $readyContractStart, $readyContractEnd - $readyContractStart)
    : '';
ojMlPublicPcAssert(
    str_contains($readyContractBody, "Object.defineProperty(window, 'OjMlPublicPcReady'")
        && str_contains($readyContractBody, 'var pending = controllers.get(root);')
        && str_contains($readyContractBody, 'readyByRoot.get(root) === true')
        && str_contains($readyContractBody, "root.dataset.publicPcLocked !== '1'")
        && str_contains($readyContractBody, "root.dataset.publicPcTerminal !== '1'"),
    'The addon readiness contract must resolve true only for a verified, unlocked, non-terminal controller.'
);

$addonStatusStart = strpos($addon, 'function setStatus(root, text, kind)');
$addonStatusEnd = strpos($addon, 'function contentNode', $addonStatusStart ?: 0);
$addonStatusBody = is_int($addonStatusStart) && is_int($addonStatusEnd)
    ? substr($addon, $addonStatusStart, $addonStatusEnd - $addonStatusStart)
    : '';
$addonInitializeStart = strrpos($addon, 'async function initialize(root)');
$addonInitializeEnd = strpos($addon, "document.addEventListener('DOMContentLoaded'", $addonInitializeStart ?: 0);
$addonInitializeBody = is_int($addonInitializeStart) && is_int($addonInitializeEnd)
    ? substr($addon, $addonInitializeStart, $addonInitializeEnd - $addonInitializeStart)
    : '';
$addonReady = strpos($addonInitializeBody, 'await window.OjMlPublicPcReady(root)');
$addonClient = strpos($addonInitializeBody, 'var api = createClient(root)');
$addonRender = strpos($addonInitializeBody, 'await renderLab(root, api)');
ojMlPublicPcAssert(
    str_contains($addon, "root.dataset.publicPcTerminal === '1'")
        && str_contains($addonStatusBody, 'if (publicPcTerminated(root)) return;')
        && is_int($addonReady) && is_int($addonClient) && is_int($addonRender)
        && $addonReady < $addonClient && $addonClient < $addonRender,
    'The addon must await public-PC readiness before creating its client and must ignore terminal status updates.'
);

$renderLabStart = strpos($addon, 'async function renderLab(root, api)');
$renderLabEnd = strpos($addon, 'async function renderStudentResult', $renderLabStart ?: 0);
$renderLabBody = is_int($renderLabStart) && is_int($renderLabEnd)
    ? substr($addon, $renderLabStart, $renderLabEnd - $renderLabStart)
    : '';
$renderLabFetch = strpos($renderLabBody, "await api.get('assignments/'");
$renderLabTerminal = strpos($renderLabBody, 'if (publicPcTerminated(root)) return;', $renderLabFetch ?: 0);
$renderLabContent = strpos($renderLabBody, 'var content = contentNode(root);', $renderLabTerminal ?: 0);
ojMlPublicPcAssert(
    is_int($renderLabFetch) && is_int($renderLabTerminal) && is_int($renderLabContent)
        && $renderLabFetch < $renderLabTerminal && $renderLabTerminal < $renderLabContent,
    'An in-flight lab response must recheck terminal state before mutating public-PC DOM.'
);

$nonceGate = strpos($bootstrap, 'controller.matchesFrameMessage(event)');
$statusGate = strpos($bootstrap, "event.data.type !== 'oj-ml:submission-status'", $nonceGate ?: 0);
$trustedPayload = strpos($bootstrap, 'var trustedStatus = { state: event.data.state }', $statusGate ?: 0);
$trustedDispatch = strpos(
    $bootstrap,
    "new window.CustomEvent('oj-ml:trusted-submission-status'",
    $trustedPayload ?: 0
);
ojMlPublicPcAssert(
    str_contains($addon, '<details class="oj-ml-submit-fallback"><summary>')
        && !str_contains($addon, '<details class="oj-ml-submit-fallback" open>')
        && str_contains($addon, 'data-oj-ml-receipt')
        && str_contains($addon, "root.addEventListener('oj-ml:trusted-submission-status'")
        && str_contains($addon, 'handleSubmissionStatus(event && event.detail)')
        && str_contains($addon, 'receiptHolder.innerHTML = receiptHtml({ receipt: data.receipt })')
        && str_contains($addon, 'showResultLink();')
        && str_contains($addon, 'uploadButton.disabled = true')
        && str_contains($bootstrap, "trustedStatus.message = event.data.message.slice(0, 128)")
        && is_int($nonceGate) && is_int($statusGate) && is_int($trustedPayload) && is_int($trustedDispatch)
        && $nonceGate < $statusGate && $statusGate < $trustedPayload && $trustedPayload < $trustedDispatch
        && str_contains($css, '.oj-ml-submit-fallback'),
    'Direct OJ submission must be primary, manual upload must stay collapsed, and receipts must cross only after nonce validation.'
);
ojMlPublicPcAssert(
    str_contains($bridgePatch, 'OjSubmitButton.vue')
        && str_contains($bridgePatch, 'exportIdentifiedProject')
        && str_contains($bridgePatch, 'submitOjPending')
        && str_contains($bridgePatch, 'submitProjectToOjHost')
        && str_contains($bridgePatch, 'const CONFIG_TIMEOUT_MS = 30_000')
        && str_contains($bridgePatch, 'const submitted = ref(false)')
        && str_contains($bridgePatch, 'const submitBlocked = computed(() => submitted.value || policyIssue.value !== null)')
        && str_contains($bridgePatch, ':disabled="submitBlocked"')
        && str_contains($bridgePatch, 'submitted.value = true')
        && str_contains($bridgePatch, '<OjSubmitButton v-if="project.projectId !== null" />'),
    'The reproducible upstream patch must include an always-visible in-memory submit button that disables after success or a policy conflict.'
);

$domReadyBody = is_int($domReady) ? substr($bootstrap, $domReady) : '';
$initializeCatch = strpos($domReadyBody, '}).catch(function (error)');
$controllersSet = strpos($domReadyBody, 'controllers.set(root, promise)', $initializeCatch ?: 0);
$initializeCatchBody = is_int($initializeCatch) && is_int($controllersSet)
    ? substr($domReadyBody, $initializeCatch, $controllersSet - $initializeCatch)
    : '';
ojMlPublicPcAssert(
    is_int($initializeCatch)
        && is_int($controllersSet)
        && !str_contains($initializeCatchBody, 'throw error;'),
    'Expected public-PC initialization failures must remain handled instead of creating an unhandled rejection.'
);

fwrite(STDOUT, "OJ ML public-PC lifecycle regression passed.\n");

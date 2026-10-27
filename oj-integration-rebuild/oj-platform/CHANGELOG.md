# Changelog

All notable changes to the OJ Platform WordPress plugin are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [2.5.3] - 2026-10-27

- Replace the completion language key input with a supported-language selector.
  Preserve existing aliases and schema hashes when the selection is unchanged.
- Separate quiz draft saves from question-bank import and export forms so
  create, save and publish buttons retain their correct form and nonce.
- Disable inactive question inputs during validation and submission, honor
  hidden quiz panels in CSS, and keep question type headings synchronized.
  Preserve choice contents when switching between choice and short answers.

## [2.5.2] - 2026-10-27

- Allow empty input descriptions and sample inputs in AI-generated problems
  that require no input, while rejecting missing keys and non-string values.
- Send a shared mode-specific authoring output schema with generation and
  title probe requests without changing queued input hashes or Addon API 1.8.
- Identify the rejected draft field and distinguish missing values, incorrect
  types, empty required content, invalid encoding and excessive byte lengths
  without exposing raw model output. Preserve sample whitespace and explicit
  review before saving any problem.

## [2.5.1] - 2026-10-26

- Add independent, enabled-by-default code copy/cut and paste settings for
  submission editors, including Ace, completion inputs and native fallbacks.
  These are browser editing restrictions, not a source-origin security check.
- Use fixed per-user messaging rate windows without extending expiry on
  retries. Validate recipients and message contents before counting attempts,
  and keep expected input or quota rejections out of system failure records.
- Explain known failures with curated operation, cause, next action and error
  codes in system status and logs. Restrict technical locations to system
  managers, preserve tracking IDs and omit credentials, raw exception messages
  and submitted source. Mark unknown causes without guessing.

## [2.5.0] - 2026-10-26

- Cache successful runtime setup inspections for up to five minutes, bound to
  the platform version, installation state and complete schema definitions.
  Keep setup actions and environment readiness checks live.
- Read learner class and group membership in batches and aggregate first
  accepted submission times in SQL instead of repeatedly paging submissions.
- Add optional Addon API 1.8 problem-only and latest-submission projections;
  preserve existing default behavior and source visibility checks.
- Keep addons independently deployable through the public Platform API.

## [2.4.4] - 2026-10-26

- Hide the reverse dependency notice only in OJ Platform's WordPress plugin
  list row. Keep addon dependency declarations and activation, deactivation
  and deletion protections unchanged.

## [2.4.3] - 2026-10-25

- Add optional Addon API 1.7 submission draft handoffs. Authorized addons can
  prepare the learner's source and language in the existing submission form
  without putting source in the URL or creating an official submission.
- Keep draft handoffs encrypted on the server with a short lifetime and bind
  them to the current learner, problem and class or group. Recheck access and
  submission policies when opening the form; the learner submits explicitly.

## [2.4.2] - 2026-10-25

- Include an optional current-learner summary ID baseline in learning-check
  lookups so PRIMM can connect a new submission without asking students to copy
  its number. Keep hidden source, feedback and contest policies intact, and
  distinguish a first submission from an older source-hidden result.

## [2.4.1] - 2026-10-25

- Add a separate AI profile from an existing connection without exposing its
  stored credential or changing the original profile and task assignments.
  Probe only the new profile's selected tasks to avoid the combined interactive
  test budget when adding PRIMM to a connection with many existing tasks.
- Preserve candidate proof, revision and credential destination checks and
  reject copying over an existing profile key.

## [2.4.0] - 2026-10-25

- Add optional Addon API 1.6 learner practice contracts. Recheck membership and
  bind private execution tickets to the current addon, learner and immutable
  material, keeping staff execution permissions and official scores separate.
- Persist AI request results and uncertain outcomes so retries cannot silently
  repeat a potentially billed provider call.
- Admit only configured languages with a successful fixed native judge probe.
  Add execution limits, probe controls and stalled-job diagnostics to submission
  settings, with independent scheduled cleanup of native execution records.
- Emit public problem-save and class problem-assignment events for authorized
  addons to prepare learning materials after content becomes accessible.

## [2.3.25] - 2026-10-24

- Add an optional Addon API 1.5 gateway for private example code execution using
  the existing judge custom-input queue. Bind idempotent tickets to the addon and
  teacher, keep runs separate from learner scores, and require verified normal
  completion before returning an executed verdict.
- Expose bounded output and diagnostics for PRIMM example validation and repair,
  without exposing judge database access to addons.

## [2.3.24] - 2026-10-24

- Compact code ordering fragments into a single row containing the position,
  drag handle, code and movement controls. Remove display-only trailing newlines
  without changing the registered source or submitted block IDs.
- Keep multiline fragments at their natural height. Add insertion indicators
  and touch handle dragging while preserving keyboard movement and drafts.

## [2.3.23] - 2026-10-24

- Add code ordering as a native problem entry mode. Teachers provide complete
  code; learners reorder all fragments once while their contents and indentation
  remain fixed. Reconstruct the registered fragments on the server and submit
  through the existing judge service, accepting equivalent working orders.
- Preserve puzzle revisions, duplicate code fragments and multiline constructs.
  Provide drag controls, keyboard-accessible movement and scoped draft recovery.
- Expose an optional Addon API runtime gateway for authorized puzzle retrieval
  and source assembly so PRIMM and other learning addons can reuse the activity.
- Add a matching optional code-completion gateway that exposes only the public
  blank template and reconstructs learner answers through the existing source
  validation policy, without treating reconstruction as a judge verdict.

## [2.3.21] - 2026-10-24

- Mask temporary passwords in group reset results using the shared password
  result component, with private no-store responses.
- Apply consistent show/hide controls to password inputs across public and
  console pages. Re-mask on page transitions, submission and regeneration
  without changing submitted values or password generation and validation.
- Include the optional learning-check gateway changes documented below.

## [2.3.20] - 2026-10-24

- Add an optional learning-check runtime gateway for authenticated learners'
  visible problem content and their own recent submission source. Preserve
  problem, class, group, contest feedback and submission source access rules.
  Exclude hidden tests, judge wrappers, execution logs and other learners' code.
- Keep existing Addon API 1.4 runtime interfaces and constructors compatible;
  consumers detect the additional learning-check interface before using it.

## [2.3.19] - 2026-10-23

- Close native WordPress comment submission for OJ board and notice posts;
  retain comments authorized through the OJ service and ordinary WordPress posts.
- Protect administrator-managed learner names at the WordPress metadata
  boundary, including native profiles and REST. Keep authorized OJ management,
  group name updates and account anonymization in target-specific write scopes.
- Canonicalize IPv6 and mapped IPv4 AI endpoint identities, and pin approved
  addresses to the cURL connection while retaining HTTPS hostname verification.
  Require direct cURL AI connections and reject proxy paths that bypass the pin.
- Bound combined subprocess output through the final pipe drain, stage test
  data ZIPs in private temporary directories, and reject NUL-containing XML
  and non-UTF-8 XML declarations that could bypass DOCTYPE checks through
  alternate encodings.

## [2.3.18] - 2026-10-23

- Require target-specific WordPress authority when delegated user operators
  change an operational account's profile or access status. Protect every
  account with effective operational capabilities from delegated password
  resets, including through group membership.
- Keep pending accounts in the registration approval flow so access toggles
  cannot bypass approval authority, required agreements or guardian consent.
- Bind submission request reuse and background job deduplication to the actor
  so one account cannot receive another account's submission or job reference.
- Prevent caching of dynamic HTML pages, including personalized account and
  console screens. Retain existing HTML safety headers and file policies.

## [2.3.17] - 2026-10-23

- Send the native WordPress login entry to the OJ login flow before core
  authentication, including direct POST requests and reauthentication flags.
  Route recovery and registration links to their OJ screens without forwarding
  submitted credentials, and preserve valid legacy password reset links.
- Keep native login available during setup, unavailable runtime prerequisites,
  disabled OJ authentication, verified recovery mode and explicit server-side
  emergency recovery. Preserve core logout and non-login confirmation actions.
- Use a new-tab reauthentication prompt in the WordPress editor and retain
  cache and referrer policies on redirect responses. Document deployment and
  emergency access without blocking admin-post.php or the WordPress admin.

## [2.3.16] - 2026-10-23

- Add same-origin frame protection, MIME sniffing protection and a referrer
  policy to platform HTML responses, including error pages. Keep the CSP
  limited to frame ancestors so existing scripts and editors continue working.
- Prevent caching and referrer disclosure on login, password recovery, password
  reset and account transition pages while preserving their response headers.
- Document server-managed HSTS, PHP package verification, service restarts and
  the deployment checks required after a security update.

## [2.3.6] - 2026-10-15

- License OJ Platform's own code under GPL-3.0-or-later with the owner's
  authorization. Include the complete GPLv3 text and retain third-party terms.
- Add a public open-source license page and shared footer link, covering ten
  bundled components, full license texts and separately installed systems.
- Restore Feather and Babel-helper MIT notices, supplement MathJax attribution,
  and expose existing Dracula and LodePNG notices. Require the aggregate notice
  and platform license in every release ZIP.

## [2.3.5] - 2026-10-15

- Match public policy typography to the common editor: line height, paragraph,
  heading and list spacing. Ignore HTML source whitespace while preserving
  explicit line breaks and preformatted code.
- Verify editor/public computed styles in browser regression coverage.

## [2.3.4] - 2026-10-15

- Add edit links to published agreement documents. Load the latest title,
  formatted HTML body and required-consent setting into the common editor.
- Suggest a new version and publish edits without overwriting previous
  documents or consent records. Add regression coverage for safe loading.

## [2.3.3] - 2026-10-15

- Replace the handwritten rich text engine with locally bundled Jodit 4.18.1
  and DOMPurify 3.4.17. Problem fields, board/notice bodies and agreement
  documents share an icon toolbar, contextual table tools and HTML source mode.
- Preserve existing HTML, merged cells, plain-text line breaks, problem image
  upload and LaTeX insertion. Keep WordPress sanitization and existing field
  names; synchronize source-mode edits before submitting a form.
- Add browser regression coverage for responsive editing, table operations,
  undo/redo, unsafe HTML, image upload and LaTeX; pin local vendor hashes.

## [2.3.2] - 2026-10-15

- Compact agreement table tools and add direct HTML source editing.

## [2.3.1] - 2026-10-15

- The login card now contains account actions only. Legal documents remain in
  the shared site footer, removing the duplicate terms link while keeping all
  five policy documents available to guests and signed-in users.
- Administrators can configure footer copyright text, an administrator contact
  email, the existing operations notice, and up to six additional links from
  Operations > Site. Duplicate URLs are collapsed, and custom links accept only
  site-relative paths or HTTP(S) URLs.

## [2.3.0] - 2026-10-15

- Addon API 1.4.0 adds one authorized `AddonIntegrationGatewayV1` channel for
  cross-addon activities, public problems, roster-scoped accepted submissions,
  and the central privacy-management path. Addons no longer need direct access
  to another plugin's classes, routes, options, or tables.
- Addons can contribute permission-aware activity providers, authenticated
  site-wide assets, and privacy-data inventory entries. The platform validates
  missing bundles and duplicate configuration element IDs before accepting a
  manifest.
- Assignment rosters now expose separate display and management names while
  retaining numeric learner IDs. Roster revisions include authorized name
  changes without exposing email addresses or login names.
- The agreement administration screen collects addon data categories and
  retention notes for administrator review. Addons do not generate or publish
  privacy-policy wording; the site document remains centrally authored in OJ
  Platform.

## [2.2.1] - 2026-10-13

D-010 색 복원. v2 배지 규칙이 앞쪽의 톤 규칙과 같은 우선순위로 뒤에 놓여
상태 색을 모두 회색으로 덮어쓰던 문제를 고쳤습니다.

- Badges: success, danger, warning and info colours are back on
  `ojp-badge` and `ojp-status-badge`, and difficulty 1-5 have their colours
  again. The success button no longer shows white text on grey.
- Judge results get their own colours on top of the tone: 맞았습니다 green,
  틀렸습니다 red, 출력 형식 오류 ochre, 시간·메모리·출력 초과 orange, 런타임 오류
  violet, 컴파일 오류 slate, 채점 중 blue, 수동 확인 대기 amber. Final verdicts
  also carry a small dot so they do not rely on colour alone. New tokens
  `--ojp-verdict-*` exist for light and both dark schemes, and every pair
  meets 4.5:1. `JudgeResult::verdict()` and `badgeClass()` build the classes;
  submissions, judge reports, class, contest and group results, problem
  statistics and live polling all use them.
- Scoreboard: solved and attempted cells keep their colour while the row is
  hovered.

## [2.2.0] - 2026-10-13

D-010 통합 UX/UI 2단계(메뉴 재구성). 주소(Route)와 권한은 바꾸지 않았습니다.

- Console: 8 top-level groups in teacher order, with users and operations
  below a divider: 홈, 수업, 문제, 제출, 학습 활동, 커뮤니티 | 사용자, 운영.
  대회·평가 moves under 수업; 쪽지 moves under 커뮤니티; 학습지원 is renamed
  학습 활동.
- New config/console-local-nav.php folds pages that do one job into a
  single sidebar entry. The other pages show as segments under the page
  header (for example 전체 사용자 | 삭제됨). Create pages show as buttons,
  unless the page already links to them. Each page keeps its own feature
  and permission check, so the sidebar shrinks from 55 core items to 28.
- Section headings: 수업 운영 / 수업 도구 under 수업; 일반 / 연결 / 시스템 /
  애드온 under 운영. Addon menus anchored to 수업 or 운영 get the 수업 도구 or
  애드온 heading.
- Labels follow D-010: 역할과 권한, 가입과 약관, 문항 은행, 문제 도구, 주제 사전,
  채점 환경, 메일, AI, 시스템 설정, and so on. Dashboard shortcuts use the
  same names.
- Public menu: 대회·평가 is under 수업, 수업 › 수업 becomes 내 수업, 쪽지 is
  added to 커뮤니티, and 학습지원 is renamed 학습 활동. Saved menus that still
  carry an old default name follow the new one. Obsolete default roots
  (학습, 대회·평가) are dropped.

## [2.1.0] - 2026-10-13

D-010 통합 UX/UI 1단계(플랫폼 기반). Addon API 1.3.0.

- Design tokens v2 with Apple-style neutrals, type, radius, shadow and
  motion scales. The teal accent is unchanged.
- Dark mode that follows the OS, plus a light/dark/system picker in both
  account menus. Addon pages stay light unless they opt in.
- Bundle Pretendard Variable 1.3.9 (SIL OFL) as dynamic subsets.
- Console shell: breadcrumb, Ctrl/Cmd+K menu search, and sidebar section
  headings and dividers (menus gain an optional `section`).
- `data-ojp-confirm` opens an in-page sheet instead of `window.confirm`.
  Success flash notices become toasts. `window.OJP.confirm/toast` are
  available to addons.
- Public mobile tab bar no longer looks for the removed `learning` key.
- Addon API 1.3.0: `AddonConsoleLocalNavBuilderV1::consoleLocalNav()` (a separate
  interface, so existing `AddonManifestBuilderV1` test doubles keep working)
  and `AddonConsoleLocalNavItemV1`
  for in-page navigation under one menu, and
  `AddonPageResponseV1::page()` with `layout` (standard, wide, immersive)
  and `supportsDarkMode`. Pages without a menu entry keep their parent
  menu highlighted. 1.2 manifests are accepted unchanged.
- New docs: `docs/addon-ui-kit.md`.

## [2.0.26] - 2026-10-06

- Reload the submission result page once automatic polling sees a final
  result, so compile messages, judge reports and AI feedback appear without a
  manual refresh. Previously only the badge, time and memory were updated.

## [2.0.25] - 2026-10-06

- Fix runtime errors after setting a color tolerance: the settings file was
  stored 0640 for the web server group only, so the judge account could not
  read it and the SPJ failed every submission. The settings file is now stored
  0644, and the SPJ falls back to the strict default (recording
  `settings=unreadable` in the verdict, which the tab explains) instead of
  failing when it cannot read the file. Invalid content still fails loudly.
- Show "새 버전 있음" when the installed `spj.cc` differs from the grader
  bundled with the plugin, and pre-check the overwrite box so an upgrade does
  not silently keep compiling the old source.

## [2.0.24] - 2026-10-06

- Add a per-problem comparison setting for image grading. A new "비교 기준"
  step in the image grading tab stores a color tolerance (0-96, default 16)
  in `image-spj-settings.txt` next to the reference images; the SPJ reads it
  at judging time and treats an invalid file as a checker error. A rejected
  submission whose largest color difference fits the cap offers a one-click
  suggestion on an 8 step grid. Spatial radius and unmatched pixel counts stay
  fixed.

## [2.0.23] - 2026-10-06

- Explain pixel rejections in more detail: the SPJ verdict now carries the test
  case, the bounding box of differing pixels and the largest color difference,
  and keeps the latest rejected image in `_capture/<problem>.actual.png`
  (removed again when a submission is accepted). The image grading tab shows
  the reference, the last submission and a blended overlay (black means equal).

- Add image insertion to the problem editor (description, input, output and
  hint): an "이미지" toolbar button plus paste and drag-and-drop. Images
  (PNG, JPEG, GIF, WebP up to 5MB and 6000px per side, type decided from the
  bytes) are uploaded through the new `oj_problem_upload_image` AJAX action,
  limited to users who can create or edit the problem, and stored under
  `uploads/oj-platform/problem-images/` with random names. The editor asks for
  an optional alt text; problem pages scale images to the content width.

## [2.0.22] - 2026-10-05

- Make image grading rejections explainable: the SPJ records its latest verdict
  (AC, WA with reason and image sizes or unmatched pixel counts, checker error)
  in `_capture/<problem>.last.txt`, and the image grading tab shows it in plain
  words with the raw line. Recording is best effort and never changes a verdict.

## [2.0.21] - 2026-10-05

- Rework the test data screen into tabs (files, image grading, special judge,
  AI draft). The image grading tab is a guided three-step flow: install the
  grader, make the reference image (automatic capture or PNG upload), review
  registered images. Actions return to the tab they were started from.
- Answer capture no longer needs write access to the problem folder: the SPJ
  writes the candidate to a separate `_capture` folder (created once on the
  server) with readable permissions, and the platform reads, previews and
  applies it into the problem folder. The panel shows the setup commands when
  the folder is missing.

## [2.0.20] - 2026-10-05

- Add Matplotlib/turtle image grading to problem settings: forces the special
  judge and a single Python 3 language, appends hidden output wrappers, and
  installs the embedded image comparison SPJ as `spj.cc` from the test data
  screen (compile job is queued; the panel explains the pending state).
- Add reference PNG upload (stored as Base64 `.out`) and a one-shot
  "answer capture" mode: arm it, submit the model answer normally, and the SPJ
  stores the judged output PNG as a candidate to preview and apply. The SPJ
  process needs write permission on the problem data folder for this.
- Merge the submission list and rejudge pages, add an optional inline
  submission panel and a sticky submit button on problem pages, and compact
  problem list rows.

## [2.0.18] - 2026-09-23

- Omit prefix/suffix lines ending in #hidden from problem and submission pages
  on the server. Keep marked originals in the administrator editor and strip
  only the marker when composing judge source. Do not interpret markers in
  student source or completion templates; this is display control, not a
  secrecy guarantee for judge diagnostics.

## [2.0.17] - 2026-09-22

- Parse slash-separated forbidden words once for problem details and server-side
  submission checks, retaining newline compatibility. Support escaped slash
  and backslash words, ignore empty/duplicate entries, and show individual words.
  Reject overlapping matches at editable code-completion boundaries as well.
- Expose spaces, tabs and non-breaking spaces in judged output comparisons while
  preserving copied text, escaped HTML and difference highlighting. Preserve
  leading whitespace in comparison rows without an opening table pipe.

## [2.0.16] - 2026-09-20

- Show a detailed metadata bulk-approval result when any problem fails, including
  problem numbers, individual reasons, editor/draft links and approval counts.
  Preserve the full failure list even when no draft is approved; successful
  batches retain the existing redirect. Reuse the console table/notice styles.
- Log per-problem approval errors and keep unexpected infrastructure details out
  of the user-facing report. Add mixed/all-failed, expired/stale, permission,
  nonce, escaping and responsive report regressions.

## [2.0.15] - 2026-09-19

- Fix problem create/update validation counting disabled language bits as enabled
  languages. Prefix/suffix and code-completion problems now use the shared Judge
  language catalog to require exactly one allowed language, matching the editor
  checkboxes and submission handling. Multi-language plain-code problems remain
  supported; invalid selections are rejected before any write.
- Add checkbox-request save/update regressions for all supported wrapper
  languages, Python code completion, and zero/multiple-language masks. Correct
  old test fixtures that treated a single disabled bit as a single allowed language.

## [2.0.14] - 2026-09-19

- Compact metadata prompts to an allowed topic key/label map followed by the
  complete problem. Do not repeat category records and the response schema after
  the source; native schemas and persisted job snapshots are unchanged. Reject
  missing or text-empty statements before calling a provider.
- Send `truncate: false` and `shift: false` for native Ollama structured requests
  so supported servers reject context exhaustion rather than dropping evidence.
  Recognized context-limit errors produce a bounded, non-retriable `num_ctx`
  diagnostic without raw source or credentials. Legacy JSON-mode requests and
  other provider adapters retain their existing context behavior.
- Add full-taxonomy prompt and transport regressions. The Hello fixture user
  message shrinks from 11,438 to 3,313 UTF-8 bytes with all source fields and
  allowed topic choices preserved; this is not a model token-count estimate.
  The actual AI endpoint timed out from the local machine, so live context loss
  remains a hypothesis until server settings and inference are checked.

## [2.0.13] - 2026-09-19

- Ground metadata analysis in the current saved statement, input/output,
  samples and hints. Require a Korean rationale tied to source evidence; prohibit
  inventing a harder problem or substituting a remembered task. Image-only or
  contradictory evidence must be identified as uncertain, not invented.
- Exclude existing difficulty/topics and acceptance statistics from metadata
  provider requests to avoid reinforcing incorrect prior classifications. Keep
  the full snapshot for conflict checks and explicit draft review. Add a Hello
  statement with incorrect DP metadata as an input-isolation regression case.
  Tests verify request construction, not the factual accuracy of live model output.
- Reported case: problem 1101 analyzed by `gemma4:26b` described a tree independent
  set and proposed adding bitmasks despite a statement requiring only `Hello`
  output. This is not a valid analysis; live regeneration remains to be verified.
- Share one metadata output schema between candidate inspection and queued
  generation; candidate inspection now sends native structured-output requests.
- Ground Ollama's native schema in the platform system message while preserving
  addon instruction order. Detect ignored top-level required fields at the
  provider boundary instead of repeating malformed responses through the queue.
- Report missing schema fields without exposing raw output, unknown response
  keys, prompts or credentials. Never invent confidence or save partial drafts.
- Add wire-payload, metering, probe/runtime parity and missing-confidence
  regression checks. Public Addon API 1.2.0 and database contracts are unchanged;
  ignored required fields now return the existing non-retriable failure result.
- Live server verification is pending; the local connection attempt timed out.
- Upstream issue #15260 (`think=false` bypassing Gemma 4 format constraints)
  was fixed in Ollama 0.21.1. The user clarified that 0.20.5 is the CLI version
  and the running server reports 0.34.2. The old-server hypothesis is therefore
  not established for the current endpoint; an outdated CLI is not used by the
  plugin's HTTP requests. Verify the configured endpoint and its actual response
  before attributing missing confidence to that historical bug.

## [2.0.12] - 2026-09-19

- Retry malformed problem-metadata AI output within the existing Jobs attempt
  budget instead of stopping at the first missing confidence field. Keep the
  result code and original diagnosis, use a five-second queue delay and require
  review when attempts are exhausted. Never fabricate confidence or store partial
  drafts; invalid topics, changed inputs and access failures still require review.
- Reinforce complete-object instructions on repeated attempts without changing
  queued input hashes. Require confidence and rationale in metadata connection
  probes as well. No database or public Addon API changes.
- Explain that changed AI routing or profile snapshots require a newly registered
  job, not retries of the old snapshot. Preserve revision and fingerprint guards
  and block provider calls for stale jobs.
- Build from the clean `v2.0.12` source tag; server deployment remains separate.

## [2.0.11] - 2026-09-18

- Compare site-local class and problem schedules with UTC Judge submission times
  as timezone-aware instants. Fix accepted class submissions disappearing from
  personal submissions and participant/staff progress reports.
- Keep inclusive schedule boundaries, permission checks, performance-mode
  restrictions and distinct solved counts unchanged. Existing class-linked
  records are read directly without rewriting timestamps or resubmission.
- Add regression coverage for UTC, positive/negative/fractional offsets, DST
  changes and open-ended schedules. No schema or public Addon API changes.
- Build from the clean `v2.0.11` source tag; server deployment remains separate.

## [2.0.10] - 2026-09-18

- Give connected-group multiselects a shared minimum height of 240px.
- Add class copy controls to management lists and edit pages. Prepare a new,
  private, stopped class with reusable problem configuration and allowed groups;
  exclude enrollments, submissions, staff grants, passwords and old schedules.
- Reuse the existing class save transaction and revalidate creation permission,
  problem access and group selection before saving a copy.
- Make class progress matrices scroll independently in both directions with
  sticky participant names and headings. Show distinct accepted problems beside
  each participant, preserving validity, enrollment and availability rules.
- Add 40 focused regression checks and responsive browser coverage at five widths.
  Preserve the public Addon API 1.2.0 and database schemas.
- Build from the clean `v2.0.10` source tag; server deployment remains separate.

## [2.0.9] - 2026-09-18

- Accept unambiguous quoted JSON numbers for AI metadata confidence while
  distinguishing missing fields, wrong types, percentages and out-of-range values.
- Reject invalid confidence instead of silently clamping it to 0% or 100%.
  Reinforce the existing response contract in the metadata prompt.
- Normalize a leading UTF-8 BOM around generated JSON and code fences. Distinguish
  empty, reasoning-only, incomplete and malformed Ollama responses without
  exposing generated content or changing provider exception codes.
- Extend regression coverage for response parsing, job results and responsive
  diagnostics. Preserve the public Addon API 1.2.0 and database schemas.
- Build from the clean `v2.0.9` source tag; server deployment remains separate.

## [2.0.8] - 2026-09-18

- Keep the console sidebar within the viewport and scroll its navigation
  independently of the main page. Prevent scroll chaining at menu boundaries.
- Add accessible submenu toggles without changing root navigation links or
  permissions. Open the current section automatically and remember other open
  sections for the browser tab; share state with the mobile drawer.
- Keep desktop sidebar collapse separate from mobile layout and navigation.
- Constrain AI metadata topic selection to active, selectable topic keys and
  expose invalid keys and rejection reasons in authorized job details. Reject
  invalid drafts without storing the provider response or changing the Addon API.
- Add PHP and responsive browser regression checks for navigation and AI topics.
- Build from the clean `v2.0.8` source tag; server deployment remains separate.

## [2.0.7] - 2026-09-17

- Fix group managed-name bulk saves posting to an invalid URL when WordPress's
  hidden `action` field shadows the form's action property.
- Apply the same action-attribute handling to AI job creation and status polling.
- Verify exact POST endpoints, partial failures, retries, invalid server responses,
  and multi-user saves in browser regression tests. Preserve permissions, naming
  policy, database schemas, and the public Addon API 1.2.0 contract.
- Build from the clean `v2.0.7` source tag; server deployment remains separate.

## [2.0.5] - 2026-09-17

- Keep research in the separate OJ Research 1.0.0 WordPress plugin and
  integrate its contracts into the common Addon API 1.2.0.
- Add an optional early `oj_platform_register_extensions` phase for research
  configuration and policies. Preserve existing addon hooks, registration timing,
  runtime callbacks, Context/AI/editor interfaces and 1.1-compatible manifests.
- Replace the dev.1 research-only API namespace, version constant and hook;
  update both packages together. Existing research data and the
  learner-level evaluation algorithm remain unchanged.
- Add API compatibility, dependency-range and combined plugin lifecycle checks.
  See [Addon API 1.2](docs/addon-api-1.2.md) for contracts and upgrade order.
- Finalize stable versions with shared release-commit provenance: platform tag
  `v2.0.5` and research tag `oj-research-v1.0.0`. Require clean sources for both
  release builders and keep existing development packages immutable.

## [2.0.1] - 2026-09-12

### C-30 Judge Result Parsing

- Parse HUSTOJ runtimeinfo test summaries and Expected/Yours comparisons before
  invoking the shared language-specific runtime error parser.
- Associate repeated tracebacks and non-zero exit codes with the correct input
  file; preserve all seven cases from the reported Python AttributeError log.
- Render AC/WA/RE using the existing result catalog, and show output differences
  as escaped two-column tables with preserved whitespace, markers and empty cells.
- Keep result-only access restrictions, metric visibility settings and admin-only
  raw diagnostics unchanged. No Judge schema or public Addon API changes.
- Add the reported RE/WA fixtures and parser, rendering and responsive regression
  coverage. The published 2.0.0 archive and v2.0.0 tag remain unchanged.
- Package this fix as 2.0.1 with a matching source tag and SHA-256 manifest;
  server deployment remains a separate operation.

## [2.0.0] - 2026-09-11

- Approved by the user as the first stable release of the rebuilt platform.
- Promoted the reviewed dev.107 baseline with C-29 release hardening. The stable
  package is identified by the matching v2.0.0 Git tag and SHA-256 manifest.
- Production deployment and environment-specific acceptance remain separate.

### C-29 Release Hardening

- Replaced duplicated version literals in tests with header/runtime consistency checks.
- Required full tests and PHP syntax/extension checks before packaging; stable versions
  require a clean Git commit and the matching version tag, even with AllowDirty.
- Unified spreadsheet CSV escaping, preserved timestamp seconds and historical offsets,
  and made private report downloads non-cacheable with nosniff headers.
- Added explicit UTC storage confirmation to Judge connection candidates. Existing
  settings without this confirmation cannot open runtime Judge connections; administrators
  must verify historical solution.in_date values before applying the candidate. No rows
  are converted automatically. WordPress display timezone remains independent of storage.
- Preserved the last retry error when a job exhausts its attempts.
- Bounded feedback validation pages to ten summaries and one selected response; full
  evidence is loaded only for explicit downloads. Split research actions and report output.
- Fixed mobile test-data form overflow in the shared stylesheet and added browser checks.
- Addon API 1.1.0 public signatures are unchanged.

## [2.0.0-dev.107] - 2026-09-11

- Kept database and background-job timestamps in UTC while displaying them in
  the WordPress site timezone across user, administrator, and export views.
- Applied the WordPress timezone to contest, class, quiz, account, dashboard,
  recommendation, and migration decisions, including local calendar filters.
- Fixed Judge DB session time consistency and added timezone boundary coverage.

## [2.0.0-dev.106] - 2026-09-11

- Added a dedicated `feedback_prompt_validation` AI task for repository-owned
  synthetic fixtures. It shares the feedback system prompt and output contract
  while preserving the existing student-source approval policy.
- Added opt-in classroom prompts for four learner states and a guide preview
  workflow for real baseline/candidate responses, immutable private records,
  structural checks, and teacher-review exports (introduced in dev.105).
- The learner-level calculation and existing built-in guides are unchanged.
- Production evidence, failed requests, missing conditions, and the outstanding
  Gemini profile restoration are documented in
  `docs/research-feedback-validation-20260911/index.html`.

## [2.0.0-dev.104] - 2026-09-10

- Added the optional `AddonEditorRuntimeContextV1` interface so addons can read
  the current account's submission editor theme and font size, along with the
  same locally hosted Ace assets. Existing runtime contracts remain compatible.
- The read-only gateway takes no user ID, exposes no account details or code
  template, and falls back to default appearance or plain text input on failure.
- No database, permission, submission workflow, or external asset changes.
## [Unreleased]

### C28-I06 release candidate (2.0.0-dev.33)

- Bumped the additive Addon API from 1.0.0 to 1.1.0. The public surface now
  contains 8 interfaces, 28 DTOs, and 1 capability utility (37 symbols).
- Added learning-context listing, exact assignment roster pagination, and
  versioned per-task AI instructions while retaining all 1.0 call shapes.
- Added separate runtime readiness diagnostics for accepted addons. Callback
  failures remain isolated and do not retract the accepted catalog entry.
- Enforced both oj_access_console and the declared addon capability for addon
  Console menus and routes, and retained the declared capability on PublicUI
  menu resolution.
- No platform database migration or addon-owned table change is included.

_No version has been tagged or released yet (`git tag --list` is empty).
The work below is committed on branch `rebuild/oj-platform-v2` on top of
`af444f0` (`feat: complete platform rebuild and release safeguards`), across
commits `a1ca5e3` (C-28 addon API, I01-I05 + AM-01 + namespace-reuse fix) and
`b719cba` (unrelated live-site UserSettingsPageHandler TypeError fix).
`OJ_PLATFORM_VERSION` in `oj-platform.php` was bumped from `2.0.0-dev.28` to
`2.0.0-dev.29` for this packaged build._

### Added

- 문제 추가·수정 화면의 문제 설명, 입력, 출력, 힌트에 공통 서식 편집기를
  적용했다. 문단, 제목, 강조, 목록, 코드 블록, 인용, 링크, LaTeX 입력을
  지원하고, AI 초안과 저장 원문을 같은 필드 계약으로 동기화한다.

- **C-28 애드온 확장 API v1** (`src/AddonApi/V1/`, C28-I01~I05) — 외부 WordPress
  플러그인("애드온")이 플랫폼 파일을 건드리지 않고 OJ Console/PublicUI에
  Console·Public 페이지, 메뉴, capability, AI task를 등록할 수 있는 공개 계약.
  등록 창(`oj_platform_register_addons`), manifest 검증·원자 승인
  (`AcceptedAddonCatalog`), 실제 Route/Menu/Capability/AI Task Registry
  연동, 학사 Context 인가(`AddonContextGatewayV1`)와 AI Task 실행 게이트웨이
  (`AddonAiTaskGatewayV1`)의 fail-closed 구현을 포함한다.
  자세한 계약은 [development-c-28-addon-api-v1-contract.html](docs/development-c-28-addon-api-v1-contract.html),
  설계 배경은 [development-c-28-addon-extension-api.html](docs/development-c-28-addon-extension-api.html)을 참고.

### Changed

- 대회 화면의 현재 서버 시각을 WordPress 사이트 시간대 기준으로 매초
  갱신한다. 사용자 장치 시계와 무관하게 서버 시각을 기준점으로 사용하며,
  대회 시작·종료 경계에 도달하면 화면을 다시 불러와 단계와 제출 가능
  상태를 현행화한다.

- **[Breaking, Addon API] AM-01 (2026-08-11)** — `oj_platform_loaded` action과
  `AddonRuntimeGatewayV1`/`forAddon()`을 완전히 제거하고
  `AddonManifestBuilderV1::onReady(callable): void`로 대체했다. 이전 설계는
  모든 애드온 callback에 같은 전역 Gateway를 방송해, 임의의 addon ID로
  `forAddon()`을 호출하면 **다른 애드온의 Runtime Context를 그대로 받을 수
  있는 애드온 간 impersonation 결함**이 있었다. 이 결함은 애드온 코드
  품질과 무관한 API 설계 자체의 문제였다. 새 설계는 각 애드온이 등록 시
  직접 넘긴 callback을, 그 애드온만을 위해 생성한 `AddonRuntimeContextV1`
  하나와 함께 plain PHP 함수 호출로 정확히 한 번 실행한다 — WordPress
  action이 아니므로 다른 코드가 가로챌 수 없고, 조회형 API가 없으므로
  임의 addon ID로 다른 애드온에 접근할 방법이 없다.
  이 변경은 SemVer상 major(공개 interface·action 삭제)에 해당하지만, 발견
  당시 Addon API가 아직 정식 릴리스(C28-I06) 전이었고 **실제로 배포된
  소비자가 없었기 때문에** — 즉 이 결함으로 실제 피해를 입은 애드온은
  없었다 — 병행 지원 없이 즉시 교체하는 예외를 사용자 승인으로 적용했다.
  API가 정식 릴리스된 뒤에는 이 예외가 적용되지 않으며, breaking 변경은
  반드시 major bump와 병행 지원 계획을 동반해야 한다.
  전환 절차는 [development-c-28-addon-onready-migration.html](docs/development-c-28-addon-onready-migration.html),
  판정 기록은 계약 이관서의 [계약 개정 기록(AM-01)](docs/development-c-28-addon-api-v1-contract.html#amendments)을 참고.

### Fixed

- **[Addon API, medium] namespace 재사용을 통한 AI routing 상속 방지
  (2026-08-12)** — `AddonManifestValidator`가 namespace/AI task key 중복
  검사를 부팅 라운드 안에서만 수행해, 애드온이 제거된 뒤 다른 애드온이 같은
  namespace를 재사용하면 옛 애드온에 배정돼 있던 AI task routing(운영자가
  승인한 provider·model)을 승인 없이 그대로 이어받을 수 있었다(routing
  배정은 task-type 문자열로만 식별되고 addon 신원과 묶여 있지 않았기
  때문). 새 `AddonNamespaceLedger`(`src/Kernel/Addon/AddonNamespaceLedger.php`,
  WordPress 옵션 구현 `src/Kernel/WordPress/WordPressAddonNamespaceLedger.php`)가
  namespace를 처음 승인된 addonId에 영구 귀속시키고, 다른 addonId가 같은
  namespace로 등록을 시도하면 새 오류 코드 `addon_namespace_reserved`로
  거부한다(minor API 추가). 같은 addonId가 재부팅 뒤 자신의 namespace를
  다시 쓰는 정상 흐름은 영향받지 않는다.
  회귀 테스트: `tests/Contract/addon-runtime-dispatch.php`.

- **[Auth, live-site crash, unrelated to C-28] 사용자 설정 화면 TypeError
  (2026-08-12)** — `UserSettingsPageHandler::select()`가 옵션 배열을
  `foreach`로 순회할 때 배열 key를 그대로 `escape(string $value)`에
  넘겼는데, PHP는 `'14'`처럼 숫자로만 된 문자열 key를 배열 생성 시점에
  자동으로 `int`로 바꾸기 때문에(`editor_font_size` 옵션 `['14' =>
  '14px', ...]`가 실제로는 `[14 => '14px', ...]`가 됨), `strict_types=1`
  아래에서 `escape(14)` 호출이 `TypeError`로 즉시 터졌다. 프로필 완성이
  필요한 계정은 모든 요청이 `/user-settings?mode=profile-completion`으로
  강제 이동되므로, 실제 운영 사이트에서는 **로그인한 뒤 모든 페이지가
  500 오류로 보였다**. 옵션 순회 시 key를 `(string)`으로 명시적으로
  맞춰 수정. 회귀 테스트: `tests/Unit/auth.php`(수정 전 코드로 되돌리면
  운영 로그와 동일한 `TypeError`가 그대로 재현됨을 확인).

이번 라운드의 보안 재검토(원본 발견 12건 중 8건 수정, 위 항목으로 9건째)
이후에도 우선순위가 낮아 이번에 다루지 않은 항목:

- `RealAddonContextGateway::classRevision()`/`groupRevision()`의 revision
  해시가 담당 교사·관리자 명단 변경을 반영하지 않음(low — 실제 인가는
  revision과 무관하게 매번 다시 검사하므로 현재 악용 불가).
- `AddonContextAccessRequestV1::operation()`이 선언만 되고 실제로 강제되지
  않음(low — 과도하게 엄격한 방향의 안전한 실패이며 우회로가 아님).
- `tests/Contract/addon-i05-runtime.php`의 `FakeAiTaskRouter`가 실제
  `AiTaskRoutingService`/`StoredAiTaskRouter` 스택을 우회해, 유사한 라우팅
  회귀를 이 스위트가 잡아내지 못함(테스트 공백, 프로덕션 결함 아님).

### Known gaps (C28-I06, not yet done)

이번 라운드는 `oj-platform` 저장소 내부 준비로 범위를 한정했다. 아래는
C28-I06(릴리스) 완료를 위해 아직 남아 있는 항목이며, 의도적으로 이번에
다루지 않았다:

- 플랫폼 제품 최종 버전 확정 및 실제 commit·tag를 통한 릴리스.
- 별도 저장소인 `oj-ai-dashboard` 애드온의 실제 소비자 인수(고정 기여
  17개 승인 등) 및 그 저장소의 소비자 프로필 문서
  (`docs/ai-assessment/a-ds-02-addon-api-profile.html`)에 남아 있는 동일한
  `@C28_PLATFORM_MIN@` placeholder 동기화.
- 애드온 설치·업데이트 전후 플랫폼 source/config/migration hash 동일성
  검증(실제 설치 환경 필요).
- Addon API 전용 upgrade·rollback 절차와 증거 문서화.

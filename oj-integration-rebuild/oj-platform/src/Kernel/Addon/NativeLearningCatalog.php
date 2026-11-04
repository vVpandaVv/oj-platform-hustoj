<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningLinkV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningPageV1;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;

/** Native lists consume escaped, bounded metadata from accepted providers only. */
final class NativeLearningCatalog implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\NativeLearningCatalog
{
    public const MAX_PROVIDERS = 4;
    public function __construct(private readonly AcceptedAddonCatalog $catalog, private readonly CurrentAccountProvider $accounts, private readonly Authorization $permissions, private readonly RouteUrlProvider $urls, private readonly ?FeatureFlags $features = null) {}
    /** @return array<string,array{manifest:AddonManifest,definition:object}> */
    private function definitions(bool $teacher): array
    {
        $actor = $this->accounts->current()->identity()?->userId() ?? 0;
        if ($actor < 1 || !$this->catalog->isFrozen() || ($this->features !== null && !$this->features->isEnabled(AddonCapabilityKeys::FEATURE_KEY))) { return []; }
        $result = [];
        foreach ($this->catalog->accepted() as $entry) {
            $manifest = $entry->manifest(); if ($manifest === null) { continue; }
            $pages = $teacher ? $manifest->consolePages() : $manifest->publicPages(); $allowed = false;
            if ($teacher && !$this->permissions->can($actor, 'oj_access_console')) { continue; }
            foreach ($pages as $page) { if ($this->permissions->can($actor, AddonCapabilityKeys::capabilityKey($page->capability()))) { $allowed = true; break; } }
            if (!$allowed) { continue; }
            foreach ($manifest->nativeLearningProviders() as $definition) { $result[$definition->key()] = ['manifest' => $manifest, 'definition' => $definition]; }
        }
        uasort($result, static fn(array $a, array $b): int => [$a['definition']->order(), $a['definition']->key()] <=> [$b['definition']->order(), $b['definition']->key()]);
        return array_slice($result, 0, self::MAX_PROVIDERS, true);
    }
    public function hasProviders(bool $teacher = false): bool { return $this->definitions($teacher) !== []; }
    public function showsProgramming(HttpRequest $request, bool $teacher = false): bool
    { return $this->definitions($teacher) === [] || in_array($request->queryString('type', 'all'), ['all', 'programming'], true); }
    public function problemFilters(HttpRequest $request, bool $teacher = false): string
    {
        $definitions = $this->definitions($teacher); if ($definitions === []) { return ''; }
        $types = ['all' => '전체 유형', 'programming' => '프로그래밍']; foreach ($definitions as $key => $entry) { $types[$key] = $entry['definition']->label(); }
        $current = $request->queryString('type', 'all'); $links = '';
        foreach ($types as $type => $label) { $links .= '<a class="ojp-tab' . ($current === $type ? ' is-active' : '') . '" href="' . $this->escape($request->path() . ($type === 'all' ? '' : '?type=' . rawurlencode($type))) . '"' . ($current === $type ? ' aria-current="page"' : '') . '>' . $this->escape($label) . '</a>'; }
        return '<nav class="ojp-tabs" aria-label="문제 유형">' . $links . '</nav>';
    }
    public function problems(HttpRequest $request, bool $teacher = false): string
    {
        $selected = $request->queryString('type', 'all');
        if ($selected === 'programming') { return ''; }
        $definitions = $this->definitions($teacher);
        if ($definitions === []) { return ''; }
        if ($selected !== 'all' && !isset($definitions[$selected])) { return '<p class="ojp-notice ojp-notice--info">선택한 문제 유형을 현재 사용할 수 없습니다.</p>'; }
        $content = '';
        foreach ($definitions as $key => $entry) { if ($selected === 'all' || $selected === $key) { $content .= $this->render($entry, $request, $teacher ? 'problems.teacher' : 'problems.public', 0); } }
        return $content;
    }
    public function classAssignments(HttpRequest $request, int $classId, bool $teacher): string
    {
        if ($classId < 1) { return ''; } $content = '';
        foreach ($this->definitions($teacher) as $entry) { $content .= $this->render($entry, $request, $teacher ? 'class.teacher' : 'class.student', $classId); }
        return $content;
    }
    private function render(array $entry, HttpRequest $request, string $surface, int $contextId): string
    {
        $definition = $entry['definition']; $manifest = $entry['manifest'];
        $cursorKey = 'native_before_' . substr(hash('sha256', $definition->key()), 0, 10);
        $raw = $request->queryString($cursorKey, '0');
        $before = preg_match('/^(0|[1-9][0-9]*)$/D', $raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        $heading = '<section class="ojp-section ojp-native-learning" data-native-provider="' . $this->escape($definition->key()) . '"><h2>' . $this->escape($definition->label()) . '</h2>';
        try {
            if ($before === false) { throw new \InvalidArgumentException('Invalid native cursor.'); }
            $query = new AddonNativeLearningQueryV1($this->accounts->current()->identity()->userId(), $surface, $contextId, $before, 20);
            $page = $definition->provider()->query($query);
            if (count($page->items()) > $query->limit() || ($page->nextBeforeId() !== null && ($page->items() === [] || ($before > 0 && $page->nextBeforeId() >= $before)))) { throw new \UnexpectedValueException('Invalid native page boundary.'); }
            if (!$page->isAvailable()) { return $heading . '<p class="ojp-notice ojp-notice--info">' . $this->escape($page->message() ?: '현재 이 목록을 사용할 수 없습니다.') . '</p></section>'; }
            $actions = ''; foreach ($page->actions() as $link) { $actions .= $this->link($manifest, $link); }
            $rows = '';
            foreach ($page->items() as $item) {
                if (!str_starts_with($item->externalId(), $manifest->namespace() . ':')) { throw new \UnexpectedValueException('Foreign native item namespace.'); }
                $primary = $this->link($manifest, $item->primaryLink()); if ($primary === '') { continue; }
                $buttons = $primary; foreach ($item->secondaryLinks() as $link) { $buttons .= $this->link($manifest, $link); }
                $displayId = preg_match('/:(\d+)$/D', $item->externalId(), $idMatch) === 1 ? '#' . $idMatch[1] : $item->typeLabel();
                $rows .= '<tr data-native-id="' . $this->escape($item->externalId()) . '"><td class="ojp-cell-nowrap">' . $this->escape($displayId) . '</td><td><span class="ojp-status-badge">' . $this->escape($item->typeLabel()) . '</span></td><td>' . $this->escape($item->title()) . '</td><td>' . $this->escape($item->statusLabel()) . '</td><td><div class="ojp-command-group">' . $buttons . '</div></td></tr>';
            }
            $body = ($page->message() === '' ? '' : '<p class="ojp-muted">' . $this->escape($page->message()) . '</p>') . ($actions === '' ? '' : '<div class="ojp-command-group">' . $actions . '</div>');
            $body .= $rows === '' ? '<div class="ojp-empty-state"><p>이 목록에 표시할 문제가 없습니다.</p></div>' : '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr><th>번호</th><th>유형</th><th>문제</th><th>상태</th><th>작업</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
            $parameters = $this->pagingParameters($request); $paging = '';
            if ($before > 0) { unset($parameters[$cursorKey]); $paging .= '<a class="ojp-button ojp-button--small" href="' . $this->escape($this->pageUrl($request->path(), $parameters)) . '">처음 페이지</a>'; }
            if ($page->nextBeforeId() !== null) { $parameters[$cursorKey] = (string) $page->nextBeforeId(); $paging .= '<a class="ojp-button ojp-button--small" href="' . $this->escape($this->pageUrl($request->path(), $parameters)) . '">다음 문제</a>'; }
            return $heading . $body . ($paging === '' ? '' : '<nav class="ojp-pagination" aria-label="' . $this->escape($definition->label()) . ' 페이지">' . $paging . '</nav>') . '</section>';
        } catch (\Throwable) {
            return $heading . '<p class="ojp-notice ojp-notice--warning">이 문제 목록을 불러오지 못했습니다. 잠시 후 다시 열어 주세요.</p></section>';
        }
    }
    private function link(AddonManifest $manifest, AddonNativeLearningLinkV1 $link): string
    {
        $actor = $this->accounts->current()->identity()?->userId() ?? 0; $found = false;
        foreach (array_merge($manifest->publicPages(), $manifest->consolePages()) as $page) {
            if ($page->key() !== $link->routeKey()) { continue; }
            if (!$this->permissions->can($actor, AddonCapabilityKeys::capabilityKey($page->capability()))) { return ''; }
            if ($page instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1 && !$this->permissions->can($actor, 'oj_access_console')) { return ''; } $found = true; break;
        }
        if (!$found) { throw new \UnexpectedValueException('Foreign native link target.'); }
        $url = $this->urls->path($link->routeKey(), $link->parameters());
        if (!$this->safePath($url)) { throw new \UnexpectedValueException('Unsafe native link target.'); }
        if ($link->query() !== []) { $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($link->query(), '', '&', PHP_QUERY_RFC3986); }
        return '<a class="ojp-button ojp-button--small" href="' . $this->escape($url) . '">' . $this->escape($link->label()) . '</a>';
    }
    private function safePath(string $url): bool
    { return str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\') && preg_match('/[\x00-\x20\x7f]/', $url) !== 1 && parse_url($url, PHP_URL_HOST) === null && parse_url($url, PHP_URL_SCHEME) === null; }
    private function pagingParameters(HttpRequest $request): array
    {
        $result = []; foreach ($request->query() as $key => $value) { if (!is_string($key) || !is_scalar($value)) { continue; } if (in_array($key, ['q', 'page', 'visibility', 'judging', 'type'], true) || preg_match('/^native_before_[a-f0-9]{10}$/D', $key)) { $result[$key] = substr((string) $value, 0, 200); } } return $result;
    }
    private function pageUrl(string $path, array $query): string
    { if (!$this->safePath($path)) { throw new \UnexpectedValueException('Unsafe native list path.'); } return $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)); }
    private function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

<?php
/** Exercise the actual platform route adapter using real WP users and capabilities. */
declare(strict_types=1);
use CodingPanda\OjPlatform\Modules\Auth\Contract as Auth;
use CodingPanda\OjPlatform\Modules\Permission\Contract as Perm;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract as Http;
$accounts = new class implements Auth\CurrentAccountProvider { public function current(): Auth\CurrentAccount { $u = wp_get_current_user(); return new Auth\CurrentAccount($u->ID ? new Auth\UserIdentity($u->ID, $u->user_login, $u->user_email, $u->display_name, $u->roles) : null, null); } };
$permission = new class implements Perm\Authorization { public function can(int $userId, string $capability, ?Perm\PermissionContext $context = null): bool { return user_can($userId, $capability); } public function canAny(int $userId, array $capabilities): bool { foreach ($capabilities as $c) if ($this->can($userId, $c)) return true; return false; } public function isSuperAdmin(int $userId): bool { return user_can($userId, 'manage_options'); } };
$urls = new class implements Http\RouteUrlProvider { public function path(string $routeName, array $parameters = []): string { return '/oj-addons/oj-logic-lab/learn'; } };
$responder = new class implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder { public function guest(string $title, string $content, Http\HttpRequest $request, int $status = 200): Http\HttpResponse { return Http\HttpResponse::html($content, $status); } public function authenticated(string $title, string $content, Http\HttpRequest $request, int $status = 200): Http\HttpResponse { return Http\HttpResponse::html($content, $status); } };
$route = new Http\RouteDefinition('addon.oj_logic_lab.learn', new \CodingPanda\OjPlatform\Kernel\Module\ModuleId('addons'), 'addons', '/oj-addons/oj-logic-lab/learn', Http\RouteArea::PublicArea, ['GET'], Http\AuthenticationRequirement::Authenticated, 'html');
$adapter = new \CodingPanda\OjPlatform\Kernel\Addon\AddonRouteHandler('addon.oj_logic_lab.learn', new \OjLogicLab\Addon\PageHandler(), 'oj_logic_lab_learn', $accounts, $permission, new \CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory(), $urls, null, $responder, null);
foreach ([0 => 302, $other => 403, $student => 200, 1 => 200] as $id => $expected) { wp_set_current_user($id); $result = $adapter->handle(new Http\RouteMatch($route, []), new Http\HttpRequest('GET', '/oj-addons/oj-logic-lab/learn')); checkLogic($result->status() === $expected, 'real platform route adapter status ' . $expected . ' user ' . $id); }

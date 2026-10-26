<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    class AddonCapabilityKeyV1 { public static function wordpress(string $key): string { return $key; } }
    class AddonContextAccessRequestV1 {
        public function __construct(public int $actor, public string $type, public string $id, public string $operation) {}
    }
    interface AddonContextGatewayV1 { public function authorize(AddonContextAccessRequestV1 $request): object; }
    class AddonRuntimeContextV1 {
        public function __construct(private AddonContextGatewayV1 $gateway) {}
        public function contexts(): AddonContextGatewayV1 { return $this->gateway; }
        public function snapshot(): object { return new class { public function status(): string { return 'accepted'; } }; }
    }
}
namespace {
    use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
    use LearningMaterials\Addon\Runtime_Bridge;
    use LearningMaterials\Materials\Material_Access;
    $manager = true;
    function is_user_logged_in(): bool { return true; }
    function current_user_can(string $cap): bool { return $GLOBALS['manager']; }
    foreach (array('Support/Capabilities', 'Addon/Runtime_Bridge', 'Materials/Material_Access') as $file) require dirname(__DIR__) . '/src/' . $file . '.php';
    $gateway = new class implements AddonContextGatewayV1 {
        public array $calls = array();
        public function authorize(AddonContextAccessRequestV1 $request): object {
            $this->calls[] = $request->operation;
            $allowed = $request->id === 'group:9' && ($request->operation === 'view' ? $request->actor === 1 : $request->actor === 2);
            return new class($allowed) {
                public function __construct(private bool $allowed) {}
                public function isAllowed(): bool { return $this->allowed; }
                public function snapshot(): ?object { return $this->allowed ? new class { public function label(): string { return '2026-2-3'; } } : null; }
            };
        }
    };
    Runtime_Bridge::connect(new AddonRuntimeContextV1($gateway));
    $access = new Material_Access();
    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    check($access->scope_label(1, array('group:9')) === '2026-2-3', 'Manager who is not enrolled must see group name');
    check($gateway->calls === array('view'), 'Manager uses staff authorization');
    $manager = false;
    $gateway->calls = array();
    check($access->scope_label(2, array('group:9', 'group:99')) === '2026-2-3', 'Learner sees only authorized group names');
    check($gateway->calls === array('participate', 'participate'), 'Learner never requests staff authorization');
    check(!$access->can_view(2, array('group:99')), 'Restricted materials remain inaccessible');
    check($access->scope_label(3, array('group:9')) === '', 'Nonmember cannot read group name');
    $manager = true;
    check($access->scope_label(2, array('group:9')) === '2026-2-3', 'Member fallback works when staff authorization denied');
    echo "Group labels: 7 checks OK\n";
}

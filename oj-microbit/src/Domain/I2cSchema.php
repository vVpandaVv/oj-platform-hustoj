<?php
declare(strict_types=1);
namespace OjMicrobit\Domain;

use InvalidArgumentException;

/** Explicit byte-register devices; arbitrary hardware protocols are not inferred. */
final class I2cSchema
{
    public static function devices(mixed $value, bool $published): array
    {
        $result = []; $seen = [];
        foreach (SchemaValue::list($value, 16, 'exercise.i2cDevices') as $index => $item) {
            $path = 'exercise.i2cDevices.' . $index;
            $item = SchemaValue::object($item, $path);
            $address = SchemaValue::integer($item['address'] ?? null, 0, 127, $path . '.address');
            if (isset($seen[$address])) { throw new InvalidArgumentException($path . ': I2C 주소가 중복되었습니다.'); }
            $seen[$address] = true; $registers = []; $seenRegisters = [];
            foreach (SchemaValue::list($item['registers'] ?? [], 256, $path . '.registers') as $registerIndex => $entry) {
                $entryPath = $path . '.registers.' . $registerIndex;
                $entry = SchemaValue::object($entry, $entryPath);
                $register = SchemaValue::integer($entry['register'] ?? null, 0, 255, $entryPath . '.register');
                if (isset($seenRegisters[$register])) { throw new InvalidArgumentException($entryPath . ': 레지스터가 중복되었습니다.'); }
                $seenRegisters[$register] = true;
                $registers[] = ['register' => $register, 'value' => SchemaValue::integer($entry['value'] ?? null, 0, 255, $entryPath . '.value'),
                    'writable' => SchemaValue::boolean(array_key_exists('writable', $entry) ? $entry['writable'] : false, $entryPath . '.writable')];
            }
            if ($published && $registers === []) { throw new InvalidArgumentException($path . ': 공개 I2C 장치에는 초기 레지스터가 필요합니다.'); }
            usort($registers, static fn (array $left, array $right): int => $left['register'] <=> $right['register']);
            $result[] = ['address' => $address, 'label' => SchemaValue::string($item['label'] ?? ('I2C 0x' . strtoupper(dechex($address))), 160, $path . '.label'), 'registers' => $registers];
        }
        usort($result, static fn (array $left, array $right): int => $left['address'] <=> $right['address']);
        return $result;
    }

    public static function inputs(mixed $value, array $devices, int $duration, string $path): array
    {
        $result = []; $seen = []; $byAddress = array_column($devices, null, 'address');
        foreach (SchemaValue::list($value, 256, $path) as $index => $item) {
            $at = $path . '.' . $index; $item = SchemaValue::object($item, $at);
            $address = self::address($item['address'] ?? null, $byAddress, $at . '.address');
            $register = SchemaValue::integer($item['register'] ?? null, 0, 255, $at . '.register');
            if (!in_array($register, array_column($byAddress[$address]['registers'], 'register'), true)) { throw new InvalidArgumentException($at . ': 선언되지 않은 I2C 레지스터입니다.'); }
            $time = SchemaValue::integer($item['atMs'] ?? null, 0, $duration - 1, $at . '.atMs');
            $key = $time . ':' . $address . ':' . $register;
            if (isset($seen[$key])) { throw new InvalidArgumentException($at . ': 같은 시각에 같은 I2C 레지스터를 중복 설정할 수 없습니다.'); }
            $seen[$key] = true;
            $result[] = ['atMs' => $time, 'address' => $address, 'register' => $register, 'value' => SchemaValue::integer($item['value'] ?? null, 0, 255, $at . '.value')];
        }
        usort($result, static fn (array $left, array $right): int => [$left['atMs'], $left['address'], $left['register']] <=> [$right['atMs'], $right['address'], $right['register']]);
        return $result;
    }

    public static function assertions(mixed $value, array $devices, int $duration, string $path): array
    {
        $result = []; $byAddress = array_column($devices, null, 'address');
        foreach (SchemaValue::list($value, 256, $path) as $index => $item) {
            $at = $path . '.' . $index; $item = SchemaValue::object($item, $at);
            $address = self::address($item['address'] ?? null, $byAddress, $at . '.address');
            $operation = SchemaValue::oneOf($item['operation'] ?? null, ['read', 'write'], $at . '.operation');
            $data = self::bytes($item['data'] ?? null, $at . '.data');
            if ($operation === 'write') {
                self::writeData($byAddress[$address], $data, $at);
            }
            $after = SchemaValue::integer($item['afterMs'] ?? null, 0, $duration - 1, $at . '.afterMs');
            $within = SchemaValue::integer($item['withinMs'] ?? null, 1, $duration, $at . '.withinMs');
            if ($after + $within > $duration) { throw new InvalidArgumentException($at . ': I2C 응답 시간이 시나리오 종료를 넘습니다.'); }
            $result[] = ['address' => $address, 'operation' => $operation, 'data' => $data,
                'repeated' => SchemaValue::boolean($item['repeated'] ?? null, $at . '.repeated'), 'afterMs' => $after, 'withinMs' => $within];
        }
        // Array order is bus transaction order, including repeated identical writes.
        return $result;
    }

    public static function transaction(mixed $value, array $devices, int $duration, string $path): array
    {
        $item = SchemaValue::object($value, $path);
        if (($item['kind'] ?? null) !== 'i2c') { throw new InvalidArgumentException($path . ': I2C 트랜잭션 종류가 올바르지 않습니다.'); }
        $byAddress = array_column($devices, null, 'address');
        $address = self::address($item['address'] ?? null, $byAddress, $path . '.address');
        $operation = SchemaValue::oneOf($item['operation'] ?? null, ['read', 'write'], $path . '.operation');
        $data = self::bytes($item['data'] ?? null, $path . '.data');
        if ($operation === 'write') { self::writeData($byAddress[$address], $data, $path); }
        return [
            'atMs' => SchemaValue::integer($item['atMs'] ?? null, 0, $duration, $path . '.atMs'),
            'address' => $address, 'operation' => $operation, 'data' => $data,
            'repeated' => SchemaValue::boolean($item['repeated'] ?? null, $path . '.repeated'), 'kind' => 'i2c',
        ];
    }

    public static function bytes(mixed $value, string $path): array
    {
        $bytes = SchemaValue::list($value, 64, $path);
        if ($bytes === []) { throw new InvalidArgumentException($path . ': 1~64바이트가 필요합니다.'); }
        return array_map(static fn (mixed $byte): int => SchemaValue::integer($byte, 0, 255, $path), $bytes);
    }

    private static function address(mixed $value, array $devices, string $path): int
    {
        $address = SchemaValue::integer($value, 0, 127, $path);
        if (!isset($devices[$address])) { throw new InvalidArgumentException($path . ': 선언되지 않은 I2C 장치입니다.'); }
        return $address;
    }

    private static function writeData(array $device, array $data, string $path): void
    {
        $registers = array_column($device['registers'], null, 'register');
        $count = max(1, count($data) - 1);
        for ($offset = 0; $offset < $count; $offset++) {
            $target = ($data[0] + $offset) % 256;
            if (!isset($registers[$target])) { throw new InvalidArgumentException($path . ': I2C 쓰기가 선언되지 않은 레지스터를 가리킵니다.'); }
            if (count($data) > 1 && ($registers[$target]['writable'] ?? false) !== true) { throw new InvalidArgumentException($path . ': 읽기 전용 I2C 레지스터에 데이터를 쓸 수 없습니다.'); }
        }
    }
}

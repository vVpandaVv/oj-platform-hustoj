import { request, assertion } from './fixtures.mjs';

export function peripheralRequest(source, connections = [], devices = []) {
  const payload = request(source);
  payload.exercise.connections = structuredClone(connections);
  payload.exercise.i2cDevices = structuredClone(devices);
  Object.assign(payload.exercise.scenarios[0], { id: 'peripherals', name: '장치 입출력', durationMs: 1400, inputs: [], assertions: [], i2cInputs: [], i2cAssertions: [] });
  return payload;
}
export const servoConnection = { pin: 1, direction: 'output', signal: 'servo', device: 'servo', label: '서보' };
export function tx(operation, data, overrides = {}) {
  return { address: 32, operation, data, repeated: false, afterMs: 0, withinMs: 100, ...overrides };
}
export function device(registers = [{ register: 0, value: 30, writable: false }, { register: 1, value: 0, writable: true }], address = 32) {
  return { address, label: 'I²C 장치', registers };
}
export const combinedSource = `
basic.forever(function () {
  pins.i2cWriteNumber(32, 0, NumberFormat.UInt8BE, true)
  let angle = pins.i2cReadNumber(32, NumberFormat.UInt8BE, false)
  pins.servoWritePin(AnalogPin.P1, angle)
  pins.i2cWriteNumber(32, 256 + angle, NumberFormat.UInt16BE, false)
  basic.pause(500)
})
`;
export function combinedRequest() {
  const payload = peripheralRequest(combinedSource, [servoConnection], [device()]);
  const scenario = payload.exercise.scenarios[0];
  scenario.i2cInputs = [
    { atMs: 400, address: 32, register: 0, value: 90 },
    { atMs: 900, address: 32, register: 0, value: 150 },
  ];
  for (const [afterMs, angle] of [[0, 30], [500, 90], [1000, 150]]) {
    scenario.assertions.push(assertion({ signal: 'servo', expected: angle, afterMs, holdMs: 100 }));
    scenario.i2cAssertions.push(
      tx('write', [0], { repeated: true, afterMs }),
      tx('read', [angle], { afterMs }),
      tx('write', [1, angle], { afterMs }),
    );
  }
  return payload;
}

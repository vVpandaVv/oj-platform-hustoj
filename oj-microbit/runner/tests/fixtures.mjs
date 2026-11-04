export const connections = [
  { pin: 0, direction: 'input', signal: 'analog', device: 'light_sensor', label: '조도센서' },
  { pin: 1, direction: 'output', signal: 'digital', device: 'led', label: 'LED' },
  { pin: 2, direction: 'output', signal: 'pwm', device: 'motor', label: '모터' },
];
export function project(source) {
  return { name: '핀 실습', files: {
    'main.ts': source,
    'pxt.json': JSON.stringify({ name: '핀 실습', description: '', dependencies: { core: '*', microphone: '*', radio: '*' }, files: ['main.ts'], preferredEditor: 'tsprj' }),
  } };
}
export const source = `
basic.forever(function () {
  let value = pins.analogReadPin(AnalogPin.P0)
  pins.digitalWritePin(DigitalPin.P1, value >= 512 ? 1 : 0)
  pins.analogWritePin(AnalogPin.P2, value)
  basic.pause(20)
})
`;
export function assertion(overrides = {}) {
  return { pin: 1, signal: 'digital', expected: 1, afterMs: 300, withinMs: 100, holdMs: 100, tolerance: 0, ...overrides };
}
export function request(code = source) {
  return { project: project(code), exercise: { connections: structuredClone(connections), scenarios: [{
    id: 'threshold', name: '임계값 전환', visible: true, durationMs: 1000,
    inputs: [
      { atMs: 0, pin: 0, signal: 'analog', value: 0 },
      { atMs: 300, pin: 0, signal: 'analog', value: 800 },
      { atMs: 600, pin: 0, signal: 'analog', value: 100 },
    ],
    assertions: [
      assertion({ expected: 0, afterMs: 0 }), assertion(),
      assertion({ expected: 0, afterMs: 600 }),
      assertion({ pin: 2, signal: 'pwm', expected: 800, tolerance: 1 }),
    ],
  }] } };
}

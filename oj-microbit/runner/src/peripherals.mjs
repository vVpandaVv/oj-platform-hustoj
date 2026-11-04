/** Extensions to official PXT simulator shims for the declared device model. */
export const peripheralInstrumentation = `
  var servoWrite = pxsim.pins.servoWritePin, servoPulse = pxsim.pins.servoSetPulse, servoContinuous = pxsim.pins.servoSetContinuous;
  pxsim.pins.servoSetContinuous = (id, continuous) => {
    guardPin(id);
    var c = connections.get(pinNumber(id));
    if (c && c.direction === 'input') throw new Error('Cannot configure a servo on an input connection.');
    if (!pxsim.getPin(id)) throw new Error('Unknown servo output pin.');
    if (continuous) throw new Error('Continuous rotation servos require a separate device model.');
    // Selecting positional mode alone produces no angle or pulse output.
    servoContinuous(id, false);
  };
  pxsim.pins.servoWritePin = (id, value) => {
    guardPin(id);
    value = value | 0;
    if (value < 0 || value > 180) throw new Error('Servo angle is out of range.');
    servoWrite(id, value);
    capture(id, 'servo', pxsim.getPin(id)?.servoAngle);
  };
  pxsim.pins.servoSetPulse = (id, micros) => {
    guardPin(id);
    micros = micros | 0;
    if (micros < 1 || micros > 20000) throw new Error('Servo pulse width is out of range.');
    servoPulse(id, micros);
    var pin = pxsim.getPin(id);
    if (!pin) throw new Error('Unknown servo output pin.');
    // The official simulator leaves this shim unimplemented. Model the
    // documented 20ms period and pulse width explicitly, without fake angles.
    pin.mode = pxsim.PinFlags.Analog | pxsim.PinFlags.Output;
    pin.period = 20000;
    pin.servoPulse = micros;
    pin.value = Math.round(micros * 1023 / 20000);
    capture(id, 'servo_pulse', micros);
  };

  var i2cTrace = [];
  var i2cDevices = new Map((payload.i2cDevices || []).map(d => [d.address, {
    pointer: 0,
    registers: new Map(d.registers.map(r => [r.register, r.value])),
    writable: new Set(d.registers.filter(r => r.writable === true).map(r => r.register)),
  }]));
  function i2cDevice(address) {
    var device = i2cDevices.get(address);
    if (!Number.isInteger(address) || address < 0 || address > 127 || !device) throw new Error('I2C address is not declared in this exercise.');
    return device;
  }
  function i2cRegister(device, register) {
    if (!device.registers.has(register)) throw new Error('I2C register is not declared in this exercise.');
  }
  function i2cRecord(address, operation, data, repeated) {
    if (i2cTrace.length >= 50000) throw new Error('I2C transaction limit exceeded.');
    i2cTrace.push({ atMs: clock.now, address, operation, data: Array.from(data), repeated: !!repeated, kind: 'i2c' });
  }
  pxsim.pins.i2cReadBuffer = (address, size, repeated) => {
    var device = i2cDevice(address);
    if (!Number.isInteger(size) || size < 1 || size > 64) throw new Error('I2C transfer size must be 1..64 bytes.');
    var data = [];
    for (var offset = 0; offset < size; offset++) {
      var register = (device.pointer + offset) & 255;
      i2cRegister(device, register);
      data.push(device.registers.get(register));
    }
    var buffer = pxsim.BufferMethods.createBuffer(size);
    buffer.data.set(data);
    device.pointer = (device.pointer + size) & 255;
    i2cRecord(address, 'read', data, repeated);
    return buffer;
  };
  pxsim.pins.i2cWriteBuffer = (address, buffer, repeated) => {
    var device = i2cDevice(address);
    if (!(buffer instanceof pxsim.RefBuffer) || buffer.data.length < 1 || buffer.data.length > 64) throw new Error('I2C transfer size must be 1..64 bytes.');
    var data = Array.from(buffer.data), register = data[0];
    i2cRegister(device, register);
    // Validate the entire transfer before mutating any registers.
    for (var offset = 1; offset < data.length; offset++) {
      var target = (register + offset - 1) & 255;
      i2cRegister(device, target);
      if (!device.writable.has(target)) throw new Error('I2C sensor register is read-only.');
    }
    for (var offset = 1; offset < data.length; offset++) device.registers.set((register + offset - 1) & 255, data[offset]);
    device.pointer = data.length === 1 ? register : (register + data.length - 1) & 255;
    i2cRecord(address, 'write', data, repeated);
  };
  for (var event of payload.i2cInputs || []) {
    var device = i2cDevice(event.address);
    i2cRegister(device, event.register);
    if (event.atMs === 0) device.registers.set(event.register, event.value);
    else {
      ((e) => { var id = clock.next++; clock.timers.set(id, { id, at: e.atMs, fn: () => i2cDevices.get(e.address).registers.set(e.register, e.value), args: [] }); })(event);
    }
  }
`;

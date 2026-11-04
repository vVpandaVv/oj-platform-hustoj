import { createUSBConnection } from '@microbit/microbit-connection/usb';
import { createUniversalHexFlashDataSource } from '@microbit/microbit-connection/universal-hex';

// A thin bridge keeps device access in the top-level student page, where the
// user's button click grants WebUSB permission. The MakeCode iframe compiles.
export function createConnection() {
  const connection = createUSBConnection();
  return {
    connect: () => connection.connect(),
    disconnect: () => connection.disconnect(),
    get status() { return connection.status; },
    flash: (hex, progress) => connection.flash(createUniversalHexFlashDataSource(hex), {
      partial: true,
      progress: (stage, percentage) => progress && progress(stage, percentage),
    }),
  };
}

(() => {
  "use strict";

  const types = Object.freeze({
    submit: "oj:blockly-submit",
    resize: "oj:blockly-resize",
  });

  const createMessage = (type, detail = {}) => Object.assign({ type }, detail);
  const isMessage = (data, type) => Boolean(data && data.type === type);

  window.OJBlocklyContract = Object.freeze({
    types,
    createMessage,
    isMessage,
  });
})();

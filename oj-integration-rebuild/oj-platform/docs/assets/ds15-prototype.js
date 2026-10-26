(() => {
  const providerDefinitions = {
    ollama: {
      label: "Ollama · 자체 AI 서버",
      kind: "자체 서버",
      baseUrl: "https://ai.example.com/ollama",
      authMode: "bearer",
      allowedAuthModes: ["bearer", "x-api-key"],
      lockBaseUrl: false,
      model: "gemma4:latest",
      secretPlaceholder: "Reverse Proxy API 키",
      modelsEndpoint: "GET /api/tags",
      chatEndpoint: "POST /api/chat",
      authDescription: "Reverse Proxy Bearer",
      structuredOutput: "Ollama format JSON Schema",
      summary: "별도 AI 서버의 Ollama를 Reverse Proxy 뒤에서 사용합니다.",
      networkNote: "Ollama는 별도 AI 서버 안에서만 실행하고 HTTPS·Bearer 인증 Reverse Proxy를 통해 연결합니다. Judge 서버는 AI 요청을 전달하지 않습니다.",
      sourceNote: "자체 AI 서버라도 명시적으로 승인한 연결에서만 제출 코드를 보냅니다.",
      testLabel: "Ollama 검사",
      serverExample: `AI 서버
ollama pull gemma4
OLLAMA_HOST=127.0.0.1:11434 ollama serve

Reverse Proxy
https://ai.example.com/ollama -> http://127.0.0.1:11434`,
      requestExample: `curl https://ai.example.com/ollama/api/chat \\
  -H "Authorization: Bearer <token>" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"gemma4:latest","messages":[{"role":"user","content":"연결 확인"}],"stream":false,"think":false}'`,
      note: "Ollama 포트를 외부에 직접 공개하지 않습니다. AI 서버의 Reverse Proxy만 허용하고 Judge 서버 주소는 사용할 수 없습니다."
    },
    mlx_lm: {
      label: "MLX-LM · Apple Silicon",
      kind: "자체 서버",
      baseUrl: "https://ai.example.com/mlx-lm",
      authMode: "bearer",
      allowedAuthModes: ["bearer", "x-api-key"],
      lockBaseUrl: false,
      model: "mlx-community/your-instruct-model",
      secretPlaceholder: "Reverse Proxy API 키",
      modelsEndpoint: "GET /v1/models",
      chatEndpoint: "POST /v1/chat/completions",
      authDescription: "Reverse Proxy Bearer",
      structuredOutput: "JSON 지시 후 서버 응답 스키마 검증",
      summary: "Apple Silicon AI 서버의 MLX-LM OpenAI 호환 API를 사용합니다.",
      networkNote: "MLX-LM 공식 서버는 기본 보안 검사만 제공하므로 외부에 직접 공개하지 않습니다. AI 서버 내부에서 실행하고 인증·TLS Reverse Proxy를 반드시 둡니다.",
      sourceNote: "격리된 자체 MLX-LM 서버와 검증된 모델에서만 제출 코드 전송을 승인할 수 있습니다.",
      testLabel: "MLX-LM 검사",
      serverExample: `AI 서버
mlx_lm.server --model mlx-community/your-instruct-model

Reverse Proxy
https://ai.example.com/mlx-lm -> http://127.0.0.1:8080`,
      requestExample: `curl https://ai.example.com/mlx-lm/v1/chat/completions \\
  -H "Authorization: Bearer <token>" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"mlx-community/your-instruct-model","messages":[{"role":"user","content":"연결 확인"}],"stream":false}'`,
      note: "관리 화면은 MLX-LM 프로세스와 모델을 설치·시작하지 않습니다. 운영자는 AI 서버에서 실행하고 Reverse Proxy 상태를 별도로 관리합니다."
    },
    vllm: {
      label: "vLLM · GPU 서버",
      kind: "자체 서버",
      baseUrl: "https://ai.example.com/vllm",
      authMode: "bearer",
      allowedAuthModes: ["bearer"],
      lockBaseUrl: false,
      model: "learning-model",
      secretPlaceholder: "vLLM 또는 Reverse Proxy API 키",
      modelsEndpoint: "GET /v1/models",
      chatEndpoint: "POST /v1/chat/completions",
      authDescription: "Bearer API 키",
      structuredOutput: "response_format 지원 여부 후보 검사",
      summary: "별도 GPU 서버의 vLLM OpenAI 호환 API를 사용합니다.",
      networkNote: "vLLM은 Judge 서버와 분리된 GPU 서버에서 실행합니다. 외부 경로는 HTTPS만 허용하고 API 키와 Chat Template을 후보 검사에서 확인합니다.",
      sourceNote: "승인된 자체 vLLM 서버와 모델에 한해 제출 코드 전송을 별도로 승인합니다.",
      testLabel: "vLLM 검사",
      serverExample: `AI 서버
vllm serve <model> \\
  --api-key <token> \\
  --served-model-name learning-model

Reverse Proxy
https://ai.example.com/vllm -> http://127.0.0.1:8000`,
      requestExample: `curl https://ai.example.com/vllm/v1/chat/completions \\
  -H "Authorization: Bearer <token>" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"learning-model","messages":[{"role":"user","content":"연결 확인"}],"stream":false}'`,
      note: "Chat Template이 없는 모델은 Chat Completions를 처리할 수 없습니다. 모델 목록뿐 아니라 실제 채팅과 구조화 결과까지 검사해야 합니다."
    },
    sglang: {
      label: "SGLang · GPU 서버",
      kind: "자체 서버",
      baseUrl: "https://ai.example.com/sglang",
      authMode: "bearer",
      allowedAuthModes: ["bearer"],
      lockBaseUrl: false,
      model: "learning-model",
      secretPlaceholder: "SGLang 또는 Reverse Proxy API 키",
      modelsEndpoint: "GET /v1/models",
      chatEndpoint: "POST /v1/chat/completions",
      authDescription: "Bearer API 키",
      structuredOutput: "response_format 지원 여부 후보 검사",
      summary: "별도 GPU 서버의 SGLang OpenAI 호환 API를 사용합니다.",
      networkNote: "SGLang은 Judge 서버와 분리된 GPU 서버에서 실행하며 HTTPS와 API 키를 강제합니다. 관리자용 제어 endpoint는 플랫폼에서 호출하지 않습니다.",
      sourceNote: "승인된 자체 SGLang 서버와 served model에 한해 제출 코드 전송을 별도로 승인합니다.",
      testLabel: "SGLang 검사",
      serverExample: `AI 서버
python -m sglang.launch_server \\
  --model-path <model> \\
  --host 127.0.0.1 --port 30000 \\
  --api-key <token> \\
  --served-model-name learning-model

Reverse Proxy
https://ai.example.com/sglang -> http://127.0.0.1:30000`,
      requestExample: `curl https://ai.example.com/sglang/v1/chat/completions \\
  -H "Authorization: Bearer <token>" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"learning-model","messages":[{"role":"user","content":"연결 확인"}],"stream":false}'`,
      note: "가중치 변경·캐시 초기화·프로파일링 같은 관리 API는 허용 목록에 넣지 않습니다. 플랫폼은 모델 목록과 Chat Completions만 사용합니다."
    },
    gemini: {
      label: "Gemini API",
      kind: "외부 API",
      baseUrl: "https://generativelanguage.googleapis.com/v1beta",
      authMode: "google-api-key",
      allowedAuthModes: ["google-api-key"],
      lockBaseUrl: true,
      model: "gemini-3.6-flash",
      secretPlaceholder: "Gemini API 키",
      modelsEndpoint: "GET /v1beta/models",
      chatEndpoint: "POST /v1beta/models/{model}:generateContent",
      authDescription: "x-goog-api-key",
      structuredOutput: "responseMimeType + responseJsonSchema",
      summary: "Google Gemini 네이티브 generateContent API를 사용합니다.",
      networkNote: "Gemini 요청은 WordPress Jobs Worker가 Google 공개 HTTPS API로 직접 보냅니다. Judge 서버와 자체 AI 서버는 경유하지 않습니다.",
      sourceNote: "외부 API이므로 제출 코드 전송은 기본 차단하며 별도의 운영 승인을 받아야 합니다.",
      testLabel: "Gemini 검사",
      serverExample: `외부 서비스
별도 추론 서버 실행 없음

고정 Base URL
https://generativelanguage.googleapis.com/v1beta`,
      requestExample: `curl "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent" \\
  -H "x-goog-api-key: <api-key>" \\
  -H "Content-Type: application/json" \\
  -d '{"contents":[{"parts":[{"text":"연결 확인"}]}],"generationConfig":{"responseMimeType":"application/json","responseJsonSchema":{"type":"object"}}}'`,
      note: "모델 목록에서 generateContent 지원 여부를 확인합니다. API 키는 Secret Vault에만 저장하고 URL 쿼리 문자열에는 넣지 않습니다."
    },
    openai: {
      label: "OpenAI API",
      kind: "외부 API",
      baseUrl: "https://api.openai.com",
      authMode: "bearer",
      allowedAuthModes: ["bearer"],
      lockBaseUrl: true,
      model: "gpt-5-mini",
      secretPlaceholder: "OpenAI API 키",
      modelsEndpoint: "GET /v1/models",
      chatEndpoint: "POST /v1/chat/completions",
      authDescription: "Bearer API 키",
      structuredOutput: "response_format + 최종 스키마 검증",
      summary: "OpenAI 공개 HTTPS API를 사용합니다.",
      networkNote: "OpenAI 요청은 WordPress Jobs Worker가 공개 HTTPS API로 직접 보냅니다. Judge 서버는 경유하지 않습니다.",
      sourceNote: "외부 API이므로 제출 코드 전송은 기본 차단하며 별도의 운영 승인을 받아야 합니다.",
      testLabel: "OpenAI 검사",
      serverExample: `외부 서비스
별도 추론 서버 실행 없음

고정 Base URL
https://api.openai.com`,
      requestExample: `curl https://api.openai.com/v1/chat/completions \\
  -H "Authorization: Bearer <api-key>" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"gpt-5-mini","messages":[{"role":"user","content":"연결 확인"}],"stream":false}'`,
      note: "공개 HTTPS와 승인된 OpenAI 호스트만 허용합니다. API 키와 응답 원문은 운영 로그에 남기지 않습니다."
    },
    openai_compatible_gateway: {
      label: "OpenAI 호환 AI Gateway",
      kind: "별도 Gateway",
      baseUrl: "https://ai.example.com/gateway",
      authMode: "bearer",
      allowedAuthModes: ["bearer", "x-api-key"],
      lockBaseUrl: false,
      model: "registered-model-key",
      secretPlaceholder: "Gateway API 키",
      modelsEndpoint: "GET /v1/models",
      chatEndpoint: "POST /v1/chat/completions",
      authDescription: "Bearer 또는 X-API-Key",
      structuredOutput: "Gateway 계약 + 최종 스키마 검증",
      summary: "고정된 OpenAI Chat Completions 계약의 별도 Gateway를 사용합니다.",
      networkNote: "Gateway는 Judge 서버와 다른 주소여야 하며 고정 HTTPS 경로와 허용된 인증 헤더만 사용할 수 있습니다.",
      sourceNote: "Gateway의 운영 주체와 저장 정책을 확인한 뒤 제출 코드 전송을 별도로 승인합니다.",
      testLabel: "Gateway 검사",
      serverExample: `필수 Gateway 계약
GET  /v1/models
POST /v1/chat/completions

허용 인증
Authorization: Bearer 또는 X-API-Key`,
      requestExample: `curl https://ai.example.com/gateway/v1/chat/completions \\
  -H "Authorization: Bearer <token>" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"registered-model-key","messages":[{"role":"user","content":"연결 확인"}],"stream":false}'`,
      note: "사용자 정의 endpoint·임의 헤더·쿠키·리디렉션은 허용하지 않습니다. Gateway 응답은 공통 스키마로 다시 검증합니다."
    }
  };

  const savedConnections = {
    "ollama-gemma4": {
      name: "Ollama Gemma 4",
      providerKey: "ollama",
      baseUrl: "https://ai.example.com/ollama",
      model: "gemma4:latest",
      sourceApproved: true,
      allowedTaskCount: 4,
      testedAt: "07-25 14:32"
    },
    "vllm-code": {
      name: "vLLM 코드 모델",
      providerKey: "vllm",
      baseUrl: "https://ai-gpu.example.com/vllm",
      model: "learning-code-model",
      sourceApproved: true,
      allowedTaskCount: 4,
      testedAt: "07-25 14:40"
    },
    "gemini-api": {
      name: "Gemini API",
      providerKey: "gemini",
      baseUrl: "https://generativelanguage.googleapis.com/v1beta",
      model: "gemini-3.6-flash",
      sourceApproved: false,
      allowedTaskCount: 3,
      testedAt: "07-25 14:51"
    }
  };

  const form = document.querySelector("[data-ai-provider-form]");
  if (!form) {
    return;
  }

  const providerSelect = form.querySelector("[data-ai-provider-select]");
  const sourceSwitch = form.querySelector("[data-ai-source-switch]");
  const profileButtons = Array.from(document.querySelectorAll("[data-ai-profile-select]"));
  const fields = {
    profileName: form.querySelector("[data-ai-profile-name]"),
    profileKey: form.querySelector("[data-ai-profile-key]"),
    baseUrl: form.querySelector('[data-ai-field="base-url"]'),
    authMode: form.querySelector('[data-ai-field="auth-mode"]'),
    model: form.querySelector('[data-ai-field="model"]'),
    secret: form.querySelector('[data-ai-field="secret"]')
  };

  const output = {
    networkNote: form.querySelector("[data-ai-network-note]"),
    sourceNote: form.querySelector("[data-ai-source-note]"),
    sourceLabel: form.querySelector("[data-ai-source-label]"),
    profileState: document.querySelector("[data-ai-profile-state]"),
    stateProfile: document.querySelector("[data-ai-state-profile]"),
    stateProvider: document.querySelector("[data-ai-state-provider]"),
    stateModel: document.querySelector("[data-ai-state-model]"),
    stateTasks: document.querySelector("[data-ai-state-tasks]"),
    stateModels: document.querySelector("[data-ai-state-models]"),
    stateChat: document.querySelector("[data-ai-state-chat]"),
    testState: document.querySelector("[data-ai-test-state]"),
    testDetail: document.querySelector("[data-ai-test-detail]"),
    testLabel: document.querySelector("[data-ai-test-label]"),
    exampleTitle: document.querySelector("[data-ai-example-title]"),
    exampleSummary: document.querySelector("[data-ai-example-summary]"),
    exampleKind: document.querySelector("[data-ai-example-kind]"),
    exampleModels: document.querySelector("[data-ai-example-models]"),
    exampleChat: document.querySelector("[data-ai-example-chat]"),
    exampleAuth: document.querySelector("[data-ai-example-auth]"),
    exampleStructured: document.querySelector("[data-ai-example-structured]"),
    exampleServer: document.querySelector("[data-ai-example-server]"),
    exampleRequest: document.querySelector("[data-ai-example-request]"),
    exampleNote: document.querySelector("[data-ai-example-note]")
  };

  const setText = (element, value) => {
    if (element) {
      element.textContent = value;
    }
  };

  const resetCandidate = () => {
    setText(output.testState, "설정 저장 전 연결 검사가 필요합니다.");
    setText(output.testDetail, "모델·인증·한국어·코드·구조화 응답을 고정 시험 자료로 확인합니다.");
    setText(output.profileState, "검사 필요");
    setText(output.stateTasks, "후보 검사 필요");
    output.profileState?.classList.remove("good");
    output.profileState?.classList.add("wait");
    output.testState?.classList.add("pending");
  };

  const applyProviderDefinition = (providerKey) => {
    const provider = providerDefinitions[providerKey] || providerDefinitions.ollama;

    fields.baseUrl.value = provider.baseUrl;
    fields.baseUrl.readOnly = provider.lockBaseUrl;
    Array.from(fields.authMode.options || []).forEach((option) => {
      const allowed = provider.allowedAuthModes.includes(option.value);
      option.disabled = !allowed;
      option.hidden = !allowed;
    });
    fields.authMode.value = provider.authMode;
    fields.model.value = provider.model;
    fields.secret.value = "";
    fields.secret.disabled = false;
    fields.secret.placeholder = provider.secretPlaceholder;
    sourceSwitch.checked = false;

    setText(output.networkNote, provider.networkNote);
    setText(output.sourceNote, provider.sourceNote);
    setText(output.sourceLabel, "승인 필요");
    setText(output.stateProvider, provider.label);
    setText(output.stateModel, provider.model);
    setText(output.stateModels, provider.modelsEndpoint);
    setText(output.stateChat, provider.chatEndpoint);
    setText(output.testLabel, provider.testLabel);
    setText(output.exampleTitle, `${provider.label} 연결 예시`);
    setText(output.exampleSummary, provider.summary);
    setText(output.exampleKind, provider.kind);
    setText(output.exampleModels, provider.modelsEndpoint);
    setText(output.exampleChat, provider.chatEndpoint);
    setText(output.exampleAuth, provider.authDescription);
    setText(output.exampleStructured, provider.structuredOutput);
    setText(output.exampleServer, provider.serverExample);
    setText(output.exampleRequest, provider.requestExample);
    setText(output.exampleNote, provider.note);
    resetCandidate();
  };

  const applySavedConnection = (connectionKey) => {
    const connection = savedConnections[connectionKey];
    if (!connection) {
      return;
    }

    providerSelect.value = connection.providerKey;
    applyProviderDefinition(connection.providerKey);
    fields.profileName.value = connection.name;
    fields.profileKey.value = connectionKey;
    fields.baseUrl.value = connection.baseUrl;
    fields.model.value = connection.model;
    sourceSwitch.checked = connection.sourceApproved;

    setText(output.sourceLabel, connection.sourceApproved ? "명시적 허용" : "승인 필요");
    setText(output.stateProfile, connection.name);
    setText(output.stateModel, connection.model);
    setText(output.stateTasks, `${connection.allowedTaskCount}개`);
    setText(output.profileState, "사용 가능");
    setText(output.testState, `${connection.testedAt} · 검사 완료`);
    setText(output.testDetail, "모델·인증·한국어·코드·구조화 응답이 등록 fingerprint와 일치합니다.");
    output.profileState?.classList.remove("wait");
    output.profileState?.classList.add("good");
    output.testState?.classList.remove("pending");

    profileButtons.forEach((button) => {
      const active = button.dataset.aiProfileSelect === connectionKey;
      button.classList.toggle("active", active);
      button.setAttribute("aria-pressed", String(active));
    });
  };

  providerSelect.addEventListener("change", () => {
    applyProviderDefinition(providerSelect.value);
  });

  fields.profileName.addEventListener("input", () => {
    setText(output.stateProfile, fields.profileName.value || "이름 입력 필요");
    resetCandidate();
  });

  fields.model.addEventListener("input", () => {
    setText(output.stateModel, fields.model.value || "모델 입력 필요");
    resetCandidate();
  });

  [fields.baseUrl, fields.authMode, fields.secret].forEach((field) => {
    field.addEventListener("input", resetCandidate);
    field.addEventListener("change", resetCandidate);
  });

  sourceSwitch.addEventListener("change", () => {
    setText(output.sourceLabel, sourceSwitch.checked ? "명시적 허용" : "승인 필요");
    resetCandidate();
  });

  profileButtons.forEach((button) => {
    button.addEventListener("click", () => {
      applySavedConnection(button.dataset.aiProfileSelect);
    });
  });

  applySavedConnection("ollama-gemma4");
})();

(() => {
  const form = document.querySelector("[data-ai-routing-form]");
  if (!form) {
    return;
  }

  const profileCapabilities = {
    "ollama-gemma4": {
      local: true,
      sourceApproved: true,
      tasks: ["problem_metadata", "problem_draft", "testcase_draft", "submission_feedback"]
    },
    "vllm-code": {
      local: true,
      sourceApproved: true,
      tasks: ["problem_metadata", "problem_draft", "testcase_draft", "submission_feedback"]
    },
    "gemini-api": {
      local: false,
      sourceApproved: false,
      tasks: ["problem_metadata", "problem_draft", "testcase_draft"]
    }
  };

  const rows = Array.from(form.querySelectorAll("[data-ai-routing-row]"));
  const notice = form.querySelector("[data-ai-routing-notice]");
  const message = form.querySelector("[data-ai-routing-message]");
  const summary = form.querySelector("[data-ai-routing-summary]");
  const saveButton = form.querySelector("[data-ai-routing-save]");
  const changedTasks = new Set();

  const setRowStatus = (row, valid, label) => {
    const status = row.querySelector("[data-routing-status]");
    status.classList.remove("good", "risk");
    status.classList.add(valid ? "good" : "risk");
    status.textContent = label;
  };

  const validateRow = (row) => {
    const taskType = row.dataset.taskType;
    const primaryKey = row.querySelector("[data-routing-primary]").value;
    const fallbackKey = row.querySelector("[data-routing-fallback]").value;
    const primary = profileCapabilities[primaryKey];
    const fallback = fallbackKey ? profileCapabilities[fallbackKey] : null;

    if (!primary || !primary.tasks.includes(taskType)) {
      return "주 프로필 작업 미허용";
    }
    if (fallbackKey && fallbackKey === primaryKey) {
      return "대체 프로필 중복";
    }
    if (fallback && !fallback.tasks.includes(taskType)) {
      return "대체 프로필 작업 미허용";
    }
    if (taskType === "submission_feedback") {
      if (!primary.sourceApproved) {
        return "제출 코드 승인 필요";
      }
      if (fallback && !fallback.sourceApproved) {
        return "대체 프로필 제출 코드 승인 필요";
      }
    }

    return "";
  };

  const validateAll = () => {
    const errors = [];

    rows.forEach((row) => {
      const error = validateRow(row);
      setRowStatus(row, !error, error || "준비");
      if (error) {
        errors.push(error);
      }
    });

    saveButton.disabled = errors.length > 0;
    notice.classList.toggle("danger", errors.length > 0);
    message.textContent = errors.length > 0
      ? `${errors.length}개 배정을 저장할 수 없습니다. 표시된 프로필과 전송 승인을 확인하세요.`
      : "4개 작업 배정이 모두 유효합니다. 저장할 때 프로필 fingerprint와 작업 허용 범위를 다시 검사합니다.";
    summary.textContent = changedTasks.size > 0 ? `저장할 변경 ${changedTasks.size}개` : "변경 없음";
  };

  rows.forEach((row) => {
    row.querySelectorAll("select").forEach((select) => {
      select.addEventListener("change", () => {
        changedTasks.add(row.dataset.taskType);
        validateAll();
      });
    });
  });

  validateAll();
})();

(() => {
  const form = document.querySelector("[data-custom-link-form]");
  if (!form) {
    return;
  }

  const fields = {
    level: form.querySelector("[data-custom-link-level]"),
    label: form.querySelector("[data-custom-link-label]"),
    url: form.querySelector("[data-custom-link-url]"),
    parent: form.querySelector("[data-custom-link-parent]"),
    scope: form.querySelector("[data-custom-link-scope]")
  };
  const validateButton = form.querySelector("[data-custom-link-validate]");
  const output = {
    badge: form.querySelector("[data-custom-link-validation-badge]"),
    text: form.querySelector("[data-custom-link-validation-text]"),
    previewRow: document.querySelector("[data-custom-link-preview]"),
    previewIndex: document.querySelector("[data-custom-link-preview-index]"),
    previewLevel: document.querySelector("[data-custom-link-preview-level]"),
    previewLabel: document.querySelector("[data-custom-link-preview-label]"),
    previewParent: document.querySelector("[data-custom-link-preview-parent]"),
    previewDomain: document.querySelector("[data-custom-link-preview-domain]"),
    previewUrl: document.querySelector("[data-custom-link-preview-url]"),
    previewScope: document.querySelector("[data-custom-link-preview-scope]"),
    previewStatus: document.querySelector("[data-custom-link-preview-status]"),
    primaryList: document.querySelector("[data-primary-menu-list]"),
    secondaryList: document.querySelector("[data-secondary-menu-list]")
  };

  const setValidationState = (state, message) => {
    output.badge.classList.remove("good", "wait", "risk");
    output.badge.classList.add(state);
    output.badge.textContent = state === "good" ? "검사 완료" : state === "risk" ? "저장 불가" : "검사 필요";
    output.text.textContent = message;
  };

  const hasControlCharacter = (value) => Array.from(value).some((character) => {
    const code = character.charCodeAt(0);
    return code < 32 || code === 127;
  });

  const validateExternalLink = () => {
    const label = fields.label.value.trim();
    const rawUrl = fields.url.value.trim();

    if (label.length < 2 || label.length > 30 || hasControlCharacter(label)) {
      throw new Error("표시 이름은 제어 문자 없이 2~30자로 입력해야 합니다.");
    }
    if (rawUrl.length === 0 || rawUrl.length > 2048 || hasControlCharacter(rawUrl)) {
      throw new Error("외부 주소는 제어 문자 없이 1~2048자로 입력해야 합니다.");
    }

    const parsedUrl = new URL(rawUrl);
    if (parsedUrl.protocol !== "https:" || !parsedUrl.hostname) {
      throw new Error("호스트가 있는 https:// 주소만 사용할 수 있습니다.");
    }
    if (parsedUrl.username || parsedUrl.password) {
      throw new Error("사용자 이름이나 비밀번호가 포함된 주소는 사용할 수 없습니다.");
    }

    return {
      level: fields.level.value,
      label,
      url: parsedUrl.href,
      hostname: parsedUrl.hostname,
      parent: fields.level.value === "1차 메뉴" ? "없음" : fields.parent.value,
      scope: fields.scope.value
    };
  };

  const syncLevelFields = () => {
    fields.parent.disabled = fields.level.value === "1차 메뉴";
  };

  const resetValidation = () => {
    syncLevelFields();
    setValidationState("wait", "입력값이 변경되었습니다. 저장 전에 다시 검사합니다.");
  };

  [fields.level, fields.label, fields.url, fields.parent, fields.scope].forEach((field) => {
    field.addEventListener("input", resetValidation);
    field.addEventListener("change", resetValidation);
  });

  syncLevelFields();

  validateButton.addEventListener("click", () => {
    try {
      const link = validateExternalLink();
      const isPrimary = link.level === "1차 메뉴";
      (isPrimary ? output.primaryList : output.secondaryList).appendChild(output.previewRow);
      output.previewIndex.textContent = isPrimary ? "7" : "4";
      output.previewLevel.textContent = isPrimary ? "1차" : "2차";
      output.previewLabel.textContent = link.label;
      output.previewParent.textContent = link.parent;
      output.previewDomain.textContent = link.hostname;
      output.previewUrl.textContent = link.url;
      output.previewScope.textContent = link.scope;
      output.previewStatus.textContent = "외부";
      setValidationState("good", "URL 구조를 확인했습니다. 메뉴 저장 시 서버가 같은 규칙으로 다시 검사합니다.");
    } catch (error) {
      output.previewStatus.textContent = "확인 필요";
      setValidationState("risk", error instanceof Error ? error.message : "외부 주소를 확인할 수 없습니다.");
    }
  });
})();

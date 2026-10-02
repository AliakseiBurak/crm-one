/**
 * Клиент провайдера языковой модели для вкладки импорта (change
 * add-organizations-json-import, design D4).
 *
 * Запрос уходит из браузера прямо провайдеру: приложение не получает ни ключа,
 * ни промпта, ни ответа. Класс у клиента один, а различаются только адрес,
 * заголовки, форма запроса и путь к ответу: у OpenRouter это
 * OpenAI-совместимый маршрут под /api/v1, у Ollama — родной /api/chat, и
 * спрашивать их одинаково было бы версией маршрута, которой у хоста может не
 * оказаться.
 *
 * Ключ живёт в переменной и в `sessionStorage`: не в `localStorage`, потому что
 * переживать закрытие вкладки он не должен, и не в поле формы, потому что поле
 * формы сервер прочитает.
 */

const PRESETS = {
  openrouter: {
    // OpenRouter говорит по OpenAI-совместимому маршруту под /api/v1: и чат, и
    // список моделей живут под одним префиксом.
    baseUrl: 'https://openrouter.ai/api/v1',
    chatPath: '/chat/completions',
    modelsUrl: () => 'https://openrouter.ai/api/v1/models',
    headers: (key) => ({
      Authorization: `Bearer ${key}`,
      // OpenRouter атрибутирует приложение по этим заголовкам.
      'HTTP-Referer': window.location.origin,
      'X-OpenRouter-Title': 'B2B Call CRM',
    }),
    // Формат ответа задаётся через `response_format` со схемой.
    buildRequest: (model, messages, schema) => ({
      model,
      messages,
      temperature: 0,
      ...(schema
        ? {
            response_format: {
              type: 'json_schema',
              json_schema: { name: 'organizations', strict: false, schema },
            },
          }
        : {}),
    }),
    readContent: (data) => data?.choices?.[0]?.message?.content ?? '',
  },
  ollama: {
    // У Ollama родной API, а не OpenAI-совместимый слой: маршрута `/v1` на хосте
    // нет, и на `POST /v1/chat/completions` приходит «no such endpoint». Поэтому
    // здесь собственная форма запроса и ответа, а список моделей — `GET /api/tags`.
    host: 'http://localhost:11434',
    baseUrl: 'http://localhost:11434',
    chatPath: '/api/chat',
    modelsUrl: (host) => `${host}/api/tags`,
    // Ключ Ollama не требует: адрес открыт локально. Заголовок не мешает.
    headers: () => ({}),
    // Формат ответа задаётся полем `format`, куда кладётся сама схема.
    buildRequest: (model, messages, schema) => ({
      model,
      messages,
      stream: false,
      options: { temperature: 0 },
      ...(schema ? { format: schema } : {}),
    }),
    readContent: (data) => data?.message?.content ?? '',
  },
};

const FAILED_REQUEST_NOTE =
  'Что-то пошло не так. Запрос и ответ можно посмотреть в консоли браузера.';

/**
 * Имя файла, который скачивает администратор.
 *
* С датой и временем: ответов за день может быть много, а два файла с именем
 * `import.json` в одной папке не различить — и на вкладке JSON один будет
 * выглядеть как другой.
 *
 * Только латиница и цифры: браузер выбрасывает не-ASCII из имени файла и
 * сохраняет вложение как `download`, унося с собой дату и время.
 */
function responseFilename() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');

  return `import-${pad(now.getDate())}.${pad(now.getMonth() + 1)}.${now.getFullYear()}`
    + `-${pad(now.getHours())}-${pad(now.getMinutes())}-${pad(now.getSeconds())}.json`;
}

/**
 * Диалог браузера «уйти со страницы?», пока ответ не пришёл.
 *
 * Запрос к модели идёт из браузера и длится минутами: закрыл вкладку или
 * перешёл по ссылке — ответ потерян, а модель всё это время работала впустую.
 * Стандартный диалог снимается вместе с окончанием запроса, успешным или нет,
 * и до перехода на вкладку JSON — иначе он помешал бы собственному переходу.
 */
function warnBeforeLeave(event) {
  event.preventDefault();
  event.returnValue = '';
}

function guardLeaving(on) {
  if (on) {
    window.addEventListener('beforeunload', warnBeforeLeave);
    return;
  }

  window.removeEventListener('beforeunload', warnBeforeLeave);
}

export class LlmClient {
  constructor({ baseUrl, chatPath, modelsUrl, headers, buildRequest, readContent, model }) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.chatPath = chatPath;
    this.modelsUrl = modelsUrl;
    this.headers = headers;
    this.buildRequest = buildRequest;
    this.readContent = readContent;
    this.model = model;
  }

  /**
   * Список моделей провайдера.
   *
   * Адрес приходит целиком и не собирается из `baseUrl`: у OpenRouter он лежит
   * под префиксом /api/v1 вместе с чатом, а у Ollama — в корне хоста.
   */
  async listModels() {
    const response = await fetch(this.modelsUrl, {
      headers: { ...this.headers(), Accept: 'application/json' },
    });

    return await readJson(response);
  }

  /**
   * Запрос к модели.
   *
   * Тело запроса и разбор ответа — провайдерские: у OpenRouter это
   * OpenAI-совместимая форма с `response_format`, у Ollama — родная, с `format`
   * и без потоковой выдачи. Схема уходит провайдеру в любом случае, поэтому
   * ответ приходит нужной формы, а разбирать текст постфактум не приходится.
   */
  async send(messages, schema) {
    const response = await fetch(`${this.baseUrl}${this.chatPath}`, {
      method: 'POST',
      headers: { ...this.headers(), 'Content-Type': 'application/json' },
      body: JSON.stringify(this.buildRequest(this.model, messages, schema)),
    });

    const data = await readJson(response);

    return this.readContent(data);
  }
}

/**
 * Ключ вкладки: переменная плюс `sessionStorage`, чтобы пережил перерисовку
 * формы, но не пережил закрытие вкладки.
 */
export class SessionKey {
  constructor(storageKey = 'b2b.llm.apiKey') {
    this.storageKey = storageKey;
    this.value = null;

    try {
      this.value = window.sessionStorage.getItem(this.storageKey);
    } catch {
      // Приватный режим браузера может запрещать хранилище: тогда ключ живёт
      // только до перезагрузки страницы, и это приемлемо.
      this.value = null;
    }
  }

  set(value) {
    this.value = value;

    try {
      window.sessionStorage.setItem(this.storageKey, value);
    } catch {
      // Ключ остаётся в переменной — запросы работают до перезагрузки.
    }
  }

}

async function readJson(response) {
  if (!response.ok) {
    throw new Error(`Провайдер ответил ${response.status}: ${await errorReason(response)}`);
  }

  return await response.json();
}

/**
 * Причина отказа провайдера из его тела ответа.
 *
 * Ollama кладёт в `error` не текст, а JSON-строку с ошибкой внутри — без
 * разворачивания в вкладке видно только `{"error":"{\\"error\\":{…"`, и
 * понять, что именно сломалось, невозможно.
 */
async function errorReason(response) {
  const body = (await response.text()).slice(0, 800);

  let decoded = body;
  try {
    const outer = JSON.parse(body);
    if (typeof outer?.error === 'string') {
      const inner = JSON.parse(outer.error);
      decoded = inner?.error?.message ?? outer.error;
    } else if (outer?.error?.message) {
      decoded = outer.error.message;
    }
  } catch {
    // Тело не JSON или не вложенный JSON — показываем как есть.
  }

  return decoded;
}

/**
 * Провайдер, готовый к работе: адрес чата и адрес списка моделей разрешены
 * окончательно.
 *
 * У Ollama хост задаёт администратор, и пустое поле означает стандартный хост,
 * а не «адрес не задан»: иначе адрес собрался бы как `null/api/tags`, и браузер
 * запросил бы его от текущей страницы — `https://<хост CRM>/admin/import/null/api/tags`.
 */
function presetFor(provider, host) {
  const preset = PRESETS[provider];

  if ('ollama' !== provider) {
    return { ...preset, modelsUrl: preset.modelsUrl(preset.host) };
  }

  const origin = (host ?? '').trim().replace(/\/+$/, '') || preset.host;

  return { ...preset, baseUrl: origin, modelsUrl: preset.modelsUrl(origin) };
}

/**
 * Модели у провайдеров приходят в разной форме: OpenRouter — `data[].id`,
 * Ollama — `models[].name`.
 */
function modelNames(data) {
  const openrouter = (data?.data ?? []).map((model) => model.id);
  if (0 < openrouter.length) {
    return openrouter;
  }

  return (data?.models ?? []).map((model) => model.name);
}

/**
 * Ошибка, отличающая неотправленный запрос от отказа провайдера.
 *
 * `fetch` роняет `TypeError` там, где запрос не ушёл вовсе, и никакого ответа
 * не было. Сказать в вкладке, чем это вызвано, нельзя: причин столько же,
 * сколько у браузера правил безопасности, и список настроек хоста в приложении
 * осел бы мгновенно. Поэтому вкладка говорит, что что-то пошло не так, и
 * отправляет разбираться в консоль — там и запрос, и ответ на месте. Причина
 * остаётся в `cause`, поэтому она не потеряна и для консоли тоже.
 *
 * Отказ провайдера — другое: ответ получен, и в нём есть чем объяснить отказ,
 * поэтому он доходит до вкладки как есть, вместе с кодом и разобранной
 * причиной.
 */
function failure(error) {
  if ('TypeError' === error?.name) {
    const failed = new Error(FAILED_REQUEST_NOTE);
    failed.cause = error;

    return failed;
  }

  return error;
}

function init() {
  const form = document.querySelector('#llm-form');
  if (!form) {
    return;
  }

  const providerSelect = form.querySelector('#llm-provider');
  const hostRow = form.querySelector('#llm-host-row');
  const hostInput = form.querySelector('#llm-host');
  const keyInput = form.querySelector('#llm-key');
  const modelSelect = form.querySelector('#llm-model');
  const modelsButton = form.querySelector('#llm-models');
  const errorBox = form.querySelector('#llm-error');
  const statusBox = form.querySelector('#llm-status');
  const sendButton = form.querySelector('#llm-send');
  const sourceBox = form.querySelector('#llm-source');

  // Ответ и кнопка скачивания стоят после формы: ищутся на странице. Раньше они
  // искались внутри формы и оказывались не найденными — обработчик скачивания
  // не подключался, и клик по «Отправить» отправлял форму в браузере.
  const responseBox = document.querySelector('#llm-response');
  const downloadRow = document.querySelector('#llm-download-row');
  const downloadButton = document.querySelector('#llm-download');

  const key = new SessionKey();
  if (key.value) {
    keyInput.value = key.value;
  }

  const showError = (message) => {
    errorBox.textContent = message;
    errorBox.hidden = '' === message;
  };

  /**
   * Видимое ожидание: запрос ушёл, ответа ещё нет.
   *
   * Одного диалога браузера мало — его не видно, пока страница открыта, и он
   * появляется только при попытке уйти. Строка в вкладке говорит, что происходит,
   * и исчезает вместе с ответом. Подпись кнопки при этом не меняется: сервер
   * отрисовал одну, и подмена её на другой глагол сбивала бы с толку.
   */
  const setWaiting = (waiting) => {
    statusBox.textContent = waiting ? 'Запрос отправлен, ответ модели ещё не пришёл. Не закрывайте вкладку.' : '';
    statusBox.hidden = !waiting;
  };

  const client = () => {
    const preset = presetFor(providerSelect.value, hostInput.value);
    const apiKey = key.value ?? keyInput.value.trim();

    return new LlmClient({
      baseUrl: preset.baseUrl,
      chatPath: preset.chatPath,
      modelsUrl: preset.modelsUrl,
      headers: () => preset.headers(apiKey),
      buildRequest: preset.buildRequest,
      readContent: preset.readContent,
      model: modelSelect.value,
    });
  };

  /**
   * Список моделей выключен, пока модели не получены: пустой выпадающий список
   * нельзя выбрать, а включённый он выглядел бы как готовый к работе.
   */
  const setModelsLoaded = (loaded) => {
    modelSelect.disabled = !loaded;

    if (!loaded) {
      modelSelect.innerHTML = '';
    }
  };

  const syncProvider = () => {
    hostRow.hidden = 'ollama' !== providerSelect.value;
    showError('');
  };

  providerSelect.addEventListener('change', syncProvider);
  syncProvider();

  keyInput.addEventListener('change', () => {
    key.set(keyInput.value.trim());
  });

  modelsButton.addEventListener('click', async () => {
    showError('');
    setModelsLoaded(false);

    try {
      const data = await client().listModels();
      const names = modelNames(data);

      modelSelect.innerHTML = '';
      for (const name of names) {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        modelSelect.append(option);
      }

      if (0 === names.length) {
        showError('Провайдер не вернул ни одной модели.');
      }

      setModelsLoaded(0 < names.length);
    } catch (error) {
      showError(failure(error).message);
    }
  });

  /**
   * Показать ответ и разрешить его скачать.
   *
   * Ответ никуда не отправляется: он остаётся на странице, а в импорт попадёт
   * только тем, что администратор сам скачает файл и загрузит на вкладке JSON.
   * Автоотправка сделала бы импорт без спроса.
   */
  const showResponse = (content) => {
    responseBox.textContent = content;
    responseBox.hidden = false;
    downloadRow.hidden = false;
  };

  const hideResponse = () => {
    responseBox.textContent = '';
    responseBox.hidden = true;
    downloadRow.hidden = true;
  };

  downloadButton?.addEventListener('click', () => {
    const content = responseBox.textContent;
    if ('' === content.trim()) {
      return;
    }

    // Файл собирается в браузере: ответ не уходит на сервер, и на диске
    // приложения ничего не появляется.
    const url = URL.createObjectURL(new Blob([content], { type: 'application/json' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = responseFilename();

    // Ссылка должна быть в документе: у неё во временной сети Chromium имя из
    // атрибута download игнорирует и сохраняет файл как «download».
    document.body.append(link);
    link.click();
    link.remove();

    // Объект URL освобождается не сразу: браузер берёт имя файла из адреса уже
    // после клика, и мгновенный revoke оставлял файл без имени — «download».
    window.setTimeout(() => URL.revokeObjectURL(url), 10000);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showError('');

    // Без модели отправлять нечего: список выключен, и провайдер получил бы
    // запрос с пустым именем модели.
    if (modelSelect.disabled) {
      showError('Сначала получите модели провайдера.');
      return;
    }

    // Без текста разбирать нечего: модель вернёт пустой ответ, и скачивать
    // будет нечего.
    if ('' === sourceBox.value.trim()) {
      showError('Вставьте текст, из которого нужно составить ответ.');
      return;
    }

    sendButton.disabled = true;
    guardLeaving(true);
    setWaiting(true);
    hideResponse();

    try {
      const content = await client().send(
        [
          {
            role: 'system',
            content: 'Отвечай только JSON по схеме ответа. Ничего не додумывай: неизвестные данные просто не возвращай.',
          },
          { role: 'user', content: `${form.dataset.prompt}\n\nТекст для разбора:\n${sourceBox.value}` },
        ],
        window.ORGANIZATION_IMPORT_SCHEMA ?? null,
      );

      showResponse(content);
    } catch (error) {
      // Отказ провайдера и блокировка браузером одинаково оставляют импорт в
      // покое: ответ просто не пришёл, поэтому страница остаётся без ответа —
      // прежний уже убран `hideResponse()` выше.
      showError(failure(error).message);
    } finally {
      // Сторож снимается на любом исходе: и при ошибке уходить можно свободно.
      guardLeaving(false);
      setWaiting(false);
      sendButton.disabled = false;
    }
  });
}

if ('loading' === document.readyState) {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

<?php

/**
 * @file
 * Mistral AI adapter for AI core.
 *
 * Mistral's REST API follows the chat-completions shape, so messages, tool
 * definitions and vision content parts pass through unchanged. The differences
 * this adapter handles are:
 * - There is no legacy text-completions endpoint; completions() wraps the
 *   prompt in a single chat message.
 * - /v1/models reports a per-model capabilities object, which drives
 *   getModelsByCapability() instead of the name matching other adapters use.
 * - Moderation results carry boolean categories but no "flagged" key, so one
 *   is synthesized for callers that expect the common moderation shape.
 * - Text-to-speech takes voice_id, not voice.
 *
 * @see https://docs.mistral.ai/api
 */

class AIMistralAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /**
   * Mistral API base URL.
   *
   * @var string
   */
  protected $baseUrl = 'https://api.mistral.ai/v1';

  /**
   * Cache ID for the decoded /v1/models payload.
   */
  const MODEL_CACHE_ID = 'ai_provider_mistral:model_data';

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->apiKey,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    $models = [];

    foreach ($this->fetchModelData() as $id => $model) {
      $models[$id] = $this->buildModelLabel($id, $model);
    }

    if (empty($models)) {
      $models = $this->getFallbackModels();
    }

    asort($models);
    return $models;
  }

  /**
   * {@inheritdoc}
   *
   * Mistral advertises capabilities per model, so the reported flags are used
   * where they exist. Embeddings have no flag of their own and are matched on
   * the model ID instead.
   */
  public function getModelsByCapability($capability): array {
    $capability = function_exists('ai_normalize_capability_name')
      ? ai_normalize_capability_name($capability)
      : strtolower(trim((string) $capability));

    $model_data = $this->fetchModelData();
    $models = $this->getModels();
    $filtered = [];

    foreach ($models as $id => $label) {
      $flags = isset($model_data[$id]['capabilities']) ? $model_data[$id]['capabilities'] : [];
      if ($this->modelHasCapability($id, $flags, $capability)) {
        $filtered[$id] = $label;
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * Build the display label for one model card.
   *
   * Mistral lists every alias as its own entry, and each alias entry reports
   * the canonical model in its "name" field. Labelling by name would therefore
   * render several distinct, separately callable IDs as the same text, so the
   * ID leads and the canonical model is shown after it.
   *
   * @param string $id
   *   Model ID.
   * @param array $model
   *   Model card from /v1/models.
   *
   * @return string
   *   Display label.
   */
  protected function buildModelLabel($id, array $model): string {
    $canonical = !empty($model['name']) ? (string) $model['name'] : $id;
    if ($canonical === $id) {
      return $id;
    }
    return $id . ' (' . $canonical . ')';
  }

  /**
   * Decide whether one model serves a canonical capability.
   *
   * @param string $id
   *   Model ID.
   * @param array $flags
   *   Capabilities object reported by /v1/models, possibly empty.
   * @param string $capability
   *   Canonical capability name.
   *
   * @return bool
   *   TRUE when the model can serve the capability.
   */
  protected function modelHasCapability($id, array $flags, $capability): bool {
    // The static fallback list has no capability metadata, so fall back to the
    // naming convention Mistral uses for its specialised models.
    $is_embed = (bool) preg_match('/embed/i', $id);
    $is_moderation = (bool) preg_match('/moderation|shieldstral/i', $id);
    $is_ocr = (bool) preg_match('/ocr/i', $id);

    switch ($capability) {
      case 'text':
        if (!empty($flags)) {
          return !empty($flags['completion_chat']);
        }
        return !$is_embed && !$is_moderation && !$is_ocr;

      case 'vision':
        return !empty($flags['vision']);

      case 'tool_calling':
        return !empty($flags['completion_chat']) && !empty($flags['function_calling']);

      case 'insert':
        return !empty($flags['completion_fim']);

      case 'audio':
        return !empty($flags['audio']) && !empty($flags['completion_chat']);

      case 'embeddings':
        return $is_embed;

      case 'moderation':
        return !empty($flags['moderation']) || (empty($flags) && $is_moderation);

      case 'stt':
        return !empty($flags['audio_transcription']) || (empty($flags) && (bool) preg_match('/transcribe|voxtral-mini/i', $id));

      case 'tts':
        return !empty($flags['audio_speech']) || (empty($flags) && (bool) preg_match('/tts/i', $id));

      case 'ocr':
        return !empty($flags['ocr']) || (empty($flags) && $is_ocr);

      case 'image':
        // Mistral has no image generation endpoint.
        return FALSE;
    }

    return FALSE;
  }

  /**
   * Fetch and cache the model list, keyed by model ID.
   *
   * @return array
   *   Map of model ID => model card from /v1/models. Empty when the request
   *   fails or no key is configured.
   */
  protected function fetchModelData(): array {
    $cache_key = ai_models_cache_key('mistral', 'model_metadata', [hash('sha256', $this->apiKey)]);
    $cached = cache_get($cache_key);
    if ($cached && isset($cached->data) && is_array($cached->data)) {
      return $cached->data;
    }

    $model_data = [];

    try {
      $result = $this->makeRequest($this->baseUrl . '/models', [], [], 'GET', 10);
      foreach ($result['data'] ?? [] as $model) {
        $id = $model['id'] ?? '';
        if ($id === '') {
          continue;
        }
        $model_data[$id] = $model;
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Failed to fetch Mistral models: @message', ['@message' => $e->getMessage()], WATCHDOG_DEBUG);
      // Negative-cache briefly so a dead endpoint is not retried (10s timeout)
      // on every model-list call.
      cache_set($cache_key, [], 'cache', REQUEST_TIME + 300);
      return [];
    }

    cache_set($cache_key, $model_data, 'cache', REQUEST_TIME + 3600);
    return $model_data;
  }

  /**
   * Model list used when /v1/models is unreachable.
   *
   * @return array
   *   Map of model ID => label.
   */
  protected function getFallbackModels(): array {
    return [
      'mistral-large-latest' => 'Mistral Large',
      'mistral-medium-latest' => 'Mistral Medium',
      'mistral-small-latest' => 'Mistral Small',
      'ministral-8b-latest' => 'Ministral 8B',
      'ministral-3b-latest' => 'Ministral 3B',
      'mistral-embed' => 'Mistral Embed',
      'codestral-embed' => 'Codestral Embed',
      'mistral-moderation-latest' => 'Mistral Moderation',
      'voxtral-mini-latest' => 'Voxtral Mini (transcription)',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Mistral has no text-completions endpoint. The prompt is sent as a single
   * user message so callers keep the completions() contract.
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $messages = [
      ['role' => 'user', 'content' => trim($prompt)],
    ];
    return $this->chat($model, $messages, $temperature, $max_tokens, $stream_response, ['skip_ai_message_alter' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    if (empty($context_extra['skip_ai_message_alter'])) {
      $context = [
        'operation' => 'chat',
        'model' => $model,
        'provider' => 'mistral',
      ];
      backdrop_alter('ai_chat_messages', $messages, $context);
    }

    try {
      $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float) $temperature,
      ];
      if ((int) $max_tokens > 0) {
        $payload['max_tokens'] = (int) $max_tokens;
      }

      if (!empty($context_extra['response_format'])) {
        $payload['response_format'] = $context_extra['response_format'];
      }
      elseif (!empty($context_extra['json_schema'])) {
        $payload['response_format'] = [
          'type' => 'json_schema',
          'json_schema' => [
            'name' => $context_extra['json_schema_name'] ?? 'response',
            'strict' => TRUE,
            'schema' => $context_extra['json_schema'],
          ],
        ];
      }
      elseif (!empty($context_extra['json_mode'])) {
        $payload['response_format'] = ['type' => 'json_object'];
      }

      if ($stream_response) {
        // Without stream=true the API returns one JSON object, which the SSE
        // line parser silently discards.
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($this->baseUrl . '/chat/completions', $this->buildPostOptions($payload, 300), function ($data) {
          return $data['choices'][0]['delta']['content'] ?? '';
        });
      }

      // Long generations can exceed the 30s makeRequest() default; match the
      // 300s the streaming path uses.
      $result = $this->makeRequest($this->baseUrl . '/chat/completions', $payload, [], 'POST', 300);
      return trim($this->stringifyMessageContent($result['choices'][0]['message']['content'] ?? ''));
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Mistral chat error for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    try {
      $payload = [
        'model' => $model,
        'messages' => $messages,
        'tools' => $tools,
        'tool_choice' => $tool_choice,
        'temperature' => (float) $temperature,
      ];
      if ((int) $max_tokens > 0) {
        $payload['max_tokens'] = (int) $max_tokens;
      }

      if (!empty($context_extra['response_format'])) {
        $payload['response_format'] = $context_extra['response_format'];
      }
      elseif (!empty($context_extra['json_schema'])) {
        $payload['response_format'] = [
          'type' => 'json_schema',
          'json_schema' => [
            'name' => $context_extra['json_schema_name'] ?? 'response',
            'strict' => TRUE,
            'schema' => $context_extra['json_schema'],
          ],
        ];
      }
      elseif (!empty($context_extra['json_mode'])) {
        $payload['response_format'] = ['type' => 'json_object'];
      }

      $result = $this->makeRequest($this->baseUrl . '/chat/completions', $payload, [], 'POST', 300);
      return $this->normalizeToolResponse($result);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Mistral chatWithTools error for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    $start_time = microtime(TRUE);

    try {
      $response = $this->makeRequest($this->baseUrl . '/embeddings', [
        'model' => $model,
        'input' => $input,
      ], [], 'POST', 60);
      $result = $response['data'][0]['embedding'] ?? [];

      if (isset($this->api) && method_exists($this->api, 'recordLog')) {
        $duration = microtime(TRUE) - $start_time;
        $this->api->recordLog('embedding', $model, ['input' => $input], $response, TRUE, $duration, NULL, !$log);
      }

      return $result;
    }
    catch (\Exception $e) {
      if (isset($this->api) && method_exists($this->api, 'recordLog')) {
        $duration = microtime(TRUE) - $start_time;
        $this->api->recordLog('embedding', $model, ['input' => $input], NULL, FALSE, $duration, $e->getMessage(), !$log);
      }
      if ($log) {
        watchdog('ai_provider_mistral', 'Mistral embedding error for model @model: @message', [
          '@model' => $model,
          '@message' => $e->getMessage(),
        ], WATCHDOG_ERROR);
      }
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Mistral returns boolean categories and scores but no "flagged" key, so one
   * is derived from the categories for callers expecting the common shape.
   */
  public function moderation(string $input, string $model = 'mistral-moderation-latest'): array {
    // The interface default is OpenAI's moderation model, which would 404 here,
    // so fall back to a Mistral one when the caller passes a foreign ID. Prefer
    // a model the API actually lists: the "-latest" alias resolves server-side
    // but is not itself a listed model ID.
    $is_mistral_model = (bool) preg_match('/^(mistral-moderation|shieldstral)/i', $model);
    if (!$is_mistral_model) {
      $moderation_models = $this->getModelsByCapability('moderation');
      $model = $moderation_models ? (string) key($moderation_models) : 'mistral-moderation-latest';
    }

    try {
      $result = $this->makeRequest($this->baseUrl . '/moderations', [
        'model' => $model,
        'input' => trim($input),
      ], [], 'POST', 60);

      foreach ($result['results'] ?? [] as $index => $item) {
        if (isset($item['flagged'])) {
          continue;
        }
        $flagged = FALSE;
        foreach ($item['categories'] ?? [] as $value) {
          if (!empty($value)) {
            $flagged = TRUE;
            break;
          }
        }
        $result['results'][$index]['flagged'] = $flagged;
      }

      return $result;
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Mistral moderation error for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    try {
      $payload = [
        'model' => $model,
        'input' => $input,
        // Mistral names this voice_id, not voice.
        'voice_id' => $voice,
        'response_format' => $response_format ?: 'mp3',
      ];
      return $this->requestRaw($this->baseUrl . '/audio/speech', $payload, [], 'POST', 120);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Mistral text-to-speech error for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    if (!in_array($task, ['transcribe', 'translate'], TRUE)) {
      throw new \InvalidArgumentException('Task must be transcribe or translate.');
    }
    // Mistral exposes transcription only; there is no translation endpoint.
    if ($task === 'translate') {
      watchdog('ai_provider_mistral', 'Audio translation is not supported by Mistral.', [], WATCHDOG_WARNING);
      throw new \RuntimeException('Audio translation is not supported by Mistral.');
    }

    try {
      $result = $this->makeMultipartRequest($this->baseUrl . '/audio/transcriptions', [
        'model' => $model,
        'temperature' => (string) $temperature,
        'file' => ['path' => $file],
      ], 120);
      return $result['text'] ?? '';
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Mistral speech-to-text error for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * Models that serve the dedicated /v1/ocr endpoint.
   *
   * OCR is not one of AI core's canonical capabilities, so these models are
   * deliberately absent from getModelsByCapability() — offering them for chat
   * would only produce errors. This accessor exists for OCR callers.
   *
   * @return array
   *   Map of model ID => label.
   */
  public function getOcrModels(): array {
    $models = [];

    foreach ($this->fetchModelData() as $id => $model) {
      if (!empty($model['capabilities']['ocr'])) {
        $models[$id] = $this->buildModelLabel($id, $model);
      }
    }

    if (empty($models)) {
      $models = ['mistral-ocr-latest' => 'Mistral OCR'];
    }

    asort($models);
    return $models;
  }

  /**
   * The OCR model used when a caller does not name one.
   *
   * Mistral keeps several OCR generations listed at once, so picking the first
   * discovered ID would pin the site to an older one. The "-latest" alias
   * tracks the current generation, so prefer it and only fall back to the
   * highest-numbered listed model.
   *
   * @return string
   *   Model ID.
   */
  protected function getDefaultOcrModel(): string {
    $models = $this->getOcrModels();
    if (isset($models['mistral-ocr-latest'])) {
      return 'mistral-ocr-latest';
    }

    $ids = array_keys($models);
    natsort($ids);
    return $ids ? (string) end($ids) : 'mistral-ocr-latest';
  }

  /**
   * Extract text from a document or image with the /v1/ocr endpoint.
   *
   * This is Mistral-specific and sits outside AIProviderClient: it reads
   * whole PDFs page by page and returns markdown, which the shared vision
   * chat path cannot do.
   *
   * @param string $document_url
   *   An http(s) URL or a data URI. PDFs must be sent as document_url (which
   *   accepts 'data:application/pdf;base64,...'), images as image_url.
   * @param string $model
   *   OCR model ID. Defaults to the first model reporting the OCR capability.
   * @param array $options
   *   Optional keys:
   *   - is_image: TRUE to send the document as an image_url chunk.
   *   - pages: Array of zero-based page numbers, or a range string.
   *   - include_image_base64: TRUE to return extracted images inline.
   *   - table_format: 'markdown' or 'html'.
   *   - document_name: Filename shown in the request.
   *
   * @return array
   *   Normalized result:
   *   - text: All page markdown joined by blank lines.
   *   - pages: Raw page objects (index, markdown, images, tables).
   *   - model: Model that produced the result.
   *   - pages_processed: Page count reported by the API.
   */
  public function ocr(string $document, string $model = '', array $options = []): array {
    $document_url = trim($document);
    if ($document_url === '') {
      throw new \InvalidArgumentException('An OCR document URL or data URI is required.');
    }

    if ($model === '') {
      $model = $this->getDefaultOcrModel();
    }

    $is_image = !empty($options['is_image']);
    if ($is_image) {
      $document = ['type' => 'image_url', 'image_url' => $document_url];
    }
    else {
      $document = ['type' => 'document_url', 'document_url' => $document_url];
      if (!empty($options['document_name'])) {
        $document['document_name'] = $options['document_name'];
      }
    }

    $payload = [
      'model' => $model,
      'document' => $document,
    ];
    if (isset($options['pages']) && $options['pages'] !== '' && $options['pages'] !== []) {
      $payload['pages'] = $options['pages'];
    }
    if (!empty($options['include_image_base64'])) {
      $payload['include_image_base64'] = TRUE;
    }
    if (!empty($options['table_format'])) {
      $payload['table_format'] = $options['table_format'];
    }

    try {
      // Multi-page documents take far longer than a chat turn.
      $result = $this->makeRequest($this->baseUrl . '/ocr', $payload, [], 'POST', 300);

      $pages = isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : [];
      $markdown = [];
      foreach ($pages as $page) {
        $text = isset($page['markdown']) ? trim((string) $page['markdown']) : '';
        if ($text !== '') {
          $markdown[] = $text;
        }
      }

      return [
        'text' => implode("\n\n", $markdown),
        'pages' => $pages,
        'model' => $result['model'] ?? $model,
        'pages_processed' => (int) ($result['usage_info']['pages_processed'] ?? count($pages)),
      ];
    }
    catch (\Exception $e) {
      watchdog('ai_provider_mistral', 'Mistral OCR error for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    watchdog('ai_provider_mistral', 'Image generation is not supported by Mistral.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Image generation is not supported by Mistral.');
  }

  /**
   * Build request options for a JSON POST, used by the streaming path.
   */
  protected function buildPostOptions(array $body, int $timeout = 300): array {
    return [
      'method' => 'POST',
      'headers' => array_merge(
        ['Accept' => 'text/event-stream', 'Content-Type' => 'application/json'],
        $this->getDefaultHeaders()
      ),
      'data' => json_encode($body),
      'timeout' => $timeout,
    ];
  }

  /**
   * Flatten structured or plain message content to a string.
   *
   * Mistral can answer with an array of content chunks (text and thinking
   * blocks); concatenating that array directly would produce "Array".
   */
  protected function stringifyMessageContent($content): string {
    if (is_string($content)) {
      return $content;
    }
    if (!is_array($content)) {
      return (string) $content;
    }

    $parts = [];
    foreach ($content as $chunk) {
      if (is_string($chunk)) {
        $parts[] = $chunk;
        continue;
      }
      if (is_array($chunk) && isset($chunk['type']) && $chunk['type'] === 'text' && isset($chunk['text'])) {
        $parts[] = (string) $chunk['text'];
      }
    }

    return implode("\n", $parts);
  }

}

<?php

/**
 * @file
 * Tool definition for Mistral document OCR.
 */

/**
 * Returns the Mistral OCR tool definition.
 */
function ai_provider_mistral_ocr_tool_definitions() {
  return [
    'ai_mistral_ocr' => [
      'definition' => [
        'type' => 'function',
        'function' => [
          'name' => 'ai_mistral_ocr',
          'description' => 'Extract text from a PDF or image as markdown using Mistral document OCR. Reads whole multi-page PDFs, including tables. Provide either file_fid or url.',
          'parameters' => [
            'type' => 'object',
            'properties' => [
              'file_fid' => [
                'type' => 'integer',
                'description' => 'The file ID (fid) of a managed PDF or image file to process.',
              ],
              'url' => [
                'type' => 'string',
                'description' => 'A public http(s) URL of a PDF or image, used when no file_fid is given.',
              ],
              'pages' => [
                'type' => 'string',
                'description' => 'Optional zero-based pages to read, e.g. "0-4" or "0,2,5". All pages when omitted.',
              ],
              'model' => [
                'type' => 'string',
                'description' => 'Optional Mistral OCR model ID. Defaults to mistral-ocr-latest.',
              ],
            ],
            'required' => [],
          ],
        ],
      ],
      'execute' => 'ai_provider_mistral_execute_ocr',
      'module' => 'ai_provider_mistral',
      'category' => 'media',
      'operation' => 'read',
      'requirements' => 'ai_provider_mistral_ocr_tool_available',
    ],
  ];
}

/**
 * Tool requirements callback: only offer OCR when a Mistral key is configured.
 */
function ai_provider_mistral_ocr_tool_available() {
  return ai_provider_mistral_get_api_key() !== '';
}

/**
 * Tool execution callback: Mistral document OCR.
 */
function ai_provider_mistral_execute_ocr(array $args) {
  $adapter = ai_provider_mistral_get_adapter();
  if (!$adapter) {
    return ['error' => 'No Mistral API key is configured.'];
  }

  $fid = (int) ($args['file_fid'] ?? 0);
  $url = trim((string) ($args['url'] ?? ''));
  if ($fid <= 0 && $url === '') {
    return ['error' => 'Provide file_fid or url.'];
  }

  $options = [];
  $pages = trim((string) ($args['pages'] ?? ''));
  if ($pages !== '') {
    $page_numbers = ai_provider_mistral_parse_ocr_pages($pages);
    if ($page_numbers === NULL) {
      return ['error' => 'pages must look like "0-4" or "0,2,5".'];
    }
    $options['pages'] = $page_numbers;
  }
  $model = trim((string) ($args['model'] ?? ''));

  if ($fid > 0) {
    $file = file_load($fid);
    if (!$file || empty($file->uri)) {
      return ['error' => "File not found: $fid"];
    }
    if (function_exists('file_access') && !file_access('view', $file)) {
      return ['error' => "Access denied for file: $fid"];
    }

    $mime = !empty($file->filemime) ? $file->filemime : '';
    $is_image = strpos($mime, 'image/') === 0;
    if (!$is_image && $mime !== 'application/pdf') {
      return ['error' => "File is not a PDF or image: $fid ($mime)"];
    }

    // The Mistral OCR endpoint accepts documents up to 50 MB.
    $max_bytes = 50 * 1024 * 1024;
    if (!empty($file->filesize) && $file->filesize > $max_bytes) {
      return ['error' => "File is too large for OCR (50 MB limit): $fid"];
    }

    // Read through the stream wrapper: on remote schemes such as s3://,
    // backdrop_realpath() returns FALSE.
    $data = @file_get_contents($file->uri);
    if ($data === FALSE || $data === '') {
      return ['error' => "Could not read file data for fid $fid"];
    }
    if (strlen($data) > $max_bytes) {
      return ['error' => "File is too large for OCR (50 MB limit): $fid"];
    }

    $document = 'data:' . $mime . ';base64,' . base64_encode($data);
    $options['is_image'] = $is_image;
    if (!$is_image && !empty($file->filename)) {
      $options['document_name'] = $file->filename;
    }
  }
  else {
    if (!preg_match('#^https?://#i', $url)) {
      return ['error' => 'url must be an http(s) URL.'];
    }
    $document = $url;
    // Treat common image extensions as images; everything else as a document.
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $options['is_image'] = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'], TRUE);
  }

  try {
    $result = $adapter->ocr($document, $model, $options);
  }
  catch (\Exception $e) {
    return ['error' => $e->getMessage()];
  }

  $text = (string) ($result['text'] ?? '');
  if ($text === '') {
    return ['error' => 'OCR returned no text.'];
  }

  // Keep enormous documents from flooding the conversation context.
  $max_chars = 100000;
  $truncated = FALSE;
  if (mb_strlen($text, 'UTF-8') > $max_chars) {
    $text = mb_substr($text, 0, $max_chars, 'UTF-8');
    $truncated = TRUE;
  }

  $response = [
    'text' => $text,
    'model' => $result['model'],
    'pages_processed' => $result['pages_processed'],
  ];
  if ($truncated) {
    $response['truncated'] = TRUE;
    $response['note'] = 'Output truncated at 100000 characters. Use the pages parameter to read specific pages.';
  }
  return $response;
}

/**
 * Parses a pages string like "0-4" or "0,2,5" into zero-based page numbers.
 *
 * @param string $pages
 *   The user-supplied pages value.
 *
 * @return array|null
 *   Page numbers, or NULL when the string is malformed.
 */
function ai_provider_mistral_parse_ocr_pages($pages) {
  $numbers = [];
  foreach (explode(',', $pages) as $part) {
    $part = trim($part);
    if ($part === '') {
      continue;
    }
    if (preg_match('/^(\d+)-(\d+)$/', $part, $matches)) {
      $start = (int) $matches[1];
      $end = (int) $matches[2];
      if ($end < $start || ($end - $start) > 999) {
        return NULL;
      }
      for ($i = $start; $i <= $end; $i++) {
        $numbers[] = $i;
      }
    }
    elseif (ctype_digit($part)) {
      $numbers[] = (int) $part;
    }
    else {
      return NULL;
    }
  }

  return $numbers ? array_values(array_unique($numbers)) : NULL;
}

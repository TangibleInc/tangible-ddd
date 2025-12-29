<?php

namespace TangibleDDD\Application\Logging;

/**
 * Redacts sensitive information from command parameters for audit logging.
 */
final class Redactor {
  public function redact(array $params): array {
    return $this->process_field('', $params, 0);
  }

  private function process_field(string $path, $value, int $depth): array {
    if ($this->is_sensitive_key($this->last_key($path))) {
      return [$this->mask($value), [$path]];
    }

    if ($value === null || is_scalar($value)) {
      if (is_string($value) && strlen($value) > 1024) {
        return [$this->summarize_string($value), []];
      }

      return [$value, []];
    }

    if ($depth >= 6) {
      return [['__summary' => 'max_depth', 'type' => gettype($value)], []];
    }

    if (is_object($value)) {
      return $this->process_object($path, $value, $depth);
    }

    if (is_array($value)) {
      return $this->process_array($path, $value, $depth);
    }

    return ['[' . gettype($value) . ']', []];
  }

  private function process_object(string $path, object $obj, int $depth): array {
    $class = get_class($obj);

    if ($obj instanceof \DateTimeInterface) {
      return [$obj->format('c'), []];
    }

    if (method_exists($obj, 'to_json')) {
      try {
        $assoc = $obj->to_json(false, true);
        return $this->process_field($path, $assoc, $depth + 1);
      } catch (\Throwable) {
        return ['[' . $class . ']', []];
      }
    }

    if ($obj instanceof \JsonSerializable) {
      $json = json_encode($obj);

      if (is_string($json)) {
        $assoc = json_decode($json, true);
        if (is_array($assoc)) {
          return $this->process_field($path, $assoc, $depth + 1);
        }
      }

      return ['[' . $class . ']', []];
    }

    return ['[' . $class . ']', []];
  }

  private function process_array(string $path, array $arr, int $depth): array {
    if ($this->looks_like_headers($path, $arr)) {
      return $this->redact_headers_array($path, $arr, $depth);
    }

    $out = [];
    $red = [];
    $count = 0;

    foreach ($arr as $k => $v) {
      if ($count >= 50) {
        $out['__summary'] = 'truncated_list_' . count($arr);
        break;
      }

      $child_path = $this->join_path($path, is_int($k) ? '[' . $k . ']' : (string) $k);
      [$safe, $r] = $this->process_field($child_path, $v, $depth + 1);
      $out[$k] = $safe;

      array_push($red, ...$r);
      $count++;
    }

    return [$out, $red];
  }

  private function redact_headers_array(string $path, array $headers, int $depth): array {
    $safe = [];
    $red = [];
    foreach ($headers as $idx => $h) {
      if (is_array($h) && isset($h['key']) && isset($h['value'])) {
        [$one, $r] = $this->redact_single_header(
          $this->join_path($path, '[' . $idx . ']'),
          (string) $h['key'],
          (string) $h['value']
        );
        $safe[] = $one;
        array_push($red, ...$r);
      } else {
        [$one, $r] = $this->process_field($this->join_path($path, '[' . $idx . ']'), $h, $depth + 1);
        $safe[] = $one;
        array_push($red, ...$r);
      }
    }

    return [$safe, $red];
  }

  private function redact_single_header(string $path, string $key, string $value): array {
    $is_sensitive = $this->is_sensitive_header($key);

    return [
      [
        'key' => $key,
        'value' => $is_sensitive ? $this->mask($value) : (strlen($value) > 256 ? $this->summarize_string($value) : $value)
      ],
      $is_sensitive ? [$this->join_path($path, 'value')] : []
    ];
  }

  private function is_sensitive_key(?string $key): bool {
    if (!$key) {
      return false;
    }

    static $keys = [
      'password',
      'passphrase',
      'secret',
      'client_secret',
      'certificate',
      'token',
      'authorization',
      'api_key',
      'cookie',
      'set-cookie',
      'jwt',
      'auth_token',
      'bearer_token',
      'refresh_token',
      'access_token'
    ];

    return in_array(strtolower($key), $keys, true);
  }

  private function is_sensitive_header(string $key): bool {
    static $hdrs = [
      'authorization',
      'cookie',
      'set-cookie',
      'x-api-key',
      'x-auth-token',
      'bearer',
      'basic',
      'digest'
    ];
    $k = strtolower($key);
    foreach ($hdrs as $h) {
      if (str_contains($k, $h)) {
        return true;
      }
    }

    return false;
  }

  private function looks_like_headers(string $path, array $arr): bool {
    if ($path !== '' && str_ends_with($path, 'headers')) {
      return true;
    }
    if (empty($arr)) {
      return false;
    }

    $first = reset($arr);

    return is_array($first) && array_key_exists('key', $first) && array_key_exists('value', $first);
  }

  private function summarize_string(string $s): array {
    return [
      '__summary' => 'long_string',
      'length' => strlen($s),
      'sha256' => hash('sha256', $s),
      'preview' => substr($s, 0, 120) . '...'
    ];
  }

  private function mask($v): string {
    $s = is_scalar($v) ? (string) $v : '[secret]';
    $len = strlen($s);

    return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', max(0, $len - 4)) . substr($s, -4);
  }

  private function join_path(string $base, string $child): string {
    if ($base === '' || $child === '') {
      return $base . $child;
    }
    if ($child[0] === '[') {
      return $base . $child;
    }

    return $base . ($base === '' ? '' : '.') . $child;
  }

  private function last_key(string $path): ?string {
    if ($path === '') {
      return null;
    }
    $parts = preg_split('/[.\[]/', $path, -1, PREG_SPLIT_NO_EMPTY);

    return $parts ? end($parts) : null;
  }
}

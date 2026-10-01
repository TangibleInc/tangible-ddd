<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

/**
 * fnmatch() selector → anchored regular expression that Postgres (`~`) and
 * PCRE read the same way, so a relay-pause selector can be evaluated inside
 * the outbox claim statement. Supports `*`, `?`, `[...]` and `[!...]`;
 * every other non-word character is escaped and matches literally.
 *
 * @internal
 */
final class GlobPattern {

  public static function to_regex(string $glob): string {
    $out = '^';
    $len = strlen($glob);
    for ($i = 0; $i < $len; $i++) {
      $c = $glob[$i];
      if ($c === '*') {
        $out .= '.*';
      } elseif ($c === '?') {
        $out .= '.';
      } elseif ($c === '[' && ($close = strpos($glob, ']', $i + 2)) !== false) {
        $body = substr($glob, $i + 1, $close - $i - 1);
        $negate = $body !== '' && $body[0] === '!';
        if ($negate) {
          $body = substr($body, 1);
        }
        $out .= '[' . ($negate ? '^' : '') . str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $body) . ']';
        $i = $close;
      } elseif (ctype_alnum($c) || $c === '_') {
        $out .= $c;
      } else {
        $out .= '\\' . $c;
      }
    }
    return $out . '$';
  }
}

<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Exceptions\ApplicationException;

/**
 * Derive a site-unique key for encrypting secrets.
 *
 * This uses WP salts when available and HKDF-SHA256 when supported.
 */
function derive_key(string $prefix, string $info = 'ddd-secret'): string {
  $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
  if ($material === '') {
    $material = wp_salt('auth') . wp_salt('secure_auth');
  }

  $context = "{$prefix}-{$info}";

  if (function_exists('hash_hkdf')) {
    // Returns a binary string suitable for openssl.
    return hash_hkdf('sha256', $material, 32, $context);
  }

  return substr(hash('sha256', $context . '-' . $material, true), 0, 32);
}

/**
 * Encrypt a secret using AES-256-GCM and return base64(iv|tag|cipher).
 */
function encrypt_secret(string $prefix, string $plaintext, string $info = 'ddd-secret'): string {
  $key = derive_key($prefix, $info);
  $iv = random_bytes(12);
  $tag = '';

  $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) {
    throw new ApplicationException('Secret encryption failed');
  }

  return base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt a base64(iv|tag|cipher) blob. Returns null on invalid input/auth failure.
 */
function decrypt_secret(string $prefix, string $b64, string $info = 'ddd-secret'): ?string {
  $blob = base64_decode($b64, true);
  if ($blob === false || strlen($blob) < 28) {
    return null; // 12 iv + 16 tag
  }

  $iv = substr($blob, 0, 12);
  $tag = substr($blob, 12, 16);
  $cipher = substr($blob, 28);

  $key = derive_key($prefix, $info);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

  return $plain === false ? null : $plain;
}



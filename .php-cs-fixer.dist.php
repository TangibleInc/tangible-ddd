<?php

/**
 * Code style for the extraction packages: the 0.6 house style, written down.
 *
 * The rules are the ones the 0.6 code already follows (the 2-space files of
 * ddd-wordpress/, packages/ddd-core/src and tests/ that predate v0.6.6):
 * two-space indentation, `} else {` on one line, `(int) $x` casts,
 * `'a' . 'b'` concatenation, short arrays, `abstract public`, no space inside
 * parentheses, column alignment left alone. They were derived by running the
 * config over those files: it changes nothing in them except five one-off
 * outliers (one `array(`, one `public abstract`, one doubled blank line after
 * a namespace, one `(int)$x`, one `// ... ?>` in an HTML template). Where
 * 0.6 is split, there is no rule: closure
 * spacing (`fn($x)` and `fn ($x)`), the trailing newline count, one-line
 * bodies (`{ return 0; }`, `) {}`) and braceless one-line guards. The 4-space
 * PSR-12 files and the WordPress-spaced files among the legacy code are not
 * 0.6 style and are not reformatted either: the finder only covers the new
 * code below, so no legacy file churns.
 *
 *   composer cs:fix                           # format the new code
 *   composer cs                               # the gate: fails on drift
 *
 * Out of scope on purpose: naming (tools/naming/rename.php and the review
 * table own it), declare(strict_types=1) (new files keep theirs, legacy files
 * never get one), import order, quote style and phpdoc layout.
 */

$finder = (new PhpCsFixer\Finder())
  ->in([
    __DIR__ . '/packages/ddd-core/src/Runtime',
    __DIR__ . '/packages/ddd-core/src/Defaults/Pdo',
    __DIR__ . '/packages/ddd-core/tests',
    __DIR__ . '/packages/ddd-wp/wordpress/Adapter',
    __DIR__ . '/packages/ddd-symfony/src',
    __DIR__ . '/packages/ddd-symfony/config',
    __DIR__ . '/packages/ddd-symfony/tests',
    __DIR__ . '/packages/ddd-conformance/src',
    __DIR__ . '/packages/ddd-conformance/tests',
    __DIR__ . '/tests/Integration/V8',
    __DIR__ . '/tests/Integration/Conformance',
    __DIR__ . '/tests/Unit/WordPress/Adapter',
    __DIR__ . '/tests/Compat/rollback',
  ])
  ->exclude(['vendor', 'var'])
  ->name('*.php');

return (new PhpCsFixer\Config())
  ->setRiskyAllowed(false)
  ->setIndent('  ')
  ->setLineEnding("\n")
  ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
  ->setFinder($finder)
  ->setRules([
    // Whitespace and file layout.
    'encoding' => true,
    'full_opening_tag' => true,
    'line_ending' => true,
    'indentation_type' => true,
    'statement_indentation' => true,
    'no_trailing_whitespace' => true,
    'no_trailing_whitespace_in_comment' => true,
    'no_whitespace_in_blank_line' => true,
    'no_closing_tag' => true,
    'blank_line_after_namespace' => true,

    // `} else {`, `} catch (...) {` on one line. Opening-brace placement is
    // not enforced: braces_position cannot leave 0.6's one-line bodies
    // (`public function delay(): int { return 0; }`, `) {}`) alone, and
    // braceless one-line guards (`if ($x) return null;`) are 0.6 idiom too.
    'control_structure_continuation_position' => ['position' => 'same_line'],
    'elseif' => true,

    // Keywords and tokens.
    'lowercase_keywords' => true,
    'lowercase_static_reference' => true,
    'constant_case' => ['case' => 'lower'],
    'lowercase_cast' => true,
    'short_scalar_cast' => true,
    'cast_spaces' => ['space' => 'single'],
    'concat_space' => ['spacing' => 'one'],
    'array_syntax' => ['syntax' => 'short'],
    'return_type_declaration' => ['space_before' => 'none'],
    'nullable_type_declaration' => ['syntax' => 'question_mark'],
    'no_spaces_after_function_name' => true,
    'spaces_inside_parentheses' => ['space' => 'none'],
    'no_space_around_double_colon' => true,
    'object_operator_without_whitespace' => true,
    // Column alignment is kept (`int   $total,`, `=> ...`, `?: 'x'` lined
    // up): no type_declaration_spaces or ternary_operator_spaces, and binary
    // operators only need at least one space.
    'binary_operator_spaces' => ['default' => 'at_least_single_space'],
    'unary_operator_spaces' => true,
    'no_singleline_whitespace_before_semicolons' => true,
    'space_after_semicolon' => ['remove_in_empty_for_expressions' => true],
    'whitespace_after_comma_in_array' => ['ensure_single_space' => false],
    'no_whitespace_before_comma_in_array' => true,
    'method_argument_space' => ['on_multiline' => 'ignore', 'keep_multiple_spaces_after_comma' => true],
    'modifier_keywords' => ['elements' => ['property', 'method', 'const']],
    'single_class_element_per_statement' => ['elements' => ['property']],
    'single_import_per_statement' => true,
    'single_line_after_imports' => true,
    'no_leading_import_slash' => true,
    'switch_case_semicolon_to_colon' => true,
    'switch_case_space' => true,
  ]);

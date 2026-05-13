<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/app')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/scripts')
    ->exclude('vendor')
    ->exclude('storage')
    ->exclude('resources')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRules([
        // Base PSR-12
        '@PSR12' => true,

        // Arrays
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_whitespace_before_comma_in_array' => true,
        'normalize_index_brace' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,

        // Braces & whitespace
        'braces_position' => [
            'allow_single_line_empty_anonymous_classes' => true,
            'allow_single_line_anonymous_functions' => true,
        ],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'control_structure_braces' => true,
        'control_structure_continuation_position' => ['position' => 'same_line'],
        'declare_parentheses' => true,
        'multiline_whitespace_before_semicolons' => false,
        'no_extra_blank_lines' => [
            'tokens' => [
                'curly_brace_block',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'throw',
                'use',
            ],
        ],
        'no_multiple_statements_per_line' => true,
        'no_whitespace_in_blank_line' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'single_blank_line_at_eof' => false,
        'single_space_around_construct' => true,
        'statement_indentation' => true,

        // Imports
        'no_leading_import_slash' => true,
        'no_unused_imports' => true,

        // Operators
        'binary_operator_spaces' => [
            'operators' => [],
        ],
        'concat_space' => ['spacing' => 'one'],
        'increment_style' => ['style' => 'post'],
        'object_operator_without_whitespace' => true,
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'unary_operator_spaces' => true,

        // Casts & types
        'cast_spaces' => true,
        'lowercase_cast' => true,
        'no_short_bool_cast' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'short_scalar_cast' => true,
        'type_declaration_spaces' => true,

        // Comments & PHPDoc
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'single_line_comment_style' => ['comment_types' => ['hash']],

        // Misc
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'combine_consecutive_unsets' => true,
        'declare_equal_normalize' => true,
        'include' => true,
        'native_function_casing' => true,
        'new_with_parentheses' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_statement' => true,
        'no_leading_namespace_whitespace' => true,
        'no_mixed_echo_print' => ['use' => 'echo'],
        'no_spaces_around_offset' => true,
        'no_unneeded_control_parentheses' => true,
        'single_quote' => true,
        'single_class_element_per_statement' => true,
        'space_after_semicolon' => true,
    ])
    ->setLineEnding("\n")
    ->setRiskyAllowed(true);

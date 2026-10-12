<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->notPath('vendor');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments']],
        'declare_strict_types' => false,
        'strict_comparison' => false,
        'blank_line_before_statement' => ['statements' => ['return']],
        'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one']],
        'concat_space' => ['spacing' => 'one'],
        'yoda_style' => false,
        'phpdoc_trim' => true,
        'no_extra_blank_lines' => ['tokens' => ['extra']],
    ])
    ->setFinder($finder);

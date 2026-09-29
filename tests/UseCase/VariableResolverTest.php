<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Language\VariableResolver;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->resolver = new VariableResolver();
});

test('value without variables is returned unchanged', function () {
    $result = $this->resolver->resolve('year:2026 age:18', collect(['year' => '2026']));

    expect($result)->toBe('year:2026 age:18');
});

test('optional variable is substituted when present', function () {
    $result = $this->resolver->resolve('age:$age', collect(['age' => '18']));

    expect($result)->toBe('age:18');
});

test('required variable is substituted when present', function () {
    $result = $this->resolver->resolve('year:$year!', collect(['year' => '2026']));

    expect($result)->toBe('year:2026');
});

test('optional variable absent is replaced with empty string', function () {
    $result = $this->resolver->resolve('age:$age', collect([]));

    expect($result)->toBe('age:');
});

test('required variable absent throws validation exception', function () {
    expect(fn () => $this->resolver->resolve('year:$year!', collect([])))
        ->toThrow(ValidationException::class);
});

test('required variable absent has correct field in exception', function () {
    try {
        $this->resolver->resolve('year:$year!', collect([]));
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('year');
    }
});

test('multiple variables are substituted', function () {
    $context = collect(['year' => '2026', 'age' => '18']);
    $result = $this->resolver->resolve('year:$year! age:$age', $context);

    expect($result)->toBe('year:2026 age:18');
});

test('mix of required and optional variables', function () {
    $context = collect(['year' => '2026', 'age' => '18']);
    $result = $this->resolver->resolve('year:$year!+age:$age', $context);

    expect($result)->toBe('year:2026+age:18');
});

test('required present and optional absent', function () {
    $context = collect(['year' => '2026']);
    $result = $this->resolver->resolve('year:$year!+age:$age', $context);

    expect($result)->toBe('year:2026+age:');
});

test('variable names with underscores are supported', function () {
    $context = collect(['start_date' => '2026-01-01']);
    $result = $this->resolver->resolve('created:$start_date', $context);

    expect($result)->toBe('created:2026-01-01');
});

test('context with extra params does not affect non-variable text', function () {
    $context = collect(['age' => '18', 'filter' => 'age:$age', 'page' => '1']);
    $result = $this->resolver->resolve('age:$age', $context);

    expect($result)->toBe('age:18');
});

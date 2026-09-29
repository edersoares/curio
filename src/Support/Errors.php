<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Support;

class Errors
{
    /**
     * @param array<string, array<string>> $errors
     * @param array<string, array<string>> $newErrors
     *
     * @return array<string, array<string>>
     */
    public static function merge(array $errors, array $newErrors): array
    {
        foreach ($newErrors as $key => $messages) {
            $errors[$key] = array_merge($errors[$key] ?? [], $messages);
        }

        return $errors;
    }
}

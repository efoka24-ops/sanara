<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

final class Ident
{
    public static function check(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Identifiant SQL invalide : $name");
        }
        return $name;
    }
}

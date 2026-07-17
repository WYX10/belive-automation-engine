<?php

declare(strict_types=1);

namespace App\Models;

final class Property extends BaseModel
{
    protected const TABLE = 'properties';

    /** @return array<int, array<string, mixed>> */
    public static function forOwner(string $ownerName): array
    {
        return self::all(['owner_name' => $ownerName], 'name ASC');
    }
}

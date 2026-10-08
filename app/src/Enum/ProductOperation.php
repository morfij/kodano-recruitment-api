<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}

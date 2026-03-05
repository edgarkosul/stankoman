<?php

namespace App\Support\CatalogImport\Enums;

enum ImportErrorLevel: string
{
    case Fatal = 'fatal';
    case Record = 'record';
}

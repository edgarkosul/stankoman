<?php

namespace App\Enums;

enum FilterType: string
{
    case SELECT      = 'select';
    case MULTISELECT = 'multiselect';
    case RANGE       = 'range';
    case BOOLEAN     = 'boolean';
    case TEXT        = 'text';
    case MULTITEXT   = 'multitext';
}

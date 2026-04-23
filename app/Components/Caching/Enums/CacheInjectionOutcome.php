<?php

declare(strict_types=1);

namespace App\Components\Caching\Enums;

enum CacheInjectionOutcome: string
{
    case Injected = 'injected';
    case SkippedDisabled = 'skipped_disabled';
    case SkippedAlreadyPresent = 'skipped_already_present';
    case SkippedPrefixTooShort = 'skipped_prefix_too_short';
    case SkippedCapExceeded = 'skipped_cap_exceeded';
}

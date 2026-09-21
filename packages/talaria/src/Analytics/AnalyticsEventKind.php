<?php

declare(strict_types=1);

namespace Talaria\Analytics;

enum AnalyticsEventKind: string
{
    case Track = 'track';
    case Page = 'page';
    case Screen = 'screen';
    case Identify = 'identify';
}

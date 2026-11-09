<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * Which version of a story the CDA should return.
 *
 * A backed enum because the case values are the query-parameter values, so no
 * call site has to spell 'draft' as a string.
 */
enum Version: string
{
    case Published = 'published';
    case Draft = 'draft';
}

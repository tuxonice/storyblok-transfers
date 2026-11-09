<?php

declare(strict_types=1);

namespace Tlab\StoryblokTransfers\Content;

/**
 * The parameters of a story listing.
 *
 * A value object rather than a dozen optional method arguments, which would be
 * unreadable and unextendable - and it turns the parameters into a map the
 * client can sort, which is what makes a caching decorator's key stable.
 *
 * Composes ContentOptions rather than repeating its fields, because version,
 * language and relation resolution are not listing concerns.
 *
 * The options it holds are an optional *override*, not a default: a query that
 * carries none inherits the repository's, which toQuery() takes as an argument.
 * A query that silently owned `version=published` would make the repository's
 * configured default unreachable from the listing path - the classic preview
 * failure where detail pages show drafts and listings show published content.
 */
final class StoryQuery
{
    /**
     * @param ContentOptions|null $options An override for the repository's
     *        configured defaults. Null - the usual case - inherits them.
     * @param array<string, array<string, string>> $filterQuery Field =>
     *        operation => value, flattened into Storyblok's bracket parameters.
     * @param list<string> $byUuids
     * @param list<string> $excludingFields
     */
    public function __construct(
        public readonly ?ContentOptions $options = null,
        public readonly ?string $startsWith = null,
        public readonly array $filterQuery = [],
        public readonly ?string $sortBy = null,
        public readonly array $byUuids = [],
        public readonly array $excludingFields = [],
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {
    }

    /**
     * The one wither worth having: walking pages is the only thing that changes
     * a query after it is built.
     */
    public function withPage(int $page): self
    {
        return new self(
            $this->options,
            $this->startsWith,
            $this->filterQuery,
            $this->sortBy,
            $this->byUuids,
            $this->excludingFields,
            $page,
            $this->perPage,
        );
    }

    /**
     * @param ContentOptions $defaults The repository's configured defaults,
     *        used unless this query carries its own override. The precedence
     *        lives here because this is the one place that can see both.
     *
     * @return array<string, string>
     */
    public function toQuery(ContentOptions $defaults): array
    {
        $query = ($this->options ?? $defaults)->toQuery();
        $query['page'] = (string) $this->page;
        $query['per_page'] = (string) $this->perPage;

        if ($this->startsWith !== null) {
            $query['starts_with'] = $this->startsWith;
        }

        if ($this->sortBy !== null) {
            $query['sort_by'] = $this->sortBy;
        }

        if ($this->byUuids !== []) {
            $query['by_uuids'] = implode(',', $this->byUuids);
        }

        if ($this->excludingFields !== []) {
            $query['excluding_fields'] = implode(',', $this->excludingFields);
        }

        foreach ($this->filterQuery as $field => $operations) {
            foreach ($operations as $operation => $value) {
                $query[sprintf('filter_query[%s][%s]', $field, $operation)] = $value;
            }
        }

        return $query;
    }
}

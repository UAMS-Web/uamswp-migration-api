<?php

/**
 * Shared pagination primitives.
 */

declare(strict_types=1);

namespace UAMSWP\MigrationAPI;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Pagination shared by every collection endpoint.
 *
 * Ordering is always by ID ascending, never by date. A migration run pages
 * through a live site while editors are working; date ordering lets a record
 * move between pages mid-run and be read twice or skipped entirely. IDs are
 * immutable, so a page boundary means the same thing on page 40 as it did on
 * page 1.
 */
final class Pagination
{
    /**
     * @param  int  $page  Requested page.
     * @param  int  $perPage  Requested page size.
     */
    private function __construct(public readonly int $page, public readonly int $perPage) {}

    /**
     * Build from a REST request, applying defaults and bounds.
     *
     * @param  WP_REST_Request<array<string, mixed>>  $request  Incoming request.
     */
    public static function fromRequest(WP_REST_Request $request): self
    {
        $default = Config::int('per_page_default', 50);
        $max = Config::int('per_page_max', 200);

        // Equivalent for DecrementInteger (#298): a fallback page of 0 is
        // floored back to 1 by `max(1, $page)` below, so no input differs.
        // @pest-mutate-ignore:DecrementInteger
        $page = self::toInt($request->get_param('page'), 1);
        $per_page = self::toInt($request->get_param('per_page'), $default);

        return new self(
            max(1, $page),
            min(max(1, $per_page), max(1, $max))
        );
    }

    /**
     * Coerce an untrusted request parameter to an integer.
     *
     * REST arguments arrive as mixed even with a declared schema, because a
     * caller can always send something else.
     *
     * @param  mixed  $value  Raw parameter value.
     * @param  int  $default  Value returned when absent or non-numeric.
     */
    private static function toInt(
        mixed $value,
        int $default,
    ): int {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Offset for the current page.
     *
     * `page` is caller-supplied and only floored at 1, so the multiplication
     * can leave the integer range: `?page=9223372036854775807` produced a float
     * and `declare(strict_types=1)` turned the return into a `TypeError`, i.e.
     * a `500` from a well-formed request. Saturating instead yields an empty
     * page, which is the honest answer for a page past the end of the
     * collection and what any smaller out-of-range page already returned.
     */
    public function offset(): int
    {
        // Clamped BEFORE the multiplication rather than after it: PHPStan does
        // not model integer overflow, so an `is_int()` check on the product
        // reads to the analyzer as always-true and reds the run.
        //
        // Equivalent for DecrementInteger (#298): the constructor is private
        // and `fromRequest()` floors the page size at 1, so `max(0, …)` picks
        // the same value this does for every instance that can exist. The
        // IncrementInteger mutant is not equivalent, and a test kills it.
        // @pest-mutate-ignore:DecrementInteger
        $perPage = max(1, $this->perPage);

        // NO `+ 1` here, and that was a real bug rather than a tidy-up. With
        // `per_page=1`, `intdiv(PHP_INT_MAX, 1) + 1` is itself outside the
        // integer range: it becomes a float, `min()` returns that float on the
        // tie, and `offset()` threw the very `TypeError` this method was
        // rewritten to eliminate. `intdiv()` alone is the largest page whose
        // offset still fits, which is exactly the bound wanted.
        $highestSafePage = intdiv(PHP_INT_MAX, $perPage);

        return (min($this->page, $highestSafePage) - 1) * $perPage;
    }

    /**
     * Merge pagination and stable ordering into WP_Query arguments.
     *
     * @param  array<string, mixed>  $args  Base query arguments.
     * @return array<string, mixed>
     */
    public function applyToQueryArgs(array $args = []): array
    {
        return array_merge(
            $args,
            [
                'posts_per_page' => $this->perPage,
                'offset' => $this->offset(),
                'orderby' => 'ID',
                'order' => 'ASC',
                // Pagination is driven by explicit offset, so skip SQL_CALC_FOUND_ROWS.
                'no_found_rows' => true,
            ]
        );
    }

    /**
     * Stamp total-count headers onto a response.
     *
     * @param  WP_REST_Response  $response  Response to decorate.
     * @param  int  $total  Total records matching the query.
     */
    public function decorate(
        WP_REST_Response $response,
        int $total,
    ): WP_REST_Response {
        $total = max(0, $total);

        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) (int) ceil($total / $this->perPage));

        return $response;
    }

    /**
     * The `page` and `per_page` parameter definitions, shared by every collection.
     *
     * NO `sanitize_callback`, and that is the whole of D-084. Core validates an
     * argument against its schema only when no sanitizer of the route's own is
     * set; with `absint` here, `type` and `minimum` were never checked, and
     * `absint` then rewrote what arrived -- `page=-3` was served as page 3, a
     * well-formed response from the wrong page. Left to core, a non-integer or
     * a value below 1 is refused with a `400`.
     *
     * NO `maximum` on `per_page`, deliberately: core would refuse above it, and
     * the importer's contract promises that an oversized page size is CLAMPED
     * rather than refused. `fromRequest()` does the clamping, and the index
     * advertises the live cap as `per_page_max`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function params(): array
    {
        return [
            'page' => [
                'description' => __('Page of results to return, 1-based.', 'uamswp-migration-api'),
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'description' => __('Records per page.', 'uamswp-migration-api'),
                'type' => 'integer',
                'default' => Config::int('per_page_default', 50),
                'minimum' => 1,
            ],
        ];
    }
}

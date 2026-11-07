<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Pages for list screens: ?page=1 (50 a page, or ?per_page= up to 100) returns that page and
 * meta {page, per_page, total, has_more}. Without ?page a list keeps its old shape, for pickers that need all of it.
 */
trait Paginates
{
    protected function paged(Request $request): bool
    {
        return $request->filled('page');
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $q
     * @return array{0: Collection<int, T>, 1: array{page: int, per_page: int, total: int, has_more: bool}}
     */
    protected function page(Request $request, Builder $q, int $per = 50): array
    {
        $per = max(1, min(100, (int) $request->query('per_page', (string) $per)));
        $page = max(1, (int) $request->query('page', '1'));
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page, $per)->get();

        return [$rows, ['page' => $page, 'per_page' => $per, 'total' => $total, 'has_more' => $page * $per < $total]];
    }
}

<?php

namespace App\Http\Controllers\Admin\Concerns;

/**
 * Small parsing helpers for jQuery DataTables' server-side processing
 * request protocol (draw/start/length/order[0][column]/order[0][dir]).
 * Each module's data() method still owns its own query/columns/filters -
 * this only standardizes reading the parameters DataTables.js sends.
 */
trait HandlesDataTablesRequest
{
    protected function dtDraw(): int
    {
        return (int) request()->query('draw', 1);
    }

    protected function dtStart(): int
    {
        return max(0, (int) request()->query('start', 0));
    }

    protected function dtLength(): int
    {
        $length = (int) request()->query('length', 10);

        return $length > 0 ? $length : 10;
    }

    /**
     * @param list<string> $columns Column names in the same order as the JS `columns` config.
     */
    protected function dtOrderColumn(array $columns, string $default): string
    {
        $index = (int) ($this->dtOrderParam('column') ?? 0);

        return $columns[$index] ?? $default;
    }

    protected function dtOrderDir(): string
    {
        return $this->dtOrderParam('dir') === 'desc' ? 'desc' : 'asc';
    }

    /**
     * DataTables sends order[0][column]/order[0][dir] as a PHP nested-array
     * query string. request()->query('order.0.column') is not reliable
     * dot-path access into that nested array, so read the array directly.
     */
    private function dtOrderParam(string $key): mixed
    {
        $order = request()->query('order');

        return $order[0][$key] ?? null;
    }
}

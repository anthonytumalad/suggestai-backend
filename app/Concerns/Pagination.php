<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait Pagination
{
    /**
     * Paginate query and return JSON response
     *
     * @param Builder $query
     * @param Request|null $request
     * @param array $options
     * @return JsonResponse
     */
    protected function paginate(
        Builder $query,
        ?Request $request = null,
        array $options = []
    ): JsonResponse {
        $request = $request ?? request();

        $validated = $this->validatePaginationParams($request, $options);

        $this->applySorting($query, $validated, $options);

        $paginator = $query->paginate(
            $validated['per_page'],
            ['*'],
            'page',
            $validated['page']
        );

        return $this->formatPaginationResponse($paginator, $options);
    }

    /**
     * Paginate with search functionality
     *
     * @param Builder $query
     * @param array $searchableColumns
     * @param Request|null $request
     * @param array $options
     * @return JsonResponse
     */
    protected function paginateWithSearch(
        Builder $query,
        array $searchableColumns,
        ?Request $request = null,
        array $options = []
    ): JsonResponse {
        $request = $request ?? request();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($searchableColumns, $search) {
                foreach ($searchableColumns as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column);
                        $q->orWhereHas($relation, function ($subQuery) use ($field, $search) {
                            $subQuery->where($field, 'like', "%{$search}%");
                        });
                    } else {
                        $q->orWhere($column, 'like', "%{$search}%");
                    }
                }
            });
        }

        return $this->paginate($query, $request, $options);
    }

    /**
     * Paginate with filters
     *
     * @param Builder $query
     * @param array $filterRules
     * @param Request|null $request
     * @param array $options
     * @return JsonResponse
     */
    protected function paginateWithFilters(
        Builder $query,
        array $filterRules,
        ?Request $request = null,
        array $options = []
    ): JsonResponse {
        $request = $request ?? request();

        foreach ($filterRules as $param => $rule) {
            if ($request->has($param)) {
                $value = $request->input($param);

                if (is_callable($rule)) {
                    $rule($query, $value);
                } elseif (is_array($rule)) {
                    $column = $rule['column'] ?? $param;
                    $operator = $rule['operator'] ?? '=';
                    $query->where($column, $operator, $value);
                }
            }
        }

        return $this->paginate($query, $request, $options);
    }

    /**
     * Paginate with resource transformation
     *
     * @param Builder $query
     * @param string $resourceClass
     * @param Request|null $request
     * @param array $options
     * @return JsonResponse
     */
    protected function paginateWithResource(
        Builder $query,
        string $resourceClass,
        ?Request $request = null,
        array $options = []
    ): JsonResponse {
        $request = $request ?? request();
        $validated = $this->validatePaginationParams($request, $options);

        $this->applySorting($query, $validated, $options);

        $paginator = $query->paginate(
            $validated['per_page'],
            ['*'],
            'page',
            $validated['page']
        );

        return $resourceClass::collection($paginator)
            ->additional($this->getAdditionalMeta($options))
            ->response();
    }

    /**
     * Simple paginate (no count query, faster)
     *
     * @param Builder $query
     * @param Request|null $request
     * @param array $options
     * @return JsonResponse
     */
    protected function simplePaginate(
        Builder $query,
        ?Request $request = null,
        array $options = []
    ): JsonResponse {
        $request = $request ?? request();
        $validated = $this->validatePaginationParams($request, $options);

        $this->applySorting($query, $validated, $options);

        $paginator = $query->simplePaginate(
            $validated['per_page'],
            ['*'],
            'page',
            $validated['page']
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Cursor-based pagination (efficient for large datasets)
     *
     * @param Builder $query
     * @param Request|null $request
     * @param array $options
     * @return JsonResponse
     */
    protected function cursorPaginate(
        Builder $query,
        ?Request $request = null,
        array $options = []
    ): JsonResponse {
        $request = $request ?? request();
        $validated = $this->validatePaginationParams($request, $options);

        $cursor = $request->input('cursor');

        $paginator = $query->cursorPaginate(
            $validated['per_page'],
            ['*'],
            'cursor',
            $cursor
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'per_page' => $paginator->perPage(),
                'path' => $paginator->path(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Validate pagination parameters
     *
     * @param Request $request
     * @param array $options
     * @return array
     */
    protected function validatePaginationParams(Request $request, array $options): array
    {
        $maxPerPage = $options['max_per_page'] ?? 100;
        $defaultPerPage = $options['per_page'] ?? 15;
        $allowedSortColumns = $options['allowed_sort_columns'] ?? [];

        $rules = [
            'page' => 'integer|min:1',
            'per_page' => "integer|min:1|max:{$maxPerPage}",
            'sort_by' => $allowedSortColumns ? 'in:' . implode(',', $allowedSortColumns) : 'string',
            'sort_direction' => 'in:asc,desc',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }

        return [
            'page' => $request->input('page', 1),
            'per_page' => $request->input('per_page', $defaultPerPage),
            'sort_by' => $request->input('sort_by'),
            'sort_direction' => $request->input('sort_direction', 'desc'),
        ];
    }

    /**
     * Apply sorting to query
     *
     * @param Builder $query
     * @param array $validated
     * @param array $options
     * @return void
     */
    protected function applySorting(Builder $query, array $validated, array $options): void
    {
        if ($validated['sort_by']) {
            $query->orderBy($validated['sort_by'], $validated['sort_direction']);
        } elseif (isset($options['default_sort'])) {
            $query->orderBy(
                $options['default_sort']['column'] ?? 'created_at',
                $options['default_sort']['direction'] ?? 'desc'
            );
        }
    }

    /**
     * Format pagination response
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator
     * @param array $options
     * @return JsonResponse
     */
    protected function formatPaginationResponse($paginator, array $options): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];

        if (isset($options['meta'])) {
            $response['meta'] = array_merge($response['meta'], $options['meta']);
        }

        return response()->json($response);
    }

    /**
     * Get additional meta data
     *
     * @param array $options
     * @return array
     */
    protected function getAdditionalMeta(array $options): array
    {
        $meta = [];

        if (isset($options['meta'])) {
            $meta['meta'] = $options['meta'];
        }

        return $meta;
    }
}

<?php

declare(strict_types=1);

namespace Baum\Extensions\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModelClass of Model
 */
class Builder extends BaseBuilder
{
    /**
     * Replace the "order by" clause of the current query.
     *
     * @return BaseBuilder|Builder
     */
    public function reOrderBy(?string $column, string $direction = 'asc'): BaseBuilder|static
    {
        $this->orders = [];

        if ($column !== null) {
            return $this->orderBy($column, $direction);
        }

        return $this;
    }

    /**
     * Execute an aggregate function on the database.
     *
     * @param  string  $function
     * @param  array<int,string>   $columns
     */
    public function aggregate($function, $columns = ['*']): mixed
    {
        // Postgres doesn't like ORDER BY when there's no GROUP BY clause
        if (empty($this->groups)) {
            $this->reOrderBy(null);
        }

        return parent::aggregate($function, $columns);
    }
}

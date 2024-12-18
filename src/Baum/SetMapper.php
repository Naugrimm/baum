<?php

declare(strict_types=1);

namespace Baum;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class SetMapper
{
    /**
     * Create a new \Baum\SetBuilder class instance.
     */
    public function __construct(
        protected Node $node,
        protected string $childrenKeyName = 'children'
    ) {
    }

    /**
     * Maps a tree structure into the database. Unguards & wraps in transaction.
     *
     * @param   array<int,Node>|Arrayable<int,Node> $nodeList
     */
    public function map(array|Arrayable $nodeList): bool
    {
        $self = $this;

        /** @var bool $result */
        $result = $this->wrapInTransaction(function () use ($self, $nodeList): bool {
            forward_static_call([get_class($self->node), 'unguard']);
            $result = $self->mapTree($nodeList);
            forward_static_call([get_class($self->node), 'reguard']);

            return $result;
        });
        return $result;
    }

    /**
     * Maps a tree structure into the database without unguarding nor wrapping
     * inside a transaction.
     *
     * @param   array<int,Node>|Arrayable<int,Node> $nodeList
     */
    public function mapTree(array|Arrayable $nodeList): bool
    {
        /** @var array<int,array<string,mixed>> $tree */
        $tree = $nodeList instanceof Arrayable ? $nodeList->toArray() : $nodeList;

        $affectedKeys = [];

        $result = $this->mapTreeRecursive($tree, $this->node->getKey(), $affectedKeys);

        if ($result && count($affectedKeys) > 0) {
            $this->deleteUnaffected($affectedKeys);
        }

        return $result;
    }

    /**
     * Returns the children key name to use on the mapping array.
     */
    public function getChildrenKeyName(): string
    {
        return $this->childrenKeyName;
    }

    /**
     * Maps a tree structure into the database.
     *
     * @param array<int,array<string,mixed>> $tree
     * @param array<int,mixed> $affectedKeys
     */
    protected function mapTreeRecursive(array $tree, mixed $parentKey = null, array &$affectedKeys = []): bool
    {
        // For every attribute entry: We'll need to instantiate a new node either
        // from the database (if the primary key was supplied) or a new instance. Then,
        // append all the remaining data attributes (including the `parent_id` if
        // present) and save it. Finally, tail-recurse performing the same
        // operations for any child node present. Setting the `parent_id` property at
        // each level will take care of the nesting work for us.
        foreach ($tree as $attributes) {
            $node = $this->firstOrNew($this->getSearchAttributes($attributes));

            $data = $this->getDataAttributes($attributes);
            if ($parentKey !== null) {
                $data[$node->getParentColumnName()] = $parentKey;
            }

            $node->fill($data);

            $result = $node->save();

            if (! $result) {
                return false;
            }

            if (! $node->isRoot() && $node->parent) {
                $node->makeLastChildOf($node->parent);
            }

            $affectedKeys[] = $node->getKey();

            if (array_key_exists($this->getChildrenKeyName(), $attributes)) {
                /** @var array<int,array<string,mixed>> $children */
                $children = $attributes[$this->getChildrenKeyName()];

                if (count($children) > 0) {
                    $result = $this->mapTreeRecursive($children, $node->getKey(), $affectedKeys);

                    if (! $result) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    protected function getSearchAttributes(array $attributes): array
    {
        $searchable = [$this->node->getKeyName()];
        return Arr::only($attributes, $searchable);
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    protected function getDataAttributes(array $attributes): array
    {
        $exceptions = [$this->node->getKeyName(), $this->getChildrenKeyName()];
        return Arr::except($attributes, $exceptions);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    protected function firstOrNew(array $attributes): Node
    {
        $className = get_class($this->node);

        if (count($attributes) === 0) {
            return new $className();
        }

        /** @var Node $model */
        $model = forward_static_call([$className, 'firstOrNew'], $attributes);
        return $model;
    }

    /**
     * @return Node|Builder
     */
    protected function pruneScope(): Node|Builder
    {
        if ($this->node->exists) {
            return $this->node->descendants();
        }

        return $this->node->newNestedSetQuery();
    }

    /**
     * @param array<int,string> $keys
     */
    protected function deleteUnaffected(array $keys = []): mixed
    {
        return $this->pruneScope()
            ->whereNotIn($this->node->getKeyName(), $keys)
            ->delete();
    }

    /**
     * @throws \Throwable
     */
    protected function wrapInTransaction(Closure $callback): mixed
    {
        return $this->node->getConnection()
            ->transaction($callback);
    }
}

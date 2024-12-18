<?php

declare(strict_types=1);

namespace Baum;

use Illuminate\Database\Eloquent\Collection;

class SetBuilder
{
    /**
     * Array which will hold temporary lft, rgt index values for each scope.
     *
     * @var array<string,int>
     */
    protected array $bounds = [];

    /**
     * Create a new \Baum\SetBuilder class instance.
     */
    public function __construct(
        protected Node $node
    ) {
    }

    /**
     * Perform the re-calculation of the left and right indexes of the whole
     * nested set tree structure.
     *
     * @throws \Throwable
     */
    public function rebuild(): void
    {
        // Rebuild lefts and rights for each root node and its children (recursively).
        // We go by setting left (and keep track of the current left bound), then
        // search for each children and recursively set the left index (while
        // incrementing that index). When going back up the recursive chain we start
        // setting the right indexes and saving the nodes...
        $self = $this;

        $this->node->getConnection()
            ->beginTransaction();
        foreach ($self->roots() as $root) {
            $self->rebuildBounds($root, 0);
        }
        $this->node->getConnection()
            ->commit();
    }

    /**
     * Return all root nodes for the current database table appropiately sorted.
     *
     * @return Collection<int,Node>
     */
    public function roots(): Collection
    {
        if ($this->node->getKey()) {
            $query = $this->node->newNestedSetQuery();
        } else {
            $query = $this->node->newQuery();
        }

        return $query
            ->where(function ($query) {
                return $query->whereNull($this->node->getQualifiedParentColumnName())
                    ->orWhere($this->node->getQualifiedParentColumnName(), 0);
            })
            ->orderBy($this->node->getQualifiedLeftColumnName())
            ->orderBy($this->node->getQualifiedRightColumnName())
            ->orderBy($this->node->getQualifiedKeyName())
            ->get();
    }

    /**
     * Recompute left and right index bounds for the specified node and its
     * children (recursive call). Fill the depth column too.
     */
    public function rebuildBounds(Node $node, int $depth = 0): void
    {
        $k = $this->scopedKey($node);

        $node->setAttribute($node->getLeftColumnName(), $this->getNextBound($k));
        $node->setAttribute($node->getDepthColumnName(), $depth);

        /** @var Node $child */
        foreach ($this->children($node) as $child) {
            $this->rebuildBounds($child, $depth + 1);
        }

        $node->setAttribute($node->getRightColumnName(), $this->getNextBound($k));

        $node->save();
    }

    /**
     * Return all children for the specified node.
     *
     * @return  Collection<int,Node>
     */
    public function children(Node $node): Collection
    {
        $query = $this->node->newQuery();

        $query->where($this->node->getQualifiedParentColumnName(), '=', $node->getKey());

        // We must also add the scoped column values to the query to compute valid
        // left and right indexes.
        foreach ($this->scopedAttributes($node) as $fld => $value) {
            $query->where($this->qualify($fld), '=', $value);
        }

        $query->orderBy($this->node->getQualifiedLeftColumnName());
        $query->orderBy($this->node->getQualifiedRightColumnName());
        $query->orderBy($this->node->getQualifiedKeyName());

        return $query->get();
    }

    /**
     * Return an array of the scoped attributes of the supplied node.
     *
     * @return  array<string,mixed>
     */
    protected function scopedAttributes(Node $node): array
    {
        $keys = $this->node->getScopedColumns();

        if (count($keys) === 0) {
            return [];
        }

        $values = array_map(function ($column) use ($node) {
            return $node->getAttribute($column);
        }, $keys);

        return array_combine($keys, $values);
    }

    /**
     * Return a string-key for the current scoped attributes. Used for index
     * computing when a scope is defined (acsts as an scope identifier).
     */
    protected function scopedKey(Node $node): string
    {
        $attributes = $this->scopedAttributes($node);

        $output = [];

        foreach ($attributes as $fld => $value) {
            $output[] = $this->qualify($fld) . '=' . ($value === null ? 'NULL' : $value);
        }

        // NOTE: Maybe an md5 or something would be better. Should be unique though.
        return implode(',', $output);
    }

    /**
     * Return next index bound value for the given key (current scope identifier).
     */
    protected function getNextBound(string $key): int
    {
        if (array_key_exists($key, $this->bounds) === false) {
            $this->bounds[$key] = 0;
        }

        $this->bounds[$key] = $this->bounds[$key] + 1;

        return $this->bounds[$key];
    }

    /**
     * Get the fully qualified value for the specified column.
     */
    protected function qualify(string $column): string
    {
        return $this->node->getTable() . '.' . $column;
    }
}

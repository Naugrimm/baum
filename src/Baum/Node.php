<?php

declare(strict_types=1);

namespace Baum;

use Baum\Extensions\Eloquent\Collection;
use Baum\Extensions\Eloquent\Model;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Node.
 *
 * This abstract class implements Nested Set functionality. A Nested Set is a
 * smart way to implement an ordered tree with the added benefit that you can
 * select all of their descendants with a single query. Drawbacks are that
 * insertion or move operations need more complex sql queries.
 *
 * Nested sets are appropiate when you want either an ordered tree (menus,
 * commercial categories, etc.) or an efficient way of querying big trees.
 */

/** @phpstan-consistent-constructor */
abstract class Node extends Model
{
    /**
     * Column name to store the reference to parent's node.
     */
    protected string $parentColumn = 'parent_id';

    /**
     * Column name for left index.
     */
    protected string $leftColumn = 'lft';

    /**
     * Column name for right index.
     */
    protected string $rightColumn = 'rgt';

    /**
     * Column name for depth field.
     */
    protected string $depthColumn = 'depth';

    /**
     * Column to perform the default sorting.
     */
    protected ?string $orderColumn = null;

    /**
     * Guard Node fields from mass-assignment.
     *
     * @var array<string>|bool
     */
    protected $guarded = ['id', 'parent_id', 'lft', 'rgt', 'depth'];

    /**
     * Indicates whether we should move to a new parent.
     */
    protected static int|string|null|false $moveToNewParentId = null;

    /**
     * Columns which restrict what we consider our Nested Set list.
     *
     * @var array<string>
     */
    protected $scoped = [];

    /**
     * The "booting" method of the model.
     *
     * We'll use this method to register event listeners on a Node instance as
     * suggested in the beta documentation...
     *
     * TODO:
     *
     *    - Find a way to avoid needing to declare the called methods "public"
     *    as registering the event listeners *inside* this methods does not give
     *    us an object context.
     *
     * Events:
     *
     *    1. "creating": Before creating a new Node we'll assign a default value
     *    for the left and right indexes.
     *
     *    2. "saving": Before saving, we'll perform a check to see if we have to
     *    move to another parent.
     *
     *    3. "saved": Move to the new parent after saving if needed and re-set
     *    depth.
     *
     *    4. "deleting": Before delete we should prune all children and update
     *    the left and right indexes for the remaining nodes.
     *
     *    5. (optional) "restoring": Before a soft-delete node restore operation,
     *    shift its siblings.
     *
     *    6. (optional) "restore": After having restored a soft-deleted node,
     *    restore all of its descendants.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($node) {
            $node->setDefaultLeftAndRight();
        });

        static::saving(function ($node) {
            $node->storeNewParent();
        });

        static::saved(function ($node) {
            $node->moveToNewParent();
            $node->setDepth();
        });

        static::deleting(function ($node) {
            $node->destroyDescendants();
        });

        if (static::softDeletesEnabled()) {
            /** @phpstan-ignore-next-line  */
            static::restoring(function ($node) {
                $node->shiftSiblingsForRestore();
            });

            /** @phpstan-ignore-next-line  */
            static::restored(function ($node) {
                $node->restoreDescendants();
            });
        }
    }

    /**
     * Get the parent column name.
     */
    public function getParentColumnName(): string
    {
        return $this->parentColumn;
    }

    /**
     * Get the table qualified parent column name.
     */
    public function getQualifiedParentColumnName(): string
    {
        return $this->getTable() . '.' . $this->getParentColumnName();
    }

    /**
     * Get the value of the models "parent_id" field.
     */
    public function getParentId(): int|string|null
    {
        /** @var int|string|null $parentId */
        $parentId = $this->getAttribute($this->getParentColumnName());
        return $parentId;
    }

    /**
     * Get the "left" field column name.
     */
    public function getLeftColumnName(): string
    {
        return $this->leftColumn;
    }

    /**
     * Get the table qualified "left" field column name.
     */
    public function getQualifiedLeftColumnName(): string
    {
        return $this->getTable() . '.' . $this->getLeftColumnName();
    }

    /**
     * Get the value of the model's "left" field.
     */
    public function getLeft(): int
    {
        /** @var int $left */
        $left = $this->getAttribute($this->getLeftColumnName());
        return $left;
    }

    /**
     * Get the "right" field column name.
     */
    public function getRightColumnName(): string
    {
        return $this->rightColumn;
    }

    /**
     * Get the table qualified "right" field column name.
     */
    public function getQualifiedRightColumnName(): string
    {
        return $this->getTable() . '.' . $this->getRightColumnName();
    }

    /**
     * Get the value of the model's "right" field.
     */
    public function getRight(): int
    {
        /** @var int $right */
        $right = $this->getAttribute($this->getRightColumnName());
        return $right;
    }

    /**
     * Get the "depth" field column name.
     */
    public function getDepthColumnName(): string
    {
        return $this->depthColumn;
    }

    /**
     * Get the table qualified "depth" field column name.
     */
    public function getQualifiedDepthColumnName(): string
    {
        return $this->getTable() . '.' . $this->getDepthColumnName();
    }

    /**
     * Get the model's "depth" value.
     */
    public function getDepth(): ?int
    {
        /** @var int $depth */
        $depth = $this->getAttribute($this->getDepthColumnName());
        return $depth;
    }

    /**
     * Get the "order" field column name.
     */
    public function getOrderColumnName(): string
    {
        return $this->orderColumn === null ? $this->getLeftColumnName() : $this->orderColumn;
    }

    /**
     * Get the table qualified "order" field column name.
     */
    public function getQualifiedOrderColumnName(): string
    {
        return $this->getTable() . '.' . $this->getOrderColumnName();
    }

    /**
     * Get the model's "order" value.
     */
    public function getOrder(): mixed
    {
        return $this->getAttribute($this->getOrderColumnName());
    }

    /**
     * Get the column names which define our scope.
     *
     * @return array<int,string>
     */
    public function getScopedColumns(): array
    {
        return (array) $this->scoped;
    }

    /**
     * Get the qualified column names which define our scope.
     *
     * @return array<int, string>
     */
    public function getQualifiedScopedColumns(): array
    {
        if (! $this->isScoped()) {
            return $this->getScopedColumns();
        }

        $prefix = $this->getTable() . '.';

        return array_map(function ($c) use ($prefix) {
            return $prefix . $c;
        }, $this->getScopedColumns());
    }

    /**
     * Returns wether this particular node instance is scoped by certain fields
     * or not.
     */
    public function isScoped(): bool
    {
        return ! ! (count($this->getScopedColumns()) > 0);
    }

    /**
     * Parent relation (self-referential) 1-1.
     *
     * @return BelongsTo<Node,Node>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getParentColumnName());
    }

    /**
     * Children relation (self-referential) 1-N.
     *
     * @return HasMany<Node>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getParentColumnName())
            ->orderBy($this->getOrderColumnName());
    }

    /**
     * Get a new "scoped" query builder for the Node's model.
     *
     * @return Builder<Node>
     */
    public function newNestedSetQuery(): Builder
    {
        $builder = $this->newQuery()
            ->orderBy($this->getQualifiedOrderColumnName());

        if ($this->isScoped()) {
            foreach ($this->scoped as $scopeFld) {
                $builder->where($scopeFld, '=', $this->{$scopeFld});
            }
        }

        return $builder;
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param  array<int,Node>  $models
     * @return Collection<int,Node>
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Get all of the nodes from the database.
     *
     * @param array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public static function all($columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        $instance = new static();

        return $instance->newQuery()
            ->orderBy($instance->getQualifiedOrderColumnName())
            ->get($columns);
    }

    /**
     * Returns the first root node.
     */
    public static function root(): \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|null
    {
        return static::roots()->first();
    }

    /**
     * Static query scope. Returns a query scope with all root nodes.
     *
     * @return Builder<Node>
     */
    public static function roots(): Builder
    {
        $instance = new static();

        return $instance->newQuery()
            ->where(function ($query) use ($instance) {
                return $query->whereNull($instance->getParentColumnName())
                    ->orWhere($instance->getParentColumnName(), 0);
            })
            ->orderBy($instance->getQualifiedOrderColumnName());
    }

    /**
     * Static query scope. Returns a query scope with all nodes which are at
     * the end of a branch.
     *
     * @return Builder<Node>
     */
    public static function allLeaves(): Builder
    {
        $instance = new static();

        $grammar = $instance->getConnection()
            ->getQueryGrammar();

        $rgtCol = $grammar->wrap($instance->getQualifiedRightColumnName());
        $lftCol = $grammar->wrap($instance->getQualifiedLeftColumnName());

        return $instance->newQuery()
            ->whereRaw($rgtCol . ' - ' . $lftCol . ' = 1')
            ->orderBy($instance->getQualifiedOrderColumnName());
    }

    /**
     * Static query scope. Returns a query scope with all nodes which are at
     * the middle of a branch (not root and not leaves).
     *
     * @return Builder<Node>
     */
    public static function allTrunks(): Builder
    {
        $instance = new static();

        $grammar = $instance->getConnection()
            ->getQueryGrammar();

        $rgtCol = $grammar->wrap($instance->getQualifiedRightColumnName());
        $lftCol = $grammar->wrap($instance->getQualifiedLeftColumnName());

        return $instance->newQuery()
            ->whereNotNull($instance->getParentColumnName())
            ->where($instance->getParentColumnName(), '!=', 0)
            ->whereRaw($rgtCol . ' - ' . $lftCol . ' != 1')
            ->orderBy($instance->getQualifiedOrderColumnName());
    }

    /**
     * Checks wether the underlying Nested Set structure is valid.
     */
    public static function isValidNestedSet(?self $node = null): bool
    {
        if ($node === null) {
            $node = new static();
        }
        $validator = new SetValidator($node);
        return $validator->passes();
    }

    /**
     * Rebuilds the structure of the current Nested Set.
     *
     * @param null $node
     */
    public static function rebuild(?self $node = null): void
    {
        if ($node === null) {
            $node = new static();
        }
        $builder = new SetBuilder($node);
        $builder->rebuild();
    }

    /**
     * Maps the provided tree structure into the database.
     *
     * @param  array<int,Node>|Arrayable<int,Node>  $nodeList
     */
    public static function buildTree(array|Arrayable $nodeList): bool
    {
        return (new static())->makeTree($nodeList);
    }

    /**
     * Query scope which extracts a certain node object from the current query
     * expression.
     *
     * @param Builder<Node> $query
     */
    public function scopeWithoutNode(Builder $query, self|Model $node): void
    {
        $query->where($node->getKeyName(), '!=', $node->getKey());
    }

    /**
     * Extracts current node (self) from current query expression.
     *
     * @param Builder<Node> $query
     */
    public function scopeWithoutSelf(Builder $query): void
    {
        $this->scopeWithoutNode($query, $this);
    }

    /**
     * Extracts first root (from the current node p-o-v) from current query
     * expression.
     *
     * @param Builder<Node> $query
     */
    public function scopeWithoutRoot(Builder $query): void
    {
        $this->scopeWithoutNode($query, $this->getRoot());
    }

    /**
     * Provides a depth level limit for the query.
     *
     * @param   Builder<Node> $query
     * @param   integer   $limit
     */
    public function scopeLimitDepth(Builder $query, ?int $limit): void
    {
        if ($limit !== null) {
            $depth = $this->exists ? $this->getDepth() : $this->getLevel();
            $max = $depth + $limit;
            $scopes = [$depth, $max];

            $query->whereBetween($this->getDepthColumnName(), [min($scopes), max($scopes)]);
        }
    }

    /**
     * Returns true if this is a root node.
     */
    public function isRoot(): bool
    {
        return $this->getParentId() === null || $this->getParentId() === 0;
    }

    /**
     * Returns true if this is a leaf node (end of a branch).
     */
    public function isLeaf(): bool
    {
        return $this->exists && ($this->getRight() - $this->getLeft() === 1);
    }

    /**
     * Returns true if this is a trunk node (not root or leaf).
     */
    public function isTrunk(): bool
    {
        return ! $this->isRoot() && ! $this->isLeaf();
    }

    /**
     * Returns true if this is a child node.
     */
    public function isChild(): bool
    {
        return ! $this->isRoot();
    }

    /**
     * Returns the root node starting at the current node.
     *
     * @return Model|Node|null
     */
    public function getRoot(): Model|self|null
    {
        if ($this->exists) {
            return $this->ancestorsAndSelf()
                ->whereNull($this->getParentColumnName())
                ->orWhere($this->getParentColumnName(), 0)
                ->first();
        }
        $parentId = $this->getParentId();

        if (($parentId !== null && $parentId !== 0) && $currentParent = static::find($parentId)) {
            return $currentParent->getRoot();
        }
        return $this;
    }

    /**
     * Instance scope which targes all the ancestor chain nodes including
     * the current one.
     *
     * @return Builder<Node>
     */
    public function ancestorsAndSelf(): Builder
    {
        return $this->newNestedSetQuery()
            ->where($this->getLeftColumnName(), '<=', $this->getLeft())
            ->where($this->getRightColumnName(), '>=', $this->getRight());
    }

    /**
     * Get all the ancestor chain from the database including the current node.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getAncestorsAndSelf(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->ancestorsAndSelf()
            ->get($columns);
    }

    /**
     * Get all the ancestor chain from the database including the current node
     * but without the root node.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getAncestorsAndSelfWithoutRoot(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->ancestorsAndSelf()
            ->withoutRoot()
            ->get($columns);
    }

    /**
     * Instance scope which targets all the ancestor chain nodes excluding
     * the current one.
     *
     * @return Builder<Node>
     */
    public function ancestors(): Builder
    {
        return $this->ancestorsAndSelf()
            ->withoutSelf();
    }

    /**
     * Get all the ancestor chain from the database excluding the current node.
     *
     * @param  array<string>  $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getAncestors(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->ancestors()
            ->get($columns);
    }

    /**
     * Get all the ancestor chain from the database excluding the current node
     * and the root node (from the current node's perspective).
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getAncestorsWithoutRoot(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->ancestors()
            ->withoutRoot()
            ->get($columns);
    }

    /**
     * Instance scope which targets all children of the parent, including self.
     *
     * @return Builder<Node>
     */
    public function siblingsAndSelf(): Builder
    {
        return $this->newNestedSetQuery()
            ->where($this->getParentColumnName(), $this->getParentId());
    }

    /**
     * Get all children of the parent, including self.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getSiblingsAndSelf(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->siblingsAndSelf()
            ->get($columns);
    }

    /**
     * Instance scope targeting all children of the parent, except self.
     *
     * @return Builder<Node>
     */
    public function siblings(): Builder
    {
        return $this->siblingsAndSelf()
            ->withoutSelf();
    }

    /**
     * Return all children of the parent, except self.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getSiblings(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->siblings()
            ->get($columns);
    }

    /**
     * Instance scope targeting all of its nested children which do not have
     * children.
     *
     * @return Builder<Node>
     */
    public function leaves(): Builder
    {
        $grammar = $this->getConnection()
            ->getQueryGrammar();

        $rgtCol = $grammar->wrap($this->getQualifiedRightColumnName());
        $lftCol = $grammar->wrap($this->getQualifiedLeftColumnName());

        return $this->descendants()
            ->whereRaw($rgtCol . ' - ' . $lftCol . ' = 1');
    }

    /**
     * Return all of its nested children which do not have children.
     *
     * @param  array<string>  $columns
     * @return \Illuminate\Support\Collection<int,Node>
     */
    public function getLeaves(array $columns = ['*']): \Illuminate\Support\Collection
    {
        return $this->leaves()
            ->get($columns);
    }

    /**
     * Instance scope targeting all of its nested children which are between the
     * root and the leaf nodes (middle branch).
     *
     * @return Builder<Node>
     */
    public function trunks(): Builder
    {
        $grammar = $this->getConnection()
            ->getQueryGrammar();

        $rgtCol = $grammar->wrap($this->getQualifiedRightColumnName());
        $lftCol = $grammar->wrap($this->getQualifiedLeftColumnName());

        return $this->descendants()
            ->whereNotNull($this->getQualifiedParentColumnName())
            ->where($this->getQualifiedParentColumnName(), '!=', 0)
            ->whereRaw($rgtCol . ' - ' . $lftCol . ' != 1');
    }

    /**
     * Return all of its nested children which are trunks.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getTrunks(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->trunks()
            ->get($columns);
    }

    /**
     * Scope targeting itself and all of its nested children.
     *
     * @return Builder<Node>
     */
    public function descendantsAndSelf(): Builder
    {
        return $this->newNestedSetQuery()
            ->where($this->getLeftColumnName(), '>=', $this->getLeft())
            ->where($this->getLeftColumnName(), '<', $this->getRight());
    }

    /**
     * Retrieve all nested children an self.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getDescendantsAndSelf(
        array $columns = ['*'],
        ?int $depthLimit = null
    ): \Illuminate\Database\Eloquent\Collection {
        return $this->descendantsAndSelf()
            ->limitDepth($depthLimit)
            ->get($columns);
    }

    /**
     * Retrieve all other nodes at the same depth,
     *
     * @return Builder<Node>
     */
    public function getOthersAtSameDepth(): Builder
    {
        return $this->newNestedSetQuery()
            ->where($this->getDepthColumnName(), '=', $this->getDepth())
            ->withoutSelf();
    }

    /**
     * Set of all children & nested children.
     *
     * @return Builder<Node>
     */
    public function descendants(): Builder
    {
        return $this->descendantsAndSelf()
            ->withoutSelf();
    }

    /**
     * Retrieve all of its children & nested children.
     *
     * @param  array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getDescendants(
        array $columns = ['*'],
        ?int $depthLimit = null
    ): \Illuminate\Database\Eloquent\Collection {
        return $this->descendants()
            ->limitDepth($depthLimit)
            ->get($columns);
    }

    /**
     * Set of "immediate" descendants (aka children), alias for the children relation.
     *
     * @return HasMany<Node>
     */
    public function immediateDescendants(): HasMany
    {
        return $this->children();
    }

    /**
     * Retrive all of its "immediate" descendants.
     *
     * @param array<string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int,Node>
     */
    public function getImmediateDescendants(array $columns = ['*']): \Illuminate\Database\Eloquent\Collection
    {
        return $this->children()
            ->get($columns);
    }

    /**
     * Returns the level of this node in the tree.
     * Root level is 0.
     */
    public function getLevel(): int
    {
        if ($this->getParentId() === null) {
            return 0;
        }

        return $this->computeLevel();
    }

    /**
     * Returns true if node is a descendant.
     */
    public function isDescendantOf(self $other): bool
    {
        return $this->getLeft() > $other->getLeft() &&
            $this->getLeft() < $other->getRight() &&
            $this->inSameScope($other)
        ;
    }

    /**
     * Returns true if node is self or a descendant.
     */
    public function isSelfOrDescendantOf(self $other): bool
    {
        return $this->getLeft() >= $other->getLeft() &&
            $this->getLeft() < $other->getRight() &&
            $this->inSameScope($other)
        ;
    }

    /**
     * Returns true if node is an ancestor.
     */
    public function isAncestorOf(self $other): bool
    {
        return $this->getLeft() < $other->getLeft() &&
            $this->getRight() > $other->getLeft() &&
            $this->inSameScope($other)
        ;
    }

    /**
     * Returns true if node is self or an ancestor.
     */
    public function isSelfOrAncestorOf(self $other): bool
    {
        return $this->getLeft() <= $other->getLeft() &&
            $this->getRight() > $other->getLeft() &&
            $this->inSameScope($other)
        ;
    }

    /**
     * Returns the first sibling to the left.
     */
    public function getLeftSibling(): ?self
    {
        return $this->siblings()
            ->where($this->getLeftColumnName(), '<', $this->getLeft())
            ->orderBy($this->getOrderColumnName(), 'desc')
            ->get()
            ->last();
    }

    /**
     * Returns the first sibling to the right.
     */
    public function getRightSibling(): ?self
    {
        return $this->siblings()
            ->where($this->getLeftColumnName(), '>', $this->getLeft())
            ->first();
    }

    /**
     * Find the left sibling and move to left of it.
     */
    public function moveLeft(): self
    {
        return $this->moveToLeftOf($this->getLeftSibling());
    }

    /**
     * Find the right sibling and move to the right of it.
     */
    public function moveRight(): self
    {
        return $this->moveToRightOf($this->getRightSibling());
    }

    /**
     * Move to the node to the left of ...
     */
    public function moveToLeftOf(?self $node): self
    {
        return $this->moveTo($node, 'left');
    }

    /**
     * Move to the node to the right of ...
     */
    public function moveToRightOf(?self $node): self
    {
        return $this->moveTo($node, 'right');
    }

    /**
     * Alias for moveToRightOf.
     */
    public function makeNextSiblingOf(?self $node): self
    {
        return $this->moveToRightOf($node);
    }

    /**
     * Alias for moveToRightOf.
     */
    public function makeSiblingOf(?self $node): self
    {
        return $this->moveToRightOf($node);
    }

    /**
     * Alias for moveToLeftOf.
     */
    public function makePreviousSiblingOf(?self $node): self
    {
        return $this->moveToLeftOf($node);
    }

    /**
     * Make the node a child of ...
     */
    public function makeChildOf(null|self|int|string $node): self
    {
        return $this->moveTo($node, 'child');
    }

    /**
     * Make the node the first child of ...
     */
    public function makeFirstChildOf(self $node): self
    {
        if ($node->children()->count() === 0) {
            return $this->makeChildOf($node);
        }

        return $this->moveToLeftOf($node->children()->first());
    }

    /**
     * Make the node the last child of ...
     */
    public function makeLastChildOf(self $node): self
    {
        return $this->makeChildOf($node);
    }

    /**
     * Make current node a root node.
     */
    public function makeRoot(): self
    {
        return $this->moveTo($this, 'root');
    }

    /**
     * Equals?
     */
    public function equals(self $node): bool
    {
        return $this === $node;
    }

    /**
     * Checkes if the given node is in the same scope as the current one.
     */
    public function inSameScope(self $other): bool
    {
        foreach ($this->getScopedColumns() as $fld) {
            if ($this->{$fld} !== $other->{$fld}) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks wether the given node is a descendant of itself. Basically, whether
     * its in the subtree defined by the left and right indices.
     */
    public function insideSubtree(self $node): bool
    {
        return $this->getLeft() >= $node->getLeft() &&
            $this->getLeft() <= $node->getRight() &&
            $this->getRight() >= $node->getLeft() &&
            $this->getRight() <= $node->getRight()
        ;
    }

    /**
     * Sets default values for left and right fields.
     */
    public function setDefaultLeftAndRight(): void
    {
        $withHighestRight = $this->newNestedSetQuery()
            ->reOrderBy($this->getRightColumnName(), 'desc')
            ->take(1)
            ->sharedLock()
            ->first();

        $maxRgt = 0;
        if ($withHighestRight !== null) {
            $maxRgt = $withHighestRight->getRight();
        }

        $this->setAttribute($this->getLeftColumnName(), $maxRgt + 1);
        $this->setAttribute($this->getRightColumnName(), $maxRgt + 2);
    }

    /**
     * Store the parent_id if the attribute is modified so as we are able to move
     * the node to this new parent after saving.
     */
    public function storeNewParent(): void
    {
        if ($this->isDirty($this->getParentColumnName()) && ($this->exists || ! $this->isRoot())) {
            static::$moveToNewParentId = $this->getParentId();
        } else {
            static::$moveToNewParentId = false;
        }
    }

    /**
     * Move to the new parent if appropiate.
     */
    public function moveToNewParent(): void
    {
        $pid = static::$moveToNewParentId;

        if ($pid === null || $pid === 0) {
            $this->makeRoot();
        } elseif ($pid !== false) {
            $this->makeChildOf($pid);
        }
    }

    /**
     * Sets the depth attribute.
     */
    public function setDepth(): self
    {
        $self = $this;

        $this->getConnection()
            ->transaction(function () use ($self) {
                $self->reload();
                $level = $self->getLevel();
                $self->newNestedSetQuery()
                    ->where($self->getKeyName(), '=', $self->getKey())
                    ->update([
                        $self->getDepthColumnName() => $level,
                    ]);
                $self->setAttribute($self->getDepthColumnName(), $level);
            });

        return $this;
    }

    /**
     * Sets the depth attribute for the current node and all of its descendants.
     */
    public function setDepthWithSubtree(): self
    {
        $self = $this;

        $this->getConnection()
            ->transaction(function () use ($self) {
                $self->reload();

                $self->descendantsAndSelf()
                    ->select($self->getKeyName())
                    ->lockForUpdate()
                    ->get();

                $oldDepth = $self->getDepth() !== null ? $self->getDepth() : 0;
                $newDepth = $self->getLevel();

                $self->newNestedSetQuery()
                    ->where($self->getKeyName(), '=', $self->getKey())
                    ->update([
                        $self->getDepthColumnName() => $newDepth,
                    ]);
                $self->setAttribute($self->getDepthColumnName(), $newDepth);

                $diff = $newDepth - $oldDepth;
                if (! $self->isLeaf() && $diff !== 0) {
                    $self->descendants()
                        ->increment($self->getDepthColumnName(), $diff);
                }
            });

        return $this;
    }

    /**
     * Prunes a branch off the tree, shifting all the elements on the right
     * back to the left so the counts work.
     *
     * @return void;
     */
    public function destroyDescendants(): void
    {
        // @TODO REMOVE?
        //        if ($this->getRight() === null || $this->getLeft() === null) {
        //            return;
        //        }

        $self = $this;
        $this->getConnection()
            ->transaction(function () use ($self) {
                $self->reload();

                $lftCol = $self->getLeftColumnName();
                $rgtCol = $self->getRightColumnName();
                $lft = $self->getLeft();
                $rgt = $self->getRight();

                // Apply a lock to the rows which fall past the deletion point
                $self->newNestedSetQuery()
                    ->where($lftCol, '>=', $lft)
                    ->select($self->getKeyName())
                    ->lockForUpdate()
                    ->get();

                // Prune children
                $self->newNestedSetQuery()
                    ->where($lftCol, '>', $lft)
                    ->where($rgtCol, '<', $rgt)
                    ->delete();

                // Update left and right indexes for the remaining nodes
                $diff = $rgt - $lft + 1;

                $self->newNestedSetQuery()
                    ->where($lftCol, '>', $rgt)
                    ->decrement($lftCol, $diff);
                $self->newNestedSetQuery()
                    ->where($rgtCol, '>', $rgt)
                    ->decrement($rgtCol, $diff);
            });
    }

    /**
     * "Makes room" for the the current node between its siblings.
     */
    public function shiftSiblingsForRestore(): void
    {
        // @TODO REMOVE?
        //        if ($this->getRight() === null || $this->getLeft() === null) {
        //            return;
        //        }

        $self = $this;

        $this->getConnection()
            ->transaction(function () use ($self) {
                $lftCol = $self->getLeftColumnName();
                $rgtCol = $self->getRightColumnName();
                $lft = $self->getLeft();
                $rgt = $self->getRight();

                $diff = $rgt - $lft + 1;

                $self->newNestedSetQuery()
                    ->where($lftCol, '>=', $lft)
                    ->increment($lftCol, $diff);
                $self->newNestedSetQuery()
                    ->where($rgtCol, '>=', $lft)
                    ->increment($rgtCol, $diff);
            });
    }

    /**
     * Restores all of the current node's descendants.
     * @throws \Throwable
     */
    public function restoreDescendants(): void
    {
        // @TODO REMOVE?
        //        if ($this->getRight() === null || $this->getLeft() === null) {
        //            return;
        //        }

        $self = $this;

        $this->getConnection()
            ->transaction(function () use ($self) {
                /** @phpstan-ignore-next-line  */
                $self->newNestedSetQuery()
                    ->withTrashed()
                    ->where($self->getLeftColumnName(), '>', $self->getLeft())
                    ->where($self->getRightColumnName(), '<', $self->getRight())
                    ->update([
                        /** @phpstan-ignore-next-line  */
                        $self->getDeletedAtColumn() => null,
                        $self->getUpdatedAtColumn() => $self->{$self->getUpdatedAtColumn()},
                    ]);
            });
    }

    /**
     * Return an key-value array indicating the node's depth with $separator.
     *
     * @return array<string>
     */
    public static function getNestedList(
        string $column,
        ?string $key = null,
        string $separator = ' ',
        string $symbol = ''
    ): array {
        $instance = new static();

        $key = $key ?: $instance->getKeyName();
        $depthColumn = $instance->getDepthColumnName();

        /** @var array<int,Node> $nodes */
        $nodes = $instance->newNestedSetQuery()
            ->get()
            ->toArray();

        /** @var array<string> $keys */
        $keys = array_map(function ($node) use ($key) {
            return $node[$key];
        }, $nodes);
        return array_combine($keys, array_map(function ($node) use ($separator, $depthColumn, $column, $symbol) {
            /** @var int $depth */
            $depth = $node[$depthColumn];
            return str_repeat($separator, $depth) . $symbol . $node[$column];
        }, $nodes));
    }

    /**
     * Maps the provided tree structure into the database using the current node
     * as the parent. The provided tree structure will be inserted/updated as the
     * descendancy subtree of the current node instance.
     *
     * @param  array<int,Node>|Arrayable<int,Node>  $nodeList
     */
    public function makeTree(array|Arrayable $nodeList): bool
    {
        $mapper = new SetMapper($this);
        return $mapper->map($nodeList);
    }

    /**
     * Main move method. Here we handle all node movements with the corresponding
     * lft/rgt index updates.
     *
     * @param Node $target
     */
    protected function moveTo(self|int|string|null $target, string $position): self
    {
        return Move::to($this, $target, $position);
    }

    /**
     * Compute current node level. If could not move past ourseleves return
     * our ancestor count, otherwhise get the first parent level + the computed
     * nesting.
     */
    protected function computeLevel(): int
    {
        /** @var Node $node */
        /** @var int $nesting */
        list($node, $nesting) = $this->determineDepth($this);

        if ($node->equals($this)) {
            return $this->ancestors()
                ->count();
        }

        return $node->getLevel() + $nesting;
    }

    /**
     * Return an array with the last node we could reach and its nesting level.
     *
     * @return  array<Node|int>
     */
    protected function determineDepth(self $node, int $nesting = 0): array
    {
        // Traverse back up the ancestry chain and add to the nesting level count
        while ($parent = $node->parent()->first()) {
            $nesting++;
            $node = $parent;
        }

        return [$node, $nesting];
    }
}

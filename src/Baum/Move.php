<?php

declare(strict_types=1);

namespace Baum;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Move.
 */
class Move
{
    /**
     * Destination node.
     */
    protected ?Node $target;

    /**
     * Memoized 1st boundary.
     */
    protected ?int $_bound1 = null;

    /**
     * Memoized 2nd boundary.
     */
    protected ?int $_bound2 = null;

    /**
     * Memoized boundaries array.
     *
     * @var array<int,int>|null
     */
    protected ?array $_boundaries = null;

    /**
     * The event dispatcher instance.
     */
    protected static Dispatcher $dispatcher;

    /**
     * Create a new Move class instance.
     */
    final public function __construct(
        protected Node $node,
        Node|int|string|null $target,
        protected string $position
    ) {
        $this->target = $this->resolveNode($target);
        if ($dispatcher = $node->getEventDispatcher()) {
            $this->setEventDispatcher($dispatcher);
        }
    }

    /**
     * Easy static accessor for performing a move operation.
     *
     * @throws \Throwable
     */
    public static function to(Node $node, Node|int|string|null $target, string $position): Node
    {
        $instance = new static($node, $target, $position);
        return $instance->perform();
    }

    /**
     * Perform the move operation.
     *
     * @throws \Throwable
     */
    public function perform(): Node
    {
        $this->guardAgainstImpossibleMove();

        if ($this->fireMoveEvent('moving') === false) {
            return $this->node;
        }

        if ($this->hasChange()) {
            $self = $this;
            $this->node->getConnection()
                ->transaction(function () use ($self) {
                    $self->updateStructure();
                });

            $this->target?->reload();
            $this->node->setDepthWithSubtree();
            $this->node->reload();
        }

        $this->fireMoveEvent('moved', false);
        return $this->node;
    }

    /**
     * Runs the SQL query associated with the update of the indexes affected
     * by the move operation.
     */
    public function updateStructure(): int
    {
        list($a, $b, $c, $d) = $this->boundaries();

        // select the rows between the leftmost & the rightmost boundaries and apply a lock
        $this->applyLockBetween($a, $d);

        $connection = $this->node->getConnection();
        $grammar = $connection->getQueryGrammar();

        /** @var string|int $key */
        $key = $this->node->getKey();
        $currentId = $this->quoteIdentifier($key);
        /** @var string|int $parentId */
        $parentId = $this->parentId();
        $parentId = $this->quoteIdentifier($parentId);

        $leftColumn = $this->node->getLeftColumnName();
        $rightColumn = $this->node->getRightColumnName();
        $parentColumn = $this->node->getParentColumnName();

        $wrappedLeft = $grammar->wrap($leftColumn);
        $wrappedRight = $grammar->wrap($rightColumn);
        $wrappedParent = $grammar->wrap($parentColumn);
        $wrappedId = $grammar->wrap($this->node->getKeyName());

        $lftSql = "CASE
      WHEN {$wrappedLeft} BETWEEN {$a} AND {$b} THEN {$wrappedLeft} + {$d} - {$b}
      WHEN {$wrappedLeft} BETWEEN {$c} AND {$d} THEN {$wrappedLeft} + {$a} - {$c}
      ELSE {$wrappedLeft} END";

        $rgtSql = "CASE
      WHEN {$wrappedRight} BETWEEN {$a} AND {$b} THEN {$wrappedRight} + {$d} - {$b}
      WHEN {$wrappedRight} BETWEEN {$c} AND {$d} THEN {$wrappedRight} + {$a} - {$c}
      ELSE {$wrappedRight} END";

        $parentSql = "CASE
      WHEN {$wrappedId} = {$currentId} THEN {$parentId}
      ELSE {$wrappedParent} END";

        $updateConditions = [
            $leftColumn => $connection->raw($lftSql),
            $rightColumn => $connection->raw($rgtSql),
            $parentColumn => $connection->raw($parentSql),
        ];

        if ($this->node->timestamps) {
            $updateConditions[$this->node->getUpdatedAtColumn()] = $this->node->freshTimestamp();
        }

        return $this->node
            ->newNestedSetQuery()
            ->where(function ($query) use ($leftColumn, $rightColumn, $a, $d) {
                $query->whereBetween($leftColumn, [$a, $d])
                    ->orWhereBetween($rightColumn, [$a, $d]);
            })
            ->update($updateConditions);
    }

    /**
     * Resolves suplied node. Basically returns the node unchanged if
     * supplied parameter is an instance of \Baum\Node. Otherwise it will try
     * to find the node in the database.
     */
    protected function resolveNode(Node|int|string|null $node): ?Node
    {
        if ($node instanceof Node) {
            /** @var Node $node */
            $node = $node->reload();
            return $node;
        }

        return $this->node->newNestedSetQuery()
            ->find($node);
    }

    /**
     * Check wether the current move is possible and if not, rais an exception.
     */
    protected function guardAgainstImpossibleMove(): void
    {
        if (! $this->node->exists) {
            throw new MoveNotPossibleException('A new node cannot be moved.');
        }

        if (! in_array($this->position, ['child', 'left', 'right', 'root'], true)) {
            throw new MoveNotPossibleException(
                "Position should be one of ['child', 'left', 'right'] but is {$this->position}."
            );
        }

        if (! $this->promotingToRoot()) {
            if ($this->target === null) {
                if ($this->position === 'left' || $this->position === 'right') {
                    throw new MoveNotPossibleException(
                        "Could not resolve target node. This node cannot move any further to the {$this->position}."
                    );
                }
                throw new MoveNotPossibleException('Could not resolve target node.');
            }

            if ($this->node->equals($this->target)) {
                throw new MoveNotPossibleException('A node cannot be moved to itself.');
            }

            if ($this->target->insideSubtree($this->node)) {
                throw new MoveNotPossibleException(
                    'A node cannot be moved to a descendant of itself (inside moved tree).'
                );
            }

            if (! $this->node->inSameScope($this->target)) {
                throw new MoveNotPossibleException('A node cannot be moved to a different scope.');
            }
        }
    }

    /**
     * Computes the boundary.
     */
    protected function bound1(): int
    {
        if ($this->_bound1 !== null) {
            return $this->_bound1;
        }

        switch ($this->position) {
            case 'child':
                $this->_bound1 = $this->target?->getRight();
                break;

            case 'left':
                $this->_bound1 = $this->target?->getLeft();
                break;

            case 'right':
                $this->_bound1 = $this->target?->getRight() + 1;
                break;

            case 'root':
                /** @var int $value */
                $value = $this->node->newNestedSetQuery()
                    ->max($this->node->getRightColumnName()) + 1;
                $this->_bound1 = $value;
                break;
        }

        $this->_bound1 = (($this->_bound1 > $this->node->getRight()) ? $this->_bound1 - 1 : $this->_bound1);
        if (! is_int($this->_bound1)) {
            throw new \RuntimeException('Method must return integer');
        }

        return $this->_bound1;
    }

    /**
     * Computes the other boundary.
     * TODO: Maybe find a better name for this... ¿?
     */
    protected function bound2(): int
    {
        if ($this->_bound2 === null) {
            $this->_bound2 = (($this->bound1() > $this->node->getRight()) ? $this->node->getRight() + 1 : $this->node->getLeft() - 1);
        }

        return $this->_bound2;
    }

    /**
     * Computes the boundaries array.
     *
     * @return array<int,int>
     */
    protected function boundaries(): array
    {
        if ($this->_boundaries !== null) {
            return $this->_boundaries;
        }

        // we have defined the boundaries of two non-overlapping intervals,
        // so sorting puts both the intervals and their boundaries in order
        $this->_boundaries = [$this->node->getLeft(), $this->node->getRight(), $this->bound1(), $this->bound2()];
        sort($this->_boundaries);

        return $this->_boundaries;
    }

    /**
     * Computes the new parent id for the node being moved.
     */
    protected function parentId(): mixed
    {
        return match ($this->position) {
            'root' => null,
            'child' => $this->target?->getKey(),
            default => $this->target?->getParentId(),
        };
    }

    /**
     * Check wether there should be changes in the downward tree structure.
     */
    protected function hasChange(): bool
    {
        return ! ($this->bound1() === $this->node->getRight() || $this->bound1() === $this->node->getLeft());
    }

    /**
     * Check if we are promoting the provided instance to a root node.
     */
    protected function promotingToRoot(): bool
    {
        return $this->position === 'root';
    }

    /**
     * Get the event dispatcher instance.
     */
    public static function getEventDispatcher(): Dispatcher
    {
        return static::$dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     */
    public static function setEventDispatcher(Dispatcher $dispatcher): void
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Fire the given move event for the model.
     */
    protected function fireMoveEvent(string $event, bool $halt = true): mixed
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        // Basically the same as \Illuminate\Database\Eloquent\Model->fireModelEvent
        // but we relay the event into the node instance.
        $event = "eloquent.{$event}: " . get_class($this->node);

        $method = $halt ? 'until' : 'dispatch';
        return static::$dispatcher->{$method}($event, $this->node);
    }

    /**
     * Quotes an identifier for being used in a database query.
     */
    protected function quoteIdentifier(string|int|null $value): string|false
    {
        if ($value === null) {
            return 'NULL';
        }

        $connection = $this->node->getConnection();
        $pdo = $connection->getPdo();
        return $pdo->quote((string) $value);
    }

    /**
     * Applies a lock to the rows between the supplied index boundaries.
     */
    protected function applyLockBetween(int $lft, int $rgt): void
    {
        $builder = $this->node->newQuery()
            ->where($this->node->getLeftColumnName(), '>=', $lft)
            ->where($this->node->getRightColumnName(), '<=', $rgt);

        if ($this->node->isScoped()) {
            foreach ($this->node->getScopedColumns() as $scopeFld) {
                $builder->where($scopeFld, '=', $this->node->{$scopeFld});
            }
        }

        $builder->select($this->node->getKeyName())
            ->lockForUpdate()
            ->get();
    }
}

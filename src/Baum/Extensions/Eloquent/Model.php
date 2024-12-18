<?php

declare(strict_types=1);

namespace Baum\Extensions\Eloquent;

use Baum\Extensions\Query\Builder as QueryBuilder;
use Baum\Node;
use Closure;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** @phpstan-consistent-constructor */
abstract class Model extends BaseModel
{
    /**
     * Reloads the model from the database.
     *
     * @throws ModelNotFoundException
     */
    public function reload(): self
    {
        /** @phpstan-ignore-next-line  */
        if ($this->exists || ($this->areSoftDeletesEnabled() && $this->trashed())) {
            $fresh = $this->getFreshInstance();

            if ($fresh === null) {
                throw (new ModelNotFoundException())
                    ->setModel(static::class);
            }

            $this->setRawAttributes($fresh->getAttributes(), true);

            $this->setRelations($fresh->getRelations());

            $this->exists = $fresh->exists;
        } else {
            // Revert changes if model is not persisted
            $this->attributes = $this->original;
        }

        return $this;
    }

    /**
     * Get the observable event names.
     *
     * @return array<int,string>
     */
    public function getObservableEvents(): array
    {
        return array_merge(['moving', 'moved'], parent::getObservableEvents());
    }

    /**
     * Register a moving model event with the dispatcher.
     */
    public static function moving(Closure|string $callback): void
    {
        static::registerModelEvent('moving', $callback);
    }

    /**
     * Register a moved model event with the dispatcher.
     */
    public static function moved(Closure|string $callback): void
    {
        static::registerModelEvent('moved', $callback);
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return QueryBuilder<BaseModel>
     */
    protected function newBaseQueryBuilder(): QueryBuilder
    {
        $conn = $this->getConnection();

        $grammar = $conn->getQueryGrammar();

        return new QueryBuilder($conn, $grammar, $conn->getPostProcessor());
    }

    /**
     * Returns a fresh instance from the database.
     */
    protected function getFreshInstance(): Node|null
    {
        if ($this->areSoftDeletesEnabled()) {
            /** @phpstan-ignore-next-line  */
            return static::withTrashed()->find($this->getKey());
        }

        /** @phpstan-ignore-next-line  */
        return static::find($this->getKey());
    }

    /**
     * Returns wether soft delete functionality is enabled on the model or not.
     */
    public function areSoftDeletesEnabled(): bool
    {
        // To determine if there's a global soft delete scope defined we must
        // first determine if there are any, to workaround a non-existent key error.
        $globalScopes = $this->getGlobalScopes();

        if (count($globalScopes) === 0) {
            return false;
        }

        // Now that we're sure that the calling class has some kind of global scope
        // we check for the SoftDeletingScope existance
        return static::hasGlobalScope(new SoftDeletingScope());
    }

    /**
     * Static method which returns wether soft delete functionality is enabled
     * on the model.
     */
    public static function softDeletesEnabled(): bool
    {
        /** @phpstan-ignore-next-line  */
        return (new static())
            ->areSoftDeletesEnabled();
    }
}

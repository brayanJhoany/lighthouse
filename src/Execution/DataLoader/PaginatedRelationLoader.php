<?php

namespace Nuwave\Lighthouse\Execution\DataLoader;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Nuwave\Lighthouse\Pagination\PaginationArgs;
use ReflectionClass;
use ReflectionMethod;

class PaginatedRelationLoader implements RelationLoader
{
    /**
     * @var \Closure
     */
    protected $decorateBuilder;

    /**
     * @var \Nuwave\Lighthouse\Pagination\PaginationArgs
     */
    protected $paginationArgs;

    public function __construct(Closure $decorateBuilder, PaginationArgs $paginationArgs)
    {
        $this->decorateBuilder = $decorateBuilder;
        $this->paginationArgs = $paginationArgs;
    }

    public function load(EloquentCollection $parents, string $relationName): void
    {
        RelationCountLoader::loadCount($parents, [$relationName => $this->decorateBuilder]);

        $relatedModels = $this->loadRelatedModels($parents, $relationName);

        $this->hydratePivotRelation($parents, $relationName, $relatedModels);
        $this->loadDefaultWith($relatedModels);
        $this->associateRelationModels($parents, $relationName, $relatedModels);
        $this->convertRelationToPaginator($parents, $relationName);
    }

    public function extract(Model $model, string $relationName)
    {
        return $model->getRelation($relationName);
    }

    protected function loadRelatedModels(EloquentCollection $parents, string $relationName): EloquentCollection
    {
        $relations = $parents
            ->toBase()
            ->map(function (Model $model) use ($parents, $relationName): Relation {
                $relation = $this->relationInstance($parents, $relationName);

                $relation->addEagerConstraints([$model]);

                ($this->decorateBuilder)($relation, $model);

                if (method_exists($relation, 'shouldSelect')) {
                    $shouldSelect = new ReflectionMethod(get_class($relation), 'shouldSelect');
                    $shouldSelect->setAccessible(true);
                    $select = $shouldSelect->invoke($relation, ['*']);

                    // @phpstan-ignore-next-line Relation&Builder mixin not recognized
                    $relation->addSelect($select);
                } elseif (method_exists($relation, 'getSelectColumns')) {
                    $getSelectColumns = new ReflectionMethod(get_class($relation), 'getSelectColumns');
                    $getSelectColumns->setAccessible(true);
                    $select = $getSelectColumns->invoke($relation, ['*']);

                    // @phpstan-ignore-next-line Relation&Builder mixin not recognized
                    $relation->addSelect($select);
                }

                $relation->initRelation([$model], $relationName);

                /** @var \Illuminate\Database\Eloquent\Relations\Relation&\Illuminate\Database\Eloquent\Builder $relation */
                return $relation->forPage($this->paginationArgs->page, $this->paginationArgs->first);
            });

        // Merge all the relation queries into a single query with UNION ALL.

        /**
         * Use the first query as the initial starting point.
         *
         * We can assume this to be non-null because only non-empty lists of parents
         * are passed into this loader.
         *
         * @var \Illuminate\Database\Eloquent\Relations\Relation $firstRelation
         */
        $firstRelation = $relations->shift();

        // We have to make sure to use ->getQuery() in order to respect
        // model scopes, such as soft deletes
        $mergedRelationQuery = $relations->reduce(
            static function (EloquentBuilder $builder, Relation $relation): EloquentBuilder {
                return $builder->unionAll(
                    // @phpstan-ignore-next-line Laravel is not that strictly typed
                    $relation->getQuery()
                );
            },
            $firstRelation->getQuery()
        );

        return $mergedRelationQuery->get();
    }

    /**
     * Use the underlying model to instantiate a relation by name.
     */
    protected function relationInstance(EloquentCollection $parents, string $relationName): Relation
    {
        return $this
            ->newModelQuery($parents)
            ->getRelation($relationName);
    }

    /**
     * Return a fresh instance of a query builder for the underlying model.
     */
    protected function newModelQuery(EloquentCollection $parents): EloquentBuilder
    {
        /** @var \Illuminate\Database\Eloquent\Model $anyModelInstance */
        $anyModelInstance = $parents->first();

        /** @var \Illuminate\Database\Eloquent\Builder $newModelQuery */
        $newModelQuery = $anyModelInstance->newModelQuery();

        return $newModelQuery;
    }

    /**
     * Ensure the pivot relation is hydrated too, if it exists.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<\Illuminate\Database\Eloquent\Model>  $relatedModels
     */
    protected function hydratePivotRelation(EloquentCollection $parents, string $relationName, EloquentCollection $relatedModels): void
    {
        $relation = $this->relationInstance($parents, $relationName);

        if ($relatedModels->isNotEmpty() && method_exists($relation, 'hydratePivotRelation')) {
            $hydrationMethod = new ReflectionMethod(get_class($relation), 'hydratePivotRelation');
            $hydrationMethod->setAccessible(true);
            $hydrationMethod->invoke($relation, $relatedModels->all());
        }
    }

    protected function loadDefaultWith(EloquentCollection $collection): void
    {
        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $collection->first();
        if ($model === null) {
            return;
        }

        $reflection = new ReflectionClass($model);
        $withProperty = $reflection->getProperty('with');
        $withProperty->setAccessible(true);

        $unloadedWiths = array_filter(
            (array) $withProperty->getValue($model),
            static function (string $relation) use ($model): bool {
                return ! $model->relationLoaded($relation);
            }
        );

        if (count($unloadedWiths) > 0) {
            $collection->load($unloadedWiths);
        }
    }

    /**
     * Associate the collection of all fetched relationModels back with their parents.
     */
    protected function associateRelationModels(EloquentCollection $parents, string $relationName, EloquentCollection $relatedModels): void
    {
        $this
            ->relationInstance($parents, $relationName)
            ->match(
                $parents->all(),
                $relatedModels,
                $relationName
            );
    }

    protected function convertRelationToPaginator(EloquentCollection $parents, string $relationName): void
    {
        foreach ($parents as $model) {
            $total = RelationCountLoader::extractCount($model, $relationName);

            $paginator = app()->makeWith(
                LengthAwarePaginator::class,
                [
                    'items' => $model->getRelation($relationName),
                    'total' => $total,
                    'perPage' => $this->paginationArgs->first,
                    'currentPage' => $this->paginationArgs->page,
                    'options' => [],
                ]
            );

            $model->setRelation($relationName, $paginator);
        }
    }
}

<?php

declare(strict_types=1);

namespace Baum\Extensions\Eloquent;

use Baum\Node;
use Illuminate\Database\Eloquent\Collection as BaseCollection;

/**
 * @extends BaseCollection<int,Node>
 */
class Collection extends BaseCollection
{
    /**
     * @return BaseCollection<int,\Illuminate\Database\Eloquent\Model>
     */
    public function toHierarchy(): BaseCollection
    {
        $dict = $this->getDictionary();

        return new BaseCollection($this->hierarchical($dict));
    }

    /**
     * @return BaseCollection<int,\Illuminate\Database\Eloquent\Model>
     */
    public function toSortedHierarchy(): BaseCollection
    {
        $dict = $this->getDictionary();

        // Enforce sorting by $orderColumn setting in Baum\Node instance
        uasort($dict, function ($a, $b) {
            return ($a->getOrder() >= $b->getOrder()) ? 1 : -1;
        });

        return new BaseCollection($this->hierarchical($dict));
    }

    /**
     * @param array<int,Node> $result
     * @return array<int,Node>
     */
    protected function hierarchical(array $result): array
    {
        foreach ($result as $node) {
            $node->setRelation('children', new BaseCollection());
        }

        $nestedKeys = [];

        foreach ($result as $node) {
            $parentKey = $node->getParentId();

            if ($parentKey !== null && array_key_exists($parentKey, $result)) {
                /** @phpstan-ignore-next-line  */
                $result[$parentKey]->children[] = $node;
                $nestedKeys[] = $node->getKey();
            }
        }

        foreach ($nestedKeys as $key) {
            unset($result[$key]);
        }

        return $result;
    }
}

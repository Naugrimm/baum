<?php

declare(strict_types=1);

use Baum\Node;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cluster extends Node
{
    protected $table = 'clusters';

    public $incrementing = false;

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($cluster) {
            $cluster->ensureUuid();
        });
    }

    public function ensureUuid()
    {
        if ($this->getAttribute($this->getKeyName()) === null) {
            $this->setAttribute($this->getKeyName(), $this->generateUuid());
        }

        return $this;
    }

    protected function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

class ScopedCluster extends Cluster
{
    protected $scoped = ['company_id'];
}

class MultiScopedCluster extends Cluster
{
    protected $scoped = ['company_id', 'language'];
}

class OrderedCluster extends Cluster
{
    protected ?string $orderColumn = 'name';
}

class SoftCluster extends Cluster
{
    use SoftDeletes;

    public $timestamps = true;

    protected $dates = ['deleted_at'];
}

<?php

namespace App\Models\Concerns;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait HasCurrentlyActiveSubscriptionScope
{
    #[Scope]
    protected function currentlyActive(Builder $query): void
    {
        $now = now();
        $model = $query->getModel();

        $query
            ->where($model->qualifyColumn('status'), SubscriptionStatus::Active->value)
            ->where($model->qualifyColumn('starts_at'), '<=', $now)
            ->where(function (Builder $query) use ($model, $now): void {
                $query
                    ->whereNull($model->qualifyColumn('ends_at'))
                    ->orWhere($model->qualifyColumn('ends_at'), '>', $now);
            });
    }
}

<?php

namespace App\Models;

use App\Models\Scopes\TsaMonthScope;
use Illuminate\Database\Eloquent\Builder;

/** Bulk writes (update/delete/increment, soft deletes included — they run
 *  through update()) go to the real tsa_shifts table, never TsaMonthScope's
 *  read-only subquery. */
class TsaShiftBuilder extends Builder
{
    public function update(array $values)
    {
        return $this->withoutGlobalScope(TsaMonthScope::class)->parentUpdate($values);
    }

    public function delete()
    {
        $this->withoutGlobalScope(TsaMonthScope::class);

        return parent::delete();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->withoutGlobalScope(TsaMonthScope::class);

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->withoutGlobalScope(TsaMonthScope::class);

        return parent::decrement($column, $amount, $extra);
    }

    private function parentUpdate(array $values)
    {
        return parent::update($values);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One (owner, field, locale) -> text row — see localized_texts'
 * own migration comment for why this is a shared polymorphic table
 * rather than a JSON blob per record or a near-duplicate table per
 * model. Never constructed/queried directly outside App\Models\Concerns\
 * HasLocalizedFields — every model with a localized field goes through
 * that trait's getLocalizedField()/setLocalizedField() instead.
 */
class Translation extends Model
{
    use HasUuids;

    protected $table = 'localized_texts';

    protected $fillable = [
        'id',
        'localizable_type',
        'localizable_id',
        'field',
        'locale',
        'text',
    ];

    public function localizable(): MorphTo
    {
        return $this->morphTo();
    }
}

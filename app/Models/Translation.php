<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One (owner, field, locale) -> text row — see localized_texts'
 * own migration comment for why this is a shared polymorphic table
 * rather than a JSON blob per record or a near-duplicate table per
 * model. Never constructed/queried directly outside App\Models\Concerns\
 * HasLocalizedFields — every model with a localized field goes through
 * that trait's getLocalizedField()/setLocalizedField() instead.
 *
 * @property string $id
 * @property string $localizable_type
 * @property string $localizable_id
 * @property string $field
 * @property string $locale
 * @property string $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|\Eloquent $localizable
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereField($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereLocalizableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereLocalizableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Translation withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Translation extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'localized_texts';

    protected $fillable = [
        'id',
        'localizable_type',
        'localizable_id',
        'field',
        'locale',
        'text',
    ];

    /** @return MorphTo<Model, $this> */
    public function localizable(): MorphTo
    {
        return $this->morphTo();
    }
}

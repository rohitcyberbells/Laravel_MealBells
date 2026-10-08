<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TiffinService extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'contact_phone',
        'deleted_by',
    ];

    public function assignments()
    {
        return $this->hasMany(CompanyTiffinAssignment::class);
    }

    /**
     * Who archived this service. Null for a live one, or where the super admin
     * who archived it has since been removed.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}

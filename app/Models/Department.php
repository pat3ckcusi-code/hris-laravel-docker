<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $table = 'departments';

    protected $primaryKey = 'Dept_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'DeptCode',
        'Dept_name',
        'EmpNo',
        'Designation',
        'parent_dept_id',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_dept_id', 'Dept_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_dept_id', 'Dept_id');
    }
}

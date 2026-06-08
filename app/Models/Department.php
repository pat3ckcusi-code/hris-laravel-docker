<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $Dept_id
 * @property string|null $DeptCode
 * @property string|null $Dept_name
 * @property string|null $EmpNo
 * @property string|null $ao_emp_no
 * @property string|null $Designation
 * @property int|null $parent_dept_id
 * @property-read \App\Models\Department|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Department> $children
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
        'ao_emp_no',
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

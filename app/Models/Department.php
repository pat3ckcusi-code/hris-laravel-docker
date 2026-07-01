<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property int|null $department_head_id
 * @property int|null $admin_officer_id
 * @property string|null $Designation
 * @property int|null $parent_dept_id
 * @property-read Department|null $parent
 * @property-read Collection<int, Department> $children
 * @property-read User|null $departmentHead
 * @property-read User|null $adminOfficer
 *
 * @mixin Builder
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
        'department_head_id',
        'admin_officer_id',
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

    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'department_head_id');
    }

    public function adminOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_officer_id');
    }
}

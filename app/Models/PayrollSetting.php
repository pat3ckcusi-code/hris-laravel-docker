<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class PayrollSetting extends Model
{
    use HasFactory;

    protected $table = 'payroll_settings';

    protected $fillable = [
        'key',
        'value',
    ];
}

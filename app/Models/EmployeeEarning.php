<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'earnings_id',
        'amount',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'recurring' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function earning()
    {
        return $this->belongsTo(Earning::class, 'earnings_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int $user_id
 * @property array|null $section_data
 * @property string|null $status
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Pds extends Model
{
    protected $table = 'user_pds';

    protected $fillable = [
        'user_id',
        'section_data',
        'status',
        'submitted_at',
    ];

   
    protected $casts = [
        'section_data' => 'array',
        'submitted_at' => 'datetime',
    ];

    //encrypt
    public function setSectionDataAttribute($value): void
    {
        $arr = [];

        if (is_array($value)) {
            $arr = $value;
        } elseif (is_string($value)) {
            $decoded = json_decode($value, true);
            $arr = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

       
        foreach ($arr as $key => $section) {
            if (is_array($section)) {
                $arr[$key] = $this->encryptSensitiveFields($section);
            }
        }

       
        $payload = Crypt::encryptString(json_encode($arr));
        $this->attributes['section_data'] = json_encode(['encrypted' => $payload]);
    }

    //decrypt
    public function getSectionDataAttribute($value)
    {
        if (is_null($value) || $value === '') {
            return [];
        }

       
        if (is_array($value)) {
            return $value;
        }

        
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (array_key_exists('encrypted', $decoded) && is_string($decoded['encrypted'])) {
                try {
                    $decrypted = Crypt::decryptString($decoded['encrypted']);
                    $inner = json_decode($decrypted, true);
                    return json_last_error() === JSON_ERROR_NONE ? $inner : [];
                } catch (\Exception $e) {
                    return [];
                }
            }

          
            return $decoded;
        }

       
        try {
            $decrypted = Crypt::decryptString($value);
            $decoded = json_decode($decrypted, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    //  List of nested keys inside `section_data` that should be encrypted individually.
    
    protected $sensitiveSectionKeys = [
        'ssn',
        'tin',
        'bank_account',
        'mother_maiden_name',
        'birthplace',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    
    public function saveSectionData(string $sectionKey, array $sectionData): void
    {
        $currentData = $this->section_data ?? [];

       
        $currentData[$sectionKey] = $this->encryptSensitiveFields($sectionData);

        $this->update(['section_data' => $currentData]);
    }

   
    public function getSectionData(string $sectionKey): array
    {
        $data = $this->section_data[$sectionKey] ?? [];

       
        return is_array($data) ? $this->decryptSensitiveFields($data) : $data;
    }

    //get data
    public function getAllSectionData(): array
    {
        $all = $this->section_data ?? [];

        if (!is_array($all)) {
            return [];
        }

        // Decrypt nested sensitive fields for every section.
        foreach ($all as $key => $section) {
            if (is_array($section)) {
                $all[$key] = $this->decryptSensitiveFields($section);
            }
        }

        return $all;
    }

   
    protected function encryptSensitiveFields(array $section): array
    {
        foreach ($this->sensitiveSectionKeys as $sKey) {
            if (array_key_exists($sKey, $section) && !is_null($section[$sKey])) {
                
                if (is_string($section[$sKey])) {
                    
                    try {
                        Crypt::decryptString($section[$sKey]);
                        
                    } catch (\Exception $e) {
                        $section[$sKey] = Crypt::encryptString($section[$sKey]);
                    }
                } elseif (is_scalar($section[$sKey])) {
                    try {
                        Crypt::decryptString((string)$section[$sKey]);
                    } catch (\Exception $e) {
                        $section[$sKey] = Crypt::encryptString((string)$section[$sKey]);
                    }
                } else {
                    
                    try {
                        Crypt::decryptString(json_encode($section[$sKey]));
                    } catch (\Exception $e) {
                        $section[$sKey] = Crypt::encryptString(json_encode($section[$sKey]));
                    }
                }
            }
        }

        return $section;
    }

    
    protected function decryptSensitiveFields(array $section): array
    {
        foreach ($this->sensitiveSectionKeys as $sKey) {
            if (array_key_exists($sKey, $section) && !is_null($section[$sKey]) && is_string($section[$sKey])) {
                try {
                    $decrypted = Crypt::decryptString($section[$sKey]);
                   
                    $maybeJson = json_decode($decrypted, true);
                    $section[$sKey] = json_last_error() === JSON_ERROR_NONE ? $maybeJson : $decrypted;
                } catch (\Exception $e) {
                    
                }
            }
        }

        return $section;
    }
}

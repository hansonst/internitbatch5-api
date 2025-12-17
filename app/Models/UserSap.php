<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class UserSap extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $connection = 'pgsql_second';
    protected $table = 'users';
    protected $primaryKey = 'user_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'full_name',
        'jabatan',
        'department',
        'email',
        'password',
        'status',
        'id_card'
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    /**
     * Check if user is active
     */
    public function isActive()
    {
        return strtolower($this->status) === 'active';
    }

    /**
     * Get full name attribute
     */
    public function getFullNameAttribute()
    {
        return $this->attributes['full_name'] ?? "{$this->first_name} {$this->last_name}";
    }

    /**
     * Scope to get only active users
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get users by department
     */
    public function scopeByDepartment($query, $department)
    {
        return $query->where('department', $department);
    }
}
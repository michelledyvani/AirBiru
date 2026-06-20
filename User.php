<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * Karena tabel users kamu memakai tgl_daftar,
     * bukan created_at dan updated_at
     */
    public $timestamps = false;

    protected $fillable = [
        'nama',
        'email',
        'no_hp',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
        'tgl_daftar' => 'datetime',
    ];
}
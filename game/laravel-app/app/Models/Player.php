<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $fillable = ['game_id', 'name', 'is_host', 'score', 'last_seen_at'];

    protected $casts = [
        'is_host' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function rounds()
    {
        return $this->belongsToMany(Round::class, 'answers');
    }

    public function answers()
    {
        return $this->hasMany(Answer::class);
    }
}

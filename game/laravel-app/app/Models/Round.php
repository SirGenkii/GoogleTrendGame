<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Round extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'question_id',
        'status',
        'theme',
        'year',
        'semester',
        'articles',
        'correct_order',
        'deadline_at',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'articles' => 'array',
        'correct_order' => 'array',
        'deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function answers()
    {
        return $this->hasMany(Answer::class);
    }
}

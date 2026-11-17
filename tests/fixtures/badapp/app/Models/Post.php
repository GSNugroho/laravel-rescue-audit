<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Post extends Model
{
    protected $guarded = [];
    protected $casts = ['published_at' => 'datetime'];
    public function author() { return $this->belongsTo(User::class); }
    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Comment::class); }
    public function tags() { return $this->belongsToMany(Tag::class); }
}

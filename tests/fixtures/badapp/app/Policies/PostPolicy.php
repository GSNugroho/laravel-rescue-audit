<?php
namespace App\Policies;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
class PostPolicy
{
    public function update(User $user, Model $post): bool { return true; }
}

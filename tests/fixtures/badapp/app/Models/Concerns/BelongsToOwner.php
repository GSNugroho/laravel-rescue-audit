<?php
namespace App\Models\Concerns;
use Illuminate\Database\Eloquent\Model;
trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void {}
}

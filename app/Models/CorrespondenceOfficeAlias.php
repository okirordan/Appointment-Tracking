<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrespondenceOfficeAlias extends Model
{
    protected $fillable = ['name', 'normalized_name', 'kind', 'created_by_user_id'];
}

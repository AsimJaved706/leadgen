<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadList extends Model
{
    protected $fillable = ['name', 'description'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads()
    {
        return $this->belongsToMany(Lead::class, 'lead_list_items')->withTimestamps();
    }
}

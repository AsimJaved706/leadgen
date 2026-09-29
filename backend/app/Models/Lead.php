<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return array_merge(array_fill_keys(['secondary_phones', 'additional_emails', 'categories', 'current_hours', 'weekly_hours', 'social_profiles', 'image_urls', 'action_links', 'additional_details', 'raw_maps_details', 'tags'], 'array'), ['collected_at' => 'datetime', 'enriched_at' => 'datetime', 'average_rating' => 'float']);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lists()
    {
        return $this->belongsToMany(LeadList::class, 'lead_list_items')->withTimestamps();
    }
}

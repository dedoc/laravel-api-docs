<?php

namespace Dedoc\Scramble\Tests\Files;

use Illuminate\Database\Eloquent\Model;

class SamplePostModel extends Model
{
    public $timestamps = true;

    protected $guarded = [];

    protected $table = 'posts';

    protected $with = ['parent', 'children', 'user'];

    protected $casts = [
        'read_time' => 'int',
        'status' => Status::class,
        'settings' => 'array',
    ];

    public function getReadTimeAttribute()
    {
        return 123;
    }

    public function parent()
    {
        return $this->belongsTo(SamplePostModel::class);
    }

    public function children()
    {
        return $this->hasMany(SamplePostModel::class);
    }

    public function user()
    {
        return $this->belongsTo(SampleUserModel::class, 'user_id');
    }
}

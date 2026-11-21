<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One 👍 per member per design (unique in the database).
 *
 * @property int $id
 * @property string $design_id
 * @property string $user_id
 */
class DesignVote extends Model
{
    protected $table = 'marmara_design_votes';

    protected $fillable = ['design_id', 'user_id'];
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Post\ApprovalDecision;
use App\Models\Post;
use App\Models\PostApprovalAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostApprovalAction> */
class PostApprovalActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'request_id' => $this->faker->uuid(),
            'action' => ApprovalDecision::Approved,
            'user_id' => User::factory(),
            'content_version' => substr(hash('sha256', $this->faker->uuid()), 0, 16),
        ];
    }
}

<?php

namespace App\Policies;

use App\Models\Trade;
use App\Models\User;

class TradePolicy
{
    public function view(User $user, Trade $trade): bool
    {
        return (int) ($trade->account?->user_id ?? 0) === (int) $user->id;
    }

    public function update(User $user, Trade $trade): bool
    {
        return $this->view($user, $trade);
    }

    public function delete(User $user, Trade $trade): bool
    {
        return $this->view($user, $trade);
    }
}

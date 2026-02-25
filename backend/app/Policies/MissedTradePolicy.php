<?php

namespace App\Policies;

use App\Models\MissedTrade;
use App\Models\User;

class MissedTradePolicy
{
    public function view(User $user, MissedTrade $missedTrade): bool
    {
        return (int) $missedTrade->user_id === (int) $user->id;
    }

    public function update(User $user, MissedTrade $missedTrade): bool
    {
        return $this->view($user, $missedTrade);
    }

    public function delete(User $user, MissedTrade $missedTrade): bool
    {
        return $this->view($user, $missedTrade);
    }
}

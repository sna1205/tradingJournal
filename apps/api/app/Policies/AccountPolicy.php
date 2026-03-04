<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return (int) $account->user_id === (int) $user->id;
    }

    public function update(User $user, Account $account): bool
    {
        return $this->view($user, $account);
    }

    public function delete(User $user, Account $account): bool
    {
        return $this->view($user, $account);
    }
}

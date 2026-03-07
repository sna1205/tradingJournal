<?php

namespace App\Policies;

use App\Models\Checklist;
use App\Models\User;

class ChecklistPolicy
{
    public function view(User $user, Checklist $checklist): bool
    {
        return (int) $checklist->user_id === (int) $user->id;
    }

    public function update(User $user, Checklist $checklist): bool
    {
        return $this->view($user, $checklist);
    }

    public function delete(User $user, Checklist $checklist): bool
    {
        return $this->view($user, $checklist);
    }
}

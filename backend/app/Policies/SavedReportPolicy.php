<?php

namespace App\Policies;

use App\Models\SavedReport;
use App\Models\User;

class SavedReportPolicy
{
    public function view(User $user, SavedReport $report): bool
    {
        return (int) $report->user_id === (int) $user->id;
    }

    public function update(User $user, SavedReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, SavedReport $report): bool
    {
        return $this->view($user, $report);
    }
}

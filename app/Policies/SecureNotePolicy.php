<?php

namespace App\Policies;

use App\Models\SecureNote;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Log;

class SecureNotePolicy
{
    use HandlesAuthorization, OwnershipTrait;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    // public function viewAny(User $user)
    // {
    //     return false;
    // }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, SecureNote $note)
    {
        $can = $this->isOwnerOf($user, $note);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot view secure note ID #%s', $user->id, $note->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, SecureNote $note)
    {
        $can = $this->isOwnerOf($user, $note);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot update secure note ID #%s', $user->id, $note->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, SecureNote $note)
    {
        $can = $this->isOwnerOf($user, $note);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot delete secure note ID #%s', $user->id, $note->id));
        }

        return $can;
    }
}

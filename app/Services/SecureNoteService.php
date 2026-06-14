<?php

namespace App\Services;

use App\Models\SecureNote;
use App\Models\User;
use Illuminate\Support\Collection;

class SecureNoteService
{
    /**
     * List all secure notes for a user.
     *
     * @param  User  $user  The user to list notes for
     * @return Collection<int, SecureNote>
     */
    public function list(User $user): Collection
    {
        return $user->secureNotes()
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Create a new secure note for a user.
     *
     * @param  User  $user  The user to create the note for
     * @param  array  $data  The note data
     * @return SecureNote
     */
    public function create(User $user, array $data): SecureNote
    {
        return $user->secureNotes()->create($data);
    }

    /**
     * Update an existing secure note.
     *
     * @param  SecureNote  $note  The note to update
     * @param  array  $data  The updated data
     * @return SecureNote
     */
    public function update(SecureNote $note, array $data): SecureNote
    {
        $note->update($data);

        return $note->fresh();
    }

    /**
     * Delete a secure note.
     *
     * @param  SecureNote  $note  The note to delete
     * @return bool
     */
    public function delete(SecureNote $note): bool
    {
        return $note->delete();
    }
}

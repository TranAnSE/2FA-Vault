<?php

namespace App\Enums;

enum PersonalAction: string
{
    case ACCOUNT_CREATED = 'account_created';
    case ACCOUNT_UPDATED = 'account_updated';
    case ACCOUNT_DELETED = 'account_deleted';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case PREFERENCES_CHANGED = 'preferences_changed';
    case VAULT_LOCKED = 'vault_locked';
    case VAULT_UNLOCKED = 'vault_unlocked';
    case BACKUP_EXPORTED = 'backup_exported';
    case BACKUP_IMPORTED = 'backup_imported';
    case ENCRYPTION_SETUP = 'encryption_setup';
    case ENCRYPTION_DISABLED = 'encryption_disabled';
    case NOTE_CREATED = 'note_created';
    case NOTE_UPDATED = 'note_updated';
    case NOTE_DELETED = 'note_deleted';
    case INVITATION_SENT = 'invitation_sent';
}

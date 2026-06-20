<?php

use App\Api\v1\Controllers\FeatureFlagController;
use App\Api\v1\Controllers\GroupController;
use App\Api\v1\Controllers\InvitationController;
use App\Api\v1\Controllers\PersonalActivityController;
use App\Api\v1\Controllers\AccountHealthController;
use App\Api\v1\Controllers\BreachController;
use App\Api\v1\Controllers\SecureNoteController;
use App\Api\v1\Controllers\TagController;
use App\Api\v1\Controllers\UserBackupDestinationController;
use App\Api\v1\Controllers\IconController;
use App\Api\v1\Controllers\QrCodeController;
use App\Api\v1\Controllers\SettingController;
use App\Api\v1\Controllers\TwoFAccountController;
use App\Api\v1\Controllers\UserController;
use App\Api\v1\Controllers\UserManagerController;
use App\Api\v1\Controllers\UserSessionController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\Admin\RateLimitDashboardController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\EmergencyAccessController;
use App\Http\Controllers\VaultController;
use App\Http\Controllers\TeamActivityController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

/**
 * DEPRECATED - Unprotected routes
 */
Route::get('user/name', function () {
    return response()->json(['deprecation' => true], 200, ['Deprecation' => Date::createFromDate(2023, 03, 21)->toRfc7231String()]);
});

/**
 * Routes protected by the api authentication guard
 */
Route::group(['middleware' => ['auth:api-guard', 'enforceMandatoryEncryption']], function () {
    Route::get('user', [UserController::class, 'show'])->name('user.show'); // Returns email address in addition to the username

    // Personal activity routes
    Route::get('user/activity', [PersonalActivityController::class, 'index'])->name('user.activity.index');
    Route::delete('user/activity', [PersonalActivityController::class, 'destroyAll'])->name('user.activity.destroyAll');

    // User sessions routes
    Route::get('user/sessions', [UserSessionController::class, 'index'])->name('user.sessions.index');
    Route::delete('user/sessions/{id}', [UserSessionController::class, 'destroy'])->name('user.sessions.destroy');

    Route::get('user/preferences/{preferenceName}', [UserController::class, 'showPreference'])->name('user.preferences.show');
    Route::get('user/preferences', [UserController::class, 'allPreferences'])->name('user.preferences.all');
    Route::put('user/preferences/{preferenceName}', [UserController::class, 'setPreference'])->name('user.preferences.set');

    Route::delete('twofaccounts', [TwoFAccountController::class, 'batchDestroy'])->name('twofaccounts.batchDestroy');

    // Account health scoring (must precede apiResource to avoid {id} capture)
    Route::get('twofaccounts/health/summary', [AccountHealthController::class, 'summary'])->name('twofaccounts.health.summary');
    Route::get('twofaccounts/{twofaccount}/health', [AccountHealthController::class, 'show'])->where('twofaccount', '[0-9]+')->name('twofaccounts.health.show');

    // Breach monitoring (HIBP). Email check is opt-in gated by the breachMonitoring preference.
    Route::post('breach/check-email', [BreachController::class, 'checkEmail'])->name('breach.checkEmail');
    Route::get('breach/check-service', [BreachController::class, 'checkService'])->name('breach.checkService');
    Route::patch('twofaccounts/withdraw', [TwoFAccountController::class, 'withdraw'])->name('twofaccounts.withdraw');
    Route::post('twofaccounts/reorder', [TwoFAccountController::class, 'reorder'])->name('twofaccounts.reorder');
    Route::post('twofaccounts/migration', [TwoFAccountController::class, 'migrate'])->name('twofaccounts.migrate');
    Route::post('twofaccounts/preview', [TwoFAccountController::class, 'preview'])->name('twofaccounts.preview');
    Route::get('twofaccounts/export', [TwoFAccountController::class, 'export'])->name('twofaccounts.export');
    Route::get('twofaccounts/encrypted', [TwoFAccountController::class, 'encrypted'])->name('twofaccounts.encrypted');
    Route::get('twofaccounts/{twofaccount}/qrcode', [QrCodeController::class, 'show'])->name('twofaccounts.show.qrcode');
    Route::get('twofaccounts/count', [TwoFAccountController::class, 'count'])->name('twofaccounts.count');
    Route::get('twofaccounts/{id}/otp', [TwoFAccountController::class, 'otp'])->where('id', '[0-9]+')->name('twofaccounts.show.otp');
    Route::patch('twofaccounts/{twofaccount}/counter', [TwoFAccountController::class, 'updateCounter'])->name('twofaccounts.update.counter');
    Route::post('twofaccounts/otp', [TwoFAccountController::class, 'otp'])->name('twofaccounts.otp');
    Route::apiResource('twofaccounts', TwoFAccountController::class);

    Route::get('groups/{group}/twofaccounts', [GroupController::class, 'accounts'])->name('groups.show.twofaccounts');
    Route::post('groups/{group}/assign', [GroupController::class, 'assignAccounts'])->name('groups.assign.twofaccounts');
    Route::post('groups/reorder', [GroupController::class, 'reorder'])->name('groups.reorder');
    Route::apiResource('groups', GroupController::class);

    // Tags
    Route::apiResource('tags', TagController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('twofaccounts/{accountId}/tags', [TagController::class, 'syncAccountTags'])->name('twofaccounts.tags.sync');

    // Secure Notes routes
    Route::apiResource('secure-notes', SecureNoteController::class);

    // Auto-backup destination routes
    Route::get('user/backup-destinations', [UserBackupDestinationController::class, 'index'])->name('user.backup-destinations.index');
    Route::post('user/backup-destinations', [UserBackupDestinationController::class, 'store'])->name('user.backup-destinations.store');
    Route::put('user/backup-destinations/{id}', [UserBackupDestinationController::class, 'update'])->name('user.backup-destinations.update');
    Route::delete('user/backup-destinations/{id}', [UserBackupDestinationController::class, 'destroy'])->name('user.backup-destinations.destroy');
    Route::post('user/backup-destinations/{id}/test', [UserBackupDestinationController::class, 'testConnection'])->name('user.backup-destinations.test');

    Route::post('qrcode/decode', [QrCodeController::class, 'decode'])->name('qrcode.decode');

    Route::get('icons/packs', [IconController::class, 'iconPacks'])->name('icons.iconPacks');
    Route::post('icons/default', [IconController::class, 'fetch'])->name('icons.fetch');
    Route::post('icons', [IconController::class, 'upload'])->name('icons.upload');
    Route::delete('icons/{icon}', [IconController::class, 'delete'])->name('icons.delete');

    // Feature flags
    Route::get('features', [FeatureFlagController::class, 'index'])->name('features.index');
    Route::get('features/{feature}', [FeatureFlagController::class, 'show'])->name('features.show');

    // E2EE Encryption routes
    Route::post('encryption/setup', [EncryptionController::class, 'setup'])->name('encryption.setup');
    Route::get('encryption/info', [EncryptionController::class, 'info'])->name('encryption.info');
    Route::get('encryption/salt', [EncryptionController::class, 'getSalt'])->name('encryption.salt');
    Route::get('encryption/status', [EncryptionController::class, 'checkEncryptionStatus'])->name('encryption.status');
    Route::post('encryption/verify', [EncryptionController::class, 'verify'])->name('encryption.verify');
    Route::post('encryption/lock', [EncryptionController::class, 'lock'])->name('encryption.lock');
    Route::delete('encryption/disable', [EncryptionController::class, 'disable'])->name('encryption.disable');

    // Backup routes
    Route::post('backups/export', [\App\Http\Controllers\BackupController::class, 'export'])->name('backups.export');
    Route::post('backups/import', [\App\Http\Controllers\BackupController::class, 'import'])->name('backups.import');
    Route::post('backups/metadata', [\App\Http\Controllers\BackupController::class, 'metadata'])->name('backups.metadata');
    Route::get('backups/info', [\App\Http\Controllers\BackupController::class, 'info'])->name('backups.info');
    
    // Legacy backup routes (backward compatibility)
    Route::match(['get', 'post'], 'backup/export', [\App\Http\Controllers\BackupController::class, 'export'])->name('backup.export');
    Route::post('backup/import', [\App\Http\Controllers\BackupController::class, 'import'])->name('backup.import');
    Route::post('backup/metadata', [\App\Http\Controllers\BackupController::class, 'metadata'])->name('backup.metadata');
    Route::get('backup/info', [\App\Http\Controllers\BackupController::class, 'info'])->name('backup.info');

    // Push notification subscription routes
    Route::post('push/subscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'subscribe'])->name('push.subscribe');
    Route::delete('push/unsubscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::get('push/subscriptions', [\App\Http\Controllers\PushSubscriptionController::class, 'index'])->name('push.subscriptions.index');
    Route::get('push/public-key', [\App\Http\Controllers\PushSubscriptionController::class, 'publicKey'])->name('push.publicKey');
    Route::post('push/test', [\App\Http\Controllers\PushSubscriptionController::class, 'sendTest'])->name('push.test');

    // Teams routes
    Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('teams/{id}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('teams/{id}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('teams/{id}', [TeamController::class, 'destroy'])->name('teams.destroy');
    Route::post('teams/{id}/invite', [TeamController::class, 'invite'])->name('teams.invite');
    Route::post('teams/{id}/invitations', [TeamController::class, 'invite'])->name('teams.invitations.create');
    Route::post('teams/invitations/{token}/accept', [TeamController::class, 'acceptInvitation'])->name('teams.invitations.accept');
    Route::get('teams/{id}/invitations', [TeamController::class, 'invitations'])->name('teams.invitations.index');
    Route::delete('teams/{id}/invitations/{invitationId}', [TeamController::class, 'cancelInvitation'])->name('teams.invitations.cancel');
    Route::post('teams/join', [TeamController::class, 'join'])->name('teams.join');
    Route::post('teams/{id}/leave', [TeamController::class, 'leave'])->name('teams.leave');
    Route::delete('teams/{id}/members/{userId}', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::put('teams/{id}/members/{userId}/role', [TeamController::class, 'updateMemberRole'])->name('teams.members.updateRole');
    Route::get('teams/{id}/shared-accounts', [TeamController::class, 'sharedAccounts'])->name('teams.sharedAccounts.index');
    Route::post('teams/{id}/share', [TeamController::class, 'shareAccount'])->name('teams.shareAccount');
    Route::post('teams/{id}/share-encrypted', [TeamController::class, 'shareEncrypted'])->name('teams.shareEncrypted');
    Route::delete('teams/{id}/share/{accountId}', [TeamController::class, 'unshareAccount'])->name('teams.unshareAccount');
    Route::get('teams/{id}/members/{userId}/public-key', [TeamController::class, 'memberPublicKey'])->name('teams.members.publicKey');
    Route::post('user/public-key', [TeamController::class, 'registerPublicKey'])->name('user.publicKey.register');
    Route::get('teams/{id}/activity', [TeamActivityController::class, 'index'])->name('teams.activity.index');
    Route::get('teams/{id}/activity/export', [TeamActivityController::class, 'export'])->name('teams.activity.export');

    // Webhooks
    Route::get('webhooks/events',          [WebhookController::class, 'availableEvents'])->name('webhooks.events');
    Route::get('webhooks',                 [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('webhooks',                [WebhookController::class, 'store'])->name('webhooks.store');
    Route::put('webhooks/{id}',            [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('webhooks/{id}',         [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('webhooks/{id}/test',      [WebhookController::class, 'test'])->name('webhooks.test');
    Route::get('webhooks/{id}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');

    // Emergency Access
    Route::get('emergency-contacts',                      [EmergencyAccessController::class, 'index'])->name('emergency.index');
    Route::post('emergency-contacts',                     [EmergencyAccessController::class, 'store'])->name('emergency.store');
    Route::delete('emergency-contacts/{id}',              [EmergencyAccessController::class, 'destroy'])->name('emergency.destroy');
    Route::get('emergency-contacts/for-me',               [EmergencyAccessController::class, 'contactsForMe'])->name('emergency.forMe');
    Route::get('emergency-requests/pending',              [EmergencyAccessController::class, 'pendingRequests'])->name('emergency.pending');
    Route::post('emergency-contacts/{contactId}/request', [EmergencyAccessController::class, 'requestAccess'])->name('emergency.request');
    Route::post('emergency-requests/{requestId}/approve', [EmergencyAccessController::class, 'approve'])->name('emergency.approve');
    Route::post('emergency-requests/{requestId}/deny',    [EmergencyAccessController::class, 'deny'])->name('emergency.deny');

    // Vaults
    Route::get('vaults',                              [VaultController::class, 'index'])->name('vaults.index');
    Route::post('vaults',                             [VaultController::class, 'store'])->name('vaults.store');
    Route::put('vaults/{id}',                         [VaultController::class, 'update'])->name('vaults.update');
    Route::delete('vaults/{id}',                      [VaultController::class, 'destroy'])->name('vaults.destroy');
    Route::post('vaults/{id}/lock',                   [VaultController::class, 'lock'])->name('vaults.lock');
    Route::post('vaults/{id}/encryption',             [VaultController::class, 'setupEncryption'])->name('vaults.encryption');
});

/**
 * Routes protected by the api authentication guard and restricted to administrators
 */
Route::group(['middleware' => ['auth:api-guard', 'admin']], function () {
    Route::get('users/{user}/authentications', [UserManagerController::class, 'authentications'])->name('users.authentications');
    Route::patch('users/{user}/password/reset', [UserManagerController::class, 'resetPassword'])->name('users.password.reset');
    Route::patch('users/{user}/promote', [UserManagerController::class, 'promote'])->name('users.promote');
    Route::delete('users/{user}/pats', [UserManagerController::class, 'revokePATs'])->name('users.revoke.pats');
    Route::delete('users/{user}/credentials', [UserManagerController::class, 'revokeWebauthnCredentials'])->name('users.revoke.credentials');
    Route::apiResource('users', UserManagerController::class, ['except' => ['update']]);

    Route::get('settings/{settingName}', [SettingController::class, 'show'])->name('settings.show');
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [SettingController::class, 'store'])->name('settings.store');
    Route::put('settings/{settingName}', [SettingController::class, 'update'])->name('settings.update');
    Route::delete('settings/{settingName}', [SettingController::class, 'destroy'])->name('settings.destroy');

    // Admin user management routes
    Route::get('admin/users', [UserManagementController::class, 'index'])->name('admin.users.index');
    Route::get('admin/users/{id}', [UserManagementController::class, 'show'])->name('admin.users.show');
    Route::put('admin/users/{id}', [UserManagementController::class, 'update'])->name('admin.users.update');
    Route::delete('admin/users/{id}', [UserManagementController::class, 'destroy'])->name('admin.users.destroy');

    // Admin: Rate Limit Dashboard
    Route::get('admin/rate-limits', [RateLimitDashboardController::class, 'index'])->name('admin.rateLimits.index');

    // User invitations routes (admin-only)
    Route::post('user/invitations', [InvitationController::class, 'store'])->name('user.invitations.store');
    Route::get('user/invitations', [InvitationController::class, 'index'])->name('user.invitations.index');
    Route::delete('user/invitations/{id}', [InvitationController::class, 'destroy'])->name('user.invitations.destroy');
});

Route::get('/{any}', function () {
    abort(404, 'unknown endpoint');
})->where('any', '.*');

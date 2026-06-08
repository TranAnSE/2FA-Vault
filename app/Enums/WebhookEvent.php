<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case ACCOUNT_CREATED   = 'account.created';
    case ACCOUNT_UPDATED   = 'account.updated';
    case ACCOUNT_DELETED   = 'account.deleted';
    case OTP_GENERATED     = 'account.otp_generated';
    case TEAM_CREATED      = 'team.created';
    case TEAM_DELETED      = 'team.deleted';
    case MEMBER_JOINED     = 'team.member_joined';
    case MEMBER_LEFT       = 'team.member_left';
    case ACCOUNT_SHARED    = 'team.account_shared';
    case ACCOUNT_UNSHARED  = 'team.account_unshared';
    case USER_LOGIN        = 'auth.user_login';
    case VAULT_LOCKED      = 'vault.locked';
    case VAULT_UNLOCKED    = 'vault.unlocked';
}

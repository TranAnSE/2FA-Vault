<?php

namespace App\Enums;

enum TeamAction: string
{
    case TEAM_CREATED           = 'team.created';
    case TEAM_UPDATED           = 'team.updated';
    case TEAM_DELETED           = 'team.deleted';
    case MEMBER_INVITED         = 'member.invited';
    case MEMBER_JOINED          = 'member.joined';
    case MEMBER_LEFT            = 'member.left';
    case MEMBER_REMOVED         = 'member.removed';
    case ROLE_CHANGED           = 'member.role_changed';
    case ACCOUNT_SHARED         = 'account.shared';
    case ACCOUNT_UNSHARED       = 'account.unshared';
    case INVITATION_CANCELLED   = 'invitation.cancelled';
}

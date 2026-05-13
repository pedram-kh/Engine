<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Social-media platform connected to a Creator. P1 supports IG/TikTok/YT;
 * twitter/twitch/linkedin are P2+ per docs/03-DATA-MODEL.md §5.
 *
 * Stored as varchar(16) on creator_social_accounts.platform.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
}

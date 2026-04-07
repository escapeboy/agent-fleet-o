<?php

namespace App\Domain\Website\Enums;

enum DeploymentProvider: string
{
    case Cloudflare = 'cloudflare';
    case Vercel = 'vercel';
    case Netlify = 'netlify';
    case Manual = 'manual';
}

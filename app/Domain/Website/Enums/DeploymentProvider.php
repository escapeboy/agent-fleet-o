<?php

namespace App\Domain\Website\Enums;

enum DeploymentProvider: string
{
    case Zip = 'zip';
    case Vercel = 'vercel';
    case Cloudflare = 'cloudflare';
    case Netlify = 'netlify';
    case Manual = 'manual';
}

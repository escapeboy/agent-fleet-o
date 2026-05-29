<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Storage Disks
    |--------------------------------------------------------------------------
    |
    | Disks used by TenantStorageManager for tenant-scoped uploads. Community
    | edition defaults to the local/public disks; cloud sets these to the
    | S3 upload disks (s3_private / s3_public) via env. The single invariant:
    | files are written to `public_disk` ONLY when visibility === 'public'.
    |
    */

    'private_disk' => env('TENANT_PRIVATE_DISK', 'local'),

    'public_disk' => env('TENANT_PUBLIC_DISK', 'public'),

    // Key prefix for every tenant object: {prefix}/{team_id}/{category}/...
    'prefix' => env('TENANT_STORAGE_PREFIX', 'tenants'),

    // Default presigned URL lifetime (minutes) for private media preview.
    'temporary_url_minutes' => (int) env('TENANT_TEMP_URL_MINUTES', 15),

];

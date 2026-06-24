<?php

/**
 * Source-ACL-aware retrieval (borrowed from Onyx's document-level permission
 * sync). First cut: per-chunk `source_acl.allowed_group_slugs` filtered at
 * retrieval time by the requesting user's groups. Dark-shipped: when disabled,
 * retrieval is unfiltered (identical to today). null ACL = unrestricted.
 */

return [

    'enabled' => env('SOURCE_ACL_RETRIEVAL_ENABLED', false),

];

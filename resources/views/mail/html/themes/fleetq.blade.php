@php
// Resolve theme tokens — use $emailTheme when available, fall back to sensible defaults
$bgColor        = $emailTheme->background_color ?? '#f4f4f4';
$canvasColor    = $emailTheme->canvas_color     ?? '#ffffff';
$primaryColor   = $emailTheme->primary_color    ?? '#2563eb';
$textColor      = $emailTheme->text_color       ?? '#1f2937';
$headingColor   = $emailTheme->heading_color    ?? '#111827';
$mutedColor     = $emailTheme->muted_color      ?? '#6b7280';
$dividerColor   = $emailTheme->divider_color    ?? '#e5e7eb';
$fontFamily     = $emailTheme->font_family      ?? 'Inter, Arial, sans-serif';
$fontUrl        = $emailTheme->font_url         ?? null;
$bodySize       = ($emailTheme->body_font_size  ?? 16) . 'px';
$headingSize    = ($emailTheme->heading_font_size ?? 24) . 'px';
$lineHeight     = $emailTheme->line_height      ?? 1.6;
$emailWidth     = ($emailTheme->email_width     ?? 600) . 'px';
$contentPad     = ($emailTheme->content_padding ?? 24) . 'px';
@endphp
@if($fontUrl)
@import url('{{ $fontUrl }}');
@endif

/* Base */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: {{ $fontFamily }};
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: {{ $bgColor }};
    color: {{ $textColor }};
    height: 100%;
    line-height: {{ $lineHeight }};
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: {{ $lineHeight }};
    text-align: left;
}

a {
    color: {{ $primaryColor }};
}

a img {
    border: none;
}

/* Typography */

h1 {
    color: {{ $headingColor }};
    font-size: {{ $headingSize }};
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

h2 {
    color: {{ $headingColor }};
    font-size: 18px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

h3 {
    color: {{ $headingColor }};
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

p {
    color: {{ $textColor }};
    font-size: {{ $bodySize }};
    line-height: {{ $lineHeight }};
    margin-top: 0;
    text-align: left;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

/* Layout */

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $bgColor }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

/* Header */

.header {
    padding: 25px 0;
    text-align: center;
}

.header a {
    color: {{ $headingColor }};
    font-size: 19px;
    font-weight: bold;
    text-decoration: none;
}

/* Logo */

.logo {
    height: 75px;
    margin-top: 15px;
    margin-bottom: 10px;
    max-height: 75px;
    width: 75px;
}

/* Body */

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $bgColor }};
    border-bottom: 1px solid {{ $bgColor }};
    border-top: 1px solid {{ $bgColor }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: {{ $emailWidth }};
    background-color: {{ $canvasColor }};
    border-color: {{ $dividerColor }};
    border-radius: 8px;
    border-width: 1px;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px -1px rgba(0, 0, 0, 0.06);
    margin: 0 auto;
    padding: 0;
    width: {{ $emailWidth }};
}

.inner-body a {
    word-break: break-all;
}

/* Subcopy */

.subcopy {
    border-top: 1px solid {{ $dividerColor }};
    margin-top: 25px;
    padding-top: 25px;
}

.subcopy p {
    color: {{ $mutedColor }};
    font-size: 14px;
}

/* Footer */

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: {{ $emailWidth }};
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: {{ $emailWidth }};
}

.footer p {
    color: {{ $mutedColor }};
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: {{ $mutedColor }};
    text-decoration: underline;
}

/* Tables */

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid {{ $dividerColor }};
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: {{ $textColor }};
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: {{ $contentPad }};
}

/* Buttons */

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 6px;
    color: #fff;
    display: inline-block;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $primaryColor }};
    border-bottom: 8px solid {{ $primaryColor }};
    border-left: 18px solid {{ $primaryColor }};
    border-right: 18px solid {{ $primaryColor }};
    border-top: 8px solid {{ $primaryColor }};
}

.button-green,
.button-success {
    background-color: #16a34a;
    border-bottom: 8px solid #16a34a;
    border-left: 18px solid #16a34a;
    border-right: 18px solid #16a34a;
    border-top: 8px solid #16a34a;
}

.button-red,
.button-error {
    background-color: #dc2626;
    border-bottom: 8px solid #dc2626;
    border-left: 18px solid #dc2626;
    border-right: 18px solid #dc2626;
    border-top: 8px solid #dc2626;
}

/* Panels */

.panel {
    border-left: {{ $primaryColor }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: {{ $bgColor }};
    color: {{ $textColor }};
    padding: 16px;
}

.panel-content p {
    color: {{ $textColor }};
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

/* Utilities */

.break-all {
    word-break: break-all;
}

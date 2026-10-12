{{--
    Favicon und Symbol für den Startbildschirm (beinkultur-Avatar, aus
    bk-avatar-kreis_schwarz.eps): im Panel per Render-Hook (AppPanelProvider),
    dazu auf den Seiten ohne Panel (Daysheet, Druckansichten). Das SVG wird im
    dunklen Browser-Design weiß, favicon.ico (16/32/48 px) ist für ältere Browser.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" href="{{ asset('images/favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

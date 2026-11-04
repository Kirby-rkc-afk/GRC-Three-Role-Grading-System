<?php
declare(strict_types=1);
/** Paint an opaque light canvas before external stylesheets arrive. */
function portalPagePaint():void {?>
<meta name="color-scheme" content="light"><meta name="theme-color" content="#990024">
<style id="portal-first-paint">
html{color-scheme:light;background:#f5f6f8;min-height:100%}body{margin:0;min-height:100vh;background:#f5f6f8;color:#292524}.portal-header{background:#990024}.portal-nav{background:#fff}
@view-transition{navigation:auto}
/* Keep the old page opaque underneath the new one; never fade through black. */
::view-transition-old(root){animation:none;opacity:1;mix-blend-mode:normal}
::view-transition-new(root){animation:portal-page-enter 140ms ease-out both;mix-blend-mode:normal}
::view-transition-group(root){animation-duration:140ms}
@keyframes portal-page-enter{from{opacity:0}to{opacity:1}}
@media(prefers-reduced-motion:reduce){@view-transition{navigation:none}::view-transition-group(*),::view-transition-old(*),::view-transition-new(*){animation:none!important}}
@media print{html,body{min-height:0;background:#fff}}
</style>
<?php }

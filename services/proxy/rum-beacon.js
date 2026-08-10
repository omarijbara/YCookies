/**
 * RUM Beacon — inline snippet for client-side telemetry.
 *
 * Collects: Navigation Timing, injection confirmation, banner render, JS errors.
 * Sends: single batched POST via navigator.sendBeacon() after load + 2s.
 * No PII, no cookies, no user identifiers. One beacon per page load.
 */

/**
 * Generate the inline <script> tag for RUM beacon injection.
 *
 * @param {string} beaconUrl - The URL to POST beacon data to (e.g. /api/rum/beacon)
 * @param {object} [options] - Optional settings
 * @param {string} [options.nonce] - CSP nonce
 * @returns {string} - Complete <script>...</script> tag
 */
export function generateRumBeaconScript(beaconUrl, options = {}) {
  const nonceAttr = options.nonce ? ` nonce="${options.nonce}"` : '';

  // The inline JS is minified-ish but readable. ~1.5KB gzipped.
  const inlineJs = `
(function(){
  if(typeof navigator==='undefined'||!navigator.sendBeacon)return;
  var sr=window.__ycRumSampleRate;
  if(typeof sr==='number'&&Math.random()>sr)return;
  var B={
    domain:location.hostname,
    path:location.pathname.replace(/\\/[0-9a-f]{8,}/gi,'/{id}').replace(/\\/\\d+/g,'/{n}').substring(0,512),
    ts:Date.now(),
    dcl:0,load:0,fp:0,ttfb:0,
    banner_expected:0,banner_rendered:0,banner_render_ms:0,
    inject_confirmed:0,inject_missing:0,
    js_errors:[]
  };
  var errBuf=[];
  window.addEventListener('error',function(e){
    if(!e.error||!e.error.stack)return;
    if(e.error.stack.indexOf('ycookies')===-1&&e.message.indexOf('ycookies')===-1)return;
    if(errBuf.length<10){
      var m=e.message.substring(0,200).replace(/:\\d+:\\d+/g,':L').replace(/https?:\\/\\/[^\\s)]+/g,'[url]');
      errBuf.push(m);
    }
  });
  function send(){
    try{
      var nav=performance.getEntriesByType('navigation');
      if(nav&&nav[0]){
        var n=nav[0];
        B.dcl=Math.round(n.domContentLoadedEventEnd-n.startTime);
        B.load=Math.round(n.loadEventEnd-n.startTime);
        B.ttfb=Math.round(n.responseStart-n.startTime);
      }
      var paint=performance.getEntriesByType('paint');
      if(paint){
        for(var i=0;i<paint.length;i++){
          if(paint[i].name==='first-paint'){B.fp=Math.round(paint[i].startTime);break;}
        }
      }
    }catch(x){}
    B.inject_confirmed=document.getElementById('ycookies-manager')?1:0;
    B.inject_missing=B.inject_confirmed?0:1;
    var reopen=document.getElementById('ycookies-reopen-widget');
    var shadow=document.getElementById('preact-border-shadow-host');
    var ycGlobal=window.YCookies;
    if(reopen||shadow||ycGlobal){
      B.banner_expected=1;
      if(shadow&&shadow.shadowRoot){
        var inner=shadow.shadowRoot.innerHTML||'';
        if(inner.length>10)B.banner_rendered=1;
      }
      if(!B.banner_rendered&&reopen&&(reopen.offsetHeight>0||reopen.children.length>0)){
        B.banner_rendered=1;
      }
      if(!B.banner_rendered&&ycGlobal&&typeof ycGlobal.getState==='function'){
        try{var s=ycGlobal.getState();if(s&&s.bannerVisible)B.banner_rendered=1;}catch(x){}
      }
    }
    B.js_errors=errBuf;
    navigator.sendBeacon('${beaconUrl}',JSON.stringify(B));
  }
  if(document.readyState==='complete'){
    setTimeout(send,2000);
  }else{
    window.addEventListener('load',function(){setTimeout(send,2000);});
  }
})();`.trim();

  return `<script${nonceAttr} data-ycookies-rum>${inlineJs}</script>\n`;
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Runtime\Consumer\ManifestConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BootstrapperController extends Controller
{
    /**
     * Serve a synchronous bootstrapper script that blocks scripts
     * matching the domain's active ScriptBlockers configuration.
     *
     * This endpoint returns a lightweight, self-executing JS that:
     * 1. Monkey-patches createElement/appendChild/insertBefore to intercept scripts
     * 2. Uses MutationObserver as a secondary safety net
     * 3. Marks blocked scripts with data-ycookies-blocked for later unblocking
     *
     * The blocklist is dynamically generated from the domain's ScriptBlockers,
     * so changes in the admin panel are reflected within the cache TTL (5 min).
     */
    public function __invoke(Request $request, string $site_id)
    {
        // Lightweight domain lookup first (no eager loading)
        $domain = Domain::where('site_id', $site_id)
            ->where('is_active', true)
            ->first();

        if (!$domain) {
            return $this->jsResponse('console.error("[YCookies] Domain not found or inactive.");');
        }

        // Try manifest path first (if enabled)
        if ($domain->manifest_enabled) {
            $service = app(ManifestConfigService::class);
            $revisionNumber = $service->getRevisionNumber($domain);

            if ($revisionNumber !== null) {
                $cacheKey = "bootstrapper_manifest:{$site_id}:{$revisionNumber}";

                $js = Cache::remember($cacheKey, 300, function () use ($service, $domain) {
                    $blocklist = $service->resolveBlocklist($domain);

                    if ($blocklist === null || empty($blocklist)) {
                        return '/* YCookies: No script blockers configured */';
                    }

                    // Flatten to simple string array (same shape as legacy buildBlocklist)
                    $flatList = array_values(array_unique(
                        array_map(fn($entry) => $entry['pattern'], $blocklist)
                    ));

                    return $this->generateBootstrapperJs(json_encode($flatList, JSON_UNESCAPED_SLASHES));
                });

                return $this->jsResponse($js);
            }
            // No revision — fall through to legacy
        }

        // Legacy path: eager-load ScriptBlockers from DB
        $cacheKey = "bootstrapper:{$site_id}";

        $js = Cache::remember($cacheKey, 300, function () use ($site_id) {
            $domain = Domain::where('site_id', $site_id)
                ->where('is_active', true)
                ->with(['scriptBlockers' => fn($q) => $q->where('is_active', true)])
                ->first();

            if (!$domain) {
                return 'console.error("[YCookies] Domain not found or inactive.");';
            }

            $blocklist = $this->buildBlocklist($domain);

            if (empty($blocklist)) {
                return '/* YCookies: No script blockers configured */';
            }

            $blocklistJson = json_encode($blocklist, JSON_UNESCAPED_SLASHES);

            return $this->generateBootstrapperJs($blocklistJson);
        });

        return $this->jsResponse($js);
    }

    /**
     * Return a cacheable JavaScript response.
     */
    private function jsResponse(string $js): \Illuminate\Http\Response
    {
        return response($js)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->header('X-Content-Type-Options', 'nosniff');
    }


    /**
     * Build a flat blocklist from the domain's active Script Blockers.
     */
    private function buildBlocklist(Domain $domain): array
    {
        $blocklist = [];

        foreach ($domain->scriptBlockers as $blocker) {
            if (!empty($blocker->handles)) {
                foreach ($blocker->handles as $handle) {
                    $handle = trim($handle);
                    if ($handle) {
                        $blocklist[] = $handle;
                    }
                }
            }
            if (!empty($blocker->phrases)) {
                foreach ($blocker->phrases as $phrase) {
                    $phrase = trim($phrase);
                    if ($phrase) {
                        $blocklist[] = $phrase;
                    }
                }
            }
        }

        return array_values(array_unique($blocklist));
    }

    /**
     * Generate the bootstrapper JavaScript.
     *
     * Uses monkey-patching as the PRIMARY blocking mechanism (synchronous),
     * with MutationObserver as a SECONDARY safety net.
     */
    private function generateBootstrapperJs(string $blocklistJson): string
    {
        return <<<JS
/* YCookies Bootstrapper v4 — synchronous script/iframe blocker */
(function(){
  "use strict";
  var B={$blocklistJson};
  if(!B.length)return;
  var BT="application/ycookies-blocked";

  /* ── Helpers ── */
  function isBlocked(str){
    if(!str)return false;
    var s=str.toLowerCase();
    for(var i=0;i<B.length;i++){
      if(s.indexOf(B[i].toLowerCase())!==-1)return true;
    }
    return false;
  }

  function isYC(el){
    if(!el||el.nodeType!==1)return true;
    if(el.id==='ycookies-manager')return true;
    if(el.hasAttribute&&el.hasAttribute('data-ycookies-injected'))return true;
    if(el.hasAttribute&&el.hasAttribute('data-ycookies-blocked'))return true;
    return false;
  }

  function shouldBlock(el){
    if(!el||el.nodeType!==1)return false;
    var t=el.tagName;
    if(t==='SCRIPT'){
      if(isYC(el))return false;
      if(el.getAttribute('type')===BT)return false;
      return isBlocked(el.src)||isBlocked(el.textContent);
    }
    if(t==='IFRAME') return isBlocked(el.src);
    return false;
  }

  function neutralize(el){
    if(el.tagName==='SCRIPT'){
      var ot=el.getAttribute('type');
      if(ot&&ot!==BT) el.setAttribute('data-ycookies-original-type',ot);
      el.type=BT;
    }else if(el.tagName==='IFRAME'){
      if(el.src&&el.src!=='about:blank'){
        el.setAttribute('data-ycookies-original-src',el.src);
        el.removeAttribute('src');
        el.src='about:blank';
      }
    }
    el.setAttribute('data-ycookies-blocked','true');
  }

  /* Recursively scan a node and all descendants for blockable elements */
  function scanTree(node){
    if(!node)return;
    if(node.nodeType===1){
      if(shouldBlock(node)) neutralize(node);
      /* Check children (e.g., DocumentFragment or wrapper div) */
      var kids=node.querySelectorAll?node.querySelectorAll('script,iframe'):[];
      for(var i=0;i<kids.length;i++){
        if(shouldBlock(kids[i])) neutralize(kids[i]);
      }
    }
    /* DocumentFragment (nodeType 11) — scan direct children */
    if(node.nodeType===11){
      var c=node.querySelectorAll('script,iframe');
      for(var j=0;j<c.length;j++){
        if(shouldBlock(c[j])) neutralize(c[j]);
      }
    }
  }

  /* ── 1. Prototype-level src setter on HTMLScriptElement ── */
  var sSrc=Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype,'src');
  if(sSrc&&sSrc.set){
    Object.defineProperty(HTMLScriptElement.prototype,'src',{
      get:sSrc.get,
      set:function(v){
        if(isBlocked(v)&&!isYC(this)){
          /* Do NOT call the native setter — that would let the browser start fetching */
          origSetAttr.call(this,'data-ycookies-blocked-src',v);
          neutralize(this);
          return;
        }
        sSrc.set.call(this,v);
      },
      configurable:true,enumerable:true
    });
  }

  /* ── 2. Prototype-level src setter on HTMLIFrameElement ── */
  var iSrc=Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype,'src');
  if(iSrc&&iSrc.set){
    Object.defineProperty(HTMLIFrameElement.prototype,'src',{
      get:iSrc.get,
      set:function(v){
        if(isBlocked(v)){
          this.setAttribute('data-ycookies-blocked-src',v);
          neutralize(this);
          return;
        }
        iSrc.set.call(this,v);
      },
      configurable:true,enumerable:true
    });
  }

  /* ── 3. Patch setAttribute('src', ...) ── */
  var origSetAttr=Element.prototype.setAttribute;
  Element.prototype.setAttribute=function(name,value){
    if(name.toLowerCase()==='src'&&typeof value==='string'&&isBlocked(value)){
      var t=this.tagName;
      if((t==='SCRIPT'&&!isYC(this))||t==='IFRAME'){
        origSetAttr.call(this,'data-ycookies-blocked-src',value);
        neutralize(this);
        return;
      }
    }
    origSetAttr.call(this,name,value);
  };

  /* ── 4. Patch Node insertion methods ── */
  ['appendChild','insertBefore','replaceChild'].forEach(function(m){
    var orig=Node.prototype[m];
    Node.prototype[m]=function(){
      scanTree(arguments[0]);
      return orig.apply(this,arguments);
    };
  });

  /* ── 5. Patch Element insertion methods (append/prepend/before/after/replaceWith) ── */
  ['append','prepend','before','after','replaceWith'].forEach(function(m){
    if(!Element.prototype[m])return;
    var orig=Element.prototype[m];
    Element.prototype[m]=function(){
      for(var i=0;i<arguments.length;i++){
        if(typeof arguments[i]!=='string') scanTree(arguments[i]);
      }
      return orig.apply(this,arguments);
    };
  });

  /* ── 6. Patch insertAdjacentHTML ── */
  var origInsAdj=Element.prototype.insertAdjacentHTML;
  if(origInsAdj){
    Element.prototype.insertAdjacentHTML=function(pos,html){
      if(typeof html==='string') html=filterMarkup(html);
      origInsAdj.call(this,pos,html);
    };
  }

  /* ── 7. Patch document.write / writeln (variadic) ── */
  var origWrite=document.write.bind(document);
  var origWriteln=document.writeln.bind(document);

  function filterMarkup(html){
    if(typeof html!=='string')return html;
    /* Rewrite <script> tags — check both opening tag attrs AND full content */
    html=html.replace(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi,function(full,attrs,body){
      if(isBlocked(attrs)||isBlocked(body)){
        return '<script type="'+BT+'" data-ycookies-blocked="true"'+attrs+'>'+body+'<\/script>';
      }
      return full;
    });
    /* Also catch self-closing or unclosed <script src="..."> tags */
    html=html.replace(/<script\b([^>]*)>/gi,function(tag,attrs){
      if(tag.indexOf(BT)!==-1)return tag; /* already blocked above */
      if(isBlocked(attrs)){
        return '<script type="'+BT+'" data-ycookies-blocked="true"'+attrs+'>';
      }
      return tag;
    });
    /* Rewrite blocked <iframe> tags — handle quoted and unquoted src= */
    html=html.replace(/<iframe\b([^>]*)>/gi,function(tag,attrs){
      if(isBlocked(attrs)){
        var origSrc='';
        var m=attrs.match(/src\s*=\s*(?:"([^"]*)"|'([^']*)'|(\S+))/i);
        if(m) origSrc=m[1]||m[2]||m[3]||'';
        var cleanAttrs=attrs.replace(/src\s*=\s*(?:"[^"]*"|'[^']*'|\S+)/i,'');
        return '<iframe data-ycookies-blocked="true" data-ycookies-blocked-src="'+origSrc.replace(/"/g,'&quot;')+'" src="about:blank"'+cleanAttrs+'>';
      }
      return tag;
    });
    return html;
  }

  document.write=function(){
    var args=[];
    for(var i=0;i<arguments.length;i++) args.push(filterMarkup(arguments[i]));
    origWrite.apply(document,args);
  };
  document.writeln=function(){
    var args=[];
    for(var i=0;i<arguments.length;i++) args.push(filterMarkup(arguments[i]));
    origWriteln.apply(document,args);
  };

  /* ── 8. MutationObserver — safety net with recursive descendant scan ── */
  new MutationObserver(function(mutations){
    for(var i=0;i<mutations.length;i++){
      var nodes=mutations[i].addedNodes;
      for(var j=0;j<nodes.length;j++) scanTree(nodes[j]);
    }
  }).observe(document.documentElement,{childList:true,subtree:true});
})();
JS;
    }
}

#!/bin/sh
# usage: lh.sh <outdir>
OUT=$1; mkdir -p "$OUT"
export CHROME_PATH="C:/Program Files/Google/Chrome/Application/chrome.exe"
for p in "home|/" "medicines|/medicines?q=napa" "medicine|/medicines/4863"; do
  n=${p%%|*}; u=${p#*|}
  npx lighthouse "http://127.0.0.1:8000$u" --quiet --form-factor=mobile --throttling-method=simulate --only-categories=performance,accessibility,best-practices,seo --output=json --output-path="$OUT/lh-$n.json" --chrome-flags="--headless=new" >/dev/null 2>&1
  node -e "const r=require('$OUT/lh-$n.json');const c=r.categories,a=r.audits;console.log('$n',Object.fromEntries(Object.entries(c).map(([k,v])=>[k,Math.round(v.score*100)])),'LCP',a['largest-contentful-paint'].displayValue,'CLS',a['cumulative-layout-shift'].displayValue,'TBT',a['total-blocking-time'].displayValue,'bytes',a['total-byte-weight'].displayValue)"
done
